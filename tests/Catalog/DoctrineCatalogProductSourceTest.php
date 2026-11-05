<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
use Koh\SyliusUcp\Catalog\CatalogFilter;
use Koh\SyliusUcp\Catalog\DoctrineCatalogProductSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;

/**
 * The page is cut by the database, and channel, enabled flag and locale are
 * filtered there: an anonymous search never hydrates more than one page and
 * never sees a product of another channel.
 */
#[CoversClass(DoctrineCatalogProductSource::class)]
#[CoversClass(CatalogFilter::class)]
final class DoctrineCatalogProductSourceTest extends TestCase
{
    public function testTheQueryCarriesOffsetAndLimit(): void
    {
        $queryBuilder = $this->source()->queryBuilder($this->createStub(ChannelInterface::class), 'de_DE', 'zigarre', 40, 11);

        self::assertSame(40, $queryBuilder->getFirstResult());
        self::assertSame(11, $queryBuilder->getMaxResults());
    }

    public function testOffsetAndLimitAreClampedToSaneValues(): void
    {
        $queryBuilder = $this->source()->queryBuilder($this->createStub(ChannelInterface::class), 'de_DE', 'zigarre', -5, 0);

        self::assertSame(0, $queryBuilder->getFirstResult());
        self::assertSame(1, $queryBuilder->getMaxResults());
    }

    public function testOnlyEnabledProductsOfTheChannelInTheLocaleMatchByName(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $queryBuilder = $this->source()->queryBuilder($channel, 'de_DE', 'zigarre', 0, 10);
        $dql = $queryBuilder->getDQL();

        self::assertStringContainsString(':channel MEMBER OF o.channels', $dql);
        self::assertStringContainsString('o.enabled = :enabled', $dql);
        self::assertStringContainsString('translation.locale = :locale', $dql);
        self::assertStringContainsString('translation.name LIKE :name', $dql);
        self::assertStringContainsString('ORDER BY translation.name ASC, o.id ASC', $dql);
        self::assertSame($channel, $queryBuilder->getParameter('channel')?->getValue());
        self::assertTrue($queryBuilder->getParameter('enabled')?->getValue());
        self::assertSame('de_DE', $queryBuilder->getParameter('locale')?->getValue());
        self::assertSame('%zigarre%', $queryBuilder->getParameter('name')?->getValue());
    }

    public function testWildcardsInTheTermAreText(): void
    {
        $queryBuilder = $this->source()->queryBuilder($this->createStub(ChannelInterface::class), 'de_DE', '50%_off\\', 0, 10);

        self::assertSame('%50\%\_off\\\\%', $queryBuilder->getParameter('name')?->getValue());
    }

    public function testAnEmptyTermBrowsesWithoutANameCondition(): void
    {
        $queryBuilder = $this->source()->queryBuilder($this->createStub(ChannelInterface::class), 'de_DE', '', 0, 10, new CatalogFilter(['cigars']));

        self::assertStringNotContainsString('LIKE', $queryBuilder->getDQL());
        self::assertNull($queryBuilder->getParameter('name'));
    }

    /** Filters are subqueries: a product in two matching categories still counts once. */
    public function testCategoriesFilterOnTaxonCodes(): void
    {
        $queryBuilder = $this->source()->queryBuilder($this->createStub(ChannelInterface::class), 'de_DE', 'a', 0, 10, new CatalogFilter(['cigars', 'cuba']));
        $dql = $queryBuilder->getDQL();

        self::assertStringContainsString('o.id IN (SELECT categoryProduct.id FROM '.Product::class.' categoryProduct JOIN categoryProduct.productTaxons categoryLink JOIN categoryLink.taxon categoryTaxon WHERE categoryTaxon.code IN (:categories))', $dql);
        self::assertStringNotContainsString('DISTINCT', $dql);
        self::assertSame(['cigars', 'cuba'], $queryBuilder->getParameter('categories')?->getValue());
        self::assertNull($queryBuilder->getParameter('channelCode'));
    }

    public function testAPriceRangeNeedsOneEnabledVariantPricedInRangeInTheChannel(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $channel->method('getCode')->willReturn('WEB');

        $both = $this->source()->queryBuilder($channel, 'de_DE', 'a', 0, 10, new CatalogFilter([], 100, 900));
        $min = $this->source()->queryBuilder($channel, 'de_DE', 'a', 0, 10, new CatalogFilter([], 100));
        $max = $this->source()->queryBuilder($channel, 'de_DE', 'a', 0, 10, new CatalogFilter([], null, 900));

        self::assertStringContainsString('o.id IN (SELECT priceProduct.id FROM '.Product::class.' priceProduct JOIN priceProduct.variants priceVariant JOIN priceVariant.channelPricings pricePricing WHERE priceVariant.enabled = :enabled AND pricePricing.channelCode = :channelCode AND pricePricing.price >= :minPrice AND pricePricing.price <= :maxPrice)', $both->getDQL());
        self::assertSame('WEB', $both->getParameter('channelCode')?->getValue());
        self::assertSame(100, $both->getParameter('minPrice')?->getValue());
        self::assertSame(900, $both->getParameter('maxPrice')?->getValue());
        self::assertStringNotContainsString(':maxPrice', $min->getDQL());
        self::assertNull($min->getParameter('maxPrice'));
        self::assertStringNotContainsString(':minPrice', $max->getDQL());
        self::assertNull($max->getParameter('minPrice'));
    }

    public function testNoFilterAddsNoSubquery(): void
    {
        $dql = $this->source()->queryBuilder($this->createStub(ChannelInterface::class), 'de_DE', 'a', 0, 10, new CatalogFilter())->getDQL();

        self::assertStringNotContainsString('SELECT categoryProduct', $dql);
        self::assertStringNotContainsString('SELECT priceProduct', $dql);
    }

    public function testIdentifiersMatchProductOrVariantCodesOfEnabledProductsInTheChannel(): void
    {
        $channel = $this->createStub(ChannelInterface::class);
        $queryBuilder = $this->source()->identifierQueryBuilder($channel, ['CIG', 'CIG-R']);
        $dql = $queryBuilder->getDQL();

        self::assertStringContainsString(':channel MEMBER OF o.channels', $dql);
        self::assertStringContainsString('o.enabled = :enabled', $dql);
        self::assertStringContainsString('o.code IN (:identifiers) OR o.id IN (SELECT variantProduct.id FROM '.Product::class.' variantProduct JOIN variantProduct.variants identifierVariant WHERE identifierVariant.code IN (:identifiers))', $dql);
        self::assertSame(['CIG', 'CIG-R'], $queryBuilder->getParameter('identifiers')?->getValue());
        self::assertSame($channel, $queryBuilder->getParameter('channel')?->getValue());
        self::assertTrue($queryBuilder->getParameter('enabled')?->getValue());
    }

    public function testNoIdentifiersAskNothing(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::never())->method('createQueryBuilder');

        self::assertSame([], (new DoctrineCatalogProductSource($repository))->findByIdentifiers($this->createStub(ChannelInterface::class), []));
    }

    private function source(): DoctrineCatalogProductSource
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('getClassName')->willReturn(Product::class);
        $repository->method('createQueryBuilder')->willReturnCallback(
            static fn (string $alias): QueryBuilder => (new QueryBuilder($em))->select($alias)->from(Product::class, $alias),
        );

        return new DoctrineCatalogProductSource($repository);
    }
}
