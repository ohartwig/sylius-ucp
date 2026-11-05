<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Koh\SyliusUcp\Exception\InvalidCheckoutRequestException;
use Koh\SyliusUcp\Service\CheckoutSessionCreator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Opt-in checkout-session creation backed by a Sylius cart.
 *
 * This is not the UCP checkout capability: there is no update, complete or
 * cancel, and the response is not the UCP checkout shape. The profile therefore
 * never declares `dev.ucp.shopping.checkout`, and the `ucp` member of the
 * response names the version only, no capability.
 */
final class CheckoutSessionController
{
    /**
     * @param bool $checkoutEnabled `koh_sylius_ucp.checkout`; off by default, the
     *                              session route then answers 404 (AUD-034-F01)
     * @param RateLimiterFactory|null $checkoutLimiter keyed by client address; required
     *                                                 when checkout is enabled
     */
    public function __construct(
        private readonly CheckoutSessionCreator $checkoutSessionCreator,
        private readonly string $version = '2026-08-25',
        private readonly bool $checkoutEnabled = false,
        private readonly ?RateLimiterFactory $checkoutLimiter = null,
    ) {
        if ($this->checkoutEnabled && null === $this->checkoutLimiter) {
            throw new \LogicException('koh_sylius_ucp.checkout is enabled but no rate limiter is configured.');
        }
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
                $this->envelope(['error' => 'too many checkout sessions, retry later']),
                JsonResponse::HTTP_TOO_MANY_REQUESTS,
                ['Retry-After' => (string) $retryAfter],
            );
        }

        try {
            $session = $this->checkoutSessionCreator->create($this->lineItems($request));
        } catch (InvalidCheckoutRequestException $e) {
            return new JsonResponse(
                $this->envelope(['error' => $e->getMessage()]),
                JsonResponse::HTTP_BAD_REQUEST,
            );
        }

        return new JsonResponse($this->envelope(['checkout_session' => $session]), JsonResponse::HTTP_CREATED);
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
    private function envelope(array $payload): array
    {
        return $payload + ['ucp' => ['version' => $this->version]];
    }
}
