<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Controller;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusUcp\Catalog\AgeRestriction;
use Koh\SyliusUcp\Catalog\CatalogProductSource;
use Koh\SyliusUcp\Catalog\CatalogRequest;
use Koh\SyliusUcp\Catalog\ProductDetail;
use Koh\SyliusUcp\Catalog\UcpProductMapper;
use Koh\SyliusUcp\Exception\InvalidUcpRequestException;
use Koh\SyliusUcp\Http\UcpResponses;
use Koh\SyliusUcp\Negotiation\Negotiator;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Capability `dev.ucp.shopping.catalog.lookup` over REST: `POST
 * /catalog/lookup` (batch) and `POST /catalog/product` (one product in
 * detail). The spec requires both routes once the capability is declared.
 *
 * Identifiers are product codes and variant codes. A variant code resolves to
 * that variant (`match: exact`); a product code to its featured variant, the
 * first available one (`match: featured`). Every returned variant carries the
 * request identifiers that led to it, a product is returned once however many
 * identifiers name it, and identifiers that resolve to nothing are listed in
 * `not_found` info messages. Lookup always answers HTTP 200 unless the request
 * itself is invalid.
 */
final class CatalogLookupController
{
    public const CAPABILITY = 'dev.ucp.shopping.catalog.lookup';

    /** Batch ceiling; larger batches get `request_too_large` (the spec asks for at least 10). */
    public const MAX_IDS = 50;

    private const MAX_ID_LENGTH = 255;

    public function __construct(
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly CatalogProductSource $products,
        private readonly UcpProductMapper $mapper,
        private readonly Negotiator $negotiator,
        private readonly AgeRestriction $ageRestriction = new AgeRestriction(),
        private readonly string $version = '2026-08-25',
    ) {
    }

    #[Route('/ucp/v1/catalog/lookup', name: 'koh_ucp_catalog_lookup', methods: ['POST'])]
    public function lookup(Request $request): JsonResponse
    {
        $context = $this->contextFactory->create();
        $refused = $this->negotiator->negotiate($request, self::CAPABILITY, $context->url('/'));
        if (null !== $refused) {
            return $refused;
        }

        try {
            $input = CatalogRequest::read($request, $context);
            $ids = $input->body['ids'] ?? null;
            if (!\is_array($ids) || [] === $ids || !array_is_list($ids)) {
                throw new InvalidUcpRequestException('ids must be a non-empty array of identifiers.', '$.ids');
            }
            foreach ($ids as $index => $id) {
                if (!\is_string($id) || '' === $id || \strlen($id) > self::MAX_ID_LENGTH) {
                    throw new InvalidUcpRequestException(\sprintf('Every identifier must be a non-empty string of at most %d bytes.', self::MAX_ID_LENGTH), \sprintf('$.ids[%d]', $index));
                }
            }
        } catch (InvalidUcpRequestException $e) {
            return $this->invalid($e);
        }

        // Duplicates are folded before the limit applies: the limit bounds the
        // work, and a repeated identifier is no extra work.
        $ids = array_values(array_unique($ids));
        if (\count($ids) > self::MAX_IDS) {
            return UcpResponses::error(400, $this->version, self::CAPABILITY, 'request_too_large', \sprintf('At most %d identifiers per lookup.', self::MAX_IDS), '$.ids');
        }

        $found = [];
        foreach ($this->products->findByIdentifiers($context->channel, $ids) as $product) {
            $mapped = $this->mapper->map($product, $context, $input->localeCode, $input->filter);
            if (null !== $mapped) {
                $found[] = $mapped;
            }
        }

        // product index => variant index => the identifiers that led there
        $inputs = [];
        $messages = $input->messages;
        foreach ($ids as $id) {
            $resolved = false;
            foreach ($found as $index => $product) {
                $match = $this->resolve($product, $id);
                if (null !== $match) {
                    $resolved = true;
                    $inputs[$index][$match[0]][] = ['id' => $id, 'match' => $match[1]];
                }
            }
            if (!$resolved) {
                $messages[] = ['type' => 'info', 'code' => 'not_found', 'content' => $id, 'path' => '$.ids'];
            }
        }

        // Only variants an identifier led to appear, each once with all its inputs.
        ksort($inputs);
        $products = [];
        foreach ($inputs as $index => $byVariant) {
            ksort($byVariant);
            $product = $found[$index];
            /** @var list<array<string, mixed>> $variants */
            $variants = $product['variants'];
            $product['variants'] = [];
            foreach ($byVariant as $variantIndex => $variantInputs) {
                $product['variants'][] = $variants[$variantIndex] + ['inputs' => $variantInputs];
            }
            $products[] = $product;
        }

        $notice = [] === $products ? null : $this->ageRestriction->message('$.products');
        if (null !== $notice) {
            $messages[] = $notice;
        }

        $payload = ['ucp' => $this->envelope(), 'products' => $products];
        if ([] !== $messages) {
            $payload['messages'] = $messages;
        }

        return UcpResponses::json($payload);
    }

    #[Route('/ucp/v1/catalog/product', name: 'koh_ucp_catalog_product', methods: ['POST'])]
    public function product(Request $request): JsonResponse
    {
        $context = $this->contextFactory->create();
        $refused = $this->negotiator->negotiate($request, self::CAPABILITY, $context->url('/'));
        if (null !== $refused) {
            return $refused;
        }

        try {
            $input = CatalogRequest::read($request, $context);
            $id = $input->body['id'] ?? null;
            if (!\is_string($id) || '' === $id || \strlen($id) > self::MAX_ID_LENGTH) {
                throw new InvalidUcpRequestException(\sprintf('id must be a non-empty string of at most %d bytes.', self::MAX_ID_LENGTH), '$.id');
            }
            $selected = $this->selected($input->body['selected'] ?? []);
            $preferences = $this->preferences($input->body['preferences'] ?? []);
        } catch (InvalidUcpRequestException $e) {
            return $this->invalid($e);
        }

        foreach ($this->products->findByIdentifiers($context->channel, [$id]) as $product) {
            $mapped = $this->mapper->map($product, $context, $input->localeCode, $input->filter);
            $resolved = null === $mapped ? null : $this->resolve($mapped, $id);
            if (null === $mapped || null === $resolved) {
                continue;
            }

            $detail = ProductDetail::build(
                $mapped,
                'exact' === $resolved[1] ? $id : null,
                $selected,
                $preferences,
            );

            $messages = $input->messages;
            $notice = $this->ageRestriction->message('$.product');
            if (null !== $notice) {
                $messages[] = $notice;
            }

            $payload = ['ucp' => $this->envelope(), 'product' => $detail];
            if ([] !== $messages) {
                $payload['messages'] = $messages;
            }

            return UcpResponses::json($payload);
        }

        // A single-resource operation: a missing product is an application
        // outcome (HTTP 200, status error), not a transport error.
        return UcpResponses::error(200, $this->version, self::CAPABILITY, 'not_found', 'Product not found: '.$id, '$.id', $this->searchPage($context));
    }

    /**
     * Which variant of a mapped product an identifier names, and how.
     *
     * @param array<string, mixed> $product
     *
     * @return array{int, string}|null variant index and match type
     */
    private function resolve(array $product, string $id): ?array
    {
        /** @var list<array<string, mixed>> $variants */
        $variants = $product['variants'];
        foreach ($variants as $index => $variant) {
            if ($variant['id'] === $id) {
                return [$index, 'exact'];
            }
        }
        if ($product['id'] !== $id) {
            return null;
        }
        foreach ($variants as $index => $variant) {
            if (true === ($variant['availability']['available'] ?? false)) {
                return [$index, 'featured'];
            }
        }

        return [0, 'featured'];
    }

    /**
     * @return list<array{name: string, id?: string, label: string}>
     */
    private function selected(mixed $selected): array
    {
        if (!\is_array($selected) || !array_is_list($selected)) {
            throw new InvalidUcpRequestException('selected must be an array of option selections.', '$.selected');
        }

        $names = [];
        $result = [];
        foreach ($selected as $index => $selection) {
            $path = \sprintf('$.selected[%d]', $index);
            if (!CatalogRequest::isObject($selection) || !\is_string($selection['name'] ?? null) || !\is_string($selection['label'] ?? null)
                || (isset($selection['id']) && !\is_string($selection['id']))
            ) {
                throw new InvalidUcpRequestException('Every selection needs a name and a label (strings) and may carry an id.', $path);
            }
            if (isset($names[$selection['name']])) {
                throw new InvalidUcpRequestException('Each option name may be selected once.', $path);
            }
            $names[$selection['name']] = true;
            $entry = ['name' => $selection['name'], 'label' => $selection['label']];
            if (isset($selection['id'])) {
                $entry['id'] = $selection['id'];
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function preferences(mixed $preferences): array
    {
        if (!\is_array($preferences) || !array_is_list($preferences)) {
            throw new InvalidUcpRequestException('preferences must be an array of option names.', '$.preferences');
        }

        $names = [];
        foreach ($preferences as $index => $name) {
            if (!\is_string($name)) {
                throw new InvalidUcpRequestException('Every preference must be an option name.', \sprintf('$.preferences[%d]', $index));
            }
            $names[] = $name;
        }

        return $names;
    }

    /** @return array<string, mixed> */
    private function envelope(): array
    {
        return [
            'version' => $this->version,
            'status' => 'success',
            'capabilities' => [self::CAPABILITY => [['version' => $this->version]]],
        ];
    }

    private function invalid(InvalidUcpRequestException $e): JsonResponse
    {
        return UcpResponses::error(400, $this->version, self::CAPABILITY, 'invalid_request', $e->getMessage(), $e->path);
    }

    private function searchPage(AgentContext $context): string
    {
        return $context->url($context->localeCode.'/');
    }
}
