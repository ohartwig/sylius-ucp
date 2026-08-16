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

final class UcpProfileControllerTest extends TestCase
{
    /** The shape ucp.dev specifies: everything nested, plus signing_keys. */
    public function testSpecificationShapeIsPresent(): void
    {
        $profile = $this->profile();

        self::assertArrayHasKey('ucp', $profile);
        self::assertSame('2026-04-08', $profile['ucp']['version']);
        self::assertArrayHasKey('dev.ucp.shopping', $profile['ucp']['services']);
        self::assertArrayHasKey('signing_keys', $profile);
    }

    /**
     * The shape discovery scanners look for. Both readings exist in real
     * clients, so the document serves both — extra members are ignored by any
     * conforming parser.
     */
    public function testScannerShapeIsPresent(): void
    {
        $profile = $this->profile();

        self::assertSame('2026-04-08', $profile['protocol_version']);
        self::assertArrayHasKey('services', $profile);
        self::assertArrayHasKey('capabilities', $profile);
        self::assertArrayHasKey('endpoints', $profile);
    }

    /** The flat keys are projections; they must never disagree with the nested object. */
    public function testBothShapesCarryTheSameFacts(): void
    {
        $profile = $this->profile();

        self::assertSame($profile['ucp']['version'], $profile['protocol_version']);
        self::assertSame($profile['ucp']['services'], $profile['services']);
        self::assertSame($profile['ucp']['capabilities'], $profile['capabilities']);
    }

    /** Clients call these from elsewhere, so relative paths would be useless. */
    public function testEndpointsAreAbsoluteAndChannelScoped(): void
    {
        $profile = $this->profile();

        self::assertSame('https://shop.example.com/ucp/v1/catalog', $profile['endpoints']['catalog']);
        self::assertSame('https://shop.example.com/ucp/v1/checkout_sessions', $profile['endpoints']['checkout']);
        self::assertSame(
            'https://shop.example.com/ucp/v1',
            $profile['ucp']['services']['dev.ucp.shopping'][0]['endpoint'],
        );
    }

    /**
     * Empty rather than absent: omitting the key violates the schema, and
     * populating it would promise a verification this shop does not perform.
     */
    public function testSigningKeysIsPresentButEmpty(): void
    {
        self::assertSame([], $this->profile()['signing_keys']);
    }

    public function testProfileIsPubliclyCacheable(): void
    {
        $response = ($this->controller())(new Request());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    /** @return array<string, mixed> */
    private function profile(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(
            (string) ($this->controller())(new Request())->getContent(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }

    private function controller(): UcpProfileController
    {
        $channel = self::createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB_DE');

        $factory = self::createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(
            new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']),
        );

        return new UcpProfileController($factory, new AgentJsonResponder());
    }
}
