<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Service;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusUcp\Exception\InvalidCheckoutRequestException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\TokenAssigner\OrderTokenAssignerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Creates a UCP checkout-session backed by a real Sylius cart (an order in the
 * "cart" state) that carries the requested line items. Resolves-or-creates the
 * current cart via Sylius' CartContext, adds the items, assigns a tokenValue and
 * persists it; the tokenValue IS the UCP session id.
 *
 * Every line item is resolved and checked before the cart is touched, and the
 * cart is persisted only once it holds at least one item (AUD-034-F01): a
 * session without items is refused instead of leaving an empty cart behind.
 */
final class CheckoutSessionCreator
{
    /** Upper bound on line items in one session request. */
    public const MAX_LINE_ITEMS = 50;

    /** Upper bound on the quantity of one line item. */
    public const MAX_QUANTITY = 99;

    /**
     * @param ProductVariantRepositoryInterface<ProductVariantInterface> $variantRepository
     * @param FactoryInterface<OrderItemInterface>                       $orderItemFactory
     */
    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly OrderTokenAssignerInterface $orderTokenAssigner,
        private readonly ObjectManager $orderManager,
        private readonly ProductVariantRepositoryInterface $variantRepository,
        private readonly FactoryInterface $orderItemFactory,
        private readonly OrderItemQuantityModifierInterface $quantityModifier,
        private readonly OrderModifierInterface $orderModifier,
    ) {
    }

    /**
     * @param list<array{code: string, quantity: int}> $lineItems
     *
     * @return array{id: string, status: string, currency: string|null, total: int, line_items: int}
     *
     * @throws InvalidCheckoutRequestException when the request cannot become a non-empty cart
     */
    public function create(array $lineItems): array
    {
        if ([] === $lineItems) {
            throw new InvalidCheckoutRequestException('line_items must not be empty');
        }
        if (\count($lineItems) > self::MAX_LINE_ITEMS) {
            throw new InvalidCheckoutRequestException(\sprintf('at most %d line items', self::MAX_LINE_ITEMS));
        }

        // Resolve everything before the cart context is asked: an unknown or
        // disabled variant must not cost a cart.
        $resolved = [];
        foreach ($lineItems as $line) {
            if ($line['quantity'] < 1 || $line['quantity'] > self::MAX_QUANTITY) {
                throw new InvalidCheckoutRequestException(\sprintf('quantity must be between 1 and %d', self::MAX_QUANTITY));
            }
            $variant = $this->variantRepository->findOneBy(['code' => $line['code']]);
            $product = $variant instanceof ProductVariantInterface ? $variant->getProduct() : null;
            if (!$variant instanceof ProductVariantInterface || !$variant->isEnabled() || null === $product || !$product->isEnabled()) {
                throw new InvalidCheckoutRequestException('unknown item: '.$line['code']);
            }
            $resolved[] = [$variant, $line['quantity']];
        }

        $cart = $this->cartContext->getCart();

        // The cart context is typed against the base order model, but the token
        // assigner and everything downstream need the Core one. In a stock
        // Sylius shop it always is; a project that swapped the order class for
        // something outside the Core hierarchy should find out here rather than
        // through a type error deep inside Sylius.
        if (!$cart instanceof OrderInterface) {
            throw new \LogicException(\sprintf(
                'UCP checkout sessions need a Sylius Core order, got %s.',
                $cart::class,
            ));
        }

        $channel = $cart->getChannel();
        foreach ($resolved as [$variant]) {
            $product = $variant->getProduct();
            if (null === $channel || !$product instanceof \Sylius\Component\Core\Model\ProductInterface || !$product->hasChannel($channel)) {
                throw new InvalidCheckoutRequestException('unknown item: '.(string) $variant->getCode());
            }
        }

        foreach ($resolved as [$variant, $quantity]) {
            $item = $this->orderItemFactory->createNew();
            $item->setVariant($variant);
            $this->quantityModifier->modify($item, $quantity);
            $this->orderModifier->addToOrder($cart, $item);
        }

        $this->orderTokenAssigner->assignTokenValueIfNotSet($cart);
        $this->orderManager->persist($cart);
        $this->orderManager->flush();

        return [
            'id' => (string) $cart->getTokenValue(),
            'status' => 'open',
            'currency' => $cart->getCurrencyCode(),
            'total' => $cart->getTotal(),
            'line_items' => \count($resolved),
        ];
    }
}
