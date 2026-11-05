<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Controller;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusUcp\Controller\OpenApiController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The OpenAPI document describes the catalog search exactly as the controller
 * serves it: a POST with a JSON body, the bounds it enforces, the answers it gives.
 */
#[CoversClass(OpenApiController::class)]
final class OpenApiControllerTest extends TestCase
{
    public function testExactlyTheServedCatalogOperationsAreDescribed(): void
    {
        $paths = $this->document()['paths'];

        self::assertSame(['/catalog/search', '/catalog/lookup', '/catalog/product'], array_keys($paths));
        self::assertSame(
            ['search_catalog', 'lookup_catalog', 'get_product'],
            array_map(static fn (array $path): string => $path['post']['operationId'], array_values($paths)),
        );
    }

    /** Bodies are the official schemas of the declared version. */
    public function testBodiesReferenceTheOfficialSchemas(): void
    {
        $search = $this->document()['paths']['/catalog/search']['post'];

        self::assertSame(
            ['$ref' => 'https://ucp.dev/2026-08-25/schemas/shopping/catalog_search.json#/$defs/search_request'],
            $search['requestBody']['content']['application/json']['schema'],
        );
        self::assertSame(
            [['$ref' => 'https://ucp.dev/2026-08-25/schemas/shopping/catalog_search.json#/$defs/search_response'], ['$ref' => '#/components/schemas/error_response']],
            $search['responses']['200']['content']['application/json']['schema']['oneOf'],
        );
        self::assertSame(['200', '400', '422', '424', '503'], array_map('strval', array_keys($search['responses'])));
        self::assertStringContainsString('At most 50 products per page and 200 per search', $search['description']);
    }

    /** UCP-Agent is required on every operation: negotiation is a MUST. */
    public function testUcpAgentIsRequired(): void
    {
        $doc = $this->document();

        foreach ($doc['paths'] as $path) {
            self::assertContains(['$ref' => '#/components/parameters/ucp_agent'], $path['post']['parameters']);
        }
        self::assertTrue($doc['components']['parameters']['ucp_agent']['required']);
        self::assertSame('UCP-Agent', $doc['components']['parameters']['ucp_agent']['name']);
    }

    public function testTheDocumentNamesVersionAndServer(): void
    {
        $doc = $this->document();

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertSame('2026-08-25', $doc['info']['version']);
        self::assertSame([['url' => 'https://shop.example.com/ucp/v1']], $doc['servers']);
        self::assertSame(['$ref' => 'https://ucp.dev/2026-08-25/schemas/common/types/error_response.json'], $doc['components']['schemas']['error_response']);
        self::assertSame(
            ['invalid_profile_url', 'profile_unreachable', 'profile_malformed', 'version_unsupported'],
            $doc['components']['schemas']['transport_error']['properties']['code']['enum'],
        );
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $channel = self::createStub(ChannelInterface::class);
        $factory = self::createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']));

        $response = (new OpenApiController($factory, new AgentJsonResponder()))(new Request());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
