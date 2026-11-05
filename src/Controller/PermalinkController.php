<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Koh\SyliusAgentContracts\Cart\CartHandoff;
use Koh\SyliusAgentContracts\Cart\CartHandoffLine;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusUcp\Permalink\PermalinkRequest;
use Koh\SyliusUcp\Permalink\PurchasableItemResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Capability `dev.ucp.shopping.permalink`: the handoff from an agent to the
 * buyer's own browser.
 *
 *     GET /ucp/buy/{items}?continue_to=/…   →   303 to the cart (or continue_to)
 *
 * This shop sells age-restricted goods, so an agent cannot complete a purchase
 * for the buyer; the permalink is the honest end of an agent's work. The buyer
 * opens it, and each item goes into the buyer's own cart through
 * `sylius.cart_item_add` — the event the shop's add-to-cart button fires — so
 * every gate the shop hangs on it (age confirmation, quantity rules) runs
 * exactly as if the buyer had clicked. If a gate refuses, nothing is added and
 * the buyer lands on the shop with a notice; once the gate is satisfied, the
 * same link works. Address, age declaration, shipping and payment are the
 * shop's normal checkout.
 *
 * Loading the link places no order and charges nothing. Opening it twice in
 * the same session does not add the items twice, and a HEAD request (link
 * previews) changes nothing. Every answer is a 303 with `Cache-Control:
 * no-store` and `Referrer-Policy: no-referrer`.
 *
 * The handoff itself is {@see CartHandoff}, shared with ACP's `continue_url`;
 * this controller parses the link, resolves the items and picks notices and
 * destinations.
 */
final class PermalinkController
{
    public const CAPABILITY = 'dev.ucp.shopping.permalink';

    public const PATH = '/ucp/buy';

    public const SESSION_KEY = 'koh_ucp.permalinks';

    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly PurchasableItemResolver $resolver,
        private readonly CartHandoff $handoff,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly bool $enabled = true,
        private readonly string $cartRoute = 'sylius_shop_cart_summary',
        private readonly string $fallbackRoute = 'sylius_shop_homepage',
    ) {
    }

    #[Route(self::PATH, name: 'koh_ucp_permalink', methods: ['GET'])]
    #[Route(self::PATH.'/', name: 'koh_ucp_permalink_slash', methods: ['GET'])]
    #[Route(self::PATH.'/{items}', name: 'koh_ucp_permalink_items', requirements: ['items' => '[^/]+'], methods: ['GET'])]
    public function __invoke(Request $request, string $items = ''): Response
    {
        if (!$this->enabled) {
            throw new NotFoundHttpException('This shop does not offer UCP permalinks.');
        }

        $context = $this->contextFactory->create();
        $locale = $context->localeCode;
        $permalink = PermalinkRequest::parse($items, (string) $request->server->get('QUERY_STRING', ''), \strlen($request->getUri()));

        if ($permalink->malformed) {
            return $this->redirect($this->route($this->fallbackRoute, $locale), $request, 'invalid', []);
        }

        $destination = null === $permalink->continueTo ? null : $context->url($permalink->continueTo);

        // Link previews and prefetchers that only ask for headers change nothing;
        // a request without a session has no buyer to hand anything to.
        if ([] === $permalink->items || $request->isMethod('HEAD') || !$request->hasSession()) {
            return $this->redirect($destination ?? $this->route($this->fallbackRoute, $locale), $request, null, $permalink->passThrough);
        }

        $session = $request->getSession();
        if ($this->handoff->wasHandedOver($session, self::SESSION_KEY, $permalink->fingerprint())) {
            return $this->redirect($destination ?? $this->route($this->cartRoute, $locale), $request, null, $permalink->passThrough);
        }

        $lines = [];
        $unavailable = false;
        foreach ($permalink->items as [$identifier, $quantity]) {
            $variant = $this->resolver->resolve($identifier, $context->channel);
            if (null === $variant) {
                $unavailable = true;
                continue;
            }
            $lines[] = new CartHandoffLine($variant, $quantity);
        }

        if ([] === $lines) {
            return $this->redirect($destination ?? $this->route($this->fallbackRoute, $locale), $request, 'unavailable', $permalink->passThrough);
        }

        $result = $this->handoff->handOver($lines);
        if ($result->isRefused()) {
            return $this->redirect($destination ?? $this->route($this->fallbackRoute, $locale), $request, 'blocked', $permalink->passThrough);
        }

        $this->handoff->remember($session, self::SESSION_KEY, $permalink->fingerprint());

        $notice = $unavailable ? 'partial' : ($permalink->capped ? 'capped' : 'added');

        return $this->redirect($destination ?? $this->route($this->cartRoute, $result->cart?->getLocaleCode() ?? $locale), $request, $notice, $permalink->passThrough);
    }

    private function route(string $route, string $locale): string
    {
        return $this->urlGenerator->generate($route, ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * @param list<array{string, string}> $passThrough
     */
    private function redirect(string $location, Request $request, ?string $notice, array $passThrough): Response
    {
        if (null !== $notice && $request->hasSession()) {
            $this->handoff->flash($request->getSession(), 'added' === $notice ? 'success' : 'info', 'koh_ucp.permalink.'.$notice);
        }

        if ([] !== $passThrough) {
            $query = implode('&', array_map(
                static fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]),
                $passThrough,
            ));
            $location .= (str_contains($location, '?') ? '&' : '?').$query;
        }

        return $this->handoff->redirect($location, Response::HTTP_SEE_OTHER);
    }
}
