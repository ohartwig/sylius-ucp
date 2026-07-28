<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Import the bundle's attribute-routed controllers. The commerce app imports
// this file: config/routes.yaml -> resource '@KohSyliusUcpBundle/config/routes.php'.
return static function (RoutingConfigurator $routes): void {
    $routes->import(
        ['path' => __DIR__ . '/../src/Controller/', 'namespace' => 'Koh\\SyliusUcp\\Controller'],
        'attribute',
    );
};
