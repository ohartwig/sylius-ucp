<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Koh\SyliusUcp\Service\CheckoutSessionCreator;
use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * UCP REST capabilities (first pass): catalog listing + checkout-session creation.
 * Reads the agent profile from the `UCP-Agent` request header (capability
 * negotiation stub) and echoes the active capabilities under `ucp` per spec.
 */
final class CheckoutSessionController
{
    private const ACTIVE = ['dev.ucp.shopping.catalog', 'dev.ucp.shopping.checkout'];

    /** @param ProductRepositoryInterface<ProductInterface> $productRepository */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CheckoutSessionCreator $checkoutSessionCreator,
        private readonly string $version = '2026-04-08',
    ) {
    }

    /** Upper bound on what one catalog call may serialise. */
    private const MAX_RESULTS = 200;

    #[Route('/ucp/v1/catalog', name: 'koh_ucp_catalog', methods: ['GET'])]
    public function catalog(Request $request): JsonResponse
    {
        $locale = $request->query->get('localeCode', 'en_US');
        $query = trim($request->query->get('q', ''));

        // findByName() is a LIKE with no limit, and this route needs no
        // credentials. An empty q makes it `LIKE '%%'` — every product in the
        // shop, hydrated and serialised, for anyone who asks as often as they
        // like. A catalog search without a search term is not a search.
        if ('' === $query) {
            return new JsonResponse(
                $this->envelope(['error' => 'q is required', 'products' => []], $request),
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        $products = array_map(
            static fn ($p): array => ['code' => (string) $p->getCode(), 'name' => (string) $p->getName(), 'slug' => $p->getSlug()],
            // Bounds the response. The repository has no limit of its own, so a
            // very broad term still costs the query — narrowing that needs a
            // repository-level limit this interface does not offer.
            \array_slice($this->productRepository->findByName($query, $locale), 0, self::MAX_RESULTS),
        );

        return new JsonResponse($this->envelope(['products' => $products], $request));
    }

    #[Route('/ucp/v1/checkout-sessions', name: 'koh_ucp_checkout_session', methods: ['POST'])]
    public function createSession(Request $request): JsonResponse
    {
        $session = $this->checkoutSessionCreator->create();

        return new JsonResponse($this->envelope(['checkout_session' => $session], $request), JsonResponse::HTTP_CREATED);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function envelope(array $payload, Request $request): array
    {
        // Capability negotiation stub: intersect our capabilities with the agent's
        // advertised profile from the UCP-Agent header (RFC 8941). First pass: echo ours.
        $agent = $request->headers->get('UCP-Agent', '');

        return $payload + ['ucp' => ['version' => $this->version, 'capabilities' => self::ACTIVE, 'agent' => $agent]];
    }
}
