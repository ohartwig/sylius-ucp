<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Negotiation;

/**
 * The parts of a caller's UCP platform profile that negotiation needs: its
 * protocol version and, per capability name, the versions it supports.
 *
 * Built only from a document with the shape UCP requires of a profile
 * (`ucp.version`, `ucp.services`, `ucp.payment_handlers`, an optional
 * `ucp.capabilities` registry of versioned entries). Anything else is
 * `profile_malformed`, which the caller reports instead of guessing.
 *
 * A capability entry counts only when its `schema` URL passes UCP's authority
 * binding (overview, "Authority Binding"): the reversed host of the schema URL
 * must equal the capability name or be a label-aligned prefix of it. The spec
 * asks businesses to apply that check to platform profiles as defence in depth
 * and to treat a failing entity as not present.
 */
final readonly class PlatformProfile
{
    private const VERSION_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * @param array<string, list<string>> $capabilities name => versions
     */
    private function __construct(
        public string $version,
        private array $capabilities,
    ) {
    }

    public static function fromDocument(mixed $document): ?self
    {
        if (!\is_array($document) || !\is_array($document['ucp'] ?? null)) {
            return null;
        }
        $ucp = $document['ucp'];

        $version = $ucp['version'] ?? null;
        if (!\is_string($version) || 1 !== preg_match(self::VERSION_PATTERN, $version)) {
            return null;
        }
        // Both registries are required, even when empty. JSON `{}` decodes to
        // an empty PHP array, so an array of either kind is accepted here.
        if (!\is_array($ucp['services'] ?? null) || !\is_array($ucp['payment_handlers'] ?? null)) {
            return null;
        }

        $registry = $ucp['capabilities'] ?? [];
        if (!\is_array($registry)) {
            return null;
        }

        $capabilities = [];
        foreach ($registry as $name => $entries) {
            if (!\is_string($name) || !\is_array($entries) || !array_is_list($entries)) {
                return null;
            }
            foreach ($entries as $entry) {
                $entryVersion = \is_array($entry) ? ($entry['version'] ?? null) : null;
                if (!\is_string($entryVersion) || 1 !== preg_match(self::VERSION_PATTERN, $entryVersion)) {
                    return null;
                }
                if (self::isAuthorityBound($name, $entry['schema'] ?? null)) {
                    $capabilities[$name][] = $entryVersion;
                }
            }
        }

        return new self($version, $capabilities);
    }

    /**
     * Restores a profile from toArray(), as the profile cache keeps it.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $version = $data['version'] ?? null;
        $capabilities = $data['capabilities'] ?? null;
        if (!\is_string($version) || !\is_array($capabilities)) {
            return null;
        }

        $restored = [];
        foreach ($capabilities as $name => $versions) {
            if (!\is_string($name) || !\is_array($versions)) {
                return null;
            }
            $restored[$name] = array_values(array_filter($versions, 'is_string'));
        }

        return new self($version, $restored);
    }

    /** @return array{version: string, capabilities: array<string, list<string>>} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'capabilities' => $this->capabilities];
    }

    /**
     * Capability intersection for one capability: both parties must list the
     * exact same version. UCP establishes compatibility by version equality,
     * never by date order.
     */
    public function supports(string $capability, string $version): bool
    {
        return \in_array($version, $this->capabilities[$capability] ?? [], true);
    }

    /**
     * UCP overview, "Derivation algorithm": https, no userinfo, a registered
     * domain of at least two labels, and the reversed host equal to the name or
     * a label-aligned prefix of it.
     */
    public static function isAuthorityBound(string $name, mixed $schemaUrl): bool
    {
        if (!\is_string($schemaUrl)) {
            return false;
        }
        $parts = parse_url($schemaUrl);
        if (!\is_array($parts) || 'https' !== strtolower($parts['scheme'] ?? '')
            || isset($parts['user']) || isset($parts['pass'])
        ) {
            return false;
        }

        $host = rtrim(strtolower($parts['host'] ?? ''), '.');
        // IP literals are not authorities.
        if (false !== filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP)) {
            return false;
        }
        $labels = explode('.', $host);
        if (\count($labels) < 2 || \in_array('', $labels, true)) {
            return false;
        }

        $authority = implode('.', array_reverse($labels));

        return $name === $authority || str_starts_with($name, $authority.'.');
    }
}
