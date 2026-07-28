<?php

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

    #[Route('/ucp/v1/catalog', name: 'koh_ucp_catalog', methods: ['GET'])]
    public function catalog(Request $request): JsonResponse
    {
        $locale = (string) $request->query->get('localeCode', 'en_US');
        $products = array_map(
            static fn ($p): array => ['code' => (string) $p->getCode(), 'name' => (string) $p->getName(), 'slug' => $p->getSlug()],
            $this->productRepository->findByName((string) $request->query->get('q', ''), $locale),
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
