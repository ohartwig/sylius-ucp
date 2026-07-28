<?php

declare(strict_types=1);

namespace Koh\SyliusUcp;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class KohSyliusUcpBundle extends AbstractBundle
{
    /** @param array<string, mixed> $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Default UCP settings. Set here (not in config/packages/*.yaml, which
        // Symfony does not auto-load from a bundle) so consumers get working
        // defaults without duplicating them in their app config. Apps can still
        // override by defining these parameters before the bundle loads.
        if (!$builder->hasParameter('koh_sylius_ucp.version')) {
            $builder->setParameter('koh_sylius_ucp.version', '2026-04-08');
        }
        if (!$builder->hasParameter('koh_sylius_ucp.endpoint_base')) {
            $builder->setParameter('koh_sylius_ucp.endpoint_base', '/ucp/v1');
        }

        $container->import(\dirname(__DIR__) . '/config/services.php');
    }
}
