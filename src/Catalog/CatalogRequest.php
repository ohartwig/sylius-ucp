<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Catalog;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusUcp\Exception\InvalidUcpRequestException;
use Symfony\Component\HttpFoundation\Request;

/**
 * What the three catalog operations share: the JSON object body, `filters`,
 * and the `context` hints that pick the content language.
 *
 * Filter keys this shop does not know, and a price filter it cannot apply, are
 * reported as messages instead of pretending they narrowed the result.
 */
final readonly class CatalogRequest
{
    /** Bodies above this size are refused unparsed. */
    public const MAX_BODY_BYTES = 65536;

    /**
     * @param array<array-key, mixed>          $body
     * @param list<array<string, string>> $messages
     */
    private function __construct(
        public array $body,
        public CatalogFilter $filter,
        public string $localeCode,
        public array $messages,
    ) {
    }

    /**
     * @throws InvalidUcpRequestException
     */
    public static function read(Request $request, AgentContext $context): self
    {
        $content = $request->getContent();
        if (\strlen($content) > self::MAX_BODY_BYTES) {
            throw new InvalidUcpRequestException(\sprintf('The request body exceeds %d bytes.', self::MAX_BODY_BYTES));
        }

        try {
            $body = json_decode($content, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidUcpRequestException('The body is not valid JSON.');
        }
        if (!self::isObject($body)) {
            throw new InvalidUcpRequestException('The request body must be a JSON object.');
        }

        $requestContext = $body['context'] ?? [];
        if (!self::isObject($requestContext)) {
            throw new InvalidUcpRequestException('context must be an object.', '$.context');
        }

        $messages = [];
        $filter = self::filter($body['filters'] ?? [], $requestContext, (string) $context->channel->getBaseCurrency()?->getCode(), $messages);
        $language = \is_string($requestContext['language'] ?? null) ? $requestContext['language'] : null;

        return new self(
            $body,
            $filter,
            self::locale($context, $language ?? $request->headers->get('Accept-Language')),
            $messages,
        );
    }

    /**
     * A JSON object, or `{}` — which json_decode turns into an empty array.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    public static function isObject(mixed $value): bool
    {
        return \is_array($value) && ([] === $value || !array_is_list($value));
    }

    /**
     * @param array<array-key, mixed>          $requestContext
     * @param list<array<string, string>> $messages
     */
    private static function filter(mixed $filters, array $requestContext, string $currency, array &$messages): CatalogFilter
    {
        if (!self::isObject($filters)) {
            throw new InvalidUcpRequestException('filters must be an object.', '$.filters');
        }

        $categories = $filters['categories'] ?? [];
        if (!\is_array($categories) || !array_is_list($categories)) {
            throw new InvalidUcpRequestException('filters.categories must be an array of strings.', '$.filters.categories');
        }
        foreach ($categories as $index => $category) {
            if (!\is_string($category) || '' === $category || \strlen($category) > 255) {
                throw new InvalidUcpRequestException('Every category must be a non-empty string.', \sprintf('$.filters.categories[%d]', $index));
            }
        }
        if (\count($categories) > 20) {
            throw new InvalidUcpRequestException('At most 20 categories per filter.', '$.filters.categories');
        }

        [$min, $max] = self::price($filters['price'] ?? null, $requestContext, $currency, $messages);

        $unknown = array_diff(array_map('strval', array_keys($filters)), ['categories', 'price']);
        if ([] !== $unknown) {
            $messages[] = [
                'type' => 'warning',
                'code' => 'unsupported_filter',
                'content' => 'Not supported by this shop and ignored: '.implode(', ', $unknown).'.',
                'path' => '$.filters',
            ];
        }

        return new CatalogFilter(array_values(array_unique($categories)), $min, $max);
    }

    /**
     * @param array<array-key, mixed>          $requestContext
     * @param list<array<string, string>> $messages
     *
     * @return array{?int, ?int}
     */
    private static function price(mixed $price, array $requestContext, string $currency, array &$messages): array
    {
        if (null === $price) {
            return [null, null];
        }
        if (!self::isObject($price)) {
            throw new InvalidUcpRequestException('filters.price must be an object.', '$.filters.price');
        }
        foreach (['min', 'max'] as $bound) {
            if (\array_key_exists($bound, $price) && (!\is_int($price[$bound]) || $price[$bound] < 0)) {
                throw new InvalidUcpRequestException(\sprintf('filters.price.%s must be a non-negative integer in minor units.', $bound), '$.filters.price.'.$bound);
            }
        }
        /** @var int|null $min */
        $min = $price['min'] ?? null;
        /** @var int|null $max */
        $max = $price['max'] ?? null;

        $requested = \is_string($requestContext['currency'] ?? null) ? strtoupper($requestContext['currency']) : null;
        if (null !== $requested && $requested !== $currency) {
            // The filter is denominated in context.currency, and this shop
            // converts nothing (price_filter.json: MAY ignore, SHOULD say so).
            $messages[] = [
                'type' => 'warning',
                'code' => 'price_filter_ignored',
                'content' => \sprintf('Prices are in %s and are not converted; the price filter in %s was ignored.', $currency, $requested),
                'path' => '$.filters.price',
            ];

            return [null, null];
        }
        if (null === $requested) {
            $messages[] = [
                'type' => 'info',
                'code' => 'price_filter_currency',
                'content' => \sprintf('context.currency was not given; the price filter was applied in %s.', $currency),
                'path' => '$.filters.price',
            ];
        }

        return [$min, $max];
    }

    /**
     * The channel locale matching the requested language (context.language,
     * else Accept-Language): an exact tag first, then the primary language.
     * The channel's default when nothing matches.
     */
    private static function locale(AgentContext $context, ?string $language): string
    {
        $available = [] === $context->localeCodes ? [$context->localeCode] : $context->localeCodes;
        if (null === $language || '' === $language) {
            return $context->localeCode;
        }

        $wanted = [];
        foreach (explode(',', $language) as $index => $part) {
            $pieces = explode(';', trim($part));
            $tag = str_replace('-', '_', trim($pieces[0]));
            $quality = 1.0;
            foreach (\array_slice($pieces, 1) as $parameter) {
                if (1 === preg_match('/^\s*q=([01](?:\.\d{0,3})?)\s*$/', $parameter, $match)) {
                    $quality = (float) $match[1];
                }
            }
            if ('' !== $tag && '*' !== $tag && $quality > 0) {
                // Stable order for equal weights: earlier first.
                $wanted[] = [$tag, $quality, $index];
            }
        }
        usort($wanted, static fn (array $a, array $b): int => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        foreach ($wanted as [$tag]) {
            foreach ($available as $code) {
                if (0 === strcasecmp($code, $tag)) {
                    return $code;
                }
            }
            $primary = strtolower(explode('_', $tag)[0]);
            if (strtolower(explode('_', $context->localeCode)[0]) === $primary) {
                return $context->localeCode;
            }
            foreach ($available as $code) {
                if (strtolower(explode('_', $code)[0]) === $primary) {
                    return $code;
                }
            }
        }

        return $context->localeCode;
    }
}
