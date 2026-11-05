<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Controller;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusUcp\Controller\CheckoutSessionController;
use Koh\SyliusUcp\Controller\OpenApiController;
use Koh\SyliusUcp\Controller\UcpProfileController;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Every URL the profile and the OpenAPI document advertise must be a route this
 * bundle serves. The profile once said `checkout_sessions` while the route was
 * `checkout-sessions`, and pointed at an openapi.json nobody served.
 */
final class AdvertisedPathsTest extends TestCase
{
    private const ORIGIN = 'https://shop.example.com';

    /** @return iterable<string, array{bool}> */
    public static function checkoutSwitch(): iterable
    {
        yield 'checkout off' => [false];
        yield 'checkout on' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('checkoutSwitch')]
    public function testEveryProfileUrlIsARoute(bool $checkout): void
    {
        $profile = $this->decode((new UcpProfileController($this->factory(), new AgentJsonResponder(), checkoutEnabled: $checkout))(new Request()));

        $urls = array_values($profile['endpoints']);
        $urls[] = $profile['ucp']['services']['dev.ucp.shopping'][0]['schema'];
        foreach ($profile['ucp']['capabilities'] as $entries) {
            $urls[] = $entries[0]['schema'];
        }

        foreach (array_unique($urls) as $url) {
            self::assertContains(substr($url, \strlen(self::ORIGIN)), $this->routePaths(), $url.' is advertised but not routed');
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('checkoutSwitch')]
    public function testEveryOpenApiPathIsARoute(bool $checkout): void
    {
        $doc = $this->decode((new OpenApiController($this->factory(), new AgentJsonResponder(), checkoutEnabled: $checkout))(new Request()));

        self::assertSame('3.1.0', $doc['openapi']);
        $base = substr($doc['servers'][0]['url'], \strlen(self::ORIGIN));
        foreach (array_keys($doc['paths']) as $path) {
            self::assertContains($base.$path, $this->routePaths(), $path.' is documented but not routed');
        }
        self::assertSame($checkout, isset($doc['paths']['/checkout-sessions']));
    }

    /** @return list<string> */
    private function routePaths(): array
    {
        $paths = [];
        foreach ([CheckoutSessionController::class, OpenApiController::class, UcpProfileController::class] as $class) {
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
