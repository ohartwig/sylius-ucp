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
 * The catalog queries over Sylius's product repository.
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

    public function search(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit, ?CatalogFilter $filter = null): array
    {
        /** @var list<ProductInterface> $products */
        $products = $this->queryBuilder($channel, $localeCode, $term, $offset, $limit, $filter)->getQuery()->getResult();

        return $products;
    }

    public function findByIdentifiers(ChannelInterface $channel, array $identifiers): array
    {
        if ([] === $identifiers) {
            return [];
        }

        /** @var list<ProductInterface> $products */
        $products = $this->identifierQueryBuilder($channel, $identifiers)->getQuery()->getResult();

        return $products;
    }

    public function queryBuilder(ChannelInterface $channel, string $localeCode, string $term, int $offset, int $limit, ?CatalogFilter $filter = null): QueryBuilder
    {
        // The translation join is filtered to one locale, so there is at most
        // one translation row per product and the row limit is a product limit.
        $builder = $this->products->createQueryBuilder('o')
            ->addSelect('translation')
            ->innerJoin('o.translations', 'translation', 'WITH', 'translation.locale = :locale')
            ->andWhere(':channel MEMBER OF o.channels')
            ->andWhere('o.enabled = :enabled')
            ->setParameter('locale', $localeCode)
            ->setParameter('channel', $channel)
            ->setParameter('enabled', true)
            ->addOrderBy('translation.name', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));

        if ('' !== $term) {
            $builder->andWhere('translation.name LIKE :name')
                ->setParameter('name', '%'.addcslashes($term, '%_\\').'%');
        }

        // Filters are subqueries, not joins: a join could repeat a product
        // and turn the row limit into something less than a product limit.
        $class = $this->products->getClassName();
        if (null !== $filter && [] !== $filter->categories) {
            $builder->andWhere(\sprintf(
                'o.id IN (SELECT categoryProduct.id FROM %s categoryProduct JOIN categoryProduct.productTaxons categoryLink JOIN categoryLink.taxon categoryTaxon WHERE categoryTaxon.code IN (:categories))',
                $class,
            ))->setParameter('categories', $filter->categories);
        }

        if (null !== $filter && $filter->hasPrice()) {
            // A product passes when at least one enabled variant is priced in
            // range in this channel; the mapper drops the variants that are not.
            $conditions = ['priceVariant.enabled = :enabled', 'pricePricing.channelCode = :channelCode'];
            $builder->setParameter('channelCode', (string) $channel->getCode());
            if (null !== $filter->minPrice) {
                $conditions[] = 'pricePricing.price >= :minPrice';
                $builder->setParameter('minPrice', $filter->minPrice);
            }
            if (null !== $filter->maxPrice) {
                $conditions[] = 'pricePricing.price <= :maxPrice';
                $builder->setParameter('maxPrice', $filter->maxPrice);
            }
            $builder->andWhere(\sprintf(
                'o.id IN (SELECT priceProduct.id FROM %s priceProduct JOIN priceProduct.variants priceVariant JOIN priceVariant.channelPricings pricePricing WHERE %s)',
                $class,
                implode(' AND ', $conditions),
            ));
        }

        return $builder;
    }

    /** @param list<string> $identifiers */
    public function identifierQueryBuilder(ChannelInterface $channel, array $identifiers): QueryBuilder
    {
        return $this->products->createQueryBuilder('o')
            ->andWhere(':channel MEMBER OF o.channels')
            ->andWhere('o.enabled = :enabled')
            ->andWhere(\sprintf(
                'o.code IN (:identifiers) OR o.id IN (SELECT variantProduct.id FROM %s variantProduct JOIN variantProduct.variants identifierVariant WHERE identifierVariant.code IN (:identifiers))',
                $this->products->getClassName(),
            ))
            ->setParameter('channel', $channel)
            ->setParameter('enabled', true)
            ->setParameter('identifiers', $identifiers)
            ->addOrderBy('o.id', 'ASC');
    }
}
