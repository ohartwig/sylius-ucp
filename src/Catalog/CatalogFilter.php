<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Catalog;

/**
 * The search filters this shop applies (`types/search_filters.json`):
 * categories (taxon codes, OR) and a price range in minor units of the
 * channel's currency. All set filters combine with AND.
 */
final readonly class CatalogFilter
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public array $categories = [],
        public ?int $minPrice = null,
        public ?int $maxPrice = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->categories && null === $this->minPrice && null === $this->maxPrice;
    }

    public function hasPrice(): bool
    {
        return null !== $this->minPrice || null !== $this->maxPrice;
    }

    public function acceptsPrice(int $amount): bool
    {
        return (null === $this->minPrice || $amount >= $this->minPrice)
            && (null === $this->maxPrice || $amount <= $this->maxPrice);
    }
}
