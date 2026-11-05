<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Catalog;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;

/**
 * Maps a Sylius product to the UCP `Product` type (schemas/shopping/types/product.json).
 *
 * The type requires a price on every variant and at least one variant per
 * product. A variant without a price in the channel cannot be bought there, so
 * it is left out; a product left without variants is left out as a whole
 * (null) rather than shipped in a shape the schema rejects.
 *
 * Variant ids are Sylius variant codes: the value checkout takes as
 * `line_items[].item.id`.
 */
final class UcpProductMapper
{
    /** Descriptions are summaries for an agent, not the full product page. */
    private const MAX_DESCRIPTION_LENGTH = 500;

    /** @return array<string, mixed>|null */
    public function map(ProductInterface $product, AgentContext $context): ?array
    {
        $currency = $context->channel->getBaseCurrency()?->getCode();
        if (null === $currency || '' === $currency) {
            return null;
        }

        $translation = $product->getTranslation($context->localeCode);
        $code = (string) $product->getCode();
        $title = $this->text($translation->getName()) ?? $code;
        $description = ['plain' => $this->plain($translation->getShortDescription())
            ?? $this->plain($translation->getDescription())
            ?? $title];

        $variants = [];
        $amounts = [];
        foreach ($product->getEnabledVariants() as $variant) {
            if (!$variant instanceof ProductVariantInterface) {
                continue;
            }
            $amount = $variant->getChannelPricingForChannel($context->channel)?->getPrice();
            if (null === $amount || $amount < 0) {
                continue;
            }

            $amounts[] = $amount;
            $variants[] = [
                'id' => (string) $variant->getCode(),
                'sku' => (string) $variant->getCode(),
                'title' => $this->text($variant->getTranslation($context->localeCode)->getName()) ?? $title,
                'description' => $description,
                'price' => ['amount' => $amount, 'currency' => $currency],
                'availability' => $variant->isInStock()
                    ? ['available' => true, 'status' => 'in_stock']
                    : ['available' => false, 'status' => 'out_of_stock'],
            ];
        }

        if ([] === $amounts) {
            return null;
        }

        $mapped = [
            'id' => $code,
            'title' => $title,
            'description' => $description,
            'price_range' => [
                'min' => ['amount' => min($amounts), 'currency' => $currency],
                'max' => ['amount' => max($amounts), 'currency' => $currency],
            ],
            'variants' => $variants,
        ];

        $slug = $this->text($translation->getSlug());
        if (null !== $slug) {
            $mapped['handle'] = $slug;
            $mapped['url'] = $context->url($context->localeCode.'/products/'.rawurlencode($slug));
        }

        return $mapped;
    }

    private function text(?string $text): ?string
    {
        $text = trim((string) $text);

        return '' === $text ? null : $text;
    }

    private function plain(?string $text): ?string
    {
        // Stripped first and checked after: `<p> </p>` is not empty on the way
        // in and is on the way out.
        $text = $this->text((string) preg_replace('/\s+/', ' ', strip_tags((string) $text)));
        if (null === $text) {
            return null;
        }

        return mb_strlen($text) > self::MAX_DESCRIPTION_LENGTH
            ? mb_substr($text, 0, self::MAX_DESCRIPTION_LENGTH - 1).'…'
            : $text;
    }
}
