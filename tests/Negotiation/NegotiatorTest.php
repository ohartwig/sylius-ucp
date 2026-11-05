<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Negotiation;

use Koh\SyliusUcp\Http\UcpResponses;
use Koh\SyliusUcp\Negotiation\Negotiator;
use Koh\SyliusUcp\Tests\Support\Platform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Negotiation outcomes and their REST shapes (overview, "Error Handling").
 */
#[CoversClass(Negotiator::class)]
#[CoversClass(UcpResponses::class)]
final class NegotiatorTest extends TestCase
{
    private const CAPABILITY = 'dev.ucp.shopping.catalog.search';

    private const CONTINUE_URL = 'https://shop.example.com/';

    public function testASharedCapabilityAtTheSameVersionProceeds(): void
    {
        self::assertNull(Platform::negotiator()->negotiate($this->request(Platform::HEADER), self::CAPABILITY, self::CONTINUE_URL));
    }

    public function testAMissingHeaderIsAnInvalidProfileUrl(): void
    {
        $this->assertTransportError(400, 'invalid_profile_url', Platform::negotiator()->negotiate($this->request(null), self::CAPABILITY, self::CONTINUE_URL));
        $this->assertTransportError(400, 'invalid_profile_url', Platform::negotiator()->negotiate($this->request('  '), self::CAPABILITY, self::CONTINUE_URL));
    }

    public function testAHeaderThatIsNotADictionaryIsAnInvalidProfileUrl(): void
    {
        $response = Platform::negotiator()->negotiate($this->request(Platform::PROFILE_URL), self::CAPABILITY, self::CONTINUE_URL);

        $this->assertTransportError(400, 'invalid_profile_url', $response);
        self::assertStringContainsString('RFC 8941', $this->decode($response)['content']);
    }

    public function testAnUnreachableProfileIs424(): void
    {
        $negotiator = new Negotiator(Platform::fetcher(new MockHttpClient(new MockResponse('', ['http_code' => 404]))));

        $this->assertTransportError(424, 'profile_unreachable', $negotiator->negotiate($this->request(Platform::HEADER), self::CAPABILITY, self::CONTINUE_URL));
    }

    public function testAMalformedProfileIs422(): void
    {
        $negotiator = new Negotiator(Platform::fetcher(new MockHttpClient(new MockResponse('[]'))));

        $this->assertTransportError(422, 'profile_malformed', $negotiator->negotiate($this->request(Platform::HEADER), self::CAPABILITY, self::CONTINUE_URL));
    }

    public function testASpentBudgetIs503WithRetryAfter(): void
    {
        $negotiator = new Negotiator(Platform::fetcher(new MockHttpClient([]), null, 1));
        $negotiator->negotiate($this->request('profile="https://one.example/p"'), self::CAPABILITY, self::CONTINUE_URL);

        $response = $negotiator->negotiate($this->request(Platform::HEADER), self::CAPABILITY, self::CONTINUE_URL);

        $this->assertTransportError(503, 'profile_unreachable', $response);
        self::assertNotNull($response);
        self::assertMatchesRegularExpression('/^\d+$/', (string) $response->headers->get('Retry-After'));
    }

    public function testAnotherProtocolVersionIsUnsupported(): void
    {
        $response = Platform::negotiator(Platform::profile(version: '2026-04-08'))->negotiate($this->request(Platform::HEADER), self::CAPABILITY, self::CONTINUE_URL);

        $this->assertTransportError(422, 'version_unsupported', $response);
        self::assertSame('Protocol version 2026-04-08 is not supported. This business implements version 2026-08-25.', $this->decode($response)['content']);
    }

    /** No shared capability is a business outcome: HTTP 200 and the UCP error response. */
    public function testAMissingCapabilityIsIncompatible(): void
    {
        $response = Platform::negotiator(Platform::profile(['dev.ucp.shopping.checkout']))->negotiate($this->request(Platform::HEADER), self::CAPABILITY, self::CONTINUE_URL);

        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'ucp' => ['version' => '2026-08-25', 'status' => 'error', 'capabilities' => []],
            'messages' => [[
                'type' => 'error',
                'code' => 'capabilities_incompatible',
                'content' => 'The platform profile does not declare dev.ucp.shopping.catalog.search at version 2026-08-25.',
                'severity' => 'unrecoverable',
            ]],
            'continue_url' => self::CONTINUE_URL,
        ], $this->decode($response));
        self::assertStringContainsString('"capabilities":{}', (string) $response->getContent());
    }

    private function assertTransportError(int $status, string $code, ?JsonResponse $response): void
    {
        self::assertNotNull($response);
        self::assertSame($status, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame(['code', 'content', 'continue_url'], array_keys($body));
        self::assertSame($code, $body['code']);
        self::assertSame(self::CONTINUE_URL, $body['continue_url']);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    private function request(?string $header): Request
    {
        $request = Request::create('/ucp/v1/catalog/search', 'POST');
        if (null !== $header) {
            $request->headers->set('UCP-Agent', $header);
        }

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(?JsonResponse $response): array
    {
        self::assertNotNull($response);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
