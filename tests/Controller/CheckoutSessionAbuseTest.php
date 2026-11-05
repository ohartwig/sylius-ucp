<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Controller;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusUcp\Controller\CheckoutSessionController;
use Koh\SyliusUcp\Service\CheckoutSessionCreator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\TokenAssigner\OrderTokenAssignerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * AUD-034-F01 reproduction and proof of fix: every anonymous body-less
 * `POST /ucp/v1/checkout-sessions` used to persist an empty, tokenised cart,
 * with no limit and no switch. The persisting ObjectManager is counted; a
 * persist() is one more `sylius_order` row.
 */
final class CheckoutSessionAbuseTest extends TestCase
{
    private int $persisted = 0;

    /** Reproduction: five body-less POSTs. Fixed: five 400s, zero carts. */
    public function testBodylessPostsAreRefusedAndPersistNothing(): void
    {
        $controller = $this->controller(limit: 100);

        for ($i = 0; $i < 5; ++$i) {
            $response = $controller->createSession(Request::create('/ucp/v1/checkout-sessions', 'POST'));
            self::assertSame(400, $response->getStatusCode());
        }

        self::assertSame(0, $this->persisted);
    }

    /** A body without line items is just as empty. */
    public function testBodiesWithoutLineItemsAreRefused(): void
    {
        $controller = $this->controller(limit: 100);

        foreach (['{}', '{"line_items": []}', 'not json', '{"line_items": [{"quantity": 1}]}', '{"line_items": {"a": 1}}'] as $body) {
            $response = $controller->createSession($this->post($body));
            self::assertSame(400, $response->getStatusCode(), $body);
        }

        self::assertSame(0, $this->persisted);
    }

    /** Past the limit the route answers 429 with Retry-After, and persists nothing more. */
    public function testTheRequestPastTheLimitGets429WithRetryAfter(): void
    {
        $controller = $this->controller(limit: 5);
        $valid = '{"line_items": [{"item": {"id": "SKU-1"}, "quantity": 1}]}';

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(201, $controller->createSession($this->post($valid))->getStatusCode());
        }

        $response = $controller->createSession($this->post($valid));

        self::assertSame(429, $response->getStatusCode());
        self::assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        self::assertSame(5, $this->persisted);
    }

    /** With `koh_sylius_ucp.checkout` off (the default) the route answers 404. */
    public function testTheRouteAnswers404WhenTheSwitchIsOff(): void
    {
        $controller = new CheckoutSessionController(self::createStub(ProductRepositoryInterface::class), $this->creator());

        try {
            $controller->createSession($this->post('{"line_items": [{"item": {"id": "SKU-1"}, "quantity": 1}]}'));
            self::fail('the session route answered with the switch off');
        } catch (NotFoundHttpException $e) {
            self::assertSame(404, $e->getStatusCode());
        }

        self::assertSame(0, $this->persisted);
    }

    /** The live proof, in-process: a hundred body-less POSTs leave the cart count unchanged. */
    public function testAHundredRequestLoopLeavesTheCartCountUnchanged(): void
    {
        $controller = $this->controller(limit: 10);
        $statuses = [];

        for ($i = 0; $i < 100; ++$i) {
            $statuses[] = $controller->createSession(Request::create('/ucp/v1/checkout-sessions', 'POST'))->getStatusCode();
        }

        self::assertSame(0, $this->persisted);
        self::assertSame([400, 429], array_values(array_unique($statuses)));
    }

    public function testEnablingCheckoutWithoutALimiterFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);

        new CheckoutSessionController(self::createStub(ProductRepositoryInterface::class), $this->creator(), checkoutEnabled: true);
    }

    private function post(string $body): Request
    {
        return Request::create('/ucp/v1/checkout-sessions', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
    }

    private function controller(int $limit): CheckoutSessionController
    {
        $limiter = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );

        return new CheckoutSessionController(
            self::createStub(ProductRepositoryInterface::class),
            $this->creator(),
            checkoutEnabled: true,
            checkoutLimiter: $limiter,
        );
    }

    private function creator(): CheckoutSessionCreator
    {
        $channel = self::createStub(ChannelInterface::class);
        $cartContext = self::createStub(CartContextInterface::class);
        // What Sylius does for a request without a session cookie: a fresh cart per call.
        $cartContext->method('getCart')->willReturnCallback(function () use ($channel): OrderInterface {
            $cart = self::createStub(OrderInterface::class);
            $cart->method('getTokenValue')->willReturn(bin2hex(random_bytes(8)));
            $cart->method('getChannel')->willReturn($channel);

            return $cart;
        });

        $manager = self::createStub(ObjectManager::class);
        $manager->method('persist')->willReturnCallback(function (): void { ++$this->persisted; });

        $product = self::createStub(ProductInterface::class);
        $product->method('isEnabled')->willReturn(true);
        $product->method('hasChannel')->willReturn(true);
        $variant = self::createStub(ProductVariantInterface::class);
        $variant->method('isEnabled')->willReturn(true);
        $variant->method('getProduct')->willReturn($product);
        $variants = self::createStub(ProductVariantRepositoryInterface::class);
        $variants->method('findOneBy')->willReturnCallback(fn (array $c) => 'SKU-1' === $c['code'] ? $variant : null);

        $items = self::createStub(FactoryInterface::class);
        $items->method('createNew')->willReturnCallback(fn () => self::createStub(OrderItemInterface::class));

        return new CheckoutSessionCreator(
            $cartContext,
            self::createStub(OrderTokenAssignerInterface::class),
            $manager,
            $variants,
            $items,
            self::createStub(OrderItemQuantityModifierInterface::class),
            self::createStub(OrderModifierInterface::class),
        );
    }
}
