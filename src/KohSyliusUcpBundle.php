<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * UCP business for a Sylius shop: profile, catalog search and lookup over
 * REST, and the permalink handoff to the buyer's browser.
 *
 *     koh_sylius_ucp:
 *         age_restriction: 18        # shop-wide minimum age, or null
 *         permalink:
 *             enabled: true
 *             cart_route: sylius_shop_cart_summary
 *             fallback_route: sylius_shop_homepage
 *
 * Every value is also a container parameter (`koh_sylius_ucp.*`); an app that
 * defines one of them before the bundle loads keeps its own value.
 */
final class KohSyliusUcpBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->integerNode('age_restriction')
                    ->defaultNull()
                    ->min(1)
                    ->info('Minimum age for every product (as koh_acp/koh_a2a age_restriction). Carried as an age_restricted disclosure on catalog responses.')
                ->end()
                ->arrayNode('permalink')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Serve /ucp/buy and declare dev.ucp.shopping.permalink in the profile.')
                        ->end()
                        ->scalarNode('cart_route')
                            ->defaultValue('sylius_shop_cart_summary')
                            ->info('Where the buyer lands once the items are in their cart.')
                        ->end()
                        ->scalarNode('fallback_route')
                            ->defaultValue('sylius_shop_homepage')
                            ->info('Where the buyer lands when nothing could be added (unknown items, a shop gate refused, an invalid link).')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /** @param array<mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $permalink = \is_array($config['permalink'] ?? null) ? $config['permalink'] : [];

        $defaults = [
            'koh_sylius_ucp.version' => '2026-08-25',
            'koh_sylius_ucp.endpoint_base' => '/ucp/v1',
            'koh_sylius_ucp.age_restriction' => \is_int($config['age_restriction'] ?? null) ? $config['age_restriction'] : null,
            'koh_sylius_ucp.permalink.enabled' => (bool) ($permalink['enabled'] ?? true),
            'koh_sylius_ucp.permalink.cart_route' => (string) ($permalink['cart_route'] ?? 'sylius_shop_cart_summary'),
            'koh_sylius_ucp.permalink.fallback_route' => (string) ($permalink['fallback_route'] ?? 'sylius_shop_homepage'),
            // Global budget for fetching unknown platform profiles.
            'koh_sylius_ucp.profile_fetch_limit' => 20,
            'koh_sylius_ucp.profile_fetch_interval' => '1 minute',
        ];
        foreach ($defaults as $name => $value) {
            if (!$builder->hasParameter($name)) {
                $builder->setParameter($name, $value);
            }
        }

        $container->import(\dirname(__DIR__).'/config/services.php');
    }
}
