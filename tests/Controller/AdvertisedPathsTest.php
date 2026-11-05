<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Controller;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusUcp\Controller\CatalogSearchController;
use Koh\SyliusUcp\Controller\CatalogLookupController;
use Koh\SyliusUcp\Controller\PermalinkController;
use Koh\SyliusUcp\Controller\OpenApiController;
use Koh\SyliusUcp\Controller\UcpProfileController;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every URL the profile and the OpenAPI document advertise on the shop must be
 * a route this bundle serves. The profile once said `checkout_sessions` while
 * the route was `checkout-sessions`, and pointed at an openapi.json nobody served.
 * Catalog lookup needs both of its REST paths, and the permalink endpoint both
 * its bare and its item form.
 */
final class AdvertisedPathsTest extends TestCase
{
    private const ORIGIN = 'https://shop.example.com';

    /** REST paths of the UCP 2026-08-25 REST binding, relative to the service endpoint. */
    private const SPEC_PATHS = [
        'dev.ucp.shopping.catalog.search' => ['/catalog/search'],
        'dev.ucp.shopping.catalog.lookup' => ['/catalog/lookup', '/catalog/product'],
    ];

    /**
     * Endpoints the profile names on this shop are routes; every capability
     * maps to the REST path the spec gives it under the service endpoint.
     */
    public function testEveryProfileUrlOnTheShopIsARoute(): void
    {
        $profile = $this->decode((new UcpProfileController($this->factory(), new AgentJsonResponder()))(new Request()));

        $endpoint = $profile['ucp']['services']['dev.ucp.shopping'][0]['endpoint'];
        $urls = array_values($profile['endpoints']);
        foreach ($profile['ucp']['capabilities'] as $capability => $entries) {
            foreach (self::SPEC_PATHS[$capability] ?? [] as $path) {
                $urls[] = $endpoint.$path;
            }
            if (isset($entries[0]['config']['endpoint'])) {
                $urls[] = $entries[0]['config']['endpoint'];
                $urls[] = $entries[0]['config']['endpoint'].'/{items}';
            }
        }

        foreach (array_unique($urls) as $url) {
            self::assertStringStartsWith(self::ORIGIN, $url);
            self::assertContains(substr($url, \strlen(self::ORIGIN)), $this->routePaths(), $url.' is advertised but not routed');
        }
    }

    public function testEveryOpenApiPathIsARoute(): void
    {
        $doc = $this->decode((new OpenApiController($this->factory(), new AgentJsonResponder()))(new Request()));

        self::assertSame('3.1.0', $doc['openapi']);
        $base = substr($doc['servers'][0]['url'], \strlen(self::ORIGIN));
        foreach (array_keys($doc['paths']) as $path) {
            self::assertContains($base.$path, $this->routePaths(), $path.' is documented but not routed');
        }
    }

    /** @return list<string> */
    private function routePaths(): array
    {
        $paths = [];
        foreach ([CatalogSearchController::class, CatalogLookupController::class, PermalinkController::class, OpenApiController::class, UcpProfileController::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                foreach ($method->getAttributes(Route::class) as $attribute) {
                    $paths[] = (string) $attribute->newInstance()->getPath();
                }
            }
        }

        return $paths;
    }

    /** @return array<string, mixed> */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function factory(): AgentContextFactoryInterface
    {
        $channel = self::createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');
        $factory = self::createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(new AgentContext($channel, self::ORIGIN, 'de_DE', ['de_DE']));

        return $factory;
    }
}
