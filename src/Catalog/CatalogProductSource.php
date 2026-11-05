<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Catalog;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * Where the catalog search reads its products from.
 */
interface CatalogProductSource
{
    /**
     * Enabled products of the channel whose name in the locale contains the
     * term, at most `$limit` of them starting at `$offset`.
     *
     * @return list<ProductInterface>
     */
    public function search(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit): array;
}
