<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Negotiation;

/**
 * Reads the platform profile URL from a `UCP-Agent` header.
 *
 * UCP (overview, "Platform Advertisement on Request") requires the header in
 * Dictionary Structured Field syntax (RFC 8941, section 3.2) with the profile
 * URL as the String member `profile`:
 *
 *     UCP-Agent: profile="https://agent.example/profiles/shopping-agent.json"
 *
 * This is a parser for that grammar, not a pattern match: other members and
 * parameters are allowed and skipped, and anything RFC 8941 says must fail
 * parsing (a trailing comma, a bare URL, an unterminated string) yields null,
 * which the caller reports as `invalid_profile_url`.
 */
final class UcpAgentHeader
{
    /** Longer headers are refused unparsed; a profile URL is short. */
    public const MAX_LENGTH = 4096;

    private string $input = '';

    private int $position = 0;

    private function __construct()
    {
    }

    /**
     * The `profile` member as a string, or null when the header is not a valid
     * Dictionary or has no String member of that name.
     */
    public static function profile(string $header): ?string
    {
        if (\strlen($header) > self::MAX_LENGTH) {
            return null;
        }

        $dictionary = (new self())->parseDictionary($header);
        $profile = null === $dictionary ? null : ($dictionary['profile'] ?? null);

        return \is_string($profile) ? $profile : null;
    }

    /** @return array<string, mixed>|null */
    private function parseDictionary(string $header): ?array
    {
        // RFC 8941 4.2: discard leading and trailing SP.
        $this->input = trim($header, ' ');
        $this->position = 0;

        $dictionary = [];
        while (!$this->atEnd()) {
            $key = $this->parseKey();
            if (null === $key) {
                return null;
            }

            if ('=' === $this->peek()) {
                ++$this->position;
                $value = '(' === $this->peek() ? $this->parseInnerList() : $this->parseBareItem();
                if (null === $value) {
                    return null;
                }
            } else {
                $value = true;
            }
            if (!$this->skipParameters()) {
                return null;
            }
            // A later duplicate overwrites an earlier one (RFC 8941 4.2.2).
            $dictionary[$key] = $value;

            $this->skipOws();
            if ($this->atEnd()) {
                return $dictionary;
            }
            if (',' !== $this->peek()) {
                return null;
            }
            ++$this->position;
            $this->skipOws();
            if ($this->atEnd()) {
                // A trailing comma fails parsing.
                return null;
            }
        }

        return $dictionary;
    }

    private function parseKey(): ?string
    {
        if (1 !== preg_match('/\G[a-z*][a-z0-9_\-.*]*/', $this->input, $match, 0, $this->position)) {
            return null;
        }
        $this->position += \strlen($match[0]);

        return $match[0];
    }

    /** @return list<mixed>|null */
    private function parseInnerList(): ?array
    {
        ++$this->position;
        $items = [];
        while (!$this->atEnd()) {
            $this->skipSp();
            if (')' === $this->peek()) {
                ++$this->position;

                return $this->skipParameters() ? $items : null;
            }
            $item = $this->parseBareItem();
            if (null === $item || !$this->skipParameters()) {
                return null;
            }
            $items[] = $item;
            if (!\in_array($this->peek(), [' ', ')'], true)) {
                return null;
            }
        }

        return null;
    }

    private function skipParameters(): bool
    {
        while (';' === $this->peek()) {
            ++$this->position;
            $this->skipSp();
            if (null === $this->parseKey()) {
                return false;
            }
            if ('=' === $this->peek()) {
                ++$this->position;
                if (null === $this->parseBareItem()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Strings come back as PHP strings; tokens and byte sequences, which are
     * not Strings in RFC 8941's sense, as one-member arrays.
     *
     * @return string|int|float|bool|array{token: string}|array{bytes: string}|null
     */
    private function parseBareItem(): string|int|float|bool|array|null
    {
        $char = $this->peek();

        return match (true) {
            '"' === $char => $this->parseString(),
            '?' === $char => $this->parseBoolean(),
            ':' === $char => $this->parseByteSequence(),
            '-' === $char || ctype_digit($char) => $this->parseNumber(),
            '*' === $char || ctype_alpha($char) => $this->parseToken(),
            default => null,
        };
    }

    private function parseString(): ?string
    {
        ++$this->position;
        $value = '';
        while (!$this->atEnd()) {
            $char = $this->input[$this->position++];
            if ('\\' === $char) {
                $next = $this->peek();
                ++$this->position;
                if ('"' !== $next && '\\' !== $next) {
                    return null;
                }
                $value .= $next;
            } elseif ('"' === $char) {
                return $value;
            } elseif (\ord($char) < 0x20 || \ord($char) > 0x7E) {
                return null;
            } else {
                $value .= $char;
            }
        }

        return null;
    }

    private function parseBoolean(): ?bool
    {
        $value = substr($this->input, $this->position, 2);
        if ('?0' !== $value && '?1' !== $value) {
            return null;
        }
        $this->position += 2;

        return '?1' === $value;
    }

    /** @return array{bytes: string}|null */
    private function parseByteSequence(): ?array
    {
        if (1 !== preg_match('/\G:([A-Za-z0-9+\/=]*):/', $this->input, $match, 0, $this->position)) {
            return null;
        }
        $this->position += \strlen($match[0]);

        return ['bytes' => $match[1]];
    }

    private function parseNumber(): int|float|null
    {
        if (1 !== preg_match('/\G-?(\d{1,15})(\.\d{1,3})?/', $this->input, $match, 0, $this->position)) {
            return null;
        }
        if (isset($match[2]) && \strlen($match[1]) > 12) {
            return null;
        }
        $this->position += \strlen($match[0]);

        return isset($match[2]) ? (float) $match[0] : (int) $match[0];
    }

    /** @return array{token: string} */
    private function parseToken(): array
    {
        // Only called on an ALPHA or `*`, so the pattern always matches.
        preg_match('/\G[A-Za-z*][!#$%&\'*+\-.^_`|~:\/0-9A-Za-z]*/', $this->input, $match, 0, $this->position);
        $token = $match[0] ?? '';
        $this->position += \strlen($token);

        return ['token' => $token];
    }

    private function skipOws(): void
    {
        while (\in_array($this->peek(), [' ', "\t"], true)) {
            ++$this->position;
        }
    }

    private function skipSp(): void
    {
        while (' ' === $this->peek()) {
            ++$this->position;
        }
    }

    private function peek(): string
    {
        return $this->input[$this->position] ?? '';
    }

    private function atEnd(): bool
    {
        return $this->position >= \strlen($this->input);
    }
}
