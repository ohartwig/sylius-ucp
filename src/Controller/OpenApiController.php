<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The OpenAPI document the api-catalog points at as `service-desc`.
 *
 * It describes the operations this bundle serves — the catalog subset of
 * UCP's shopping REST binding — and nothing more: a schema that lists
 * operations the shop does not serve sends a client to a 404 with the shop's
 * own signature on the promise. Request and response bodies reference the
 * official UCP schemas of the declared version.
 */
final class OpenApiController
{
    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '2026-08-25',
        private readonly string $endpointBase = '/ucp/v1',
    ) {
    }

    #[Route('/ucp/v1/openapi.json', name: 'koh_ucp_openapi', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request): Response
    {
        $server = $this->contextFactory->create()->url($this->endpointBase);
        $schemas = 'https://ucp.dev/'.$this->version.'/schemas/';

        $operation = fn (string $id, string $summary, string $description, string $requestSchema, string $responseSchema): array => [
            'post' => [
                'operationId' => $id,
                'summary' => $summary,
                'description' => $description,
                'parameters' => [
                    ['$ref' => '#/components/parameters/ucp_agent'],
                    ['$ref' => '#/components/parameters/request_id'],
                    ['$ref' => '#/components/parameters/accept_language'],
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => ['$ref' => $schemas.$requestSchema]]],
                ],
                'responses' => [
                    '200' => [
                        'description' => 'The operation result, or a UCP error response (`ucp.status: error`) for outcomes such as `capabilities_incompatible` or `not_found`.',
                        'content' => ['application/json' => ['schema' => ['oneOf' => [
                            ['$ref' => $schemas.$responseSchema],
                            ['$ref' => '#/components/schemas/error_response'],
                        ]]]],
                    ],
                    '400' => [
                        'description' => 'Invalid request (UCP error response, `invalid_request` or `request_too_large`), or a missing or unusable UCP-Agent profile URL (transport error `invalid_profile_url`).',
                        'content' => ['application/json' => ['schema' => ['oneOf' => [
                            ['$ref' => '#/components/schemas/error_response'],
                            ['$ref' => '#/components/schemas/transport_error'],
                        ]]]],
                    ],
                    '422' => ['$ref' => '#/components/responses/negotiation_failed'],
                    '424' => ['$ref' => '#/components/responses/negotiation_failed'],
                    '503' => ['$ref' => '#/components/responses/discovery_busy'],
                ],
            ],
        ];

        $paths = [
            '/catalog/search' => $operation(
                'search_catalog',
                'Search products (dev.ucp.shopping.catalog.search).',
                \sprintf('Needs a query or a filter (categories: taxon codes; price: minor units). At most %d products per page and %d per search; signals and attribution are not used.', CatalogSearchController::MAX_LIMIT, CatalogSearchController::MAX_RESULTS),
                'shopping/catalog_search.json#/$defs/search_request',
                'shopping/catalog_search.json#/$defs/search_response',
            ),
            '/catalog/lookup' => $operation(
                'lookup_catalog',
                'Look up products by product or variant code (dev.ucp.shopping.catalog.lookup).',
                \sprintf('At most %d identifiers per request; unknown identifiers are reported as not_found info messages.', CatalogLookupController::MAX_IDS),
                'shopping/catalog_lookup.json#/$defs/lookup_request',
                'shopping/catalog_lookup.json#/$defs/lookup_response',
            ),
            '/catalog/product' => $operation(
                'get_product',
                'One product in detail, with option selection (dev.ucp.shopping.catalog.lookup).',
                'A variant code makes that variant the featured one; selected/preferences narrow the variants of a product code.',
                'shopping/catalog_lookup.json#/$defs/get_product_request',
                'shopping/catalog_lookup.json#/$defs/get_product_response',
            ),
        ];

        return $this->responder->respond([
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'UCP shopping service (catalog)',
                'version' => $this->version,
                'description' => 'The catalog operations of the UCP shopping REST binding (https://ucp.dev/'.$this->version.'/services/shopping/rest.openapi.json). Every request names its platform profile in UCP-Agent; the shop fetches it and negotiates before it answers.',
            ],
            'servers' => [['url' => $server]],
            'paths' => $paths,
            'components' => [
                'parameters' => [
                    'ucp_agent' => [
                        'name' => 'UCP-Agent',
                        'in' => 'header',
                        'required' => true,
                        'description' => 'RFC 8941 dictionary naming the platform profile: profile="https://platform.example/.well-known/ucp".',
                        'schema' => ['type' => 'string'],
                    ],
                    'request_id' => [
                        'name' => 'Request-Id',
                        'in' => 'header',
                        'required' => false,
                        'description' => 'Tracing identifier; accepted, not required by this shop.',
                        'schema' => ['type' => 'string'],
                    ],
                    'accept_language' => [
                        'name' => 'Accept-Language',
                        'in' => 'header',
                        'required' => false,
                        'description' => 'Content language when context.language is absent.',
                        'schema' => ['type' => 'string'],
                    ],
                ],
                'schemas' => [
                    'error_response' => ['$ref' => $schemas.'common/types/error_response.json'],
                    'transport_error' => [
                        'type' => 'object',
                        'description' => 'Negotiation and discovery failures (UCP overview, "Error Handling", REST).',
                        'required' => ['code', 'content'],
                        'properties' => [
                            'code' => ['type' => 'string', 'enum' => ['invalid_profile_url', 'profile_unreachable', 'profile_malformed', 'version_unsupported']],
                            'content' => ['type' => 'string'],
                            'continue_url' => ['type' => 'string', 'format' => 'uri'],
                        ],
                    ],
                ],
                'responses' => [
                    'negotiation_failed' => [
                        'description' => '422: profile_malformed or version_unsupported; 424: profile_unreachable.',
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/transport_error']]],
                    ],
                    'discovery_busy' => [
                        'description' => 'The shop\'s budget for fetching unknown platform profiles is spent; retry after Retry-After seconds.',
                        'headers' => ['Retry-After' => ['schema' => ['type' => 'integer']]],
                        'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/transport_error']]],
                    ],
                ],
            ],
        ], maxAge: 300, request: $request);
    }
}
