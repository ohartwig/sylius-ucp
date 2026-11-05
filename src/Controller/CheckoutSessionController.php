<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Koh\SyliusUcp\Exception\InvalidCheckoutRequestException;
use Koh\SyliusUcp\Service\CheckoutSessionCreator;
use Sylius\Component\Product\Model\ProductInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * UCP REST capabilities (first pass): catalog listing + checkout-session creation.
 * Reads the agent profile from the `UCP-Agent` request header (capability
 * negotiation stub) and echoes the active capabilities under `ucp` per spec.
 */
final class CheckoutSessionController
{
    /**
     * @param ProductRepositoryInterface<ProductInterface> $productRepository
     * @param bool $checkoutEnabled `koh_sylius_ucp.checkout`; off by default, the
     *                              session route then answers 404 (AUD-034-F01)
     * @param RateLimiterFactory|null $checkoutLimiter keyed by client address; required
     *                                                 when checkout is enabled
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly CheckoutSessionCreator $checkoutSessionCreator,
        private readonly string $version = '2026-04-08',
        private readonly bool $checkoutEnabled = false,
        private readonly ?RateLimiterFactory $checkoutLimiter = null,
    ) {
        if ($this->checkoutEnabled && null === $this->checkoutLimiter) {
            throw new \LogicException('koh_sylius_ucp.checkout is enabled but no rate limiter is configured.');
        }
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
        // Every anonymous POST used to persist an empty cart (AUD-034-F01). The
        // route is now opt-in, rate-limited and refuses a request that does not
        // name what the cart should hold.
        if (!$this->checkoutEnabled || null === $this->checkoutLimiter) {
            throw new NotFoundHttpException();
        }

        $limit = $this->checkoutLimiter->create($request->getClientIp() ?? 'unknown')->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

            return new JsonResponse(
                $this->envelope(['error' => 'too many checkout sessions, retry later'], $request),
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        try {
            $session = $this->checkoutSessionCreator->create($this->lineItems($request));
        } catch (InvalidCheckoutRequestException $e) {
            return new JsonResponse(
                $this->envelope(['error' => $e->getMessage()], $request),
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        return new JsonResponse($this->envelope(['checkout_session' => $session], $request), JsonResponse::HTTP_CREATED);
    }

    /**
     * Reads `line_items` in the UCP shape: `[{"item": {"id": "<variant code>"}, "quantity": 1}]`.
     *
     * @return list<array{code: string, quantity: int}>
     */
    private function lineItems(Request $request): array
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            throw new InvalidCheckoutRequestException('a JSON body with line_items is required');
        }

        try {
            $body = json_decode($content, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidCheckoutRequestException('the body is not valid JSON');
        }

        $lines = \is_array($body) ? ($body['line_items'] ?? null) : null;
        if (!\is_array($lines) || [] === $lines || !array_is_list($lines)) {
            throw new InvalidCheckoutRequestException('line_items must be a non-empty list');
        }
        if (\count($lines) > CheckoutSessionCreator::MAX_LINE_ITEMS) {
            throw new InvalidCheckoutRequestException(\sprintf('at most %d line items', CheckoutSessionCreator::MAX_LINE_ITEMS));
        }

        $items = [];
        foreach ($lines as $line) {
            $code = \is_array($line) && \is_array($line['item'] ?? null) ? ($line['item']['id'] ?? null) : null;
            $quantity = \is_array($line) ? ($line['quantity'] ?? 1) : null;
            if (!\is_string($code) || '' === $code || \strlen($code) > 255 || !\is_int($quantity)) {
                throw new InvalidCheckoutRequestException('each line item needs item.id (string) and quantity (integer)');
            }
            $items[] = ['code' => $code, 'quantity' => $quantity];
        }

        return $items;
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

        $capabilities = ['dev.ucp.shopping.catalog'];
        if ($this->checkoutEnabled) {
            $capabilities[] = 'dev.ucp.shopping.checkout';
        }

        return $payload + ['ucp' => ['version' => $this->version, 'capabilities' => $capabilities, 'agent' => $agent]];
    }
}
