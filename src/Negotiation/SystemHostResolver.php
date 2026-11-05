<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Negotiation;

/**
 * The system resolver: A and AAAA records of a name.
 *
 * @codeCoverageIgnore thin wrapper over DNS; exercised only against real resolvers
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $addresses = [];
        foreach ([\DNS_A => 'ip', \DNS_AAAA => 'ipv6'] as $type => $field) {
            $records = @dns_get_record($host, $type);
            foreach (false === $records ? [] : $records as $record) {
                if (\is_string($record[$field] ?? null)) {
                    $addresses[] = $record[$field];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
