<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\ExchangeConnectorBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class ExchangeConnectorExtension extends Extension implements PrependExtensionInterface
{
    #[\Override]
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('twig')) {
            return;
        }

        $container->prependExtensionConfig('twig', [
            'paths' => [
                __DIR__.'/../../templates' => 'ExchangeConnector',
            ],
        ]);
    }

    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        // The EWS library is bundled inside the plugin (lib/vendor) so the
        // root application vendor stays free of connector dependencies.
        $autoloader = __DIR__.'/../../lib/vendor/autoload.php';
        if (is_file($autoloader)) {
            require_once $autoloader;
        }

        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yaml');
    }
}
