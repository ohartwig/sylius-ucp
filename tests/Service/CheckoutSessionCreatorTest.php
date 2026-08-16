<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Service;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusUcp\Service\CheckoutSessionCreator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\TokenAssigner\OrderTokenAssignerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;

final class CheckoutSessionCreatorTest extends TestCase
{
    public function testItCreatesAnOpenSessionFromTheCart(): void
    {
        $cart = self::createStub(OrderInterface::class);
        $cart->method('getTokenValue')->willReturn('TKN123');
        $cart->method('getCurrencyCode')->willReturn('EUR');
        $cart->method('getTotal')->willReturn(0);

        $cartContext = self::createStub(CartContextInterface::class);
        $cartContext->method('getCart')->willReturn($cart);

        $assigner = $this->createMock(OrderTokenAssignerInterface::class);
        $assigner->expects(self::once())->method('assignTokenValueIfNotSet')->with($cart);

        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('persist')->with($cart);
        $manager->expects(self::once())->method('flush');

        $session = (new CheckoutSessionCreator($cartContext, $assigner, $manager))->create();

        self::assertSame(['id' => 'TKN123', 'status' => 'open', 'currency' => 'EUR', 'total' => 0], $session);
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

        $creator = new CheckoutSessionCreator(
            $cartContext,
            self::createStub(OrderTokenAssignerInterface::class),
            self::createStub(ObjectManager::class),
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('UCP checkout sessions need a Sylius Core order');

        $creator->create();
    }
}
