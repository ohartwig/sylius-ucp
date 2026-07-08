<?php

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * UCP discovery profile — Universal Commerce Protocol (Google/Shopify).
 * Served at /.well-known/ucp. First pass: REST service + catalog/checkout
 * capabilities, pinned to UCP version 2026-04-08. TODO (documented): signing_keys
 * (RFC 9421), payment_handlers, cart/order/identity_linking, MCP/A2A transports.
 */
final class UcpProfileController
{
    public function __construct(
        private readonly string $version = '2026-04-08',
        private readonly string $endpointBase = '/ucp/v1',
    ) {
    }

    #[Route('/.well-known/ucp', name: 'koh_ucp_profile', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $profile = [
            'version' => $this->version,
            'services' => [
                [
                    'transport' => 'rest',
                    'endpoint' => $this->endpointBase,
                    'schema' => $this->endpointBase . '/openapi.json',
                ],
            ],
            'capabilities' => [
                'dev.ucp.shopping.catalog' => ['version' => $this->version, 'spec' => 'https://ucp.dev/', 'schema' => $this->endpointBase . '/openapi.json'],
                'dev.ucp.shopping.checkout' => ['version' => $this->version, 'spec' => 'https://ucp.dev/', 'schema' => $this->endpointBase . '/openapi.json'],
            ],
            // TODO: payment_handlers, signing_keys (JWK), cart/order/identity_linking.
        ];

        return (new JsonResponse($profile))
            ->setPublic()
            ->setMaxAge(60);
    }
}
