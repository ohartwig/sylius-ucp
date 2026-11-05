<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusUcp\Agent\UcpEndpointProvider;
use Koh\SyliusUcp\Catalog\AgeRestriction;
use Koh\SyliusUcp\Catalog\CatalogProductSource;
use Koh\SyliusUcp\Catalog\DoctrineCatalogProductSource;
use Koh\SyliusUcp\Controller\CatalogLookupController;
use Koh\SyliusUcp\Controller\CatalogSearchController;
use Koh\SyliusUcp\Controller\OpenApiController;
use Koh\SyliusUcp\Controller\PermalinkController;
use Koh\SyliusUcp\Controller\UcpProfileController;
use Koh\SyliusUcp\Negotiation\HostResolver;
use Koh\SyliusUcp\Negotiation\Negotiator;
use Koh\SyliusUcp\Negotiation\PlatformProfileFetcher;
use Koh\SyliusUcp\Negotiation\SystemHostResolver;
use Koh\SyliusUcp\Permalink\PurchasableItemResolver;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Koh\\SyliusUcp\\', __DIR__.'/../src/')
        ->exclude([
            __DIR__.'/../src/Exception/',
            __DIR__.'/../src/KohSyliusUcpBundle.php',
            // Value objects and static helpers, not services.
            __DIR__.'/../src/Catalog/CatalogFilter.php',
            __DIR__.'/../src/Catalog/CatalogRequest.php',
            __DIR__.'/../src/Catalog/ProductDetail.php',
            __DIR__.'/../src/Http/',
            __DIR__.'/../src/Negotiation/PlatformProfile.php',
            __DIR__.'/../src/Negotiation/ProfileFetchError.php',
            __DIR__.'/../src/Negotiation/PublicAddress.php',
            __DIR__.'/../src/Negotiation/UcpAgentHeader.php',
            __DIR__.'/../src/Permalink/PermalinkRequest.php',
        ]);

    $services->set(AgeRestriction::class)
        ->args([param('koh_sylius_ucp.age_restriction')]);

    // Platform profile discovery: a global budget of uncached fetches shared by
    // every worker, and the cache the profiles are kept in.
    $services->set('koh_sylius_ucp.profile_fetch_limiter.storage', CacheStorage::class)
        ->args([service('cache.app')]);
    $services->set('koh_sylius_ucp.profile_fetch_limiter', RateLimiterFactory::class)
        ->args([
            [
                'id' => 'koh_sylius_ucp_profile_fetch',
                'policy' => 'fixed_window',
                'limit' => param('koh_sylius_ucp.profile_fetch_limit'),
                'interval' => param('koh_sylius_ucp.profile_fetch_interval'),
            ],
            service('koh_sylius_ucp.profile_fetch_limiter.storage'),
        ]);
    $services->alias(HostResolver::class, SystemHostResolver::class);
    $services->set(PlatformProfileFetcher::class)
        ->args([
            service(HostResolver::class),
            service('cache.app'),
            service('koh_sylius_ucp.profile_fetch_limiter'),
        ]);
    $services->set(Negotiator::class)
        ->args([service(PlatformProfileFetcher::class), param('koh_sylius_ucp.version')]);

    $services->set(DoctrineCatalogProductSource::class)
        ->args([service('sylius.repository.product')]);
    $services->alias(CatalogProductSource::class, DoctrineCatalogProductSource::class);

    $services->get(CatalogSearchController::class)
        ->arg('$version', param('koh_sylius_ucp.version'));
    $services->get(CatalogLookupController::class)
        ->arg('$version', param('koh_sylius_ucp.version'));

    $services->set(PurchasableItemResolver::class)
        ->args([service('sylius.repository.product_variant'), service('sylius.repository.product')]);
    $services->get(PermalinkController::class)
        ->args([
            service('Koh\\SyliusAgentContracts\\Context\\AgentContextFactoryInterface'),
            service(PurchasableItemResolver::class),
            service('Koh\\SyliusAgentContracts\\Cart\\CartHandoff'),
            service('router'),
            param('koh_sylius_ucp.permalink.enabled'),
            param('koh_sylius_ucp.permalink.cart_route'),
            param('koh_sylius_ucp.permalink.fallback_route'),
        ]);

    $services->get(UcpProfileController::class)
        ->arg('$version', param('koh_sylius_ucp.version'))
        ->arg('$endpointBase', param('koh_sylius_ucp.endpoint_base'))
        ->arg('$permalinkEnabled', param('koh_sylius_ucp.permalink.enabled'));
    $services->get(OpenApiController::class)
        ->arg('$version', param('koh_sylius_ucp.version'))
        ->arg('$endpointBase', param('koh_sylius_ucp.endpoint_base'));

    $services->get(UcpEndpointProvider::class)
        ->arg('$endpointBase', param('koh_sylius_ucp.endpoint_base'));
};
