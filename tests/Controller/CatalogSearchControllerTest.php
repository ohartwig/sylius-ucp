<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Controller;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusUcp\Catalog\CatalogProductSource;
use Koh\SyliusUcp\Catalog\UcpProductMapper;
use Koh\SyliusUcp\Controller\CatalogSearchController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Currency\Model\Currency;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /ucp/v1/catalog/search` against UCP 2026-08-25 (catalog_search.json).
 *
 * The route needs no credentials, so the bounds are part of the contract: a
 * term is required, a page is capped, and paging stops at MAX_RESULTS.
 */
#[CoversClass(CatalogSearchController::class)]
final class CatalogSearchControllerTest extends TestCase
{
    /** @var list<array{string, int, int}> term, offset, limit of each source call */
    private array $calls = [];

    private int $available = 1000;

    public function testASearchAnswersTheUcpSearchResponse(): void
    {
        $this->available = 3;

        $body = $this->search(['query' => 'robusto']);

        self::assertSame([
            'version' => '2026-08-25',
            'status' => 'success',
            'capabilities' => ['dev.ucp.shopping.catalog.search' => [['version' => '2026-08-25']]],
        ], $body['ucp']);
        self::assertCount(3, $body['products']);
        self::assertSame('P-0', $body['products'][0]['id']);
        self::assertSame(['amount' => 990, 'currency' => 'EUR'], $body['products'][0]['variants'][0]['price']);
        self::assertSame(['has_next_page' => false], $body['pagination']);
        self::assertArrayNotHasKey('messages', $body);
        // The default page of 10, plus one row to learn whether there is a next page.
        self::assertSame([['robusto', 0, 11]], $this->calls);
    }

    public function testTheCursorWalksThroughTheResults(): void
    {
        $first = $this->search(['query' => 'a', 'pagination' => ['limit' => 20]]);

        self::assertCount(20, $first['products']);
        self::assertTrue($first['pagination']['has_next_page']);

        $second = $this->search(['query' => 'a', 'pagination' => ['limit' => 20, 'cursor' => $first['pagination']['cursor']]]);

        self::assertSame('P-20', $second['products'][0]['id']);
        self::assertSame(['a', 20, 21], $this->calls[1]);
    }

    /** Cursors are URL-safe base64 without padding. */
    public function testTheCursorIsUnpadded(): void
    {
        $body = $this->search(['query' => 'a', 'pagination' => ['limit' => 20]]);

        self::assertSame('bzoyMA', $body['pagination']['cursor']);
    }

    public function testAFullLastPageHasNoNextPage(): void
    {
        $this->available = 10;

        $body = $this->search(['query' => 'a']);

        self::assertCount(10, $body['products']);
        self::assertSame(['has_next_page' => false], $body['pagination']);
    }

    public function testAPageOfOneIsAllowed(): void
    {
        $body = $this->search(['query' => 'a', 'pagination' => ['limit' => 1]]);

        self::assertCount(1, $body['products']);
        self::assertSame(['a', 0, 2], $this->calls[0]);
    }

    public function testThePageSizeIsCapped(): void
    {
        $body = $this->search(['query' => 'a', 'pagination' => ['limit' => 10_000]]);

        self::assertCount(CatalogSearchController::MAX_LIMIT, $body['products']);
        self::assertSame(['a', 0, CatalogSearchController::MAX_LIMIT + 1], $this->calls[0]);
    }

    /** A broad term cannot be paged through the whole catalogue. */
    public function testPagingStopsAtMaxResults(): void
    {
        $seen = 0;
        $cursor = null;
        $pages = 0;
        do {
            $pagination = ['limit' => 50] + (null === $cursor ? [] : ['cursor' => $cursor]);
            $body = $this->search(['query' => 'a', 'pagination' => $pagination]);
            $seen += \count($body['products']);
            $cursor = $body['pagination']['cursor'] ?? null;
            ++$pages;
        } while ($body['pagination']['has_next_page'] && $pages < 20);

        self::assertSame(CatalogSearchController::MAX_RESULTS, $seen);
        self::assertSame(4, $pages);
    }

    public function testTheLastShortPageEndsBeforeMaxResults(): void
    {
        $this->available = 25;

        $first = $this->search(['query' => 'a', 'pagination' => ['limit' => 20]]);
        $second = $this->search(['query' => 'a', 'pagination' => ['limit' => 20, 'cursor' => $first['pagination']['cursor']]]);

        self::assertCount(5, $second['products']);
        self::assertSame(['has_next_page' => false], $second['pagination']);
    }

    /** A cursor near the end only asks for what is left of the window. */
    public function testACursorNearTheEndAsksOnlyForTheRestOfTheWindow(): void
    {
        $cursor = rtrim(strtr(base64_encode('o:190'), '+/', '-_'), '=');

        $body = $this->search(['query' => 'a', 'pagination' => ['limit' => 50, 'cursor' => $cursor]]);

        self::assertCount(10, $body['products']);
        self::assertSame(['a', 190, 11], $this->calls[0]);
        self::assertFalse($body['pagination']['has_next_page']);
    }

    public function testProductsTheMapperRejectsAreLeftOut(): void
    {
        $this->available = 2;
        $controller = $this->controller(unpricedFrom: 1);

        $body = $this->decode($controller($this->post(['query' => 'a'])));

        self::assertSame(['P-0'], array_column($body['products'], 'id'));
    }

    public function testUnsupportedInputsAreReportedNotSilentlyIgnored(): void
    {
        $body = $this->search(['query' => 'a', 'filters' => ['categories' => ['x']], 'context' => ['address_country' => 'DE']]);

        self::assertSame([[
            'type' => 'warning',
            'code' => 'unsupported_input',
            'content' => 'Not supported by this shop and ignored: filters, context.',
        ]], $body['messages']);
    }

    public function testTheTermIsTrimmedAndCut(): void
    {
        $this->search(['query' => '  '.str_repeat('x', 150).'  ']);
        $this->search(['query' => str_repeat('ä', 150)]);

        self::assertSame(str_repeat('x', 100), $this->calls[0][0]);
        self::assertSame(str_repeat('ä', 100), $this->calls[1][0]);
    }

    public function testASingleUnsupportedInputIsNamed(): void
    {
        $body = $this->search(['query' => 'a', 'context' => ['address_country' => 'DE']]);

        self::assertSame('Not supported by this shop and ignored: context.', $body['messages'][0]['content']);
        self::assertSame([0], array_keys($body['messages']));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function badRequests(): iterable
    {
        yield 'no body' => ['', null];
        yield 'not JSON' => ['{', null];
        yield 'not an object' => ['"robusto"', null];
        yield 'a list' => ['["robusto"]', null];
        yield 'no query' => ['{}', '$.query'];
        yield 'empty query' => ['{"query": "   "}', '$.query'];
        yield 'query not a string' => ['{"query": 5}', '$.query'];
        yield 'limit zero' => ['{"query": "a", "pagination": {"limit": 0}}', '$.pagination.limit'];
        yield 'limit not an integer' => ['{"query": "a", "pagination": {"limit": "5"}}', '$.pagination.limit'];
        yield 'cursor not a string' => ['{"query": "a", "pagination": {"cursor": 5}}', '$.pagination.cursor'];
        yield 'cursor not issued here' => ['{"query": "a", "pagination": {"cursor": "bm9wZQ"}}', '$.pagination.cursor'];
        yield 'cursor not base64' => ['{"query": "a", "pagination": {"cursor": "!!"}}', '$.pagination.cursor'];
        yield 'cursor past the window' => ['{"query": "a", "pagination": {"cursor": "'.rtrim(base64_encode('o:200'), '=').'"}}', '$.pagination.cursor'];
        yield 'cursor with leading data' => ['{"query": "a", "pagination": {"cursor": "'.rtrim(base64_encode('xo:20'), '=').'"}}', '$.pagination.cursor'];
        yield 'cursor with trailing data' => ['{"query": "a", "pagination": {"cursor": "'.rtrim(base64_encode('o:20x'), '=').'"}}', '$.pagination.cursor'];
    }

    /** Malformed requests answer 400 with the UCP error response, and the catalogue is not touched. */
    #[DataProvider('badRequests')]
    public function testMalformedRequestsAreRefused(string $content, ?string $path): void
    {
        $response = $this->controller()(Request::create('/ucp/v1/catalog/search', 'POST', content: $content));
        $body = $this->decode($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'version' => '2026-08-25',
            'status' => 'error',
            'capabilities' => ['dev.ucp.shopping.catalog.search' => [['version' => '2026-08-25']]],
        ], $body['ucp']);
        self::assertSame(['ucp', 'messages'], array_keys($body));
        self::assertCount(1, $body['messages']);
        self::assertSame('error', $body['messages'][0]['type']);
        self::assertSame('invalid_request', $body['messages'][0]['code']);
        self::assertSame('unrecoverable', $body['messages'][0]['severity']);
        self::assertNotSame('', $body['messages'][0]['content']);
        if (null === $path) {
            self::assertArrayNotHasKey('path', $body['messages'][0]);
        } else {
            self::assertSame($path, $body['messages'][0]['path']);
        }
        self::assertSame([], $this->calls);
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function search(array $request): array
    {
        $response = $this->controller()($this->post($request));
        self::assertSame(200, $response->getStatusCode());

        return $this->decode($response);
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): Request
    {
        return Request::create('/ucp/v1/catalog/search', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(\Symfony\Component\HttpFoundation\Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function controller(int $unpricedFrom = \PHP_INT_MAX): CatalogSearchController
    {
        $currency = new Currency();
        $currency->setCode('EUR');
        $channel = new Channel();
        $channel->setCode('WEB');
        $channel->setBaseCurrency($currency);

        $factory = self::createStub(AgentContextFactoryInterface::class);
        $factory->method('create')->willReturn(new AgentContext($channel, 'https://shop.example.com', 'de_DE', ['de_DE']));

        $source = self::createStub(CatalogProductSource::class);
        $source->method('search')->willReturnCallback(
            function (ChannelInterface $c, string $locale, string $term, int $offset, int $limit) use ($channel, $unpricedFrom): array {
                $this->calls[] = [$term, $offset, $limit];
                $products = [];
                for ($i = $offset; $i < min($offset + $limit, $this->available); ++$i) {
                    $products[] = $this->product($channel, $i, $i < $unpricedFrom);
                }

                return $products;
            },
        );

        return new CatalogSearchController($factory, $source, new UcpProductMapper());
    }

    private function product(Channel $channel, int $i, bool $priced): Product
    {
        $product = new Product();
        $product->setCurrentLocale('de_DE');
        $product->setFallbackLocale('de_DE');
        $product->setCode('P-'.$i);
        $product->setName('Product '.$i);

        $variant = new ProductVariant();
        $variant->setCurrentLocale('de_DE');
        $variant->setFallbackLocale('de_DE');
        $variant->setCode('V-'.$i);
        if ($priced) {
            $pricing = new ChannelPricing();
            $pricing->setChannelCode((string) $channel->getCode());
            $pricing->setPrice(990);
            $variant->addChannelPricing($pricing);
        }
        $product->addVariant($variant);

        return $product;
    }
}
