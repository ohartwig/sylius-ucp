<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Support;

use Koh\SyliusUcp\Negotiation\HostResolver;
use Koh\SyliusUcp\Negotiation\Negotiator;
use Koh\SyliusUcp\Negotiation\PlatformProfileFetcher;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * A UCP platform for controller tests: its profile is served by a mock HTTP
 * client at PROFILE_URL, which resolves to a public address.
 */
final class Platform
{
    public const PROFILE_URL = 'https://platform.example/.well-known/ucp';

    public const HEADER = 'profile="'.self::PROFILE_URL.'"';

    private function __construct()
    {
    }

    /**
     * A platform profile declaring the given capabilities at the version.
     *
     * @param list<string> $capabilities
     *
     * @return array<string, mixed>
     */
    public static function profile(array $capabilities = [
        'dev.ucp.shopping.catalog.search',
        'dev.ucp.shopping.catalog.lookup',
        'dev.ucp.shopping.permalink',
    ], string $version = '2026-08-25'): array
    {
        $registry = [];
        foreach ($capabilities as $name) {
            $registry[$name] = [[
                'version' => $version,
                'spec' => 'https://ucp.dev/'.$version.'/specification/overview',
                'schema' => 'https://ucp.dev/'.$version.'/schemas/shopping/'.str_replace(['dev.ucp.shopping.', '.'], ['', '_'], $name).'.json',
            ]];
        }

        return [
            'ucp' => [
                'version' => $version,
                'services' => ['dev.ucp.shopping' => [[
                    'version' => $version,
                    'transport' => 'rest',
                    'spec' => 'https://ucp.dev/'.$version.'/specification/overview',
                    'schema' => 'https://ucp.dev/'.$version.'/services/shopping/rest.openapi.json',
                ]]],
                'capabilities' => $registry,
                'payment_handlers' => new \stdClass(),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $profile served profile; null: the default one
     */
    public static function negotiator(?array $profile = null): Negotiator
    {
        return new Negotiator(self::fetcher(new MockHttpClient(new MockResponse(json_encode($profile ?? self::profile(), \JSON_THROW_ON_ERROR)))));
    }

    public static function fetcher(MockHttpClient $client, ?HostResolver $resolver = null, int $budget = 20): PlatformProfileFetcher
    {
        return new PlatformProfileFetcher(
            $resolver ?? self::resolver(['93.184.215.14']),
            new ArrayAdapter(),
            new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => $budget, 'interval' => '1 minute'], new InMemoryStorage()),
            $client,
        );
    }

    /** @param list<string> $addresses */
    public static function resolver(array $addresses): HostResolver
    {
        return new class($addresses) implements HostResolver {
            /** @var list<string> */
            public array $asked = [];

            /** @param list<string> $addresses */
            public function __construct(private readonly array $addresses)
            {
            }

            public function resolve(string $host): array
            {
                $this->asked[] = $host;

                return $this->addresses;
            }
        };
    }

    /**
     * A JSON POST carrying UCP-Agent.
     *
     * @param array<string, mixed>|string $body
     */
    public static function post(string $path, array|string $body, ?string $header = self::HEADER): Request
    {
        $request = Request::create($path, 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: \is_string($body) ? $body : json_encode($body, \JSON_THROW_ON_ERROR));
        if (null !== $header) {
            $request->headers->set('UCP-Agent', $header);
        }

        return $request;
    }
}
