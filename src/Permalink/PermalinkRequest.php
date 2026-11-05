<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Permalink;

/**
 * A UCP shopping permalink, parsed and validated (spec "permalink": Compact
 * Item Path, Item ID Token, Query Processing, Destination Preference, Merge
 * Rules).
 *
 * Every part of the URL is untrusted. What comes out of here is safe to act
 * on: decoded item identifiers without control characters, positive
 * quantities capped at MAX_QUANTITY, a `continue_to` reduced to a canonical
 * same-origin path, and the query parameters that may travel on to the
 * destination. A request this cannot interpret safely is `malformed` and
 * carries nothing else.
 *
 * Query parameters are classified as the spec says: `continue_to`; UCP field
 * paths (root is a top-level Cart or Checkout field); everything else. This
 * shop applies no UCP field path to the buyer's cart — buyer details, context
 * and discounts are entered in the shop's own checkout — so they are consumed
 * and dropped, except `attribution/*`, which is rewritten to its bare name for
 * the storefront's analytics. Non-UCP parameters pass through unless their
 * name looks like a credential.
 */
final readonly class PermalinkRequest
{
    public const MAX_ITEMS = 20;

    public const MAX_QUANTITY = 99;

    public const MAX_PARAMETERS = 50;

    public const MAX_URL_LENGTH = 4096;

    private const MAX_VALUE_LENGTH = 512;

    /** Top-level fields of the UCP Cart and Checkout schemas (2026-08-25). */
    private const UCP_ROOTS = [
        'actions', 'attribution', 'buyer', 'context', 'continue_url', 'currency', 'expires_at', 'id',
        'line_items', 'links', 'messages', 'order', 'payment', 'policies', 'signals', 'status', 'totals', 'ucp',
    ];

    private const SENSITIVE_NAME = '/token|secret|passw|pwd|key|auth|session|cookie|credential|signature|mandate|card|cvc|iban/i';

    /**
     * @param list<array{string, int}>         $items      identifier, quantity
     * @param list<array{string, string}>     $passThrough name, value
     */
    private function __construct(
        public bool $malformed,
        public array $items = [],
        public ?string $continueTo = null,
        public array $passThrough = [],
        public bool $capped = false,
    ) {
    }

    public static function parse(string $items, string $queryString, int $urlLength = 0): self
    {
        if ($urlLength > self::MAX_URL_LENGTH) {
            return new self(true);
        }

        $parsedItems = self::items($items);
        if (null === $parsedItems) {
            return new self(true);
        }

        $query = self::query($queryString);
        if (null === $query) {
            return new self(true);
        }

        $continueTo = null;
        $pointers = [];
        $passThrough = [];
        $continueSeen = false;
        foreach ($query as [$name, $value]) {
            if ('continue_to' === $name) {
                // Two destination preferences are ambiguous: neither wins.
                if ($continueSeen) {
                    return new self(true);
                }
                $continueSeen = true;
                $continueTo = self::destination($value);
                continue;
            }

            $pointer = '/'.ltrim($name, '/');
            $root = str_replace(['~1', '~0'], ['/', '~'], explode('/', $pointer)[1]);
            if (\in_array($root, self::UCP_ROOTS, true)) {
                // Two writes to one pointer are ambiguous (Merge Rules).
                if (isset($pointers[$pointer])) {
                    return new self(true);
                }
                $pointers[$pointer] = true;

                $rest = substr($pointer, \strlen('/attribution/'));
                if ('attribution' === $root && 1 === preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $rest) && !self::sensitive($rest)) {
                    $passThrough[] = [$rest, $value];
                }
                continue;
            }

            if (!self::sensitive($name) && 1 === preg_match('/^[A-Za-z0-9_.\-\[\]]{1,64}$/', $name)) {
                $passThrough[] = [$name, $value];
            }
        }

        return new self(false, $parsedItems[0], $continueTo, $passThrough, $parsedItems[1]);
    }

    /**
     * @return array{list<array{string, int}>, bool}|null items and whether a quantity was capped
     */
    private static function items(string $path): ?array
    {
        if ('' === $path) {
            return [[], false];
        }

        $pairs = explode(',', $path);
        if (\count($pairs) > self::MAX_ITEMS) {
            return null;
        }

        $items = [];
        $capped = false;
        foreach ($pairs as $pair) {
            $parts = explode(':', $pair);
            if (2 !== \count($parts) || 1 !== preg_match('/^[1-9]\d{0,8}$/', $parts[1])) {
                return null;
            }
            $id = self::decodeToken($parts[0]);
            if (null === $id) {
                return null;
            }
            $quantity = (int) $parts[1];
            if ($quantity > self::MAX_QUANTITY) {
                $quantity = self::MAX_QUANTITY;
                $capped = true;
            }
            $items[] = [$id, $quantity];
        }

        return [$items, $capped];
    }

    /**
     * Raw token (`[A-Za-z0-9._-]+`) or `~` + canonical base64url without
     * padding of a UTF-8 identifier without control characters.
     */
    public static function decodeToken(string $token): ?string
    {
        if (1 === preg_match('/^[A-Za-z0-9._-]{1,255}$/', $token)) {
            return $token;
        }
        if (!str_starts_with($token, '~') || 1 !== preg_match('/^[A-Za-z0-9_-]{1,340}$/', substr($token, 1))) {
            return null;
        }

        $encoded = substr($token, 1);
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        // Canonical only: re-encoding must give back exactly the input.
        if (false === $decoded || '' === $decoded || rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') !== $encoded) {
            return null;
        }
        if (!mb_check_encoding($decoded, 'UTF-8') || 1 === preg_match('/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', $decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * Name/value pairs, decoded once, in order — PHP's own parser would merge
     * duplicates and rewrite names, and both matter here.
     *
     * @return list<array{string, string}>|null
     */
    private static function query(string $queryString): ?array
    {
        if ('' === $queryString) {
            return [];
        }

        $pairs = [];
        foreach (explode('&', $queryString) as $part) {
            if ('' === $part) {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);
            if ('' === $name || self::hasControl($name) || self::hasControl($value) || \strlen($value) > self::MAX_VALUE_LENGTH
                || !mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')
            ) {
                return null;
            }
            $pairs[] = [$name, $value];
        }

        return \count($pairs) > self::MAX_PARAMETERS ? null : $pairs;
    }

    /**
     * `continue_to` validated in the order the spec gives: (decoded once by
     * the query parser) a single leading slash, no scheme, backslash,
     * whitespace or control character; dot segments removed; the result
     * re-encoded. Null when any step fails: the default destination applies.
     */
    public static function destination(string $value): ?string
    {
        if (!str_starts_with($value, '/') || str_starts_with($value, '//')
            || 1 === preg_match('/[\\\\\s\x00-\x1F\x7F]/', $value)
            || 1 === preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $value)
        ) {
            return null;
        }

        $fragmentless = explode('#', $value, 2)[0];
        $parts = explode('?', $fragmentless, 2);
        $path = $parts[0];
        $query = $parts[1] ?? null;

        $segments = [];
        foreach (\array_slice(explode('/', $path), 1) as $segment) {
            if ('..' === $segment) {
                array_pop($segments);
            } elseif ('.' !== $segment) {
                $segments[] = $segment;
            }
        }
        // A trailing dot segment leaves a directory: `/a/.` is `/a/`.
        if (\in_array(basename('/'.$path), ['.', '..'], true)) {
            $segments[] = '';
        }

        $canonical = '/'.implode('/', array_map(static fn (string $segment): string => self::encode($segment, ''), $segments));
        if (str_starts_with($canonical, '//')) {
            return null;
        }

        if (null !== $query && '' !== $query) {
            $canonical .= '?'.self::encode($query, '/?&=');
        }

        return $canonical;
    }

    /** A stable fingerprint of what this permalink adds, for replay detection. */
    public function fingerprint(): string
    {
        return hash('xxh128', serialize($this->items));
    }

    /**
     * Percent-encodes every byte RFC 3986 does not allow in a path segment
     * (plus `$extra`), leaving valid escapes as they are: the value was
     * decoded once already, and a second pass must not change what it means.
     */
    private static function encode(string $value, string $extra): string
    {
        return (string) preg_replace_callback(
            '/%(?![0-9A-Fa-f]{2})|[^A-Za-z0-9\-._~!$\'()*+,;:@%'.preg_quote($extra, '/').']/',
            static fn (array $match): string => rawurlencode($match[0]),
            $value,
        );
    }

    private static function sensitive(string $name): bool
    {
        return 1 === preg_match(self::SENSITIVE_NAME, $name);
    }

    private static function hasControl(string $value): bool
    {
        return 1 === preg_match('/[\x00-\x1F\x7F]/', $value);
    }
}
