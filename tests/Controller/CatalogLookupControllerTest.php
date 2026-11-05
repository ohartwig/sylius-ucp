<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusUcp\Catalog\AgeRestriction;
use Koh\SyliusUcp\Catalog\CatalogProductSource;
use Koh\SyliusUcp\Catalog\UcpProductMapper;
use Koh\SyliusUcp\Controller\CatalogLookupController;
use Koh\SyliusUcp\Negotiation\Negotiator;
use Koh\SyliusUcp\Tests\Support\Catalog;
use Koh\SyliusUcp\Tests\Support\Platform;
use Koh\SyliusUcp\Catalog\CatalogRequest;
use Koh\SyliusUcp\Catalog\CatalogFilter;
use Koh\SyliusUcp\Http\UcpResponses;
use Koh\SyliusUcp\Exception\InvalidUcpRequestException;
use Koh\SyliusUcp\Catalog\ProductDetail;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\Product;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /catalog/lookup` and `POST /catalog/product` (capability
 * `dev.ucp.shopping.catalog.lookup`, UCP 2026-08-25).
 */
#[CoversClass(CatalogLookupController::class)]
#[CoversClass(CatalogRequest::class)]
#[CoversClass(CatalogFilter::class)]
#[CoversClass(AgeRestriction::class)]
#[CoversClass(UcpResponses::class)]
#[CoversClass(InvalidUcpRequestException::class)]
#[CoversClass(ProductDetail::class)]
final class CatalogLookupControllerTest extends TestCase
{
    /** @var list<list<string>> identifiers of each source call */
    private array $calls = [];

    private Channel $channel;

    /** @var list<Product> */
    private array $catalogue = [];

    protected function setUp(): void
    {
        $this->channel = Catalog::channel();
        $this->catalogue = [
            Catalog::product($this->channel, 'CIG', [
                ['code' => 'CIG-R', 'stock' => 0, 'options' => ['Format' => 'Robusto']],
                ['code' => 'CIG-C', 'options' => ['Format' => 'Corona']],
            ], ['cigars']),
            Catalog::product($this->channel, 'PIPE', [['code' => 'PIPE-1', 'price' => 4900]]),
            Catalog::product($this->channel, 'UNPRICED', [['code' => 'UNPRICED-1', 'price' => null]]),
        ];
    }

    /** A variant code resolves exactly; a product code to its first available variant. */
    public function testIdentifiersResolveToVariantsWithTheirInputs(): void
    {
        $body = $this->lookup(['ids' => ['CIG-R', 'PIPE', 'CIG-R', 'CIG']]);

        self::assertSame([
            'version' => '2026-08-25',
            'status' => 'success',
            'capabilities' => ['dev.ucp.shopping.catalog.lookup' => [['version' => '2026-08-25']]],
        ], $body['ucp']);
        self::assertSame(['CIG', 'PIPE'], array_column($body['products'], 'id'));
        self::assertSame(['CIG-R', 'CIG-C'], array_column($body['products'][0]['variants'], 'id'));
        self::assertSame([['id' => 'CIG-R', 'match' => 'exact']], $body['products'][0]['variants'][0]['inputs']);
        self::assertSame([['id' => 'CIG', 'match' => 'featured']], $body['products'][0]['variants'][1]['inputs']);
        self::assertSame([['id' => 'PIPE', 'match' => 'featured']], $body['products'][1]['variants'][0]['inputs']);
        // Duplicates are folded before the source is asked.
        self::assertSame([['CIG-R', 'PIPE', 'CIG']], $this->calls);
        self::assertArrayNotHasKey('messages', $body);
    }

    public function testOneProductNamedTwiceIsReturnedOnceWithBothInputs(): void
    {
        $body = $this->lookup(['ids' => ['CIG-C', 'CIG']]);

        self::assertCount(1, $body['products']);
        self::assertSame([['id' => 'CIG-C', 'match' => 'exact'], ['id' => 'CIG', 'match' => 'featured']], $body['products'][0]['variants'][0]['inputs']);
        self::assertCount(1, $body['products'][0]['variants']);
    }

    /** A product whose variants are all sold out still has a featured one. */
    public function testASoldOutProductFeaturesItsFirstVariant(): void
    {
        $this->catalogue = [Catalog::product($this->channel, 'OUT', [['code' => 'OUT-1', 'stock' => 0], ['code' => 'OUT-2', 'stock' => 0]])];

        $body = $this->lookup(['ids' => ['OUT']]);

        self::assertSame(['OUT-1'], array_column($body['products'][0]['variants'], 'id'));
    }

    public function testUnknownAndUnpricedIdentifiersAreNotFoundMessages(): void
    {
        $body = $this->lookup(['ids' => ['NOPE', 'UNPRICED-1', 'PIPE-1']]);

        self::assertSame(['PIPE'], array_column($body['products'], 'id'));
        self::assertSame([
            ['type' => 'info', 'code' => 'not_found', 'content' => 'NOPE', 'path' => '$.ids'],
            ['type' => 'info', 'code' => 'not_found', 'content' => 'UNPRICED-1', 'path' => '$.ids'],
        ], $body['messages']);
    }

    public function testNothingFoundIsAnEmptyListNotAnError(): void
    {
        $response = $this->controller()->lookup(Platform::post('/ucp/v1/catalog/lookup', ['ids' => ['NOPE']]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->decode($response)['products']);
    }

    public function testAPriceFilterExcludesProductsOutsideIt(): void
    {
        $body = $this->lookup(['ids' => ['CIG', 'PIPE'], 'filters' => ['price' => ['min' => 1000]], 'context' => ['currency' => 'EUR']]);

        self::assertSame(['PIPE'], array_column($body['products'], 'id'));
    }

    public function testAgeRestrictedShopsDiscloseIt(): void
    {
        $body = $this->decode($this->controller(age: 18)->lookup(Platform::post('/ucp/v1/catalog/lookup', ['ids' => ['PIPE']])));
        $none = $this->decode($this->controller(age: 18)->lookup(Platform::post('/ucp/v1/catalog/lookup', ['ids' => ['NOPE']])));

        self::assertSame('age_restricted', $body['messages'][0]['code']);
        self::assertSame('$.products', $body['messages'][0]['path']);
        self::assertSame('disclosure', $body['messages'][0]['presentation']);
        self::assertSame(['not_found'], array_column($none['messages'], 'code'));
    }

    public function testTooManyIdentifiersAreRequestTooLarge(): void
    {
        $ids = array_map(static fn (int $i): string => 'ID-'.$i, range(1, CatalogLookupController::MAX_IDS + 1));

        $response = $this->controller()->lookup(Platform::post('/ucp/v1/catalog/lookup', ['ids' => $ids]));
        $atLimit = $this->controller()->lookup(Platform::post('/ucp/v1/catalog/lookup', ['ids' => [...\array_slice($ids, 0, CatalogLookupController::MAX_IDS), 'ID-1']]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('request_too_large', $this->decode($response)['messages'][0]['code']);
        self::assertSame(200, $atLimit->getStatusCode());
    }

    /** @return iterable<string, array{string, string}> */
    public static function badLookups(): iterable
    {
        yield 'not JSON' => ['{', '$'];
        yield 'no ids' => ['{}', '$.ids'];
        yield 'ids empty' => ['{"ids": []}', '$.ids'];
        yield 'ids an object' => ['{"ids": {"a": "b"}}', '$.ids'];
        yield 'id not a string' => ['{"ids": ["a", 5]}', '$.ids[1]'];
        yield 'id empty' => ['{"ids": [""]}', '$.ids[0]'];
        yield 'id too long' => ['{"ids": ["'.str_repeat('a', 256).'"]}', '$.ids[0]'];
        yield 'filters invalid' => ['{"ids": ["a"], "filters": {"price": {"min": "1"}}}', '$.filters.price.min'];
    }

    #[DataProvider('badLookups')]
    public function testInvalidLookupsAre400(string $content, string $path): void
    {
        $response = $this->controller()->lookup(Platform::post('/ucp/v1/catalog/lookup', $content));
        $body = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_request', $body['messages'][0]['code']);
        self::assertSame($path, $body['messages'][0]['path']);
        self::assertSame(['dev.ucp.shopping.catalog.lookup' => [['version' => '2026-08-25']]], $body['ucp']['capabilities']);
        self::assertSame([], $this->calls);
    }

    public function testGetProductByProductCode(): void
    {
        $body = $this->product(['id' => 'CIG']);

        self::assertSame('CIG', $body['product']['id']);
        // The first available variant is featured; its options are selected.
        self::assertSame(['CIG-C'], array_column($body['product']['variants'], 'id'));
        self::assertSame([['name' => 'Format', 'id' => 'format_corona', 'label' => 'Corona']], $body['product']['selected']);
        self::assertSame([['CIG']], $this->calls);
    }

    public function testGetProductByVariantCodeFeaturesThatVariant(): void
    {
        $body = $this->product(['id' => 'CIG-R', 'selected' => [['name' => 'Format', 'label' => 'Corona']], 'preferences' => ['Format']]);

        self::assertSame('CIG-R', $body['product']['variants'][0]['id']);
        self::assertSame('format_robusto', $body['product']['selected'][0]['id']);
    }

    public function testGetProductHonoursSelections(): void
    {
        $body = $this->product(['id' => 'CIG', 'selected' => [['name' => 'Format', 'id' => 'format_robusto', 'label' => 'Robusto']]]);

        self::assertSame(['CIG-R'], array_column($body['product']['variants'], 'id'));
        self::assertSame([
            ['id' => 'format_robusto', 'label' => 'Robusto', 'available' => false, 'exists' => true],
            ['id' => 'format_corona', 'label' => 'Corona', 'available' => true, 'exists' => true],
        ], $body['product']['options'][0]['values']);
    }

    public function testGetProductCarriesTheDisclosureOnTheProduct(): void
    {
        $body = $this->decode($this->controller(age: 18)->product(Platform::post('/ucp/v1/catalog/product', ['id' => 'PIPE'])));

        self::assertSame([['type' => 'warning', 'code' => 'age_restricted', 'path' => '$.product', 'presentation' => 'disclosure', 'content' => $body['messages'][0]['content']]], $body['messages']);
        self::assertStringContainsString('18', $body['messages'][0]['content']);
    }

    /** A missing product is an application outcome: HTTP 200, status error. */
    public function testAnUnknownProductIsNotFound(): void
    {
        foreach (['NOPE', 'UNPRICED', 'UNPRICED-1'] as $id) {
            $response = $this->controller()->product(Platform::post('/ucp/v1/catalog/product', ['id' => $id]));

            self::assertSame(200, $response->getStatusCode());
            self::assertSame([
                'ucp' => ['version' => '2026-08-25', 'status' => 'error', 'capabilities' => ['dev.ucp.shopping.catalog.lookup' => [['version' => '2026-08-25']]]],
                'messages' => [['type' => 'error', 'code' => 'not_found', 'content' => 'Product not found: '.$id, 'severity' => 'unrecoverable', 'path' => '$.id']],
                'continue_url' => 'https://shop.example.com/de_DE/',
            ], $this->decode($response));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function badProductRequests(): iterable
    {
        yield 'no id' => ['{}', '$.id'];
        yield 'id not a string' => ['{"id": 1}', '$.id'];
        yield 'id too long' => ['{"id": "'.str_repeat('a', 256).'"}', '$.id'];
        yield 'selected not a list' => ['{"id": "CIG", "selected": {"name": "Format"}}', '$.selected'];
        yield 'selection without label' => ['{"id": "CIG", "selected": [{"name": "Format"}]}', '$.selected[0]'];
        yield 'selection id not a string' => ['{"id": "CIG", "selected": [{"name": "Format", "label": "x", "id": 5}]}', '$.selected[0]'];
        yield 'selection not an object' => ['{"id": "CIG", "selected": ["Format"]}', '$.selected[0]'];
        yield 'option selected twice' => ['{"id": "CIG", "selected": [{"name": "Format", "label": "a"}, {"name": "Format", "label": "b"}]}', '$.selected[1]'];
        yield 'preferences not a list' => ['{"id": "CIG", "preferences": "Format"}', '$.preferences'];
        yield 'preference not a string' => ['{"id": "CIG", "preferences": ["Format", 2]}', '$.preferences[1]'];
    }

    #[DataProvider('badProductRequests')]
    public function testInvalidProductRequestsAre400(string $content, string $path): void
    {
        $response = $this->controller()->product(Platform::post('/ucp/v1/catalog/product', $content));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame($path, $this->decode($response)['messages'][0]['path']);
        self::assertSame([], $this->calls);
    }

    public function testBothOperationsNegotiateFirst(): void
    {
        $searchOnly = Platform::negotiator(Platform::profile(['dev.ucp.shopping.catalog.search']));

        $lookup = $this->controller(negotiator: $searchOnly)->lookup(Platform::post('/ucp/v1/catalog/lookup', ['ids' => ['CIG']]));
        $product = $this->controller()->product(Platform::post('/ucp/v1/catalog/product', ['id' => 'CIG'], null));

        self::assertSame('capabilities_incompatible', $this->decode($lookup)['messages'][0]['code']);
        self::assertSame(400, $product->getStatusCode());
        self::assertSame('invalid_profile_url', $this->decode($product)['code']);
        self::assertSame([], $this->calls);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function lookup(array $request): array
    {
        $response = $this->controller()->lookup(Platform::post('/ucp/v1/catalog/lookup', $request));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function product(array $request): array
    {
        $response = $this->controller()->product(Platform::post('/ucp/v1/catalog/product', $request));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function controller(?int $age = null, ?Negotiator $negotiator = null): CatalogLookupController
    {
        $factory = self::createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(Catalog::context($this->channel));

        $source = self::createStub(CatalogProductSource::class);
        $source->method('findByIdentifiers')->willReturnCallback(function (ChannelInterface $channel, array $ids): array {
            $this->calls[] = $ids;

            return array_values(array_filter($this->catalogue, static function (Product $product) use ($ids): bool {
                foreach ($product->getVariants() as $variant) {
                    if (\in_array($variant->getCode(), $ids, true)) {
                        return true;
                    }
                }

                return \in_array($product->getCode(), $ids, true);
            }));
        });

        return new CatalogLookupController($factory, $source, new UcpProductMapper(), $negotiator ?? Platform::negotiator(), new AgeRestriction($age));
    }
}
