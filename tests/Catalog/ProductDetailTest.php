<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Catalog;

use Koh\SyliusUcp\Catalog\ProductDetail;
use Koh\SyliusUcp\Catalog\UcpProductMapper;
use Koh\SyliusUcp\Tests\Support\Catalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * get_product option selection (spec "Get Product": Resolution Behavior,
 * Option Selection, Effective Selections, Availability Signals).
 */
#[CoversClass(ProductDetail::class)]
final class ProductDetailTest extends TestCase
{
    public function testAProductWithoutOptionsPutsTheRequestedVariantFirst(): void
    {
        $product = $this->mapped([['code' => 'A'], ['code' => 'B'], ['code' => 'C']]);

        $detail = ProductDetail::build($product, 'B');
        $plain = ProductDetail::build($product, null);

        self::assertSame(['B', 'A', 'C'], array_column($detail['variants'], 'id'));
        self::assertArrayNotHasKey('selected', $detail);
        self::assertSame($product, $plain);
    }

    /** A variant id fully determines the selection; `selected` is ignored. */
    public function testAVariantIdIsTheFeaturedVariantAndItsOptionsAreSelected(): void
    {
        $detail = ProductDetail::build($this->cigars(), 'C10', [['name' => 'Format', 'label' => 'Robusto']]);

        self::assertSame(['C10'], array_column($detail['variants'], 'id'));
        self::assertSame([['name' => 'Format', 'id' => 'format_corona', 'label' => 'Corona'], ['name' => 'Packung', 'id' => 'packung_10er', 'label' => '10er']], $detail['selected']);
    }

    /** Without selections the first available variant is featured. */
    public function testWithoutSelectionsTheFirstAvailableVariantIsFeatured(): void
    {
        $detail = ProductDetail::build($this->cigars(), null);

        self::assertSame('R10', $detail['variants'][0]['id']);
        self::assertSame(['format_robusto', 'packung_10er'], array_column($detail['selected'], 'id'));
    }

    public function testSelectionsNarrowTheVariants(): void
    {
        $detail = ProductDetail::build($this->cigars(), null, [['name' => 'Format', 'label' => 'Robusto']]);

        self::assertSame(['R10', 'R5'], array_column($detail['variants'], 'id'));
        self::assertSame([['name' => 'Format', 'id' => 'format_robusto', 'label' => 'Robusto']], $detail['selected']);
    }

    public function testASelectionByIdWinsOverItsLabel(): void
    {
        $detail = ProductDetail::build($this->cigars(), null, [['name' => 'Format', 'id' => 'format_corona', 'label' => 'Robusto']]);

        self::assertSame('format_corona', $detail['selected'][0]['id']);
        self::assertSame(['C10'], array_column($detail['variants'], 'id'));
    }

    /** Corona exists only as 10er: the 5er selection is relaxed, Format is kept. */
    public function testUnresolvableSelectionsAreRelaxedFromTheEndOfThePreferences(): void
    {
        $selected = [['name' => 'Format', 'label' => 'Corona'], ['name' => 'Packung', 'label' => '5er']];

        $keepFormat = ProductDetail::build($this->cigars(), null, $selected, ['Format', 'Packung']);
        $keepPackung = ProductDetail::build($this->cigars(), null, $selected, ['Packung', 'Format']);
        $unranked = ProductDetail::build($this->cigars(), null, $selected);

        self::assertSame([['name' => 'Format', 'id' => 'format_corona', 'label' => 'Corona']], $keepFormat['selected']);
        self::assertSame([['name' => 'Packung', 'id' => 'packung_5er', 'label' => '5er']], $keepPackung['selected']);
        // Without preferences the last requested selection goes first.
        self::assertSame($keepFormat['selected'], $unranked['selected']);
    }

    public function testUnknownSelectionsAreDroppedAndEverythingRelaxedLeavesNone(): void
    {
        $detail = ProductDetail::build($this->cigars(), null, [['name' => 'Farbe', 'label' => 'Rot'], ['name' => 'Format', 'label' => 'Lancero']]);

        self::assertSame([], $detail['selected']);
        self::assertSame('R10', $detail['variants'][0]['id']);
        self::assertCount(3, $detail['variants']);
    }

    /** exists/available are relative to the selections of the other options. */
    public function testOptionValuesCarryAvailabilitySignals(): void
    {
        $detail = ProductDetail::build($this->cigars(), null, [['name' => 'Format', 'label' => 'Corona'], ['name' => 'Packung', 'label' => '10er']]);

        self::assertSame([
            ['name' => 'Format', 'values' => [
                ['id' => 'format_robusto', 'label' => 'Robusto', 'available' => true, 'exists' => true],
                ['id' => 'format_corona', 'label' => 'Corona', 'available' => false, 'exists' => true],
            ]],
            ['name' => 'Packung', 'values' => [
                ['id' => 'packung_10er', 'label' => '10er', 'available' => false, 'exists' => true],
                ['id' => 'packung_5er', 'label' => '5er', 'available' => false, 'exists' => false],
            ]],
        ], $detail['options']);
        // Nothing is in stock for Corona: the matching variant is still featured.
        self::assertSame(['C10'], array_column($detail['variants'], 'id'));
    }

    /**
     * R10 (in stock), R5 (sold out), C10 (sold out).
     *
     * @return array<string, mixed>
     */
    private function cigars(): array
    {
        return $this->mapped([
            ['code' => 'R10', 'options' => ['Format' => 'Robusto', 'Packung' => '10er']],
            ['code' => 'R5', 'stock' => 0, 'options' => ['Format' => 'Robusto', 'Packung' => '5er']],
            ['code' => 'C10', 'stock' => 0, 'options' => ['Format' => 'Corona', 'Packung' => '10er']],
        ]);
    }

    /**
     * @param list<array{code: string, price?: ?int, stock?: int, options?: array<string, string>}> $variants
     *
     * @return array<string, mixed>
     */
    private function mapped(array $variants): array
    {
        $channel = Catalog::channel();
        $mapped = (new UcpProductMapper())->map(Catalog::product($channel, 'P', $variants), Catalog::context($channel));
        self::assertNotNull($mapped);

        return $mapped;
    }
}
