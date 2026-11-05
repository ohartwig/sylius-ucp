<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Controller;

use Doctrine\Persistence\ObjectManager;
use Doctrine\Persistence\ObjectRepository;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusUcp\Controller\PermalinkController;
use Koh\SyliusUcp\Permalink\PurchasableItemResolver;
use Koh\SyliusUcp\Tests\Support\Catalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\OrderBundle\Controller\AddToCartCommandInterface;
use Sylius\Bundle\OrderBundle\Factory\AddToCartCommandFactory;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\OrderItem;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\SyliusCartEvents;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * `GET /ucp/buy/{items}`: the buyer's browser takes the agent's selection into
 * the buyer's own cart through the shop's add-to-cart event.
 */
#[CoversClass(PermalinkController::class)]
final class PermalinkControllerTest extends TestCase
{
    private Channel $channel;

    /** @var list<Product> */
    private array $products;

    private Order $cart;

    /** @var list<array{string, int}> variant code and quantity of each add-to-cart event */
    private array $added = [];

    private bool $gateRefuses = false;

    private int $flushes = 0;

    private int $refreshes = 0;

    private Session $session;

    protected function setUp(): void
    {
        $this->channel = Catalog::channel();
        $this->products = [
            Catalog::product($this->channel, 'CIG', [['code' => 'CIG-R'], ['code' => 'CIG-C']]),
            Catalog::product($this->channel, 'PIPE', [['code' => 'PIPE-1']]),
        ];
        $this->cart = new Order();
        $this->cart->setLocaleCode('en_GB');
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testItemsGoIntoTheBuyersCartThroughTheAddToCartEvent(): void
    {
        $response = $this->open('/ucp/buy/CIG-R:2,PIPE:1');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://shop.example.com/en_GB/cart', $response->headers->get('Location'));
        self::assertSame([['CIG-R', 2], ['PIPE-1', 1]], $this->added);
        self::assertSame(1, $this->flushes);
        self::assertSame(['koh_ucp.permalink.added'], $this->session->getFlashBag()->get('success'));
        $this->assertNotCachedOrLeaked($response);
    }

    public function testContinueToIsTheDestinationOnTheShopsOrigin(): void
    {
        $response = $this->open('/ucp/buy/CIG-R:1?continue_to=/de_DE/taxons/zigarren&attribution/utm_source=agent&gclid=abc&access_token=secret');

        self::assertSame('https://shop.example.com/de_DE/taxons/zigarren?utm_source=agent&gclid=abc', $response->headers->get('Location'));
        self::assertSame([['CIG-R', 1]], $this->added);
    }

    public function testAnUnsafeContinueToFallsBackToTheCart(): void
    {
        $response = $this->open('/ucp/buy/CIG-R:1?continue_to=//evil.example/');

        self::assertSame('https://shop.example.com/en_GB/cart', $response->headers->get('Location'));
    }

    /** Age confirmation and the like: nothing is added, the buyer is told and lands on the shop. */
    public function testARefusingShopGateAddsNothing(): void
    {
        $this->gateRefuses = true;
        $this->setId($this->cart, 7);

        $response = $this->open('/ucp/buy/CIG-R:1');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://shop.example.com/de_DE/', $response->headers->get('Location'));
        self::assertSame(0, $this->flushes);
        self::assertSame(1, $this->refreshes);
        self::assertSame(['koh_ucp.permalink.blocked'], $this->session->getFlashBag()->get('info'));
        // The same link works once the gate is satisfied.
        $this->gateRefuses = false;
        $this->open('/ucp/buy/CIG-R:1');
        self::assertSame([['CIG-R', 1]], $this->added);
    }

    public function testANewCartIsNotRefreshedWhenAGateRefuses(): void
    {
        $this->gateRefuses = true;

        $this->open('/ucp/buy/CIG-R:1');

        self::assertSame(0, $this->refreshes);
    }

    public function testOpeningTheSameLinkTwiceAddsOnce(): void
    {
        $this->open('/ucp/buy/CIG-R:1');
        $second = $this->open('/ucp/buy/CIG-R:1');
        $other = $this->open('/ucp/buy/CIG-R:2');

        self::assertSame([['CIG-R', 1], ['CIG-R', 2]], $this->added);
        self::assertSame('https://shop.example.com/de_DE/cart', $second->headers->get('Location'));
        self::assertSame(2, $this->flushes);
    }

    public function testTheReplayMemoryIsBounded(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->open('/ucp/buy/CIG-R:'.$i);
        }

        self::assertCount(20, (array) $this->session->get(PermalinkController::SESSION_KEY));
    }

    public function testUnknownItemsAreSkippedAndSaid(): void
    {
        $response = $this->open('/ucp/buy/NOPE:1,PIPE-1:1');

        self::assertSame([['PIPE-1', 1]], $this->added);
        self::assertSame('https://shop.example.com/en_GB/cart', $response->headers->get('Location'));
        self::assertSame(['koh_ucp.permalink.partial'], $this->session->getFlashBag()->get('info'));
    }

    public function testOnlyUnknownItemsLandOnTheShop(): void
    {
        $response = $this->open('/ucp/buy/NOPE:1,CIG:1?continue_to=/de_DE/');

        self::assertSame([], $this->added);
        self::assertSame('https://shop.example.com/de_DE/', $response->headers->get('Location'));
        self::assertSame(['koh_ucp.permalink.unavailable'], $this->session->getFlashBag()->get('info'));
    }

    public function testCappedQuantitiesAreSaid(): void
    {
        $this->open('/ucp/buy/PIPE-1:500');

        self::assertSame([['PIPE-1', 99]], $this->added);
        self::assertSame(['koh_ucp.permalink.capped'], $this->session->getFlashBag()->get('info'));
    }

    public function testAMalformedLinkChangesNothing(): void
    {
        $response = $this->open('/ucp/buy/CIG-R:0?continue_to=/x');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('https://shop.example.com/de_DE/', $response->headers->get('Location'));
        self::assertSame([], $this->added);
        self::assertSame(['koh_ucp.permalink.invalid'], $this->session->getFlashBag()->get('info'));
        $this->assertNotCachedOrLeaked($response);
    }

    public function testWithoutItemsOnlyTheDestinationIsResolved(): void
    {
        $response = $this->open('/ucp/buy?continue_to=/de_DE/taxons/pfeifen&utm_medium=sms');

        self::assertSame('https://shop.example.com/de_DE/taxons/pfeifen?utm_medium=sms', $response->headers->get('Location'));
        self::assertSame([], $this->added);
        self::assertSame([], $this->session->getFlashBag()->peekAll());
        self::assertSame('https://shop.example.com/de_DE/', $this->open('/ucp/buy/')->headers->get('Location'));
    }

    /** Link previews ask with HEAD; they must not fill anybody's cart. */
    public function testAHeadRequestChangesNothing(): void
    {
        $response = $this->open('/ucp/buy/CIG-R:1', 'HEAD');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame([], $this->added);
    }

    public function testWithoutASessionNothingIsAdded(): void
    {
        $request = Request::create('https://shop.example.com/ucp/buy/CIG-R:1');

        $response = ($this->controller())($request, 'CIG-R:1');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame([], $this->added);
    }

    public function testASwitchedOffPermalinkIs404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        ($this->controller(enabled: false))(Request::create('/ucp/buy/CIG-R:1'), 'CIG-R:1');
    }

    public function testACartOutsideSyliusCoreIsAConfigurationError(): void
    {
        $cartContext = $this->createStub(CartContextInterface::class);
        $cartContext->method('getCart')->willReturn($this->createStub(BaseOrderInterface::class));

        $this->expectException(\LogicException::class);

        $this->open('/ucp/buy/CIG-R:1', cartContext: $cartContext);
    }

    private function assertNotCachedOrLeaked(Response $response): void
    {
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
    }

    private function open(string $path, string $method = 'GET', ?CartContextInterface $cartContext = null): Response
    {
        $request = Request::create('https://shop.example.com'.$path, $method);
        $request->setSession($this->session);
        $items = (string) preg_replace('#^/ucp/buy/?#', '', explode('?', $path, 2)[0]);

        return ($this->controller(cartContext: $cartContext))($request, $items);
    }

    private function controller(bool $enabled = true, ?CartContextInterface $cartContext = null): PermalinkController
    {
        $factory = $this->createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(Catalog::context($this->channel));

        $variants = $this->createStub(ProductVariantRepositoryInterface::class);
        $variants->method('findOneBy')->willReturnCallback(function (array $criteria): ?ProductVariantInterface {
            foreach ($this->products as $product) {
                foreach ($product->getVariants() as $variant) {
                    if ($variant->getCode() === $criteria['code'] && $variant instanceof ProductVariantInterface) {
                        return $variant;
                    }
                }
            }

            return null;
        });
        $productRepository = $this->createStub(ObjectRepository::class);
        $productRepository->method('findOneBy')->willReturnCallback(fn (array $criteria): ?ProductInterface => array_values(array_filter($this->products, static fn (Product $p): bool => $p->getCode() === $criteria['code']))[0] ?? null);

        if (null === $cartContext) {
            $cartContext = $this->createStub(CartContextInterface::class);
            $cartContext->method('getCart')->willReturn($this->cart);
        }

        $itemFactory = $this->createStub(FactoryInterface::class);
        $itemFactory->method('createNew')->willReturnCallback(static fn (): OrderItem => new OrderItem());

        $quantity = $this->createStub(OrderItemQuantityModifierInterface::class);
        $quantity->method('modify')->willReturnCallback(static function (OrderItem $item, int $quantity): void {
            $reflection = new \ReflectionProperty(OrderItem::class, 'quantity');
            $reflection->setValue($item, $quantity);
        });

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SyliusCartEvents::CART_ITEM_ADD, function (GenericEvent $event): void {
            if ($this->gateRefuses) {
                throw new \RuntimeException('age not confirmed');
            }
            $command = $event->getSubject();
            self::assertInstanceOf(AddToCartCommandInterface::class, $command);
            self::assertSame($this->cart, $command->getCart());
            $this->added[] = [(string) $command->getCartItem()->getVariant()?->getCode(), $command->getCartItem()->getQuantity()];
        });

        $manager = $this->createStub(ObjectManager::class);
        $manager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });
        $manager->method('refresh')->willReturnCallback(function (): void {
            ++$this->refreshes;
        });

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters, int $type): string => match ($route) {
            'sylius_shop_cart_summary' => 'https://shop.example.com/'.$parameters['_locale'].'/cart',
            default => 'https://shop.example.com/'.$parameters['_locale'].'/',
        });

        return new PermalinkController(
            $factory,
            new PurchasableItemResolver($variants, $productRepository),
            $cartContext,
            $itemFactory,
            $quantity,
            new AddToCartCommandFactory(),
            $dispatcher,
            $manager,
            $router,
            $enabled,
        );
    }

    private function setId(Order $order, int $id): void
    {
        (new \ReflectionProperty(Order::class, 'id'))->setValue($order, $id);
    }
}
