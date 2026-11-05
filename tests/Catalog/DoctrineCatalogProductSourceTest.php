<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    private function source(): DoctrineCatalogProductSource
    {
        $em = $this->createStub(EntityManagerInterface::class);

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('createQueryBuilder')->willReturnCallback(
            static fn (string $alias): QueryBuilder => (new QueryBuilder($em))->select($alias)->from(Product::class, $alias),
        );

        return new DoctrineCatalogProductSource($repository);
    }
}
