<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class KohSyliusUcpBundle extends AbstractBundle
{
    /** @param array<mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Default UCP settings. Set here (not in config/packages/*.yaml, which
        // Symfony does not auto-load from a bundle) so consumers get working
        // defaults without duplicating them in their app config. Apps can still
        // override by defining these parameters before the bundle loads.
        if (!$builder->hasParameter('koh_sylius_ucp.version')) {
            $builder->setParameter('koh_sylius_ucp.version', '2026-08-25');
        }
        if (!$builder->hasParameter('koh_sylius_ucp.endpoint_base')) {
            $builder->setParameter('koh_sylius_ucp.endpoint_base', '/ucp/v1');
        }
        // The checkout-session route writes a cart for an anonymous caller, so
        // it is opt-in (AUD-034-F01): off, the route answers 404 and the OpenAPI
        // document does not describe it. The UCP profile never declares it: the
        // route is not the UCP checkout capability. Set it from the same decision
        // as `koh_acp.checkout`.
        if (!$builder->hasParameter('koh_sylius_ucp.checkout')) {
            $builder->setParameter('koh_sylius_ucp.checkout', false);
        }
        // Sliding window per client address in front of the session creator.
        if (!$builder->hasParameter('koh_sylius_ucp.checkout_limit')) {
            $builder->setParameter('koh_sylius_ucp.checkout_limit', 10);
        }
        if (!$builder->hasParameter('koh_sylius_ucp.checkout_interval')) {
            $builder->setParameter('koh_sylius_ucp.checkout_interval', '1 minute');
        }

        $container->import(\dirname(__DIR__) . '/config/services.php');
    }
}
