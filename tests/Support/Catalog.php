<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Support;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Product\Model\ProductOption;
use Sylius\Component\Product\Model\ProductOptionValue;

/**
 * Real Sylius models for catalog tests.
 */
final class Catalog
{
    /** @var array<string, ProductOption> */
    private static array $options = [];

    private function __construct()
    {
    }

    public static function channel(): Channel
    {
        $currency = new Currency();
        $currency->setCode('EUR');
        $channel = new Channel();
        $channel->setCode('WEB');
        $channel->setBaseCurrency($currency);

        return $channel;
    }

    public static function context(Channel $channel): AgentContext
    {
        return new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE', 'en_GB']);
    }

    /**
     * @param list<array{code: string, price?: ?int, stock?: int, options?: array<string, string>}> $variants option name => value label
     * @param list<string>                                                                       $taxons
     */
    public static function product(Channel $channel, string $code, array $variants, array $taxons = [], string $name = 'Robusto'): Product
    {
        $product = new Product();
        $product->setCurrentLocale('de_DE');
        $product->setFallbackLocale('de_DE');
        $product->setCode($code);
        $product->setName($name);
        $product->setSlug(strtolower($code));
        $product->setShortDescription('Kraftvoll und cremig.');
        $product->addChannel($channel);

        foreach ($taxons as $taxonCode) {
            $taxon = new Taxon();
            $taxon->setCode($taxonCode);
            $link = new ProductTaxon();
            $link->setTaxon($taxon);
            $product->addProductTaxon($link);
        }

        foreach ($variants as $spec) {
            $variant = new ProductVariant();
            $variant->setCurrentLocale('de_DE');
            $variant->setFallbackLocale('de_DE');
            $variant->setCode($spec['code']);
            $variant->setName($spec['code'].' name');
            $variant->setTracked(true);
            $variant->setOnHand($spec['stock'] ?? 10);
            $price = \array_key_exists('price', $spec) ? $spec['price'] : 990;
            if (null !== $price) {
                $pricing = new ChannelPricing();
                $pricing->setChannelCode((string) $channel->getCode());
                $pricing->setPrice($price);
                $variant->addChannelPricing($pricing);
            }
            foreach ($spec['options'] ?? [] as $optionName => $label) {
                $variant->addOptionValue(self::value($optionName, $label));
            }
            $product->addVariant($variant);
        }

        return $product;
    }

    private static function value(string $optionName, string $label): ProductOptionValue
    {
        if (!isset(self::$options[$optionName])) {
            $option = new ProductOption();
            $option->setCurrentLocale('de_DE');
            $option->setFallbackLocale('de_DE');
            $option->setCode(strtolower($optionName));
            $option->setName($optionName);
            self::$options[$optionName] = $option;
        }

        $value = new ProductOptionValue();
        $value->setCurrentLocale('de_DE');
        $value->setFallbackLocale('de_DE');
        $value->setCode(strtolower($optionName.'_'.$label));
        $value->setValue($label);
        $value->setOption(self::$options[$optionName]);

        return $value;
    }
}
