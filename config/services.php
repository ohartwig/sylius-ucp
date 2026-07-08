<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Koh\\SyliusUcp\\', __DIR__ . '/../src/');

    // Controllers are autoconfigured (tag controller.service_arguments).
    $services->get(\Koh\SyliusUcp\Controller\UcpProfileController::class)
        ->arg('$version', '%koh_sylius_ucp.version%')
        ->arg('$endpointBase', '%koh_sylius_ucp.endpoint_base%');
    $services->get(\Koh\SyliusUcp\Controller\CheckoutSessionController::class)
        ->arg('$version', '%koh_sylius_ucp.version%');
};
