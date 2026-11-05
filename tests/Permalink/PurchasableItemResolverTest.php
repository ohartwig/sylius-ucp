<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Permalink;

use Doctrine\Persistence\ObjectRepository;
use Koh\SyliusUcp\Permalink\PurchasableItemResolver;
use Koh\SyliusUcp\Tests\Support\Catalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;

#[CoversClass(PurchasableItemResolver::class)]
final class PurchasableItemResolverTest extends TestCase
{
    private Channel $channel;

    /** @var list<Product> */
    private array $products;

    protected function setUp(): void
    {
        $this->channel = Catalog::channel();
        $this->products = [
            Catalog::product($this->channel, 'SINGLE', [['code' => 'SINGLE-1']]),
            Catalog::product($this->channel, 'MULTI', [['code' => 'MULTI-1'], ['code' => 'MULTI-2']]),
        ];
    }

    public function testAVariantCodeResolvesToItsVariant(): void
    {
        self::assertSame('MULTI-2', $this->resolver()->resolve('MULTI-2', $this->channel)?->getCode());
    }

    public function testAProductCodeResolvesOnlyWhenUnambiguous(): void
    {
        self::assertSame('SINGLE-1', $this->resolver()->resolve('SINGLE', $this->channel)?->getCode());
        self::assertNull($this->resolver()->resolve('MULTI', $this->channel));
        self::assertNull($this->resolver()->resolve('NOPE', $this->channel));
    }

    public function testDisabledOrForeignItemsDoNotResolve(): void
    {
        $this->products[1]->getVariants()->first()?->setEnabled(false);
        self::assertNull($this->resolver()->resolve('MULTI-1', $this->channel));
        // One enabled variant left: the product code is now unambiguous.
        self::assertSame('MULTI-2', $this->resolver()->resolve('MULTI', $this->channel)?->getCode());

        $this->products[0]->setEnabled(false);
        self::assertNull($this->resolver()->resolve('SINGLE-1', $this->channel));
        self::assertNull($this->resolver()->resolve('SINGLE', $this->channel));

        $other = Catalog::channel();
        $other->setCode('OTHER');
        self::assertNull($this->resolver()->resolve('MULTI-2', $other));
    }

    public function testAnOrphanVariantDoesNotResolve(): void
    {
        $orphan = $this->createStub(ProductVariantInterface::class);
        $orphan->method('isEnabled')->willReturn(true);
        $orphan->method('getProduct')->willReturn(null);
        $variants = $this->createStub(ProductVariantRepositoryInterface::class);
        $variants->method('findOneBy')->willReturn($orphan);

        self::assertNull((new PurchasableItemResolver($variants, $this->createStub(ObjectRepository::class)))->resolve('X', $this->channel));
    }

    private function resolver(): PurchasableItemResolver
    {
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
        $products = $this->createStub(ObjectRepository::class);
        $products->method('findOneBy')->willReturnCallback(function (array $criteria): ?ProductInterface {
            foreach ($this->products as $product) {
                if ($product->getCode() === $criteria['code']) {
                    return $product;
                }
            }

            return null;
        });

        return new PurchasableItemResolver($variants, $products);
    }
}
