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
    public function testTheCatalogSearchOperationIsDescribedAsServed(): void
    {
        $envelope = ['$ref' => '#/components/schemas/UcpEnvelope'];

        self::assertSame(['post' => [
            'operationId' => 'search_catalog',
            'summary' => 'Search products by name (UCP dev.ucp.shopping.catalog.search).',
            'description' => 'Request and response follow https://ucp.dev/2026-08-25/schemas/shopping/catalog_search.json. At most 50 products per page and 200 per search term; filters and context are not applied.',
            'parameters' => [
                ['name' => 'UCP-Agent', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']],
            ],
            'requestBody' => [
                'required' => true,
                'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['query'],
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 1],
                        'pagination' => ['type' => 'object', 'properties' => [
                            'cursor' => ['type' => 'string'],
                            'limit' => ['type' => 'integer', 'minimum' => 1, 'default' => 10],
                        ]],
                    ],
                ]]],
            ],
            'responses' => [
                '200' => ['description' => 'Matching products, one page.', 'content' => ['application/json' => ['schema' => $envelope]]],
                '400' => ['description' => 'Malformed JSON, no query, or an invalid cursor or limit.', 'content' => ['application/json' => ['schema' => $envelope]]],
            ],
        ]], $this->document()['paths']['/catalog/search']);
    }

    public function testTheEnvelopeAndHeaderAreUcp20260825(): void
    {
        $doc = $this->document();

        self::assertSame('3.1.0', $doc['openapi']);
        self::assertSame(['title' => 'UCP shopping service', 'version' => '2026-08-25'], $doc['info']);
        self::assertSame([['url' => 'https://shop.example.com/ucp/v1']], $doc['servers']);
        self::assertSame(['schemas' => ['UcpEnvelope' => [
            'type' => 'object',
            'required' => ['ucp'],
            'properties' => ['ucp' => [
                'type' => 'object',
                'properties' => [
                    'version' => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['success', 'error']],
                    'capabilities' => ['type' => 'object'],
                ],
            ]],
            'additionalProperties' => true,
        ]]], $doc['components']);
    }

    public function testCheckoutIsDocumentedOnlyWhenSwitchedOn(): void
    {
        self::assertSame(['/catalog/search'], array_keys($this->document()['paths']));
        self::assertSame(['/catalog/search', '/checkout-sessions'], array_keys($this->document(checkout: true)['paths']));
    }

    /** @return array<string, mixed> */
    private function document(bool $checkout = false): array
    {
        $channel = self::createStub(ChannelInterface::class);
        $factory = self::createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']));

        $response = (new OpenApiController($factory, new AgentJsonResponder(), checkoutEnabled: $checkout))(new Request());

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
