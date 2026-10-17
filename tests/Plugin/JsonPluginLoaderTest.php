<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Plugin;

use AvalynxOffice\Plugin\JsonPluginLoader;
use AvalynxOffice\Service\PluginManifestService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\Loader\PhpFileLoader;
use Symfony\Component\Routing\RouteCollection;

final class JsonPluginLoaderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/mailer-plugin-loader-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/plugins/acme/demo-bundle/src', 0777, true);

        file_put_contents($this->projectDir.'/plugins/acme/demo-bundle/composer.json', <<<'JSON'
{
    "name": "acme/demo-bundle",
    "autoload": {
        "psr-4": {
            "Acme\\DemoBundle\\": "src/"
        }
    },
    "extra": {
        "symfony": {
            "bundle": "Acme\\DemoBundle\\DemoBundle"
        }
    }
}
JSON);

        file_put_contents($this->projectDir.'/plugins/acme/demo-bundle/src/DemoBundle.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\DemoBundle;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class DemoBundle extends Bundle
{
}
PHP);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testLoaderAutoloadsAndCreatesPluginBundlesFromManifest(): void
    {
        $loader = new JsonPluginLoader(new PluginManifestService($this->projectDir));
        $loader->initializePlugins();

        $bundles = iterator_to_array($loader->getBundles('dev', []));

        self::assertCount(1, $bundles);
        self::assertContainsOnlyInstancesOf(BundleInterface::class, $bundles);
        self::assertSame('DemoBundle', $bundles[0]->getName());
    }

    public function testLoaderSkipsInactivePlugins(): void
    {
        file_put_contents($this->projectDir.'/plugins/plugins.json', <<<'JSON'
{
    "plugins": [
        {
            "package": "acme/invalid-autoload",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": true,
            "autoload": "invalid"
        },
        {
            "package": "acme/invalid-psr4",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": true,
            "autoload": {
                "psr-4": "invalid"
            }
        },
        {
            "package": "acme/demo-bundle",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": false,
            "environments": ["all"],
            "autoload": {
                "psr-4": {
                    "Acme\\DemoBundle\\": ["plugins/acme/demo-bundle/src"]
                }
            }
        }
    ]
}
JSON);

        $loader = new JsonPluginLoader(new PluginManifestService($this->projectDir));
        $loader->initializePlugins();

        self::assertSame([], iterator_to_array($loader->getBundles('dev', [])));
    }

    public function testLoaderHonorsEnvironmentFiltersAndSkipsAlreadyInstantiatedBundles(): void
    {
        file_put_contents($this->projectDir.'/plugins/plugins.json', <<<'JSON'
{
    "plugins": [
        {
            "package": "acme/demo-bundle",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": true,
            "environments": ["prod"],
            "autoload": {
                "psr-4": {
                    "Acme\\DemoBundle\\": ["plugins/acme/demo-bundle/src"]
                }
            }
        }
    ]
}
JSON);

        $loader = new JsonPluginLoader(new PluginManifestService($this->projectDir));
        $loader->initializePlugins();

        self::assertSame([], iterator_to_array($loader->getBundles('dev', [])));
        self::assertSame([], iterator_to_array($loader->getBundles('prod', ['DemoBundle'])));
    }

    public function testLoaderThrowsForMissingOrInvalidBundleClasses(): void
    {
        file_put_contents($this->projectDir.'/plugins/acme/demo-bundle/src/NotABundle.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\DemoBundle;

final class NotABundle
{
}
PHP);

        $loader = new JsonPluginLoader(new PluginManifestService($this->projectDir));

        file_put_contents($this->projectDir.'/plugins/plugins.json', <<<'JSON'
{
    "plugins": [
        {
            "package": "acme/demo-bundle",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\MissingBundle",
            "active": true,
            "environments": ["all"],
            "autoload": {
                "psr-4": {
                    "Acme\\DemoBundle\\": ["plugins/acme/demo-bundle/src"]
                }
            }
        }
    ]
}
JSON);
        $loader->initializePlugins();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MissingBundle');
        iterator_to_array($loader->getBundles('dev', []));
    }

    public function testLoaderThrowsWhenResolvedClassIsNotABundle(): void
    {
        file_put_contents($this->projectDir.'/plugins/acme/demo-bundle/src/NotABundle.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\DemoBundle;

final class NotABundle
{
}
PHP);

        file_put_contents($this->projectDir.'/plugins/plugins.json', <<<'JSON'
{
    "plugins": [
        {
            "package": "acme/demo-bundle",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\NotABundle",
            "active": true,
            "environments": ["all"],
            "autoload": {
                "psr-4": {
                    "Acme\\DemoBundle\\": ["plugins/acme/demo-bundle/src"]
                }
            }
        }
    ]
}
JSON);

        $loader = new JsonPluginLoader(new PluginManifestService($this->projectDir));
        $loader->initializePlugins();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kein Symfony-Bundle');
        iterator_to_array($loader->getBundles('dev', []));
    }

    public function testLoaderImportsBundleRoutesAndAutoloadMissesGracefully(): void
    {
        file_put_contents($this->projectDir.'/plugins/plugins.json', <<<'JSON'
{
    "plugins": [
        {
            "package": "acme/demo-bundle",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": true,
            "environments": ["all"],
            "autoload": {
                "psr-4": {
                    "Acme\\DemoBundle\\": ["plugins/acme/demo-bundle/src"]
                }
            },
            "routes": {
                "resource": {
                    "path": "plugins/acme/demo-bundle/config/routes.php",
                    "namespace": "Acme\\DemoBundle\\Controller"
                },
                "type": "attribute"
            }
        }
    ]
}
JSON);
        mkdir($this->projectDir.'/plugins/acme/demo-bundle/config', 0777, true);
        file_put_contents($this->projectDir.'/plugins/acme/demo-bundle/config/routes.php', '<?php return true;');

        $loader = new JsonPluginLoader(new PluginManifestService($this->projectDir));
        $loader->initializePlugins();
        $bundle = current(iterator_to_array($loader->getBundles('dev', [])));
        self::assertInstanceOf(BundleInterface::class, $bundle);

        $imports = [];
        $loaderSpy = new class(new FileLocator(), $imports) extends PhpFileLoader {
            /**
             * @param array<int, array{0: array{path: string, namespace: string}, 1: string}> $imports
             */
            public function __construct(
                FileLocator $locator,
                private array &$imports,
            ) {
                parent::__construct($locator);
            }

            #[\Override]
            public function import(mixed $resource, ?string $type = null, bool $ignoreErrors = false, ?string $sourceResource = null, string|array|null $exclude = null): mixed
            {
                if (is_array($resource) && is_string($type)) {
                    $this->imports[] = [$resource, $type];
                }

                return new RouteCollection();
            }
        };
        $routes = new RoutingConfigurator(new RouteCollection(), $loaderSpy, '', '', null);

        $loader->addRoutesForBundle($bundle, $routes);
        $loader->autoloadPluginClass('Acme\\DemoBundle\\MissingClass');

        self::assertCount(1, $imports);
        self::assertSame('attribute', $imports[0][1]);
        self::assertStringEndsWith('/plugins/acme/demo-bundle/config/routes.php', $imports[0][0]['path']);
        self::assertSame('Acme\\DemoBundle\\Controller', $imports[0][0]['namespace']);
    }

    public function testLoaderHandlesMalformedAutoloadEntriesAndMissingRoutesGracefully(): void
    {
        file_put_contents($this->projectDir.'/plugins/plugins.json', <<<'JSON'
{
    "plugins": [
        {
            "package": "acme/demo-bundle",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": true,
            "environments": "all",
            "autoload": {
                "psr-4": {
                    "Acme\\BrokenOne\\": "plugins/acme/demo-bundle/src",
                    "Acme\\BrokenTwo\\": [123],
                    "Acme\\BrokenThree\\": ["plugins/acme/demo-bundle/src"],
                    "Acme\\DemoBundle\\": ["plugins/acme/demo-bundle/src"]
                }
            }
        }
    ]
}
JSON);

        $loader = new JsonPluginLoader(new PluginManifestService($this->projectDir));
        $loader->initializePlugins();
        $bundles = iterator_to_array($loader->getBundles('dev', []));

        self::assertCount(1, $bundles);
        self::assertSame('DemoBundle', $bundles[0]->getName());

        $routes = new RoutingConfigurator(new RouteCollection(), new PhpFileLoader(new FileLocator()), '', '', null);
        $loader->addRoutesForBundle($bundles[0], $routes);
        $loader->autoloadPluginClass('Acme\\BrokenThree\\MissingClass');

        self::assertTrue(true);
    }

    public function testLoaderSkipsPluginsWithInvalidAutoloadStructuresAfterCollectingRoutes(): void
    {
        file_put_contents($this->projectDir.'/plugins/plugins.json', <<<'JSON'
{
    "plugins": [
        {
            "package": "acme/demo-bundle",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": true,
            "routes": {
                "resource": {
                    "path": "plugins/acme/demo-bundle/config/routes.php",
                    "namespace": "Acme\\DemoBundle\\Controller"
                },
                "type": "attribute"
            },
            "autoload": "invalid"
        },
        {
            "package": "acme/demo-bundle",
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": true,
            "routes": {
                "resource": {
                    "path": "plugins/acme/demo-bundle/config/routes.php",
                    "namespace": "Acme\\DemoBundle\\Controller"
                },
                "type": "attribute"
            },
            "autoload": {
                "psr-4": "invalid"
            }
        }
    ]
}
JSON);
        mkdir($this->projectDir.'/plugins/acme/demo-bundle/config', 0777, true);
        file_put_contents($this->projectDir.'/plugins/acme/demo-bundle/config/routes.php', '<?php return true;');

        $loader = new JsonPluginLoader(new PluginManifestService($this->projectDir));
        $loader->initializePlugins();

        self::assertSame([], iterator_to_array($loader->getBundles('dev', ['DemoBundle'])));
    }

    public function testInitializePluginsSkipsNonArrayAutoloadAndPsr4EntriesFromManifestService(): void
    {
        $manifestService = $this->createStub(PluginManifestService::class);
        $manifestService->method('loadManifest')->willReturn([
            'plugins' => [
                [
                    'active' => true,
                    'autoload' => 'invalid',
                ],
                [
                    'active' => true,
                    'autoload' => [
                        'psr-4' => 'invalid',
                    ],
                ],
            ],
        ]);

        $loader = new JsonPluginLoader($manifestService);
        $loader->initializePlugins();

        self::assertSame([], iterator_to_array($loader->getBundles('dev', [])));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
