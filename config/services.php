<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    $services->load('Koh\\SyliusUcp\\', __DIR__ . '/../src/');

    // CartContextInterface + OrderTokenAssignerInterface autowire from Sylius;
    // the order ObjectManager is ambiguous, so bind the Sylius order manager.
    $services->get(\Koh\SyliusUcp\Service\CheckoutSessionCreator::class)
        ->arg('$orderManager', service('sylius.manager.order'));

    $services->get(\Koh\SyliusUcp\Controller\UcpProfileController::class)
        ->arg('$version', '%koh_sylius_ucp.version%')
        ->arg('$endpointBase', '%koh_sylius_ucp.endpoint_base%');
    $services->get(\Koh\SyliusUcp\Controller\CheckoutSessionController::class)
        ->arg('$version', '%koh_sylius_ucp.version%');

    $services->get(\Koh\SyliusUcp\Agent\UcpEndpointProvider::class)
        ->arg('$endpointBase', '%koh_sylius_ucp.endpoint_base%');
};
