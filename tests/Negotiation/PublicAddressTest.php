<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Negotiation;

use Koh\SyliusUcp\Negotiation\PublicAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** RFC 6890 special-use ranges are never a profile host. */
#[CoversClass(PublicAddress::class)]
final class PublicAddressTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function addresses(): iterable
    {
        yield 'public v4' => ['93.184.215.14', true];
        yield 'public v6' => ['2606:4700:4700::1111', true];
        yield 'just below 10/8' => ['9.255.255.255', true];
        yield 'just above 100.64/10' => ['100.128.0.1', true];
        yield 'just above 172.16/12' => ['172.32.0.1', true];
        yield 'just below 198.18/15' => ['198.17.255.255', true];
        yield 'just above 198.18/15' => ['198.20.0.1', true];
        yield 'loopback' => ['127.0.0.1', false];
        yield 'this network' => ['0.1.2.3', false];
        yield 'private 10' => ['10.1.2.3', false];
        yield 'private 172.16' => ['172.16.0.1', false];
        yield 'private 172.31' => ['172.31.255.254', false];
        yield 'private 192.168' => ['192.168.1.1', false];
        yield 'shared 100.64' => ['100.64.0.1', false];
        yield 'shared 100.127' => ['100.127.255.254', false];
        yield 'metadata' => ['169.254.169.254', false];
        yield 'benchmarking' => ['198.19.0.1', false];
        yield 'protocol assignments' => ['192.0.0.8', false];
        yield 'documentation' => ['203.0.113.5', false];
        yield 'multicast' => ['224.0.0.1', false];
        yield 'reserved' => ['240.0.0.1', false];
        yield 'broadcast' => ['255.255.255.255', false];
        yield 'v6 loopback' => ['::1', false];
        yield 'v6 unspecified' => ['::', false];
        yield 'v4-mapped loopback' => ['::ffff:127.0.0.1', false];
        yield 'v4-mapped public' => ['::ffff:93.184.215.14', false];
        yield 'NAT64' => ['64:ff9b::a00:1', false];
        yield 'ULA' => ['fd00::1', false];
        yield 'link-local v6' => ['fe80::1', false];
        yield 'site-local v6' => ['fec0::1', false];
        yield 'v6 documentation' => ['2001:db8::1', false];
        yield '6to4' => ['2002::1', false];
        yield 'Teredo' => ['2001::1', false];
        yield 'v6 multicast' => ['ff02::1', false];
        yield 'not an address' => ['example.com', false];
    }

    #[DataProvider('addresses')]
    public function testOnlyOrdinaryPublicAddressesPass(string $address, bool $public): void
    {
        self::assertSame($public, PublicAddress::isPublic($address));
    }
}
