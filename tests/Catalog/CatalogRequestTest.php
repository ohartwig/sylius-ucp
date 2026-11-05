<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Catalog;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusUcp\Catalog\CatalogFilter;
use Koh\SyliusUcp\Catalog\CatalogRequest;
use Koh\SyliusUcp\Tests\Support\Catalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/** The content language: context.language, else Accept-Language, else the channel default. */
#[CoversClass(CatalogRequest::class)]
#[CoversClass(CatalogFilter::class)]
final class CatalogRequestTest extends TestCase
{
    /** @return iterable<string, array{list<string>, ?string, ?string, string}> */
    public static function languages(): iterable
    {
        $locales = ['de_DE', 'en_GB', 'en_US', 'fr_FR'];

        yield 'nothing asked' => [$locales, null, null, 'de_DE'];
        yield 'exact tag' => [$locales, 'en-US', null, 'en_US'];
        yield 'case-insensitive' => [$locales, 'EN-us', null, 'en_US'];
        yield 'primary language' => [$locales, 'fr', null, 'fr_FR'];
        yield 'primary language of the default first' => [$locales, 'de-AT', null, 'de_DE'];
        yield 'context wins over the header' => [$locales, 'fr', 'en', 'fr_FR'];
        yield 'header weights' => [$locales, null, 'it;q=1, fr;q=0.5, en;q=0.8', 'en_GB'];
        yield 'equal weights keep their order' => [$locales, null, 'fr, en', 'fr_FR'];
        yield 'zero weight is a refusal' => [$locales, null, 'fr;q=0, it', 'de_DE'];
        yield 'wildcard ignored' => [$locales, null, '*', 'de_DE'];
        yield 'unknown language' => [$locales, 'ja', null, 'de_DE'];
        yield 'empty context language falls back to the default' => [$locales, '', 'fr', 'de_DE'];
        yield 'no channel locales listed' => [[], 'en', null, 'de_DE'];
    }

    /** @param list<string> $locales */
    #[DataProvider('languages')]
    public function testTheLocaleFollowsTheRequest(array $locales, ?string $language, ?string $acceptLanguage, string $expected): void
    {
        $context = new AgentContext(Catalog::channel(), 'https://shop.example.com', 'de_DE', $locales);
        $body = null === $language ? ['query' => 'a'] : ['query' => 'a', 'context' => ['language' => $language]];
        $request = Request::create('/', 'POST', content: json_encode($body, \JSON_THROW_ON_ERROR));
        // Request::create() sends a default Accept-Language; a real client may not.
        $request->headers->remove('Accept-Language');
        if (null !== $acceptLanguage) {
            $request->headers->set('Accept-Language', $acceptLanguage);
        }

        self::assertSame($expected, CatalogRequest::read($request, $context)->localeCode);
    }

    public function testFilterPredicates(): void
    {
        self::assertTrue((new CatalogFilter())->isEmpty());
        self::assertFalse((new CatalogFilter())->hasPrice());
        self::assertTrue((new CatalogFilter([], null, 5))->hasPrice());
        self::assertFalse((new CatalogFilter([], null, 5))->isEmpty());
        self::assertFalse((new CatalogFilter(['a']))->isEmpty());
        self::assertTrue((new CatalogFilter([], 5, 10))->acceptsPrice(5));
        self::assertTrue((new CatalogFilter([], 5, 10))->acceptsPrice(10));
        self::assertFalse((new CatalogFilter([], 5, 10))->acceptsPrice(4));
        self::assertFalse((new CatalogFilter([], 5, 10))->acceptsPrice(11));
    }
}
