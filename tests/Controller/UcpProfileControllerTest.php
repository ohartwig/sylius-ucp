<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Controller;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusUcp\Controller\UcpProfileController;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The business profile against UCP 2026-08-25 (schemas/profile.json#/$defs/business_schema).
 */
final class UcpProfileControllerTest extends TestCase
{
    public function testTheVersionIs20260825Everywhere(): void
    {
        $profile = $this->profile();

        self::assertSame('2026-08-25', $profile['ucp']['version']);
        self::assertSame('2026-08-25', $profile['ucp']['services']['dev.ucp.shopping'][0]['version']);
        self::assertSame('2026-08-25', $profile['ucp']['capabilities']['dev.ucp.shopping.catalog.search'][0]['version']);
    }

    /** `services` and `payment_handlers` are required even when empty, and both are maps. */
    public function testPaymentHandlersIsAnEmptyObjectNotAList(): void
    {
        $json = $this->json();

        self::assertStringContainsString('"payment_handlers": {}', $json);
    }

    /**
     * Exactly what the shop serves. Checkout is not declared: a UCP checkout
     * completes with a payment instrument, and this shop's purchase runs in
     * the buyer's browser behind its age confirmation.
     */
    public function testCatalogAndPermalinkAreDeclaredAndCheckoutIsNot(): void
    {
        self::assertSame(
            ['dev.ucp.shopping.catalog.search', 'dev.ucp.shopping.catalog.lookup', 'dev.ucp.shopping.permalink'],
            array_keys($this->profile()['ucp']['capabilities']),
        );
    }

    /** The permalink config.endpoint is absolute, without trailing slash. */
    public function testThePermalinkNamesItsBrowserEndpoint(): void
    {
        $permalink = $this->profile()['ucp']['capabilities']['dev.ucp.shopping.permalink'][0];

        self::assertSame(['endpoint' => 'https://shop.example.com/ucp/buy'], $permalink['config']);
        self::assertSame('https://ucp.dev/2026-08-25/schemas/shopping/permalink.json', $permalink['schema']);
        self::assertSame('https://ucp.dev/2026-08-25/specification/permalink', $permalink['spec']);
    }

    public function testASwitchedOffPermalinkIsNotDeclared(): void
    {
        $profile = $this->profile(new UcpProfileController($this->factory(), new AgentJsonResponder(), permalinkEnabled: false));

        self::assertArrayNotHasKey('dev.ucp.shopping.permalink', $profile['ucp']['capabilities']);
        self::assertArrayNotHasKey('permalink', $profile['endpoints']);
    }

    /**
     * A platform drops any entity whose schema host does not match its
     * namespace, so `dev.ucp.*` schemas must come from ucp.dev.
     */
    public function testSchemaUrlsAreOnUcpDev(): void
    {
        $ucp = $this->profile()['ucp'];

        self::assertSame(
            'https://ucp.dev/2026-08-25/services/shopping/rest.openapi.json',
            $ucp['services']['dev.ucp.shopping'][0]['schema'],
        );
        self::assertSame(
            'https://ucp.dev/2026-08-25/schemas/shopping/catalog_search.json',
            $ucp['capabilities']['dev.ucp.shopping.catalog.search'][0]['schema'],
        );
        self::assertSame(
            'https://ucp.dev/2026-08-25/specification/shopping/catalog/search',
            $ucp['capabilities']['dev.ucp.shopping.catalog.search'][0]['spec'],
        );
        self::assertSame('https://ucp.dev/2026-08-25/specification/overview', $ucp['services']['dev.ucp.shopping'][0]['spec']);
    }

    /** The REST binding of a business profile requires an endpoint. */
    public function testTheServiceIsARestBindingWithAnAbsoluteEndpoint(): void
    {
        $service = $this->profile()['ucp']['services']['dev.ucp.shopping'][0];

        self::assertSame('rest', $service['transport']);
        self::assertSame('https://shop.example.com/ucp/v1', $service['endpoint']);
    }

    /** `signing_keys` was removed in 2026-04-08; `keys` is optional and the shop signs nothing. */
    public function testNoSigningKeyMembers(): void
    {
        $profile = $this->profile();

        self::assertArrayNotHasKey('signing_keys', $profile);
        self::assertArrayNotHasKey('keys', $profile);
    }

    /** The flat keys are projections; they must never disagree with the nested object. */
    public function testTheScannerShapeCarriesTheSameFacts(): void
    {
        $profile = $this->profile();

        self::assertSame($profile['ucp']['version'], $profile['protocol_version']);
        self::assertSame($profile['ucp']['services'], $profile['services']);
        self::assertSame($profile['ucp']['capabilities'], $profile['capabilities']);
        self::assertSame([
            'catalog_search' => 'https://shop.example.com/ucp/v1/catalog/search',
            'catalog_lookup' => 'https://shop.example.com/ucp/v1/catalog/lookup',
            'catalog_product' => 'https://shop.example.com/ucp/v1/catalog/product',
            'permalink' => 'https://shop.example.com/ucp/buy',
        ], $profile['endpoints']);
    }

    public function testAConfiguredVersionAndBaseAreUsed(): void
    {
        $profile = $this->profile(new UcpProfileController($this->factory(), new AgentJsonResponder(), '2026-09-01', '/agents/ucp'));

        self::assertSame('2026-09-01', $profile['ucp']['version']);
        self::assertSame('https://shop.example.com/agents/ucp', $profile['ucp']['services']['dev.ucp.shopping'][0]['endpoint']);
        self::assertSame('https://ucp.dev/2026-09-01/schemas/shopping/catalog_search.json', $profile['capabilities']['dev.ucp.shopping.catalog.search'][0]['schema']);
    }

    public function testProfileIsPubliclyCacheable(): void
    {
        $response = (new UcpProfileController($this->factory(), new AgentJsonResponder()))(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('300', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    /** @return array<string, mixed> */
    private function profile(?UcpProfileController $controller = null): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->json($controller), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function json(?UcpProfileController $controller = null): string
    {
        $controller ??= new UcpProfileController($this->factory(), new AgentJsonResponder());

        return (string) $controller(new Request())->getContent();
    }

    private function factory(): AgentContextFactoryInterface
    {
        $channel = self::createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        $factory = self::createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(
            new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']),
        );

        return $factory;
    }
}
