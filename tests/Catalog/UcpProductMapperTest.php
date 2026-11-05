<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Catalog;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusUcp\Catalog\UcpProductMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Currency\Model\Currency;

/**
 * The UCP Product type requires id, title, description, price_range and at
 * least one variant, and every variant requires id, title, description and a
 * price. These tests build real Sylius models, so the mapping is checked
 * against what Sylius actually returns.
 */
#[CoversClass(UcpProductMapper::class)]
final class UcpProductMapperTest extends TestCase
{
    public function testAProductMapsToTheUcpShape(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel, [['V-1', 'Einzeln', 1290, true], ['V-5', '5er-Kiste', 5990, false]]);

        $mapped = (new UcpProductMapper())->map($product, $this->context($channel));

        self::assertNotNull($mapped);
        self::assertSame('P-1', $mapped['id']);
        self::assertSame('Robusto', $mapped['title']);
        self::assertSame(['plain' => 'Kraftvoll und cremig.'], $mapped['description']);
        self::assertSame('robusto', $mapped['handle']);
        self::assertSame('https://shop.example.com/de_DE/products/robusto', $mapped['url']);
        self::assertSame(
            ['min' => ['amount' => 1290, 'currency' => 'EUR'], 'max' => ['amount' => 5990, 'currency' => 'EUR']],
            $mapped['price_range'],
        );
        self::assertSame([
            'id' => 'V-1',
            'sku' => 'V-1',
            'title' => 'Einzeln',
            'description' => ['plain' => 'Kraftvoll und cremig.'],
            'price' => ['amount' => 1290, 'currency' => 'EUR'],
            'availability' => ['available' => true, 'status' => 'in_stock'],
        ], $mapped['variants'][0]);
        self::assertSame(['available' => false, 'status' => 'out_of_stock'], $mapped['variants'][1]['availability']);
    }

    /** A variant without a price in the channel cannot be bought there. */
    public function testUnpricedVariantsAreLeftOut(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel, [['V-1', 'Einzeln', null, true], ['V-5', '5er-Kiste', 5990, true]]);

        $mapped = (new UcpProductMapper())->map($product, $this->context($channel));

        self::assertNotNull($mapped);
        self::assertSame(['V-5'], array_column($mapped['variants'], 'id'));
        self::assertSame(5990, $mapped['price_range']['min']['amount']);
    }

    /** The schema needs at least one variant: a product without a priced one is not shipped. */
    public function testAProductWithoutAPricedVariantIsLeftOut(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel, [['V-1', 'Einzeln', null, true]]);

        self::assertNull((new UcpProductMapper())->map($product, $this->context($channel)));
    }

    public function testDisabledVariantsAreLeftOut(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel, [['V-1', 'Einzeln', 1290, true], ['V-5', '5er-Kiste', 5990, true]]);
        $product->getVariants()->last()->disable();

        $mapped = (new UcpProductMapper())->map($product, $this->context($channel));

        self::assertSame(['V-1'], array_column($mapped['variants'] ?? [], 'id'));
    }

    /** A Price needs a currency; without one there is nothing valid to say. */
    public function testAChannelWithoutCurrencyMapsNothing(): void
    {
        $channel = new Channel();
        $channel->setCode('WEB');
        $product = $this->product($channel, [['V-1', 'Einzeln', 1290, true]]);

        self::assertNull((new UcpProductMapper())->map($product, $this->context($channel)));
    }

    /** Description and titles fall back rather than shipping an empty object the schema rejects. */
    public function testFallbacksKeepTheRequiredFieldsFilled(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel, [['V-1', '  ', 1290, true]], shortDescription: '<p> </p>', description: "<p>Lang\n und  <b>würzig</b>.</p>", slug: null);

        $mapped = (new UcpProductMapper())->map($product, $this->context($channel));

        self::assertNotNull($mapped);
        self::assertSame(['plain' => 'Lang und würzig.'], $mapped['description']);
        self::assertSame('Robusto', $mapped['variants'][0]['title']);
        self::assertArrayNotHasKey('handle', $mapped);
        self::assertArrayNotHasKey('url', $mapped);
    }

    public function testWithoutAnyTextTheTitleIsTheCode(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel, [['V-1', null, 1290, true]], name: null, shortDescription: null, description: null);

        $mapped = (new UcpProductMapper())->map($product, $this->context($channel));

        self::assertNotNull($mapped);
        self::assertSame('P-1', $mapped['title']);
        self::assertSame(['plain' => 'P-1'], $mapped['description']);
    }

    public function testLongDescriptionsAreCut(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel, [['V-1', 'Einzeln', 1290, true]], shortDescription: str_repeat('a', 600));

        $plain = (new UcpProductMapper())->map($product, $this->context($channel))['description']['plain'] ?? '';

        self::assertSame(500, mb_strlen($plain));
        self::assertStringEndsWith('…', $plain);

        $exact = $this->product($channel, [['V-1', 'Einzeln', 1290, true]], shortDescription: str_repeat('ü', 500));
        self::assertSame(str_repeat('ü', 500), (new UcpProductMapper())->map($exact, $this->context($channel))['description']['plain'] ?? '');

        $long = $this->product($channel, [['V-1', 'Einzeln', 1290, true]], shortDescription: str_repeat('ü', 600));
        self::assertSame(str_repeat('ü', 499).'…', (new UcpProductMapper())->map($long, $this->context($channel))['description']['plain'] ?? '');
    }

    /** A Price amount is never negative; a free item (0) is a price. */
    public function testNegativePricesAreLeftOutAndZeroIsKept(): void
    {
        $channel = $this->channel();
        $product = $this->product($channel, [['V-1', 'Einzeln', -1, true], ['V-2', 'Probe', 0, true]]);

        $mapped = (new UcpProductMapper())->map($product, $this->context($channel));

        self::assertSame(['V-2'], array_column($mapped['variants'] ?? [], 'id'));
    }

    private function channel(): Channel
    {
        $currency = new Currency();
        $currency->setCode('EUR');
        $channel = new Channel();
        $channel->setCode('WEB');
        $channel->setBaseCurrency($currency);

        return $channel;
    }

    private function context(Channel $channel): AgentContext
    {
        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']);
    }

    /** @param list<array{string, ?string, ?int, bool}> $variants code, name, price, in stock */
    private function product(
        Channel $channel,
        array $variants,
        ?string $name = 'Robusto',
        ?string $shortDescription = 'Kraftvoll und cremig.',
        ?string $description = 'Eine lange Beschreibung.',
        ?string $slug = 'robusto',
    ): Product {
        $product = new Product();
        $product->setCurrentLocale('de_DE');
        $product->setFallbackLocale('de_DE');
        $product->setCode('P-1');
        $product->setName($name);
        $product->setShortDescription($shortDescription);
        $product->setDescription($description);
        $product->setSlug($slug);

        foreach ($variants as [$code, $variantName, $price, $inStock]) {
            $variant = new ProductVariant();
            $variant->setCurrentLocale('de_DE');
            $variant->setFallbackLocale('de_DE');
            $variant->setCode($code);
            $variant->setName($variantName);
            $variant->setTracked(true);
            $variant->setOnHand($inStock ? 10 : 0);
            if (null !== $price) {
                $pricing = new ChannelPricing();
                $pricing->setChannelCode((string) $channel->getCode());
                $pricing->setPrice($price);
                $variant->addChannelPricing($pricing);
            }
            $product->addVariant($variant);
        }

        return $product;
    }
}
