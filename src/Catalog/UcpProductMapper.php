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
 * (null) rather than shipped in a shape the schema rejects. A price filter
 * drops the variants outside its range the same way.
 *
 * Ids are Sylius codes: the product code for the product, the variant code for
 * the variant — the value the permalink takes as its item id. Categories are
 * the product's taxon codes, the values `filters.categories` matches. Options
 * are the product's option axes with the values its variants use; each
 * variant names its own.
 */
final class UcpProductMapper
{
    /** Descriptions are summaries for an agent, not the full product page. */
    private const MAX_DESCRIPTION_LENGTH = 500;

    /** @return array<string, mixed>|null */
    public function map(ProductInterface $product, AgentContext $context, ?string $localeCode = null, ?CatalogFilter $filter = null): ?array
    {
        $currency = $context->channel->getBaseCurrency()?->getCode();
        if (null === $currency || '' === $currency) {
            return null;
        }
        $locale = $localeCode ?? $context->localeCode;

        $translation = $product->getTranslation($locale);
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
            if (null === $amount || $amount < 0 || (null !== $filter && !$filter->acceptsPrice($amount))) {
                continue;
            }

            $amounts[] = $amount;
            $mapped = [
                'id' => (string) $variant->getCode(),
                'sku' => (string) $variant->getCode(),
                'title' => $this->text($variant->getTranslation($locale)->getName()) ?? $title,
                'description' => $description,
                'price' => ['amount' => $amount, 'currency' => $currency],
                'availability' => $variant->isInStock()
                    ? ['available' => true, 'status' => 'in_stock']
                    : ['available' => false, 'status' => 'out_of_stock'],
            ];
            $options = $this->variantOptions($variant, $locale);
            if ([] !== $options) {
                $mapped['options'] = $options;
            }
            $variants[] = $mapped;
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
            $mapped['url'] = $context->url($locale.'/products/'.rawurlencode($slug));
        }

        $categories = $this->categories($product);
        if ([] !== $categories) {
            $mapped['categories'] = $categories;
        }

        $options = $this->productOptions($variants);
        if ([] !== $options) {
            $mapped['options'] = $options;
        }

        return $mapped;
    }

    /** @return list<array{value: string, taxonomy: string}> */
    private function categories(ProductInterface $product): array
    {
        $categories = [];
        foreach ($product->getTaxons() as $taxon) {
            $code = $this->text($taxon->getCode());
            if (null !== $code) {
                $categories[$code] = ['value' => $code, 'taxonomy' => 'merchant'];
            }
        }

        return array_values($categories);
    }

    /** @return list<array{name: string, id: string, label: string}> */
    private function variantOptions(ProductVariantInterface $variant, string $locale): array
    {
        $options = [];
        foreach ($variant->getOptionValues() as $value) {
            $option = $value->getOption();
            $name = null === $option ? null : ($this->text($option->getTranslation($locale)->getName()) ?? $this->text($option->getCode()));
            $label = $this->text($value->getTranslation($locale)->getValue()) ?? $this->text($value->getCode());
            if (null !== $name && null !== $label) {
                $options[] = ['name' => $name, 'id' => (string) $value->getCode(), 'label' => $label];
            }
        }

        return $options;
    }

    /**
     * The option axes as the mapped variants use them, values in first-seen
     * order.
     *
     * @param list<array<string, mixed>> $variants
     *
     * @return list<array{name: string, values: list<array{id: string, label: string}>}>
     */
    private function productOptions(array $variants): array
    {
        $options = [];
        foreach ($variants as $variant) {
            /** @var list<array{name: string, id: string, label: string}> $selected */
            $selected = $variant['options'] ?? [];
            foreach ($selected as $option) {
                $options[$option['name']][$option['id']] = ['id' => $option['id'], 'label' => $option['label']];
            }
        }

        $list = [];
        foreach ($options as $name => $values) {
            $list[] = ['name' => $name, 'values' => array_values($values)];
        }

        return $list;
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
