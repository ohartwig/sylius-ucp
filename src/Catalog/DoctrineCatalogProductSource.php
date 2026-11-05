<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Catalog;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;

/**
 * The catalog search query over Sylius's product repository.
 *
 * findByName() matches across channels, ignores the enabled flag and has no
 * limit, so this builds the query with all three in SQL: a page is cut by the
 * database, never after hydrating every match, and a disabled product or one
 * from another channel never reaches an agent.
 */
final class DoctrineCatalogProductSource implements CatalogProductSource
{
    /** @param EntityRepository<ProductInterface> $products */
    public function __construct(private readonly EntityRepository $products)
    {
    }

    public function search(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit): array
    {
        /** @var list<ProductInterface> $products */
        $products = $this->queryBuilder($channel, $localeCode, $term, $offset, $limit)->getQuery()->getResult();

        return $products;
    }

    public function queryBuilder(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit): QueryBuilder
    {
        // The translation join is filtered to one locale, so there is at most
        // one translation row per product and the row limit is a product limit.
        return $this->products->createQueryBuilder('o')
            ->addSelect('translation')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->andWhere(':channel MEMBER OF o.channels')
            ->andWhere('o.enabled = :enabled')
            ->andWhere('translation.name LIKE :name')
            ->setParameter('locale', $localeCode)
            ->setParameter('channel', $channel)
            ->setParameter('enabled', true)
            ->setParameter('name', '%'.addcslashes($term, '%_\\').'%')
            ->addOrderBy('translation.name', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));
    }
}
