<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusUcp\Catalog\AgeRestriction;
use Koh\SyliusUcp\Catalog\CatalogProductSource;
use Koh\SyliusUcp\Catalog\CatalogRequest;
use Koh\SyliusUcp\Catalog\UcpProductMapper;
use Koh\SyliusUcp\Exception\InvalidUcpRequestException;
use Koh\SyliusUcp\Http\UcpResponses;
use Koh\SyliusUcp\Negotiation\Negotiator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /catalog/search` of the UCP REST binding (capability
 * `dev.ucp.shopping.catalog.search`).
 *
 * Negotiation runs first: without a usable platform profile that shares the
 * capability, nothing is searched.
 *
 * The route needs no credentials, so every bound comes from here: a search
 * needs a term or a filter, a page holds at most MAX_LIMIT products, and the
 * cursor cannot reach past MAX_RESULTS — a broad request pages through a
 * window, not the whole catalogue.
 */
final class CatalogSearchController
{
    public const CAPABILITY = 'dev.ucp.shopping.catalog.search';

    /** Page size when the request names none; the spec recommends 10. */
    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 50;

    /** Products reachable by paging through one search. */
    public const MAX_RESULTS = 200;

    /** Longer terms are cut: a name search is not a place for paragraphs. */
    private const MAX_QUERY_LENGTH = 100;

    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly CatalogProductSource $products,
        private readonly UcpProductMapper $mapper,
        private readonly Negotiator $negotiator,
        private readonly AgeRestriction $ageRestriction = new AgeRestriction(),
        private readonly string $version = '2026-08-25',
    ) {
    }

    #[Route('/ucp/v1/catalog/search', name: 'koh_ucp_catalog_search', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $context = $this->contextFactory->create();
        $storefront = $context->url('/');

        $refused = $this->negotiator->negotiate($request, self::CAPABILITY, $storefront);
        if (null !== $refused) {
            return $refused;
        }

        try {
            $input = CatalogRequest::read($request, $context);
            $body = $input->body;

            $query = $body['query'] ?? null;
            if (null !== $query && !\is_string($query)) {
                throw new InvalidUcpRequestException('query must be a string.', '$.query');
            }
            $query = mb_substr(trim((string) $query), 0, self::MAX_QUERY_LENGTH);
            // No term and no filter would be `LIKE '%%'`: the whole channel by
            // accident rather than by request.
            if ('' === $query && $input->filter->isEmpty()) {
                throw new InvalidUcpRequestException('A search needs a non-empty query or at least one filter (categories, price).', '$.query');
            }

            $pagination = $body['pagination'] ?? [];
            if (!CatalogRequest::isObject($pagination)) {
                throw new InvalidUcpRequestException('pagination must be an object.', '$.pagination');
            }
            $limit = $pagination['limit'] ?? self::DEFAULT_LIMIT;
            if (!\is_int($limit) || $limit < 1) {
                throw new InvalidUcpRequestException('pagination.limit must be a positive integer.', '$.pagination.limit');
            }
            $limit = min($limit, self::MAX_LIMIT);

            $offset = 0;
            if (\array_key_exists('cursor', $pagination)) {
                $offset = $this->decodeCursor($pagination['cursor'])
                    ?? throw new InvalidUcpRequestException('pagination.cursor is not a cursor this shop issued.', '$.pagination.cursor');
            }
        } catch (InvalidUcpRequestException $e) {
            return UcpResponses::error(400, $this->version, self::CAPABILITY, 'invalid_request', $e->getMessage(), $e->path);
        }

        // decodeCursor() keeps the offset below MAX_RESULTS, so at least one row remains.
        $limit = min($limit, self::MAX_RESULTS - $offset);

        // One row more than the page tells whether there is a next one.
        $found = $this->products->search($context->channel, $input->localeCode, $query, $offset, $limit + 1, $input->filter);
        $hasNext = \count($found) > $limit && $offset + $limit < self::MAX_RESULTS;

        $products = [];
        foreach (\array_slice($found, 0, $limit) as $product) {
            $mapped = $this->mapper->map($product, $context, $input->localeCode, $input->filter);
            if (null !== $mapped) {
                $products[] = $mapped;
            }
        }

        $payload = [
            'ucp' => [
                'version' => $this->version,
                'status' => 'success',
                'capabilities' => [self::CAPABILITY => [['version' => $this->version]]],
            ],
            'products' => $products,
            'pagination' => $hasNext
                ? ['has_next_page' => true, 'cursor' => $this->encodeCursor($offset + $limit)]
                : ['has_next_page' => false],
        ];

        $messages = $input->messages;
        $notice = [] === $products ? null : $this->ageRestriction->message('$.products');
        if (null !== $notice) {
            $messages[] = $notice;
        }
        if ([] !== $messages) {
            $payload['messages'] = $messages;
        }

        return UcpResponses::json($payload);
    }

    private function encodeCursor(int $offset): string
    {
        return rtrim(strtr(base64_encode('o:'.$offset), '+/', '-_'), '=');
    }

    private function decodeCursor(mixed $cursor): ?int
    {
        if (!\is_string($cursor)) {
            return null;
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (false === $decoded || 1 !== preg_match('/^o:(0|[1-9]\d{0,3})$/', $decoded, $match)) {
            return null;
        }
        $offset = (int) $match[1];

        return $offset < self::MAX_RESULTS ? $offset : null;
    }
}
