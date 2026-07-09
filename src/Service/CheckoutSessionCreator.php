<?php

declare(strict_types=1);

namespace Koh\SyliusUcp\Service;

use Doctrine\Persistence\ObjectManager;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\OrderTokenAssignerInterface;

/**
 * Creates a UCP checkout-session backed by a real Sylius cart (an order in the
 * "cart" state). Resolves-or-creates the current cart via Sylius' CartContext,
 * assigns a tokenValue and persists it; the tokenValue IS the UCP session id.
 *
 * RUNTIME-VERIFY (needs a running Sylius): in a headless/agent request there is
 * no shop session, so the ShopBasedCartContext must resolve a channel/currency/
 * locale (e.g. via a default channel or a channel passed in the request). If it
 * throws CartNotFoundException, wire an explicit channel/customer context here.
 */
final class CheckoutSessionCreator
{
    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly OrderTokenAssignerInterface $orderTokenAssigner,
        private readonly ObjectManager $orderManager,
    ) {
    }

    /**
     * @return array{id: string, status: string, currency: string|null, total: int}
     */
    public function create(): array
    {
        $cart = $this->cartContext->getCart();

        $this->orderTokenAssigner->assignTokenValueIfNotAssigned($cart);
        $this->orderManager->persist($cart);
        $this->orderManager->flush();

        return [
            'id' => (string) $cart->getTokenValue(),
            'status' => 'open',
            'currency' => method_exists($cart, 'getCurrencyCode') ? $cart->getCurrencyCode() : null,
            'total' => (int) $cart->getTotal(),
        ];
    }
}
