<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Negotiation;

use Koh\SyliusUcp\Negotiation\UcpAgentHeader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `UCP-Agent` is an RFC 8941 Dictionary; the profile URL is its String member
 * `profile`.
 */
#[CoversClass(UcpAgentHeader::class)]
final class UcpAgentHeaderTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function valid(): iterable
    {
        $url = 'https://agent.example/profiles/shopping-agent.json';

        yield 'the spec example' => ['profile="'.$url.'"', $url];
        yield 'surrounding spaces' => ['  profile="'.$url.'"  ', $url];
        yield 'other members first' => ['version=1, sig=?1, profile="'.$url.'"', $url];
        yield 'other members after, with tabs' => ["profile=\"$url\",\tfoo=bar", $url];
        yield 'parameters on the member' => ['profile="'.$url.'";v=2;x', $url];
        yield 'inner list and byte sequence' => ['a=(1 "two" three);p, b=:aGk=:, c=-1.5, d=*tok/en, profile="'.$url.'"', $url];
        yield 'a later duplicate wins' => ['profile="https://old.example/", profile="'.$url.'"', $url];
        yield 'escaped quote and backslash' => ['profile="https://a.example/\"x\\\\y"', 'https://a.example/"x\y'];
        yield 'empty inner list' => ['a=(), profile="'.$url.'"', $url];
        yield 'boolean member' => ['flag, profile="'.$url.'"', $url];
    }

    #[DataProvider('valid')]
    public function testTheProfileMemberIsRead(string $header, string $expected): void
    {
        self::assertSame($expected, UcpAgentHeader::profile($header));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        yield 'empty' => [''];
        yield 'bare URL' => ['https://agent.example/profile.json'];
        yield 'profile as token' => ['profile=https://agent.example/'];
        yield 'profile as integer' => ['profile=5'];
        yield 'profile as boolean' => ['profile'];
        yield 'profile as inner list' => ['profile=("https://agent.example/")'];
        yield 'unterminated string' => ['profile="https://agent.example/'];
        yield 'trailing comma' => ['profile="https://agent.example/",'];
        yield 'uppercase key' => ['Profile="https://agent.example/"'];
        yield 'garbage after member' => ['profile="https://agent.example/" x'];
        yield 'bad escape' => ['profile="a\b"'];
        yield 'non-ASCII in string' => ["profile=\"https://agent.example/\xC3\xA4\""];
        yield 'control character in string' => ["profile=\"a\x01\""];
        yield 'bad parameter' => ['profile="x";=1'];
        yield 'bad parameter value' => ['profile="x";a=@'];
        yield 'bad boolean' => ['a=?2, profile="x"'];
        yield 'bad byte sequence' => ['a=:abc, profile="x"'];
        yield 'number too long' => ['a=1234567890123456, profile="x"'];
        yield 'decimal too long' => ['a=1234567890123.5, profile="x"'];
        yield 'unterminated inner list' => ['a=(1 2'];
        yield 'inner list without space' => ['a=(1"x"), profile="x"'];
        yield 'bad item in inner list' => ['a=(@), profile="x"'];
        yield 'bad bare item' => ['a=@'];
        yield 'inner list cut off' => ['a=('];
        yield 'minus without digits' => ['a=-x, profile="x"'];
        yield 'too long' => ['profile="https://agent.example/'.str_repeat('a', UcpAgentHeader::MAX_LENGTH).'"'];
        yield 'no member at all' => ['   '];
    }

    #[DataProvider('invalid')]
    public function testAnythingElseIsNoProfile(string $header): void
    {
        self::assertNull(UcpAgentHeader::profile($header));
    }
}
