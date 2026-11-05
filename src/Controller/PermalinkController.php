<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusUcp\Permalink\PermalinkRequest;
use Koh\SyliusUcp\Permalink\PurchasableItemResolver;
use Sylius\Bundle\OrderBundle\Factory\AddToCartCommandFactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\SyliusCartEvents;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
 */
final class PermalinkController
{
    public const CAPABILITY = 'dev.ucp.shopping.permalink';

    public const PATH = '/ucp/buy';

    public const SESSION_KEY = 'koh_ucp.permalinks';

    /**
     * @param FactoryInterface<OrderItemInterface> $orderItemFactory
     */
    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly PurchasableItemResolver $resolver,
        private readonly CartContextInterface $cartContext,
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly AddToCartCommandFactoryInterface $addToCartCommandFactory,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly ObjectManager $orderManager,
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
        $done = $session->get(self::SESSION_KEY, []);
        $done = \is_array($done) ? $done : [];
        if (\in_array($permalink->fingerprint(), $done, true)) {
            return $this->redirect($destination ?? $this->route($this->cartRoute, $locale), $request, null, $permalink->passThrough);
        }

        $variants = [];
        $unavailable = false;
        foreach ($permalink->items as [$identifier, $quantity]) {
            $variant = $this->resolver->resolve($identifier, $context->channel);
            if (null === $variant) {
                $unavailable = true;
                continue;
            }
            $variants[] = [$variant, $quantity];
        }

        if ([] === $variants) {
            return $this->redirect($destination ?? $this->route($this->fallbackRoute, $locale), $request, 'unavailable', $permalink->passThrough);
        }

        $cart = $this->cartContext->getCart();
        if (!$cart instanceof OrderInterface) {
            throw new \LogicException(\sprintf('The UCP permalink needs a Sylius Core order, got %s.', $cart::class));
        }

        if (!$this->addAll($cart, $variants)) {
            return $this->redirect($destination ?? $this->route($this->fallbackRoute, $locale), $request, 'blocked', $permalink->passThrough);
        }

        $this->orderManager->persist($cart);
        $this->orderManager->flush();

        $done[] = $permalink->fingerprint();
        $session->set(self::SESSION_KEY, \array_slice($done, -20));

        $notice = $unavailable ? 'partial' : ($permalink->capped ? 'capped' : 'added');

        return $this->redirect($destination ?? $this->route($this->cartRoute, $cart->getLocaleCode() ?? $locale), $request, $notice, $permalink->passThrough);
    }

    /**
     * @param list<array{ProductVariantInterface, int}> $variants
     */
    private function addAll(OrderInterface $cart, array $variants): bool
    {
        try {
            foreach ($variants as [$variant, $quantity]) {
                $item = $this->orderItemFactory->createNew();
                $item->setVariant($variant);
                $this->quantityModifier->modify($item, $quantity);

                $this->eventDispatcher->dispatch(
                    new GenericEvent($this->addToCartCommandFactory->createWithCartAndCartItem($cart, $item)),
                    SyliusCartEvents::CART_ITEM_ADD,
                );
            }
        } catch (\RuntimeException) {
            // A gate said no. Undo what earlier items changed in memory, so a
            // later flush elsewhere cannot persist half a handoff.
            if (null !== $cart->getId()) {
                $this->orderManager->refresh($cart);
            }

            return false;
        }

        return true;
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
            $this->flash($request->getSession(), 'added' === $notice ? 'success' : 'info', 'koh_ucp.permalink.'.$notice);
        }

        if ([] !== $passThrough) {
            $query = implode('&', array_map(
                static fn (array $pair): string => rawurlencode($pair[0]).'='.rawurlencode($pair[1]),
                $passThrough,
            ));
            $location .= (str_contains($location, '?') ? '&' : '?').$query;
        }

        $response = new RedirectResponse($location, Response::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function flash(SessionInterface $session, string $type, string $message): void
    {
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
