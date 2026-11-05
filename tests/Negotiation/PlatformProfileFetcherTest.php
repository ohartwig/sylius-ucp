<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Negotiation;

use Koh\SyliusUcp\Negotiation\PlatformProfile;
use Koh\SyliusUcp\Negotiation\PlatformProfileFetcher;
use Koh\SyliusUcp\Negotiation\ProfileFetchError;
use Koh\SyliusUcp\Tests\Support\Platform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * The caller decides what URL the shop fetches, so every UCP fetching rule is
 * a test here: https only, public addresses only and pinned, no redirects,
 * timeouts, a body cap, a cache and a global budget.
 */
#[CoversClass(PlatformProfileFetcher::class)]
#[CoversClass(ProfileFetchError::class)]
final class PlatformProfileFetcherTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    public function testAProfileIsFetchedFromThePinnedAddressWithoutRedirectsOrProxy(): void
    {
        $fetcher = Platform::fetcher($this->client([$this->profileResponse()]));

        $profile = $fetcher->fetch(Platform::PROFILE_URL);

        self::assertInstanceOf(PlatformProfile::class, $profile);
        self::assertTrue($profile->supports('dev.ucp.shopping.catalog.search', '2026-08-25'));
        self::assertCount(1, $this->requests);
        [$method, $url, $options] = $this->requests[0];
        self::assertSame('GET', $method);
        self::assertSame(Platform::PROFILE_URL, $url);
        self::assertSame(['platform.example' => '93.184.215.14'], $options['resolve']);
        self::assertSame(0, $options['max_redirects']);
        self::assertSame('*', $options['no_proxy']);
        self::assertSame(2.0, $options['timeout']);
        self::assertSame(4.0, $options['max_duration']);
        self::assertSame(2.0, $options['max_connect_duration']);
        self::assertContains('Accept: application/json', $options['headers']);
    }

    public function testAFetchedProfileIsCached(): void
    {
        $client = $this->client([$this->profileResponse()]);
        $fetcher = Platform::fetcher($client);

        $fetcher->fetch(Platform::PROFILE_URL);
        $second = $fetcher->fetch(Platform::PROFILE_URL);

        self::assertInstanceOf(PlatformProfile::class, $second);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testACachedFailureIsNotRetriedAtOnce(): void
    {
        $client = $this->client([new MockResponse('', ['http_code' => 500])]);
        $fetcher = Platform::fetcher($client);

        $first = $fetcher->fetch(Platform::PROFILE_URL);
        $second = $fetcher->fetch(Platform::PROFILE_URL);

        self::assertSame(424, $this->error($second)->status);
        self::assertSame($this->error($first)->code, $this->error($second)->code);
        self::assertSame(1, $client->getRequestsCount());
    }

    public function testAnUnreadableCacheEntryIsFetchedAgain(): void
    {
        $cache = new ArrayAdapter();
        $cache->save($cache->getItem('koh_ucp.platform_profile.'.hash('xxh128', Platform::PROFILE_URL))->set('garbage'));
        $client = $this->client([$this->profileResponse()]);
        $fetcher = new PlatformProfileFetcher(Platform::resolver(['93.184.215.14']), $cache, $this->budget(5), $client);

        self::assertInstanceOf(PlatformProfile::class, $fetcher->fetch(Platform::PROFILE_URL));
        self::assertSame(1, $client->getRequestsCount());
    }

    /** @return iterable<string, array{string}> */
    public static function unusableUrls(): iterable
    {
        yield 'http' => ['http://platform.example/.well-known/ucp'];
        yield 'no scheme' => ['//platform.example/.well-known/ucp'];
        yield 'other scheme' => ['file:///etc/passwd'];
        yield 'userinfo' => ['https://user@platform.example/'];
        yield 'password' => ['https://user:pw@platform.example/'];
        yield 'no host' => ['https:///x'];
        yield 'whitespace' => ['https://platform.example/a b'];
        yield 'control character' => ["https://platform.example/\x00"];
        yield 'too long' => ['https://platform.example/'.str_repeat('a', 2048)];
        yield 'not a URL' => ['https://'];
    }

    #[DataProvider('unusableUrls')]
    public function testUnusableUrlsAreRefusedWithoutAnyRequest(string $url): void
    {
        $resolver = Platform::resolver(['93.184.215.14']);
        $client = $this->client([]);

        $error = $this->error(Platform::fetcher($client, $resolver)->fetch($url));

        self::assertSame(400, $error->status);
        self::assertSame('invalid_profile_url', $error->code);
        self::assertSame(0, $client->getRequestsCount());
        self::assertSame([], $resolver->asked);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function privateResolutions(): iterable
    {
        yield 'loopback' => [['127.0.0.1']];
        yield 'metadata' => [['169.254.169.254']];
        yield 'one private among public ones' => [['93.184.215.14', '10.0.0.5']];
        yield 'v6 loopback' => [['::1']];
        yield 'nothing' => [[]];
    }

    /** @param list<string> $addresses */
    #[DataProvider('privateResolutions')]
    public function testHostsResolvingToSpecialUseAddressesAreRefused(array $addresses): void
    {
        $resolver = Platform::resolver($addresses);
        $client = $this->client([]);
        $fetcher = Platform::fetcher($client, $resolver);

        $error = $this->error($fetcher->fetch(Platform::PROFILE_URL));
        $fetcher->fetch(Platform::PROFILE_URL);

        self::assertSame(400, $error->status);
        self::assertSame('invalid_profile_url', $error->code);
        self::assertSame(0, $client->getRequestsCount());
        // Not cached: the next request resolves again.
        self::assertSame(['platform.example', 'platform.example'], $resolver->asked);
    }

    public function testAnIpLiteralIsCheckedWithoutDns(): void
    {
        $resolver = Platform::resolver(['93.184.215.14']);

        $private = $this->error(Platform::fetcher($this->client([]), $resolver)->fetch('https://127.0.0.1/.well-known/ucp'));
        $mapped = $this->error(Platform::fetcher($this->client([]), $resolver)->fetch('https://[::ffff:10.0.0.1]/.well-known/ucp'));
        $public = Platform::fetcher($this->client([$this->profileResponse()]), $resolver)->fetch('https://93.184.215.14/.well-known/ucp');

        self::assertSame('invalid_profile_url', $private->code);
        self::assertSame('invalid_profile_url', $mapped->code);
        self::assertInstanceOf(PlatformProfile::class, $public);
        self::assertSame([], $resolver->asked);
    }

    public function testIpv4IsDialledFirst(): void
    {
        Platform::fetcher($this->client([$this->profileResponse()]), Platform::resolver(['2606:4700:4700::1111', '93.184.215.14']))->fetch(Platform::PROFILE_URL);

        self::assertSame(['platform.example' => '93.184.215.14'], $this->requests[0][2]['resolve']);
    }

    /** @return iterable<string, array{MockResponse, int, string}> */
    public static function failedFetches(): iterable
    {
        yield 'redirect' => [new MockResponse('', ['http_code' => 301, 'response_headers' => ['Location' => 'http://127.0.0.1/']]), 424, 'profile_unreachable'];
        yield 'not found' => [new MockResponse('', ['http_code' => 404]), 424, 'profile_unreachable'];
        yield 'server error' => [new MockResponse('', ['http_code' => 503]), 424, 'profile_unreachable'];
        yield 'informational' => [new MockResponse('', ['http_code' => 199]), 424, 'profile_unreachable'];
        yield 'transport error' => [new MockResponse([new TransportException('timeout')]), 424, 'profile_unreachable'];
        yield 'not JSON' => [new MockResponse('{'), 422, 'profile_malformed'];
        yield 'not a profile' => [new MockResponse('{"name": "x"}'), 422, 'profile_malformed'];
        yield 'declared too large' => [new MockResponse(str_repeat('a', PlatformProfileFetcher::MAX_BYTES + 1), ['response_headers' => ['Content-Length' => (string) (PlatformProfileFetcher::MAX_BYTES + 1)]]), 422, 'profile_malformed'];
        yield 'streamed too large' => [new MockResponse(self::chunks(PlatformProfileFetcher::MAX_BYTES + 1)), 422, 'profile_malformed'];
    }

    #[DataProvider('failedFetches')]
    public function testFailedFetchesAreTheSpecsErrors(MockResponse $response, int $status, string $code): void
    {
        $error = $this->error(Platform::fetcher($this->client([$response]))->fetch(Platform::PROFILE_URL));

        self::assertSame($status, $error->status);
        self::assertSame($code, $error->code);
        self::assertNull($error->retryAfter);
    }

    public function testABodyAtTheCapIsRead(): void
    {
        $json = json_encode(Platform::profile(), \JSON_THROW_ON_ERROR);
        $padded = $json.str_repeat(' ', PlatformProfileFetcher::MAX_BYTES - \strlen($json));

        self::assertInstanceOf(PlatformProfile::class, Platform::fetcher($this->client([new MockResponse($padded)]))->fetch(Platform::PROFILE_URL));
    }

    /** Past the global budget the answer is 503 with Retry-After, not a request. */
    public function testTheDiscoveryBudgetBoundsUncachedFetches(): void
    {
        $client = $this->client([$this->profileResponse()]);
        $fetcher = Platform::fetcher($client, null, 1);

        $first = $fetcher->fetch(Platform::PROFILE_URL);
        $cached = $fetcher->fetch(Platform::PROFILE_URL);
        $other = $this->error($fetcher->fetch('https://other.example/.well-known/ucp'));

        self::assertInstanceOf(PlatformProfile::class, $first);
        self::assertInstanceOf(PlatformProfile::class, $cached);
        self::assertSame(503, $other->status);
        self::assertSame('profile_unreachable', $other->code);
        self::assertGreaterThanOrEqual(1, $other->retryAfter);
        self::assertLessThanOrEqual(61, $other->retryAfter);
        self::assertSame(1, $client->getRequestsCount());
    }

    /** @return list<string> */
    private static function chunks(int $bytes): array
    {
        return str_split(str_repeat('a', $bytes), 65536);
    }

    private function error(PlatformProfile|ProfileFetchError $result): ProfileFetchError
    {
        self::assertInstanceOf(ProfileFetchError::class, $result);

        return $result;
    }

    private function profileResponse(): MockResponse
    {
        return new MockResponse(json_encode(Platform::profile(), \JSON_THROW_ON_ERROR));
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = [$method, $url, $options];

            return array_shift($responses) ?? throw new TransportException('unexpected request');
        });
    }

    private function budget(int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(['id' => 't', 'policy' => 'fixed_window', 'limit' => $limit, 'interval' => '1 minute'], new InMemoryStorage());
    }
}
