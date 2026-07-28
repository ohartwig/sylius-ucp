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
 * UCP discovery profile at /.well-known/ucp.
 *
 * The document is a superset, deliberately.
 *
 * The specification (ucp.dev) nests everything under a `ucp` object and pairs it
 * with `signing_keys`. Discovery scanners in the wild instead look for
 * `protocol_version`, `services`, `capabilities` and `endpoints` at the top
 * level. Both readings exist in real clients and neither is going away;
 * publishing only one means being invisible to half of them.
 *
 * Extra members are ignored by any conforming parser, so serving both costs a
 * few hundred bytes. The nested `ucp` object is the authority — the top-level
 * keys are projections of it and must not be edited on their own.
 *
 * Endpoint URLs are absolute and come from the channel, not the request: this
 * profile is consumed by clients that will call those URLs from elsewhere.
 */
final class UcpProfileController
{
    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '2026-04-08',
        private readonly string $endpointBase = '/ucp/v1',
    ) {
    }

    #[Route('/.well-known/ucp', name: 'koh_ucp_profile', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request): Response
    {
        $context = $this->contextFactory->create();
        $endpoint = $context->url($this->endpointBase);
        $schema = $endpoint.'/openapi.json';

        $services = [
            'dev.ucp.shopping' => [[
                'version' => $this->version,
                'spec' => 'https://ucp.dev/'.$this->version.'/specification/overview',
                'transport' => 'rest',
                'endpoint' => $endpoint,
                'schema' => $schema,
            ]],
        ];

        $capabilities = [
            'dev.ucp.shopping.catalog' => [[
                'version' => $this->version,
                'spec' => 'https://ucp.dev/'.$this->version.'/specification/catalog',
                'schema' => $schema,
            ]],
            'dev.ucp.shopping.checkout' => [[
                'version' => $this->version,
                'spec' => 'https://ucp.dev/'.$this->version.'/specification/checkout',
                'schema' => $schema,
            ]],
        ];

        return $this->responder->respond([
            // Specification shape.
            'ucp' => [
                'version' => $this->version,
                'services' => $services,
                'capabilities' => $capabilities,
            ],
            // Required by the spec. Empty until request signing exists: omitting
            // the key violates the schema, and populating it would promise a
            // verification this shop does not yet perform.
            'signing_keys' => [],

            // Scanner shape: the same facts, flattened.
            'protocol_version' => $this->version,
            'services' => $services,
            'capabilities' => $capabilities,
            'endpoints' => [
                'catalog' => $endpoint.'/catalog',
                'checkout' => $endpoint.'/checkout_sessions',
            ],
        ], maxAge: 300, request: $request);
    }
}
