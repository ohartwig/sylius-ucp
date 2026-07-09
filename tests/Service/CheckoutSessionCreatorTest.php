<?php

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Service;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusUcp\Service\CheckoutSessionCreator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\OrderTokenAssignerInterface;

final class CheckoutSessionCreatorTest extends TestCase
{
    public function testItCreatesAnOpenSessionFromTheCart(): void
    {
        $cart = $this->createMock(OrderInterface::class);
        $cart->method('getTokenValue')->willReturn('TKN123');
        $cart->method('getCurrencyCode')->willReturn('EUR');
        $cart->method('getTotal')->willReturn(0);

        $cartContext = $this->createMock(CartContextInterface::class);
        $cartContext->method('getCart')->willReturn($cart);

        $assigner = $this->createMock(OrderTokenAssignerInterface::class);
        $assigner->expects(self::once())->method('assignTokenValueIfNotAssigned')->with($cart);

        $manager = $this->createMock(ObjectManager::class);
        $manager->expects(self::once())->method('persist')->with($cart);
        $manager->expects(self::once())->method('flush');

        $session = (new CheckoutSessionCreator($cartContext, $assigner, $manager))->create();

        self::assertSame(['id' => 'TKN123', 'status' => 'open', 'currency' => 'EUR', 'total' => 0], $session);
    }
}
