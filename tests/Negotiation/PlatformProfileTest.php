<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Negotiation;

use Koh\SyliusUcp\Negotiation\PlatformProfile;
use Koh\SyliusUcp\Tests\Support\Platform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PlatformProfile::class)]
final class PlatformProfileTest extends TestCase
{
    public function testAValidProfileSupportsItsCapabilitiesAtTheirVersion(): void
    {
        $profile = PlatformProfile::fromDocument(self::decoded());

        self::assertNotNull($profile);
        self::assertSame('2026-08-25', $profile->version);
        self::assertTrue($profile->supports('dev.ucp.shopping.catalog.search', '2026-08-25'));
        self::assertFalse($profile->supports('dev.ucp.shopping.catalog.search', '2026-04-08'));
        self::assertFalse($profile->supports('dev.ucp.shopping.checkout', '2026-08-25'));
    }

    public function testCapabilitiesAreOptional(): void
    {
        $profile = PlatformProfile::fromDocument(['ucp' => ['version' => '2026-08-25', 'services' => [], 'payment_handlers' => []]]);

        self::assertNotNull($profile);
        self::assertFalse($profile->supports('dev.ucp.shopping.catalog.search', '2026-08-25'));
    }

    /** A capability whose schema is not on its namespace's authority is not present. */
    public function testEntitiesFailingAuthorityBindingAreIgnored(): void
    {
        $document = self::decoded();
        $document['ucp']['capabilities']['dev.ucp.shopping.catalog.search'][0]['schema'] = 'https://evil.example/catalog_search.json';

        $profile = PlatformProfile::fromDocument($document);

        self::assertNotNull($profile);
        self::assertFalse($profile->supports('dev.ucp.shopping.catalog.search', '2026-08-25'));
        self::assertTrue($profile->supports('dev.ucp.shopping.catalog.lookup', '2026-08-25'));
    }

    public function testTheCacheRoundTripKeepsTheFacts(): void
    {
        $profile = PlatformProfile::fromDocument(self::decoded());
        self::assertNotNull($profile);

        $restored = PlatformProfile::fromArray($profile->toArray());

        self::assertNotNull($restored);
        self::assertSame($profile->toArray(), $restored->toArray());
        self::assertNull(PlatformProfile::fromArray(['version' => 1]));
        self::assertNull(PlatformProfile::fromArray(['version' => '2026-08-25', 'capabilities' => 'x']));
        self::assertNull(PlatformProfile::fromArray(['version' => '2026-08-25', 'capabilities' => [5 => ['x']]]));
        self::assertSame(['a' => ['2026-08-25']], PlatformProfile::fromArray(['version' => 'v', 'capabilities' => ['a' => ['2026-08-25', 7]]])?->toArray()['capabilities']);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformed(): iterable
    {
        $valid = self::decoded();

        yield 'not an object' => ['x'];
        yield 'no ucp' => [['version' => '2026-08-25']];
        yield 'ucp not an object' => [['ucp' => 'x']];
        yield 'no version' => [['ucp' => ['services' => [], 'payment_handlers' => []]]];
        yield 'version not a date' => [['ucp' => ['version' => 'draft', 'services' => [], 'payment_handlers' => []]]];
        yield 'no services' => [['ucp' => ['version' => '2026-08-25', 'payment_handlers' => []]]];
        yield 'no payment handlers' => [['ucp' => ['version' => '2026-08-25', 'services' => []]]];
        yield 'capabilities not an object' => [['ucp' => ['version' => '2026-08-25', 'services' => [], 'payment_handlers' => [], 'capabilities' => 'x']]];
        yield 'capability entries not a list' => [['ucp' => ['version' => '2026-08-25', 'services' => [], 'payment_handlers' => [], 'capabilities' => ['a.b' => ['x' => 1]]]]];
        yield 'capability name not a string' => [['ucp' => ['version' => '2026-08-25', 'services' => [], 'payment_handlers' => [], 'capabilities' => [['version' => '2026-08-25']]]]];
        yield 'entry not an object' => [['ucp' => ['version' => '2026-08-25', 'services' => [], 'payment_handlers' => [], 'capabilities' => ['a.b' => ['x']]]]];
        $valid['ucp']['capabilities']['dev.ucp.shopping.catalog.search'][0]['version'] = 'latest';
        yield 'entry version not a date' => [$valid];
    }

    #[DataProvider('malformed')]
    public function testMalformedProfilesAreRejected(mixed $document): void
    {
        self::assertNull(PlatformProfile::fromDocument($document));
    }

    /** @return iterable<string, array{string, mixed, bool}> */
    public static function bindings(): iterable
    {
        yield 'exact authority' => ['dev.ucp.shopping.catalog.search', 'https://ucp.dev/schemas/x.json', true];
        yield 'equal name' => ['dev.ucp', 'https://ucp.dev/x', true];
        yield 'subdomain authority' => ['com.example.shop.loyalty', 'https://shop.example.com/s.json', true];
        yield 'trailing dot host' => ['dev.ucp.x', 'https://ucp.dev./x', true];
        yield 'other host' => ['dev.ucp.shopping', 'https://evil.example/x.json', false];
        yield 'label not aligned' => ['dev.ucpx.shopping', 'https://ucp.dev/x', false];
        yield 'http' => ['dev.ucp.shopping', 'http://ucp.dev/x', false];
        yield 'userinfo' => ['dev.ucp.shopping', 'https://a@ucp.dev/x', false];
        yield 'password' => ['dev.ucp.shopping', 'https://:p@ucp.dev/x', false];
        yield 'IP literal' => ['1.2.3.4', 'https://4.3.2.1/x', false];
        yield 'IPv6 literal' => ['dev.ucp', 'https://[::1]/x', false];
        yield 'single label' => ['localhost.x', 'https://localhost/x', false];
        yield 'empty label' => ['dev.ucp', 'https://ucp..dev/x', false];
        yield 'not a string' => ['dev.ucp', null, false];
        yield 'not a URL' => ['dev.ucp', 'https:///x', false];
    }

    /** The default test profile as a platform serves it: JSON, decoded. */
    private static function decoded(): mixed
    {
        return json_decode(json_encode(Platform::profile(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
    }

    #[DataProvider('bindings')]
    public function testAuthorityBinding(string $name, mixed $schema, bool $bound): void
    {
        self::assertSame($bound, PlatformProfile::isAuthorityBound($name, $schema));
    }
}
