<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Service;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusUcp\Exception\InvalidCheckoutRequestException;
use Koh\SyliusUcp\Service\CheckoutSessionCreator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\TokenAssigner\OrderTokenAssignerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;

final class CheckoutSessionCreatorTest extends TestCase
{
    private ChannelInterface $channel;

    protected function setUp(): void
    {
        $this->channel = self::createStub(ChannelInterface::class);
    }

    public function testItCreatesAnOpenSessionHoldingTheLineItems(): void
    {
        $cart = $this->cart();
        $cartContext = self::createStub(CartContextInterface::class);
        $cartContext->method('getCart')->willReturn($cart);

        $assigner = $this->createMock(OrderTokenAssignerInterface::class);
        $assigner->expects(self::once())->method('assignTokenValueIfNotSet')->with($cart);

        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('persist')->with($cart);
        $manager->expects(self::once())->method('flush');

        $quantity = $this->createMock(OrderItemQuantityModifierInterface::class);
        $quantity->expects(self::once())->method('modify')->with(self::isInstanceOf(OrderItemInterface::class), 2);
        $modifier = $this->createMock(OrderModifierInterface::class);
        $modifier->expects(self::once())->method('addToOrder')->with($cart, self::isInstanceOf(OrderItemInterface::class));

        $session = $this->creator($cartContext, $assigner, $manager, $quantity, $modifier)
            ->create([['code' => 'SKU-1', 'quantity' => 2]]);

        self::assertSame(['id' => 'TKN123', 'status' => 'open', 'currency' => 'EUR', 'total' => 0, 'line_items' => 1], $session);
    }

    /** AUD-034-F01: a session without items is refused before the cart context is asked. */
    public function testAnEmptySessionIsRefusedWithoutTouchingTheCart(): void
    {
        $cartContext = $this->createMock(CartContextInterface::class);
        $cartContext->expects(self::never())->method('getCart');
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::never())->method('persist');

        $this->expectException(InvalidCheckoutRequestException::class);
        $this->creator($cartContext, null, $manager)->create([]);
    }

    /** An unknown, disabled or out-of-channel variant costs no cart. */
    public function testUnknownDisabledOrForeignItemsPersistNothing(): void
    {
        foreach (['UNKNOWN', 'DISABLED', 'FOREIGN'] as $code) {
            $manager = $this->createMock(ObjectManager::class);
            $manager->expects(self::never())->method('persist');
            $manager->expects(self::never())->method('flush');
            $cartContext = self::createStub(CartContextInterface::class);
            $cartContext->method('getCart')->willReturn($this->cart());

            try {
                $this->creator($cartContext, null, $manager)->create([['code' => $code, 'quantity' => 1]]);
                self::fail($code.' was accepted');
            } catch (InvalidCheckoutRequestException $e) {
                self::assertStringContainsString($code, $e->getMessage());
            }
        }
    }

    public function testQuantityAndLineCountAreBounded(): void
    {
        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::never())->method('persist');
        $creator = $this->creator(self::createStub(CartContextInterface::class), null, $manager);

        foreach ([0, CheckoutSessionCreator::MAX_QUANTITY + 1] as $qty) {
            try {
                $creator->create([['code' => 'SKU-1', 'quantity' => $qty]]);
                self::fail('quantity '.$qty.' was accepted');
            } catch (InvalidCheckoutRequestException) {
            }
        }

        $this->expectException(InvalidCheckoutRequestException::class);
        $creator->create(array_fill(0, CheckoutSessionCreator::MAX_LINE_ITEMS + 1, ['code' => 'SKU-1', 'quantity' => 1]));
    }

    /**
     * The cart context is typed against the base order model. A project that
     * swapped the order class for something outside the Core hierarchy should
     * find out here, not through a type error deep inside Sylius.
     */
    public function testNonCoreOrderFailsWithAClearMessage(): void
    {
        $cartContext = self::createStub(CartContextInterface::class);
        $cartContext->method('getCart')->willReturn(self::createStub(BaseOrderInterface::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('UCP checkout sessions need a Sylius Core order');

        $this->creator($cartContext)->create([['code' => 'SKU-1', 'quantity' => 1]]);
    }

    private function cart(): OrderInterface
    {
        $cart = self::createStub(OrderInterface::class);
        $cart->method('getTokenValue')->willReturn('TKN123');
        $cart->method('getCurrencyCode')->willReturn('EUR');
        $cart->method('getTotal')->willReturn(0);
        $cart->method('getChannel')->willReturn($this->channel);

        return $cart;
    }

    private function creator(
        CartContextInterface $cartContext,
        ?OrderTokenAssignerInterface $assigner = null,
        ?ObjectManager $manager = null,
        ?OrderItemQuantityModifierInterface $quantity = null,
        ?OrderModifierInterface $modifier = null,
    ): CheckoutSessionCreator {
        $variants = self::createStub(ProductVariantRepositoryInterface::class);
        $variants->method('findOneBy')->willReturnCallback(function (array $criteria): ?ProductVariantInterface {
            return match ($criteria['code']) {
                'SKU-1' => $this->variant(true, true, true),
                'DISABLED' => $this->variant(false, true, true),
                'FOREIGN' => $this->variant(true, true, false),
                default => null,
            };
        });
        $items = self::createStub(FactoryInterface::class);
        $items->method('createNew')->willReturnCallback(fn () => self::createStub(OrderItemInterface::class));

        return new CheckoutSessionCreator(
            $cartContext,
            $assigner ?? self::createStub(OrderTokenAssignerInterface::class),
            $manager ?? self::createStub(ObjectManager::class),
            $variants,
            $items,
            $quantity ?? self::createStub(OrderItemQuantityModifierInterface::class),
            $modifier ?? self::createStub(OrderModifierInterface::class),
        );
    }

    private function variant(bool $enabled, bool $productEnabled, bool $inChannel): ProductVariantInterface
    {
        $product = self::createStub(ProductInterface::class);
        $product->method('isEnabled')->willReturn($productEnabled);
        $product->method('hasChannel')->willReturn($inChannel);
        $variant = self::createStub(ProductVariantInterface::class);
        $variant->method('isEnabled')->willReturn($enabled);
        $variant->method('getProduct')->willReturn($product);
        $variant->method('getCode')->willReturn($inChannel ? ($enabled ? 'SKU-1' : 'DISABLED') : 'FOREIGN');

        return $variant;
    }
}
