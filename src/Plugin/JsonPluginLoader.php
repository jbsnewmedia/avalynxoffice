<?php

declare(strict_types=1);

namespace App\Plugin;

use App\Service\PluginManifestService;
use Symfony\Component\DependencyInjection\Kernel\BundleInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

class JsonPluginLoader
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $plugins = [];

    /**
     * @var array<string, list<string>>
     */
    private array $psr4Prefixes = [];

    /**
     * @var array<string, array{resource: array{path: string, namespace: string}, type: string}>
     */
    private array $routesByBundleClass = [];

    private bool $autoloadRegistered = false;

    public function __construct(
        private readonly PluginManifestService $pluginManifestService,
    ) {
    }

    public function initializePlugins(): void
    {
        $manifest = $this->pluginManifestService->loadManifest();
        $plugins = $manifest['plugins'];

        $this->plugins = $plugins;
        $this->psr4Prefixes = [];
        $this->routesByBundleClass = [];

        foreach ($plugins as $plugin) {
            if (!is_array($plugin) || !$this->isPluginActive($plugin)) {
                continue;
            }

            $bundleClass = $plugin['bundle'] ?? null;
            if (is_string($bundleClass) && isset($plugin['routes']) && is_array($plugin['routes'])) {
                /** @var array{resource: array{path: string, namespace: string}, type: string} $routes */
                $routes = $plugin['routes'];
                $this->routesByBundleClass[$bundleClass] = $routes;
            }

            $autoload = $plugin['autoload'] ?? null;
            if (!is_array($autoload)) {
                continue;
            }

            $psr4Autoload = $autoload['psr-4'] ?? null;
            if (!is_array($psr4Autoload)) {
                continue;
            }

            foreach ($psr4Autoload as $prefix => $paths) {
                if (!is_string($prefix) || !is_array($paths)) {
                    continue;
                }

                foreach ($paths as $path) {
                    if (!is_string($path)) {
                        continue;
                    }

                    $this->psr4Prefixes[$prefix] ??= [];
                    if (!in_array($path, $this->psr4Prefixes[$prefix], true)) {
                        $this->psr4Prefixes[$prefix][] = $path;
                    }
                }
            }
        }

        uksort(
            $this->psr4Prefixes,
            static fn (string $left, string $right): int => strlen($right) <=> strlen($left),
        );

        if ([] !== $this->psr4Prefixes && !$this->autoloadRegistered) {
            spl_autoload_register($this->autoloadPluginClass(...));
            $this->autoloadRegistered = true;
        }
    }

    /**
     * @param list<string> $instantiatedBundleNames
     *
     * @return iterable<BundleInterface>
     */
    public function getBundles(string $environment, array $instantiatedBundleNames): iterable
    {
        foreach ($this->plugins as $plugin) {
            $bundleClass = $plugin['bundle'] ?? null;
            if (!is_string($bundleClass) || !$this->isPluginActive($plugin) || !$this->isEnabledForEnvironment($plugin, $environment)) {
                continue;
            }

            if (!class_exists($bundleClass)) {
                throw new \RuntimeException(sprintf('Plugin-Bundle "%s" konnte nicht geladen werden.', $bundleClass));
            }

            $bundle = new $bundleClass();
            if (!$bundle instanceof BundleInterface) {
                throw new \RuntimeException(sprintf('Plugin "%s" ist kein Symfony-Bundle.', $bundleClass));
            }

            if (in_array($bundle->getName(), $instantiatedBundleNames, true)) {
                continue;
            }

            $instantiatedBundleNames[] = $bundle->getName();

            yield $bundle;
        }
    }

    public function addRoutesForBundle(BundleInterface $bundle, RoutingConfigurator $routes): void
    {
        $routeResource = $this->routesByBundleClass[$bundle::class] ?? null;
        if (null === $routeResource) {
            return;
        }

        $resource = $routeResource['resource'];
        $resource['path'] = $this->pluginManifestService->toAbsolutePath($resource['path']);

        $routes->import($resource, $routeResource['type']);
    }

    public function autoloadPluginClass(string $class): void
    {
        foreach ($this->psr4Prefixes as $prefix => $paths) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            $relativePath = str_replace('\\', '/', $relativeClass).'.php';
            foreach ($paths as $path) {
                $file = $this->pluginManifestService->toAbsolutePath($path).'/'.$relativePath;
                if (!is_file($file)) {
                    continue;
                }

                require $file;

                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $plugin
     */
    private function isEnabledForEnvironment(array $plugin, string $environment): bool
    {
        $environments = $plugin['environments'] ?? ['all'];
        if (!is_array($environments)) {
            return true;
        }

        return in_array('all', $environments, true) || in_array($environment, $environments, true);
    }

    /**
     * @param array<string, mixed> $plugin
     */
    private function isPluginActive(array $plugin): bool
    {
        return (bool) ($plugin['active'] ?? true);
    }
}
