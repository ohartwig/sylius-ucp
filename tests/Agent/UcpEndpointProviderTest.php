<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests\Agent;

use Koh\SyliusUcp\Agent\UcpEndpointProvider;
use Koh\SyliusUcp\Tests\Support\Catalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UcpEndpointProvider::class)]
final class UcpEndpointProviderTest extends TestCase
{
    public function testTheProfileAndTheServiceAreAnnounced(): void
    {
        $context = Catalog::context(Catalog::channel());
        $provider = new UcpEndpointProvider('/ucp/v1');

        $endpoints = iterator_to_array($provider->getAgentEndpoints($context), false);
        $entries = iterator_to_array($provider->getApiCatalogEntries($context), false);

        self::assertCount(1, $endpoints);
        self::assertSame('/.well-known/ucp', $endpoints[0]->path);
        self::assertSame('ucp', $endpoints[0]->protocol);
        self::assertStringContainsString('lookup', (string) $endpoints[0]->description);
        self::assertCount(1, $entries);
        self::assertSame('https://shop.example.com/ucp/v1', $entries[0]->anchor);
        self::assertSame('https://shop.example.com/ucp/v1/openapi.json', $entries[0]->serviceDesc[0]->href);
        self::assertSame('https://shop.example.com/.well-known/ucp', $entries[0]->describedBy[0]->href);
    }
}
