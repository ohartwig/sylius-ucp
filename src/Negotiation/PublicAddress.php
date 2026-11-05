<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Negotiation;

/**
 * Whether an IP address is an ordinary public unicast address: the only kind a
 * caller-supplied profile URL may make this shop connect to.
 *
 * UCP requires rejecting special-use addresses (RFC 6890): loopback,
 * link-local including the cloud metadata address, private and the other
 * reserved ranges. PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE miss several
 * of them (shared address space 100.64/10, benchmarking 198.18/15, IETF
 * protocol assignments 192.0.0/24, NAT64 and the IPv4-mapped and -compatible
 * IPv6 forms), so the ranges are listed here explicitly.
 */
final class PublicAddress
{
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '::/96', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64',
        '2001::/23', '2001:db8::/32', '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    public static function isPublic(string $address): bool
    {
        $binary = @inet_pton($address);
        if (false === $binary) {
            return false;
        }

        foreach (self::BLOCKED as $range) {
            [$network, $bits] = explode('/', $range);
            $networkBinary = (string) inet_pton($network);
            if (\strlen($networkBinary) === \strlen($binary) && self::inRange($binary, $networkBinary, (int) $bits)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $address, string $network, int $bits): bool
    {
        $bytes = intdiv($bits, 8);
        if (substr($address, 0, $bytes) !== substr($network, 0, $bytes)) {
            return false;
        }
        $remainder = $bits % 8;
        if (0 === $remainder) {
            return true;
        }
        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (\ord($address[$bytes]) & $mask) === (\ord($network[$bytes]) & $mask);
    }
}
