<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Agent;

use Koh\SyliusAgentContracts\Context\AgentContext;
use Koh\SyliusAgentContracts\Model\AgentEndpoint;
use Koh\SyliusAgentContracts\Model\ApiCatalogEntry;
use Koh\SyliusAgentContracts\Model\LinkTarget;
use Koh\SyliusAgentContracts\Provider\AgentEndpointProviderInterface;
use Koh\SyliusAgentContracts\Provider\ApiCatalogEntryProviderInterface;

/**
 * Announces the UCP profile to llms.txt and the api-catalog.
 */
final class UcpEndpointProvider implements AgentEndpointProviderInterface, ApiCatalogEntryProviderInterface
{
    public function __construct(private readonly string $endpointBase = '/ucp/v1')
    {
    }

    public function getAgentEndpoints(AgentContext $context): iterable
    {
        yield new AgentEndpoint(
            path: '/.well-known/ucp',
            title: 'UCP profile',
            protocol: 'ucp',
            description: 'Universal Commerce Protocol: catalogue and checkout capabilities.',
        );
    }

    public function getApiCatalogEntries(AgentContext $context): iterable
    {
        yield new ApiCatalogEntry(
            anchor: $context->url($this->endpointBase),
            serviceDesc: [new LinkTarget($context->url($this->endpointBase.'/openapi.json'), 'application/json')],
            describedBy: [new LinkTarget($context->url('/.well-known/ucp'), 'application/json')],
            title: 'UCP shopping service',
        );
    }
}
