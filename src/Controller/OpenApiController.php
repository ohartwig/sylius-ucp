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
 * The OpenAPI document the UCP profile and the api-catalog point at as `schema`.
 *
 * It describes the two operations CheckoutSessionController implements, and
 * nothing more: a schema that lists operations the shop does not serve sends a
 * client to a 404 with the shop's own signature on the promise.
 */
final class OpenApiController
{
    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '2026-04-08',
        private readonly string $endpointBase = '/ucp/v1',
    ) {
    }

    #[Route('/ucp/v1/openapi.json', name: 'koh_ucp_openapi', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request): Response
    {
        $server = $this->contextFactory->create()->url($this->endpointBase);
        $envelope = ['$ref' => '#/components/schemas/UcpEnvelope'];

        return $this->responder->respond([
            'openapi' => '3.1.0',
            'info' => ['title' => 'UCP shopping service', 'version' => $this->version],
            'servers' => [['url' => $server]],
            'paths' => [
                '/catalog' => ['get' => [
                    'operationId' => 'searchCatalog',
                    'summary' => 'Search products by name (at most 200 results).',
                    'parameters' => [
                        ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1]],
                        ['name' => 'localeCode', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'default' => 'en_US']],
                        ['name' => 'UCP-Agent', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '200' => ['description' => 'Matching products.', 'content' => ['application/json' => ['schema' => $envelope]]],
                        '400' => ['description' => 'q is missing or empty.', 'content' => ['application/json' => ['schema' => $envelope]]],
                    ],
                ]],
                '/checkout-sessions' => ['post' => [
                    'operationId' => 'createCheckoutSession',
                    'summary' => 'Create a checkout session for the current cart.',
                    'parameters' => [
                        ['name' => 'UCP-Agent', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'responses' => [
                        '201' => ['description' => 'The created session.', 'content' => ['application/json' => ['schema' => $envelope]]],
                    ],
                ]],
            ],
            'components' => ['schemas' => ['UcpEnvelope' => [
                'type' => 'object',
                'required' => ['ucp'],
                'properties' => ['ucp' => [
                    'type' => 'object',
                    'properties' => [
                        'version' => ['type' => 'string'],
                        'capabilities' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'agent' => ['type' => 'string'],
                    ],
                ]],
                'additionalProperties' => true,
            ]]],
        ], maxAge: 300, request: $request);
    }
}
