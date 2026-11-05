<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Catalog;

/**
 * Turns a mapped product into the `get_product` detail product
 * (catalog_lookup.json#/$defs/detail_product, spec "Get Product").
 *
 * - A variant id makes that variant the featured one, first in `variants`;
 *   its options are the effective selections and `selected` in the request is
 *   ignored.
 * - Otherwise the request's `selected` options narrow the variants. When no
 *   variant matches all of them, selections are dropped from the end of
 *   `preferences` first (names not listed there go before any listed one,
 *   last-requested first) until one does: the spec's relaxation.
 * - Without selections, the featured variant is the first available one and
 *   its options are the effective selections.
 *
 * `variants` then holds the featured variant and the others matching the
 * effective selections; `selected` is set whenever the product has options;
 * every option value carries `exists` and `available` relative to the
 * selections of the other options.
 */
final class ProductDetail
{
    /**
     * @param array<string, mixed>                                  $product  UcpProductMapper output
     * @param list<array{name: string, id?: string, label: string}> $selected
     * @param list<string>                                          $preferences
     *
     * @return array<string, mixed>
     */
    public static function build(array $product, ?string $variantId, array $selected = [], array $preferences = []): array
    {
        /** @var list<array<string, mixed>> $variants */
        $variants = $product['variants'];
        /** @var list<array{name: string, values: list<array{id: string, label: string}>}> $options */
        $options = $product['options'] ?? [];

        $featured = null;
        if (null !== $variantId) {
            foreach ($variants as $variant) {
                if ($variant['id'] === $variantId) {
                    $featured = $variant;
                    break;
                }
            }
        }

        if ([] === $options) {
            // No option axes: nothing to select, the featured variant first.
            if (null !== $featured) {
                $product['variants'] = [$featured, ...array_values(array_filter($variants, static fn (array $v): bool => $v['id'] !== $featured['id']))];
            }

            return $product;
        }

        if (null !== $featured) {
            $effective = self::selectionsOf($featured);
        } else {
            $effective = self::relax($variants, self::normalise($selected, $options), $preferences);
            $matching = array_values(array_filter($variants, static fn (array $v): bool => self::matches($v, $effective)));
            $featured = self::firstAvailable($matching) ?? $matching[0] ?? $variants[0];
            if ([] === $selected) {
                $effective = self::selectionsOf($featured);
            }
        }

        $rest = array_values(array_filter(
            $variants,
            static fn (array $v): bool => $v['id'] !== $featured['id'] && self::matches($v, $effective),
        ));
        $product['variants'] = [$featured, ...$rest];
        $product['selected'] = array_values($effective);
        $product['options'] = self::withSignals($options, $variants, $effective);

        return $product;
    }

    /**
     * Request selections resolved against the product's options, keyed by
     * option name: by value id when given, else by label. Unknown names and
     * values are dropped; they cannot match any variant.
     *
     * @param list<array{name: string, id?: string, label: string}>                     $selected
     * @param list<array{name: string, values: list<array{id: string, label: string}>}> $options
     *
     * @return array<string, array{name: string, id: string, label: string}>
     */
    private static function normalise(array $selected, array $options): array
    {
        $byName = [];
        foreach ($options as $option) {
            $byName[$option['name']] = $option['values'];
        }

        $resolved = [];
        foreach ($selected as $selection) {
            foreach ($byName[$selection['name']] ?? [] as $value) {
                if ((isset($selection['id']) && $selection['id'] === $value['id']) || (!isset($selection['id']) && $selection['label'] === $value['label'])) {
                    $resolved[$selection['name']] = ['name' => $selection['name'], 'id' => $value['id'], 'label' => $value['label']];
                    break;
                }
            }
        }

        return $resolved;
    }

    /**
     * @param list<array<string, mixed>>                                   $variants
     * @param array<string, array{name: string, id: string, label: string}> $selections
     * @param list<string>                                                 $preferences
     *
     * @return array<string, array{name: string, id: string, label: string}>
     */
    private static function relax(array $variants, array $selections, array $preferences): array
    {
        // Drop order: names without a preference (last requested first), then
        // the preferences from the end of the list.
        $unranked = array_reverse(array_values(array_diff(array_keys($selections), $preferences)));
        $dropOrder = [...$unranked, ...array_reverse(array_values(array_intersect($preferences, array_keys($selections))))];

        while ([] !== $selections) {
            foreach ($variants as $variant) {
                if (self::matches($variant, $selections)) {
                    return $selections;
                }
            }
            // Every selected name is in the drop order, so it runs dry only
            // when the selections do.
            unset($selections[array_shift($dropOrder) ?? array_key_first($selections)]);
        }

        return $selections;
    }

    /**
     * @param array<string, mixed> $variant
     *
     * @return array<string, array{name: string, id: string, label: string}>
     */
    private static function selectionsOf(array $variant): array
    {
        $selections = [];
        /** @var list<array{name: string, id: string, label: string}> $own */
        $own = $variant['options'] ?? [];
        foreach ($own as $option) {
            $selections[$option['name']] = $option;
        }

        return $selections;
    }

    /**
     * @param array<string, mixed>                                         $variant
     * @param array<string, array{name: string, id: string, label: string}> $selections
     */
    private static function matches(array $variant, array $selections): bool
    {
        $own = self::selectionsOf($variant);
        foreach ($selections as $name => $selection) {
            if (($own[$name]['id'] ?? null) !== $selection['id']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $variants
     *
     * @return array<string, mixed>|null
     */
    private static function firstAvailable(array $variants): ?array
    {
        foreach ($variants as $variant) {
            if (true === ($variant['availability']['available'] ?? false)) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * @param list<array{name: string, values: list<array{id: string, label: string}>}> $options
     * @param list<array<string, mixed>>                                                $variants
     * @param array<string, array{name: string, id: string, label: string}>              $effective
     *
     * @return list<array{name: string, values: list<array{id: string, label: string, available: bool, exists: bool}>}>
     */
    private static function withSignals(array $options, array $variants, array $effective): array
    {
        $result = [];
        foreach ($options as $option) {
            $others = $effective;
            unset($others[$option['name']]);

            $values = [];
            foreach ($option['values'] as $value) {
                $candidate = $others + [$option['name'] => ['name' => $option['name'], 'id' => $value['id'], 'label' => $value['label']]];
                $exists = false;
                $available = false;
                foreach ($variants as $variant) {
                    if (self::matches($variant, $candidate)) {
                        $exists = true;
                        $available = $available || true === ($variant['availability']['available'] ?? false);
                    }
                }
                $values[] = $value + ['available' => $available, 'exists' => $exists];
            }
            $result[] = ['name' => $option['name'], 'values' => $values];
        }

        return $result;
    }
}
