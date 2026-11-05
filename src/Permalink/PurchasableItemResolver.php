<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Permalink;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Doctrine\Persistence\ObjectRepository;

/**
 * Resolves a permalink item identifier to the variant it buys (spec
 * "Purchasable Item Resolution").
 *
 * A variant code is the identifier catalog operations hand out. A product code
 * is accepted too when it is unambiguous: the product has exactly one enabled
 * variant. Either way the variant and its product must be enabled and the
 * product sold in this channel; anything else resolves to nothing, and the
 * buyer is told at the destination.
 */
final class PurchasableItemResolver
{
    /**
     * @param ProductVariantRepositoryInterface<ProductVariantInterface> $variants
     * @param ObjectRepository<ProductInterface>                         $products
     */
    public function __construct(
        private readonly ProductVariantRepositoryInterface $variants,
        private readonly ObjectRepository $products,
    ) {
    }

    public function resolve(string $identifier, ChannelInterface $channel): ?ProductVariantInterface
    {
        $variant = $this->variants->findOneBy(['code' => $identifier]);
        if (!$variant instanceof ProductVariantInterface) {
            $product = $this->products->findOneBy(['code' => $identifier]);
            $enabled = $product instanceof ProductInterface ? $product->getEnabledVariants() : null;
            $variant = null !== $enabled && 1 === $enabled->count() ? $enabled->first() : null;
            if (!$variant instanceof ProductVariantInterface) {
                return null;
            }
        }

        $product = $variant->getProduct();
        if (!$variant->isEnabled() || !$product instanceof ProductInterface || !$product->isEnabled() || !$product->hasChannel($channel)) {
            return null;
        }

        return $variant;
    }
}
