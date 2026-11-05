<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Negotiation;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches the platform profile a UCP caller names in `UCP-Agent`.
 *
 * The URL comes from an anonymous request, so this is the one place where a
 * stranger decides what the shop connects to. UCP's fetching rules (overview,
 * "Profile Requirements — Fetching") are applied as written:
 *
 * - https only, no userinfo, no redirects followed;
 * - every address the host resolves to must be public — a name with one
 *   private record among public ones is refused outright — and the connection
 *   is pinned to the address that was checked (the client's `resolve` option),
 *   so a second DNS answer cannot swap in an internal one between check and
 *   connect;
 * - no proxy, not even one from the environment, so the check applies to the
 *   address that is really dialled;
 * - two seconds to connect, four in total, and the transfer is aborted past
 *   256 KiB (the spec asks for no less than 128 KiB);
 * - profiles are cached for five minutes (the spec's floor is 60 seconds) and
 *   a failed fetch for one minute, which is the backoff the spec suggests;
 * - a global budget of 20 uncached fetches a minute, shared by every worker
 *   through the rate limiter's storage. Past it the answer is 503 with
 *   Retry-After rather than an outbound request.
 *
 * The service holds no request state: the cache and the budget live in shared
 * storage, so FrankenPHP's worker mode changes nothing about it. The HTTP
 * client is this service's own instance — the pinned address must not leak
 * into the DNS cache of the application's shared client.
 */
final class PlatformProfileFetcher
{
    public const MAX_BYTES = 262144;

    public const TTL_SECONDS = 300;

    public const FAILURE_TTL_SECONDS = 60;

    public const CONNECT_TIMEOUT = 2.0;

    public const TOTAL_TIMEOUT = 4.0;

    private const MAX_URL_LENGTH = 2048;

    private const CACHE_PREFIX = 'koh_ucp.platform_profile.';

    private readonly HttpClientInterface $client;

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly CacheItemPoolInterface $cache,
        private readonly RateLimiterFactory $budget,
        ?HttpClientInterface $client = null,
    ) {
        $this->client = $client ?? HttpClient::create();
    }

    public function fetch(string $url): PlatformProfile|ProfileFetchError
    {
        $parsed = $this->parse($url);
        if ($parsed instanceof ProfileFetchError) {
            return $parsed;
        }

        $item = $this->cache->getItem(self::CACHE_PREFIX.hash('xxh128', $url));
        if ($item->isHit()) {
            $cached = $this->fromCache($item->get());
            if (null !== $cached) {
                return $cached;
            }
        }

        // DNS lookups go out too, so the budget is spent before resolving.
        $limit = $this->budget->create('global')->consume();
        if (!$limit->isAccepted()) {
            return new ProfileFetchError(
                503,
                'profile_unreachable',
                'Too many profile discoveries right now; retry shortly.',
                max(1, $limit->getRetryAfter()->getTimestamp() - time()),
            );
        }

        $result = $this->resolveAndDownload($url, $parsed);

        // Unusable addresses are not cached: they cost no fetch, and the
        // answer may legitimately change with the next DNS update.
        if ($result instanceof PlatformProfile || 400 !== $result->status) {
            $item->set($result instanceof PlatformProfile
                ? ['profile' => $result->toArray()]
                : ['error' => [$result->status, $result->code, $result->message]]);
            $item->expiresAfter($result instanceof PlatformProfile ? self::TTL_SECONDS : self::FAILURE_TTL_SECONDS);
            $this->cache->save($item);
        }

        return $result;
    }

    /**
     * @return string|ProfileFetchError the host
     */
    private function parse(string $url): string|ProfileFetchError
    {
        $parts = \strlen($url) <= self::MAX_URL_LENGTH && 1 !== preg_match('/[\x00-\x20\x7F]/', $url) ? parse_url($url) : false;
        if (!\is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '') || '' === ($parts['host'] ?? '')
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            return new ProfileFetchError(400, 'invalid_profile_url', 'The profile URL must be an absolute https URL without credentials.');
        }

        return strtolower($parts['host']);
    }

    private function resolveAndDownload(string $url, string $host): PlatformProfile|ProfileFetchError
    {
        $literal = trim($host, '[]');
        $addresses = false !== filter_var($literal, \FILTER_VALIDATE_IP) ? [$literal] : $this->resolver->resolve($host);
        if ([] === $addresses) {
            return new ProfileFetchError(400, 'invalid_profile_url', 'The profile host does not resolve.');
        }
        foreach ($addresses as $address) {
            if (!PublicAddress::isPublic($address)) {
                return new ProfileFetchError(400, 'invalid_profile_url', 'The profile host resolves to an address this shop does not connect to.');
            }
        }

        // IPv4 first: not every network this runs in has IPv6 egress.
        usort($addresses, static fn (string $a, string $b): int => (int) str_contains($a, ':') <=> (int) str_contains($b, ':'));

        $document = $this->download($url, $literal, $addresses[0]);
        if ($document instanceof ProfileFetchError) {
            return $document;
        }

        return PlatformProfile::fromDocument($document)
            ?? new ProfileFetchError(422, 'profile_malformed', 'The platform profile is not a valid UCP profile.');
    }

    private function download(string $url, string $host, string $address): mixed
    {
        $options = [
            'max_redirects' => 0,
            'timeout' => self::CONNECT_TIMEOUT,
            'max_duration' => self::TOTAL_TIMEOUT,
            // Connect to the address that was checked, nothing else.
            'resolve' => [$host => $address],
            // `*` exempts every host from every proxy, the environment's included.
            'no_proxy' => '*',
            'headers' => ['Accept' => 'application/json'],
            'buffer' => false,
        ];
        if (self::supportsConnectTimeout()) {
            $options['max_connect_duration'] = self::CONNECT_TIMEOUT;
        }

        try {
            $response = $this->client->request('GET', $url, $options);
            $status = $response->getStatusCode();
            if ($status < 200 || $status > 299) {
                // A 3xx lands here too: profile endpoints must not redirect,
                // and a redirect is not followed.
                $response->cancel();

                return new ProfileFetchError(424, 'profile_unreachable', \sprintf('The platform profile answered HTTP %d.', $status));
            }

            $content = $this->body($response);
        } catch (ExceptionInterface) {
            return new ProfileFetchError(424, 'profile_unreachable', 'The platform profile could not be fetched.');
        }

        if (null === $content) {
            return new ProfileFetchError(422, 'profile_malformed', 'The platform profile is larger than this shop accepts.');
        }

        try {
            return json_decode($content, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new ProfileFetchError(422, 'profile_malformed', 'The platform profile is not valid JSON.');
        }
    }

    /** `max_connect_duration` exists from symfony/http-client-contracts 3.6 on. */
    private static function supportsConnectTimeout(): bool
    {
        $defaults = (new \ReflectionClassConstant(HttpClientInterface::class, 'OPTIONS_DEFAULTS'))->getValue();

        return \is_array($defaults) && \array_key_exists('max_connect_duration', $defaults);
    }

    /**
     * The body, or null once it grows past MAX_BYTES (the transfer is then
     * cancelled rather than read to the end).
     */
    private function body(ResponseInterface $response): ?string
    {
        $declared = $response->getHeaders(false)['content-length'][0] ?? null;
        if (null !== $declared && (int) $declared > self::MAX_BYTES) {
            $response->cancel();

            return null;
        }

        $content = '';
        foreach ($this->client->stream($response) as $chunk) {
            $content .= $chunk->getContent();
            if (\strlen($content) > self::MAX_BYTES) {
                $response->cancel();

                return null;
            }
        }

        return $content;
    }

    private function fromCache(mixed $cached): PlatformProfile|ProfileFetchError|null
    {
        if (\is_array($cached) && \is_array($cached['profile'] ?? null)) {
            return PlatformProfile::fromArray($cached['profile']);
        }
        $error = \is_array($cached) ? ($cached['error'] ?? null) : null;
        if (\is_array($error) && \is_int($error[0] ?? null) && \is_string($error[1] ?? null) && \is_string($error[2] ?? null)) {
            return new ProfileFetchError($error[0], $error[1], $error[2]);
        }

        return null;
    }
}
