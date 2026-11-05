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
 * UCP discovery profile at /.well-known/ucp (business profile, UCP 2026-08-25).
 *
 * Declares exactly what the shop implements per the specification:
 *
 *   dev.ucp.shopping.catalog.search   POST {endpoint}/catalog/search
 *   dev.ucp.shopping.catalog.lookup   POST {endpoint}/catalog/lookup, /catalog/product
 *   dev.ucp.shopping.permalink        GET  /ucp/buy/{items}  (303 into the buyer's cart)
 *
 * There is no `dev.ucp.shopping.checkout`. A UCP checkout is a session the
 * platform completes with a payment instrument through a declared payment
 * handler; this shop sells age-restricted goods (JuSchG, TabakerzG) whose
 * purchase runs in the buyer's browser, behind the shop's age confirmation,
 * with the shop's own payment provider. The permalink is the capability UCP
 * defines for that handoff, and `payment_handlers` stays empty — present,
 * because the profile schema requires the registry, and empty, because the
 * shop accepts no instrument from an agent.
 *
 * `schema` URLs point at ucp.dev, not at this shop: a platform accepts an
 * entity only when its schema host matches the namespace (`dev.ucp.*` must
 * come from ucp.dev), and drops it otherwise.
 *
 * Next to the nested `ucp` object, the document repeats the same facts at the
 * top level (`protocol_version`, `services`, `capabilities`, `endpoints`) for
 * discovery scanners that read that shape. The profile schema allows extra
 * members; the nested object is the authority and the flat keys are
 * projections of it.
 *
 * Endpoint URLs are absolute and come from the channel, not the request: this
 * profile is consumed by clients that will call those URLs from elsewhere.
 */
final class UcpProfileController
{
    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
        private readonly string $version = '2026-08-25',
        private readonly string $endpointBase = '/ucp/v1',
        private readonly bool $permalinkEnabled = true,
    ) {
    }

    #[Route('/.well-known/ucp', name: 'koh_ucp_profile', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request): Response
    {
        $context = $this->contextFactory->create();
        $endpoint = $context->url($this->endpointBase);
        $ucpDev = 'https://ucp.dev/'.$this->version;

        $services = [
            'dev.ucp.shopping' => [[
                'version' => $this->version,
                'spec' => $ucpDev.'/specification/overview',
                'transport' => 'rest',
                'endpoint' => $endpoint,
                'schema' => $ucpDev.'/services/shopping/rest.openapi.json',
            ]],
        ];

        $capabilities = [
            CatalogSearchController::CAPABILITY => [[
                'version' => $this->version,
                'spec' => $ucpDev.'/specification/shopping/catalog/search',
                'schema' => $ucpDev.'/schemas/shopping/catalog_search.json',
            ]],
            CatalogLookupController::CAPABILITY => [[
                'version' => $this->version,
                'spec' => $ucpDev.'/specification/shopping/catalog/lookup',
                'schema' => $ucpDev.'/schemas/shopping/catalog_lookup.json',
            ]],
        ];
        $endpoints = [
            'catalog_search' => $endpoint.'/catalog/search',
            'catalog_lookup' => $endpoint.'/catalog/lookup',
            'catalog_product' => $endpoint.'/catalog/product',
        ];
        if ($this->permalinkEnabled) {
            $permalink = $context->url(PermalinkController::PATH);
            $capabilities[PermalinkController::CAPABILITY] = [[
                'version' => $this->version,
                'spec' => $ucpDev.'/specification/permalink',
                'schema' => $ucpDev.'/schemas/shopping/permalink.json',
                'config' => ['endpoint' => $permalink],
            ]];
            $endpoints['permalink'] = $permalink;
        }

        return $this->responder->respond([
            // Specification shape. No `keys`: the shop signs nothing yet, and
            // the member is optional — an entry would promise a verification
            // this shop does not perform.
            'ucp' => [
                'version' => $this->version,
                'services' => $services,
                'capabilities' => $capabilities,
                // Required even when empty; an object, not a list.
                'payment_handlers' => new \stdClass(),
            ],

            // Scanner shape: the same facts, flattened.
            'protocol_version' => $this->version,
            'services' => $services,
            'capabilities' => $capabilities,
            'endpoints' => $endpoints,
        ], maxAge: 300, request: $request);
    }
}
