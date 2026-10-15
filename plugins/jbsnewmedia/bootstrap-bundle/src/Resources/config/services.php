<?php

declare(strict_types=1);

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
