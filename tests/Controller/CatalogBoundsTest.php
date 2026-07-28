<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Controller;

use Doctrine\Persistence\ObjectManager;
use Koh\SyliusUcp\Controller\CheckoutSessionController;
use Koh\SyliusUcp\Service\CheckoutSessionCreator;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Core\TokenAssigner\OrderTokenAssignerInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * /ucp/v1/catalog needs no credentials, and findByName() is a LIKE with no
 * limit of its own. Whatever bounds this route has to come from the route.
 */
final class CatalogBoundsTest extends TestCase
{
    public function testAnEmptySearchTermIsRefusedRatherThanReturningEverything(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        // The point of the guard: the repository must not even be asked.
        $repository->expects(self::never())->method('findByName');

        $response = $this->controller($repository)->catalog(Request::create('/ucp/v1/catalog'));

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAWhitespaceOnlyTermCountsAsEmpty(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->expects(self::never())->method('findByName');

        $response = $this->controller($repository)->catalog(Request::create('/ucp/v1/catalog', 'GET', ['q' => '   ']));

        self::assertSame(400, $response->getStatusCode());
    }

    public function testABroadTermCannotSerialiseTheWholeCatalogue(): void
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('findByName')->willReturn($this->products(1000));

        $response = $this->controller($repository)->catalog(
            Request::create('/ucp/v1/catalog', 'GET', ['q' => 'a']),
        );

        /** @var array{products: list<array<string, mixed>>} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(200, $body['products']);
    }

    /** @return list<ProductInterface> */
    private function products(int $count): array
    {
        $products = [];
        for ($i = 0; $i < $count; ++$i) {
            // A real Product would need a translation locale for getName();
            // this test is about how many of them survive, not about their text.
            $product = $this->createMock(ProductInterface::class);
            $product->method('getCode')->willReturn('SKU-'.$i);
            $product->method('getName')->willReturn('Product '.$i);
            $product->method('getSlug')->willReturn('product-'.$i);
            $products[] = $product;
        }

        return $products;
    }

    /** @param ProductRepositoryInterface<ProductInterface> $repository */
    private function controller(ProductRepositoryInterface $repository): CheckoutSessionController
    {
        // CheckoutSessionCreator is final; the catalog route never touches it,
        // so a real instance over mocked collaborators is enough.
        return new CheckoutSessionController(
            $repository,
            new CheckoutSessionCreator(
                $this->createMock(CartContextInterface::class),
                $this->createMock(OrderTokenAssignerInterface::class),
                $this->createMock(ObjectManager::class),
            ),
        );
    }
}
