<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Permalink;

use Koh\SyliusUcp\Permalink\PermalinkRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Item path, item tokens, query classification and `continue_to` as the UCP
 * permalink specification (2026-08-25) defines them.
 */
#[CoversClass(PermalinkRequest::class)]
final class PermalinkRequestTest extends TestCase
{
    public function testCompactItemsAreParsed(): void
    {
        $request = PermalinkRequest::parse('sku_123:2,sku.456-x:1', '');

        self::assertFalse($request->malformed);
        self::assertSame([['sku_123', 2], ['sku.456-x', 1]], $request->items);
        self::assertFalse($request->capped);
        self::assertNull($request->continueTo);
        self::assertSame([], $request->passThrough);
    }

    public function testNoItemsIsAValidPermalink(): void
    {
        $request = PermalinkRequest::parse('', 'continue_to=/');

        self::assertFalse($request->malformed);
        self::assertSame([], $request->items);
        self::assertSame('/', $request->continueTo);
    }

    /** The spec's own example: gid://shopify/ProductVariant/70881412. */
    public function testTildeTokensAreBase64UrlDecoded(): void
    {
        $request = PermalinkRequest::parse('~Z2lkOi8vc2hvcGlmeS9Qcm9kdWN0VmFyaWFudC83MDg4MTQxMg:1,~w6TDtsO8:3', '');

        self::assertSame([['gid://shopify/ProductVariant/70881412', 1], ['äöü', 3]], $request->items);
    }

    public function testRawAndEncodedFormsAreTheSameIdentity(): void
    {
        $raw = PermalinkRequest::parse('sku_1:1', '');
        $encoded = PermalinkRequest::parse('~'.rtrim(strtr(base64_encode('sku_1'), '+/', '-_'), '=').':1', '');

        self::assertSame($raw->items, $encoded->items);
        self::assertSame($raw->fingerprint(), $encoded->fingerprint());
        self::assertNotSame($raw->fingerprint(), PermalinkRequest::parse('sku_1:2', '')->fingerprint());
    }

    public function testQuantitiesAboveTheCapAreCappedAndSaid(): void
    {
        $request = PermalinkRequest::parse('a:100,b:99', '');

        self::assertSame([['a', 99], ['b', 99]], $request->items);
        self::assertTrue($request->capped);
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformed(): iterable
    {
        yield 'no quantity' => ['sku', ''];
        yield 'zero quantity' => ['sku:0', ''];
        yield 'leading zero' => ['sku:01', ''];
        yield 'negative quantity' => ['sku:-1', ''];
        yield 'quantity not a number' => ['sku:x', ''];
        yield 'two colons' => ['a:b:1', ''];
        yield 'empty pair' => ['a:1,', ''];
        yield 'percent-encoded token' => ['a%2Fb:1', ''];
        yield 'token with space' => ['a b:1', ''];
        yield 'tilde without payload' => ['~:1', ''];
        yield 'tilde not base64url' => ['~a+b:1', ''];
        yield 'tilde with padding' => ['~YQ==:1', ''];
        yield 'tilde not canonical' => ['~YR:1', ''];
        yield 'tilde not UTF-8' => ['~_w:1', ''];
        yield 'tilde control character' => ['~YQph:1', ''];
        yield 'tilde C1 control' => ['~woU:1', ''];
        yield 'too many items' => [implode(',', array_map(static fn (int $i): string => 'i'.$i.':1', range(1, PermalinkRequest::MAX_ITEMS + 1))), ''];
        yield 'two destinations' => ['a:1', 'continue_to=/a&continue_to=/b'];
        yield 'one pointer written twice' => ['a:1', 'buyer/email=a%40b.c&/buyer/email=x%40y.z'];
        yield 'control character in a value' => ['a:1', 'utm_source=a%0Ab'];
        yield 'control character in a name' => ['a:1', 'utm%00x=1'];
        yield 'value too long' => ['a:1', 'utm_source='.str_repeat('a', 513)];
        yield 'invalid UTF-8 value' => ['a:1', 'utm_source=%FF'];
        yield 'empty name' => ['a:1', '=x'];
        yield 'too many parameters' => ['a:1', implode('&', array_map(static fn (int $i): string => 'p'.$i.'=1', range(1, PermalinkRequest::MAX_PARAMETERS + 1)))];
    }

    #[DataProvider('malformed')]
    public function testMalformedPermalinksCarryNothing(string $items, string $query): void
    {
        $request = PermalinkRequest::parse($items, $query);

        self::assertTrue($request->malformed);
        self::assertSame([], $request->items);
        self::assertNull($request->continueTo);
        self::assertSame([], $request->passThrough);
    }

    public function testAnOverlongUrlIsMalformed(): void
    {
        self::assertTrue(PermalinkRequest::parse('a:1', '', PermalinkRequest::MAX_URL_LENGTH + 1)->malformed);
        self::assertFalse(PermalinkRequest::parse('a:1', '', PermalinkRequest::MAX_URL_LENGTH)->malformed);
    }

    /**
     * The spec's example: buyer fields are consumed, attribution rewritten,
     * other parameters passed through, sensitive ones dropped.
     */
    public function testQueryParametersAreClassified(): void
    {
        $request = PermalinkRequest::parse(
            'sku_123:1',
            'continue_to=/collections/spring&buyer/email=alice%40example.com&buyer/unknown=foo&attribution/utm_source=email&utm_medium=sms&color=black&access_token=secret&context/postal_code=94105&line_items/0/quantity=5&api_key=x&weird/name=1',
        );

        self::assertFalse($request->malformed);
        self::assertSame('/collections/spring', $request->continueTo);
        self::assertSame([['utm_source', 'email'], ['utm_medium', 'sms'], ['color', 'black']], $request->passThrough);
        // The compact path is authoritative for quantities.
        self::assertSame([['sku_123', 1]], $request->items);
    }

    public function testAttributionThatLooksSensitiveOrOddIsDropped(): void
    {
        $request = PermalinkRequest::parse('', 'attribution/session_id=1&attribution/a%20b=2&attribution=3&&attribution/ref=creator_42&ref2&empty=');

        self::assertSame([['ref', 'creator_42'], ['ref2', ''], ['empty', '']], $request->passThrough);
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function destinations(): iterable
    {
        yield 'root' => ['/', '/'];
        yield 'path' => ['/collections/spring', '/collections/spring'];
        yield 'path with query' => ['/c?a=1&b', '/c?a=1&b'];
        yield 'query re-encoded' => ['/c?q=a b', null];
        yield 'fragment dropped' => ['/c#top', '/c'];
        yield 'dot segments removed' => ['/a/./b/../c', '/a/c'];
        yield 'dot segments cannot climb above the root' => ['/../../etc', '/etc'];
        yield 'trailing dot segment' => ['/a/b/..', '/a/'];
        yield 'trailing single dot' => ['/a/.', '/a/'];
        yield 'segments re-encoded' => ['/söhne/a"b', '/s%C3%B6hne/a%22b'];
        yield 'valid escapes kept' => ['/a%2Fb/c%20d', '/a%2Fb/c%20d'];
        yield 'stray percent encoded' => ['/100%/x%2', '/100%25/x%252'];
        yield 'query delimiters kept, others encoded' => ['/c?a=1&b=/x?y&c=<', '/c?a=1&b=/x?y&c=%3C'];
        yield 'protocol-relative' => ['//evil.example/', null];
        yield 'dot segments forming protocol-relative' => ['/./evil.example', '/evil.example'];
        yield 'empty first segment after removal' => ['/..//evil.example', null];
        yield 'absolute URL' => ['https://evil.example/', null];
        yield 'scheme' => ['javascript:alert(1)', null];
        yield 'relative' => ['collections', null];
        yield 'backslash' => ['/\\evil.example', null];
        yield 'tab' => ["/a\tb", null];
        yield 'CR LF' => ["/a\r\nSet-Cookie: x", null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('destinations')]
    public function testContinueToIsValidatedAndCanonicalised(string $value, ?string $expected): void
    {
        self::assertSame($expected, PermalinkRequest::destination($value));
    }

    /** continue_to is decoded exactly once, by the query parser. */
    public function testContinueToIsDecodedOnce(): void
    {
        self::assertSame('/a%2Fb', PermalinkRequest::parse('', 'continue_to=/a%252Fb')->continueTo);
        self::assertSame('/a/b', PermalinkRequest::parse('', 'continue_to=%2Fa%2Fb')->continueTo);
        self::assertNull(PermalinkRequest::parse('', 'continue_to=%2F%2Fevil.example')->continueTo);
        self::assertFalse(PermalinkRequest::parse('', 'continue_to=%2F%2Fevil.example')->malformed);
    }
}
