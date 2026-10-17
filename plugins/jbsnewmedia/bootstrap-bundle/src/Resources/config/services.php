<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $config): void {
    $services = $config->services()
        ->defaults()
            ->autowire()
            ->autoconfigure();

    $services->load('JBSNewMedia\\BootstrapBundle\\Command\\', __DIR__.'/../../Command')
        ->private();

    $services->load('JBSNewMedia\\BootstrapBundle\\Service\\', __DIR__.'/../../Service')
        ->private();
};
