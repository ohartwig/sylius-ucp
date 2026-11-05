<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Catalog;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * Where the catalog operations read their products from. Only enabled
 * products of the channel ever come out of it.
 */
interface CatalogProductSource
{
    /**
     * Products whose name in the locale contains the term (every product when
     * the term is empty) and that pass the filter, at most `$limit` of them
     * starting at `$offset`.
     *
     * @return list<ProductInterface>
     */
    public function search(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit, ?CatalogFilter $filter = null): array;

    /**
     * Products whose code, or the code of one of whose variants, is one of the
     * identifiers.
     *
     * @param list<string> $identifiers
     *
     * @return list<ProductInterface>
     */
    public function findByIdentifiers(ChannelInterface $channel, array $identifiers): array;
}
