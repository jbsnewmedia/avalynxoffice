<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice;

use AvalynxOffice\Plugin\JsonPluginLoader;
use AvalynxOffice\Service\PluginManifestService;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\BundleInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    private readonly JsonPluginLoader $pluginLoader;

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);

        $this->pluginLoader = new JsonPluginLoader(new PluginManifestService($this->getProjectDir()));
        $this->pluginLoader->initializePlugins();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import('../config/{routes}/'.$this->environment.'/*.yaml');
        $routes->import('../config/{routes}/*.yaml');

        if (is_file(\dirname(__DIR__).'/config/routes.yaml')) {
            $routes->import('../config/{routes}.yaml');
        } elseif (is_file($path = \dirname(__DIR__).'/config/routes.php')) {
            /** @var callable(RoutingConfigurator, self): void $configureRoutes */
            $configureRoutes = require $path;
            $configureRoutes($routes->withPath($path), $this);
        }

        $this->addBundleRoutes($routes);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->import('../config/{packages}/*.yaml');
        $container->import('../config/{packages}/'.$this->environment.'/*.yaml');

        if (is_file(\dirname(__DIR__).'/config/services.yaml')) {
            $container->import('../config/{services}.yaml');
            $container->import('../config/{services}_'.$this->environment.'.yaml');
        } elseif (is_file($path = \dirname(__DIR__).'/config/services.php')) {
            /** @var callable(ContainerConfigurator, self): void $configureContainer */
            $configureContainer = require $path;
            $configureContainer($container->withPath($path), $this);
        }
    }

    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $projectDir = $this->getProjectDir();
        $manifestPath = $projectDir.'/'.PluginManifestService::MANIFEST_RELATIVE_PATH;
        if (is_file($manifestPath)) {
            $container->addResource(new FileResource($manifestPath));
        }

        $pluginsDir = $projectDir.'/plugins';
        if (is_dir($pluginsDir)) {
            $container->addResource(new DirectoryResource($pluginsDir, '/^composer\.json$/'));
        }
    }

    /**
     * @return iterable<BundleInterface>
     */
    #[\Override]
    public function registerBundles(): iterable
    {
        /** @var array<string, array<string, bool>> $contents */
        $contents = require $this->getProjectDir().'/config/bundles.php';
        $instantiatedBundleNames = [];

        foreach ($contents as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                $bundle = new $class();
                if ($bundle instanceof BundleInterface) {
                    $instantiatedBundleNames[] = $bundle->getName();

                    yield $bundle;
                }
            }
        }

        yield from $this->pluginLoader->getBundles($this->environment, $instantiatedBundleNames);
    }

    private function addBundleRoutes(RoutingConfigurator $routes): void
    {
        foreach ($this->getBundles() as $bundle) {
            $bundlePath = $bundle->getPath();

            if (is_file($bundlePath.'/config/routes.yaml')) {
                $routes->import($bundlePath.'/config/{routes}.yaml');
            } elseif (is_file($path = $bundlePath.'/config/routes.php')) {
                /** @var callable(RoutingConfigurator, self): void $configureRoutes */
                $configureRoutes = require $path;
                $configureRoutes($routes->withPath($path), $this);
            }

            $this->pluginLoader->addRoutesForBundle($bundle, $routes);
        }
    }
}
