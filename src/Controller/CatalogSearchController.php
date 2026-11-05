<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusUcp\Catalog\CatalogProductSource;
use Koh\SyliusUcp\Catalog\UcpProductMapper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `POST /catalog/search` of the UCP REST binding (capability
 * `dev.ucp.shopping.catalog.search`).
 *
 * The route needs no credentials, so every bound comes from here: a search term
 * is required, a page holds at most MAX_LIMIT products, and the cursor cannot
 * reach past MAX_RESULTS — a broad term pages through a window, not the whole
 * catalogue.
 *
 * `filters` and `context` are accepted and not applied; the response says so in
 * a warning instead of pretending the result was filtered.
 */
final class CatalogSearchController
{
    public const CAPABILITY = 'dev.ucp.shopping.catalog.search';

    /** Page size when the request names none; the spec recommends 10. */
    public const DEFAULT_LIMIT = 10;

    public const MAX_LIMIT = 50;

    /** Products reachable by paging through one search term. */
    public const MAX_RESULTS = 200;

    /** Longer terms are cut: a name search is not a place for paragraphs. */
    private const MAX_QUERY_LENGTH = 100;

    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly CatalogProductSource $products,
        private readonly UcpProductMapper $mapper,
        private readonly string $version = '2026-08-25',
    ) {
    }

    #[Route('/ucp/v1/catalog/search', name: 'koh_ucp_catalog_search', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 16, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error('the body is not valid JSON');
        }
        if (!\is_array($body) || ([] !== $body && array_is_list($body))) {
            return $this->error('a JSON object with a query is required');
        }

        $query = \is_string($body['query'] ?? null) ? mb_substr(trim($body['query']), 0, self::MAX_QUERY_LENGTH) : '';
        // An empty term would be `LIKE '%%'`: every product in the channel.
        if ('' === $query) {
            return $this->error('query is required and must be a non-empty string', '$.query');
        }

        $pagination = \is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
        $limit = $pagination['limit'] ?? self::DEFAULT_LIMIT;
        if (!\is_int($limit) || $limit < 1) {
            return $this->error('pagination.limit must be a positive integer', '$.pagination.limit');
        }
        $limit = min($limit, self::MAX_LIMIT);

        $offset = 0;
        if (isset($pagination['cursor'])) {
            $offset = $this->decodeCursor($pagination['cursor']);
            if (null === $offset) {
                return $this->error('pagination.cursor is not a cursor this shop issued', '$.pagination.cursor');
            }
        }
        // decodeCursor() keeps the offset below MAX_RESULTS, so at least one row remains.
        $limit = min($limit, self::MAX_RESULTS - $offset);

        $context = $this->contextFactory->create();
        // One row more than the page tells whether there is a next one.
        $found = $this->products->search($context->channel, $context->localeCode, $query, $offset, $limit + 1);
        $hasNext = \count($found) > $limit && $offset + $limit < self::MAX_RESULTS;

        $products = [];
        foreach (\array_slice($found, 0, $limit) as $product) {
            $mapped = $this->mapper->map($product, $context);
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

        $ignored = array_values(array_filter(['filters', 'context'], static fn (string $key): bool => isset($body[$key])));
        if ([] !== $ignored) {
            $payload['messages'] = [[
                'type' => 'warning',
                'code' => 'unsupported_input',
                'content' => 'Not supported by this shop and ignored: '.implode(', ', $ignored).'.',
            ]];
        }

        return new JsonResponse($payload);
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
        if (false === $decoded || 1 !== preg_match('/^o:(\d{1,4})$/', $decoded, $match)) {
            return null;
        }
        $offset = (int) $match[1];

        return $offset < self::MAX_RESULTS ? $offset : null;
    }

    /** A request this route cannot run: HTTP 400 with the UCP error response. */
    private function error(string $content, ?string $path = null): JsonResponse
    {
        $message = ['type' => 'error', 'code' => 'invalid_request', 'content' => $content, 'severity' => 'unrecoverable'];
        if (null !== $path) {
            $message['path'] = $path;
        }

        return new JsonResponse([
            'ucp' => [
                'version' => $this->version,
                'status' => 'error',
                'capabilities' => [self::CAPABILITY => [['version' => $this->version]]],
            ],
            'messages' => [$message],
        ], JsonResponse::HTTP_BAD_REQUEST);
    }
}
