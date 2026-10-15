<?php

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use App\Service\PluginManifestService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader as ContainerPhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\Routing\Loader\PhpFileLoader as RoutingPhpFileLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouteCollection;

final class KernelTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/mailer-kernel-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->projectDir.'/config/routes/test', 0777, true) || is_dir($this->projectDir.'/config/routes/test'));
        self::assertTrue(mkdir($this->projectDir.'/config/packages/test', 0777, true) || is_dir($this->projectDir.'/config/packages/test'));
        self::assertTrue(mkdir($this->projectDir.'/plugins', 0777, true) || is_dir($this->projectDir.'/plugins'));
        self::assertNotFalse(file_put_contents($this->projectDir.'/config/bundles.php', '<?php return [];'));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testBuildTracksPluginManifestAndPluginComposerFiles(): void
    {
        $kernel = new class('test', true) extends Kernel {
            public function exposeBuild(ContainerBuilder $container): void
            {
                $this->build($container);
            }
        };
        $container = new ContainerBuilder();

        $kernel->exposeBuild($container);

        $resources = $container->getResources();
        $manifestPath = realpath($kernel->getProjectDir().'/'.PluginManifestService::MANIFEST_RELATIVE_PATH);
        $pluginsDir = realpath($kernel->getProjectDir().'/plugins');

        self::assertNotFalse($manifestPath);
        self::assertNotFalse($pluginsDir);
        self::assertTrue($this->hasManifestResource($resources, $manifestPath));
        self::assertTrue($this->hasPluginDirectoryResource($resources, $pluginsDir));
    }

    public function testRegisterBundlesYieldsSymfonyBundles(): void
    {
        $bundles = iterator_to_array((new Kernel('test', true))->registerBundles());

        self::assertNotSame([], $bundles);
        self::assertContainsOnlyInstancesOf(BundleInterface::class, $bundles);
    }

    public function testConfigureRoutesImportsProjectAndBundleYamlRoutes(): void
    {
        self::assertNotFalse(file_put_contents($this->projectDir.'/config/routes.yaml', 'index:'));

        $bundleDir = $this->projectDir.'/bundle';
        self::assertTrue(mkdir($bundleDir.'/config', 0777, true) || is_dir($bundleDir.'/config'));
        self::assertNotFalse(file_put_contents($bundleDir.'/config/routes.yaml', 'bundle:'));

        $kernel = new TestKernel($this->projectDir, [
            new class($bundleDir) extends Bundle {
                public function __construct(private readonly string $bundleDir)
                {
                }

                #[\Override]
                public function getPath(): string
                {
                    return $this->bundleDir;
                }
            },
        ]);

        $imports = [];
        $loader = new class(new FileLocator(), $imports) extends RoutingPhpFileLoader {
            /**
             * @param list<string> $imports
             */
            public function __construct(
                FileLocator $locator,
                private array &$imports,
            ) {
                parent::__construct($locator);
            }

            #[\Override]
            public function import(mixed $resource, ?string $type = null, string|bool $ignoreErrors = false, ?string $sourceResource = null, $exclude = null): mixed
            {
                if (is_string($resource)) {
                    $this->imports[] = $resource;
                }

                return new RouteCollection();
            }
        };

        $kernel->exposeConfigureRoutes(new RoutingConfigurator(new RouteCollection(), $loader, '', '', null));

        self::assertContains('../config/{routes}/test/*.yaml', $imports);
        self::assertContains('../config/{routes}/*.yaml', $imports);
        self::assertContains('../config/{routes}.yaml', $imports);
        self::assertContains($bundleDir.'/config/{routes}.yaml', $imports);
    }

    public function testConfigureRoutesUsesPhpFallbackWhenYamlFileIsMissing(): void
    {
        $configDir = dirname(__DIR__).'/config';
        $yamlPath = $configDir.'/routes.yaml';
        $phpPath = $configDir.'/routes.php';
        $backupPath = $configDir.'/routes.yaml.copilot-backup';
        $markerPath = sys_get_temp_dir().'/kernel-routes-php-called-'.bin2hex(random_bytes(8));

        self::assertTrue(rename($yamlPath, $backupPath));
        self::assertNotFalse(file_put_contents(
            $phpPath,
            <<<'PHP'
<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes, Kernel $kernel): void {
    file_put_contents($_ENV['KERNEL_TEST_ROUTE_MARKER'], '1');
};
PHP,
        ));

        try {
            $_ENV['KERNEL_TEST_ROUTE_MARKER'] = $markerPath;

            $kernel = new TestKernel($this->projectDir);
            $loader = new class(new FileLocator()) extends RoutingPhpFileLoader {
                #[\Override]
                public function import(mixed $resource, ?string $type = null, string|bool $ignoreErrors = false, ?string $sourceResource = null, $exclude = null): mixed
                {
                    return new RouteCollection();
                }
            };

            $kernel->exposeConfigureRoutes(new RoutingConfigurator(new RouteCollection(), $loader, '', '', null));

            self::assertFileExists($markerPath);
        } finally {
            unset($_ENV['KERNEL_TEST_ROUTE_MARKER']);
            @unlink($phpPath);
            @unlink($markerPath);
            rename($backupPath, $yamlPath);
        }
    }

    public function testConfigureRoutesUsesBundlePhpFallbackWhenBundleYamlIsMissing(): void
    {
        $bundleDir = $this->projectDir.'/bundle-php';
        self::assertTrue(mkdir($bundleDir.'/config', 0777, true) || is_dir($bundleDir.'/config'));
        $markerPath = sys_get_temp_dir().'/kernel-bundle-routes-php-called-'.bin2hex(random_bytes(8));
        self::assertNotFalse(file_put_contents(
            $bundleDir.'/config/routes.php',
            <<<'PHP'
<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes, Kernel $kernel): void {
    file_put_contents($_ENV['KERNEL_TEST_BUNDLE_ROUTE_MARKER'], '1');
};
PHP,
        ));

        $_ENV['KERNEL_TEST_BUNDLE_ROUTE_MARKER'] = $markerPath;

        try {
            $kernel = new TestKernel($this->projectDir, [
                new class($bundleDir) extends Bundle {
                    public function __construct(private readonly string $bundleDir)
                    {
                    }

                    #[\Override]
                    public function getPath(): string
                    {
                        return $this->bundleDir;
                    }
                },
            ]);

            $loader = new class(new FileLocator()) extends RoutingPhpFileLoader {
                #[\Override]
                public function import(mixed $resource, ?string $type = null, string|bool $ignoreErrors = false, ?string $sourceResource = null, $exclude = null): mixed
                {
                    return new RouteCollection();
                }
            };

            $kernel->exposeConfigureRoutes(new RoutingConfigurator(new RouteCollection(), $loader, '', '', null));

            self::assertFileExists($markerPath);
        } finally {
            unset($_ENV['KERNEL_TEST_BUNDLE_ROUTE_MARKER']);
            @unlink($bundleDir.'/config/routes.php');
            @rmdir($bundleDir.'/config');
            @rmdir($bundleDir);
            @unlink($markerPath);
        }
    }

    public function testConfigureContainerImportsYamlServices(): void
    {
        self::assertNotFalse(file_put_contents($this->projectDir.'/config/services.yaml', "services:\n"));

        $imports = [];
        $loader = new class(new FileLocator(), $imports) extends ContainerPhpFileLoader {
            /**
             * @param list<string> $imports
             */
            public function __construct(
                FileLocator $locator,
                private array &$imports,
            ) {
                parent::__construct(new ContainerBuilder(), $locator);
            }

            #[\Override]
            public function import(mixed $resource, ?string $type = null, string|bool $ignoreErrors = false, ?string $sourceResource = null, $exclude = null): mixed
            {
                if (is_string($resource)) {
                    $this->imports[] = $resource;
                }

                return null;
            }
        };

        $kernel = new TestKernel($this->projectDir);
        $containerBuilder = new ContainerBuilder();
        $instanceof = [];
        $kernel->exposeConfigureContainer(new ContainerConfigurator($containerBuilder, $loader, $instanceof, '', '', 'test'));

        self::assertContains('../config/{packages}/*.yaml', $imports);
        self::assertContains('../config/{packages}/test/*.yaml', $imports);
        self::assertContains('../config/{services}.yaml', $imports);
        self::assertContains('../config/{services}_test.yaml', $imports);
    }

    public function testConfigureContainerUsesPhpFallbackWhenYamlFileIsMissing(): void
    {
        $configDir = dirname(__DIR__).'/config';
        $yamlPath = $configDir.'/services.yaml';
        $phpPath = $configDir.'/services.php';
        $backupPath = $configDir.'/services.yaml.copilot-backup';
        $markerPath = sys_get_temp_dir().'/kernel-services-php-called-'.bin2hex(random_bytes(8));

        self::assertTrue(rename($yamlPath, $backupPath));
        self::assertNotFalse(file_put_contents(
            $phpPath,
            <<<'PHP'
<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container, Kernel $kernel): void {
    file_put_contents($_ENV['KERNEL_TEST_SERVICES_MARKER'], '1');
};
PHP,
        ));

        try {
            $_ENV['KERNEL_TEST_SERVICES_MARKER'] = $markerPath;

            $loader = new class(new FileLocator()) extends ContainerPhpFileLoader {
                public function __construct(FileLocator $locator)
                {
                    parent::__construct(new ContainerBuilder(), $locator);
                }

                #[\Override]
                public function import(mixed $resource, ?string $type = null, string|bool $ignoreErrors = false, ?string $sourceResource = null, $exclude = null): mixed
                {
                    return null;
                }
            };

            $kernel = new TestKernel($this->projectDir);
            $instanceof = [];
            $kernel->exposeConfigureContainer(new ContainerConfigurator(new ContainerBuilder(), $loader, $instanceof, '', '', 'test'));

            self::assertFileExists($markerPath);
        } finally {
            unset($_ENV['KERNEL_TEST_SERVICES_MARKER']);
            @unlink($phpPath);
            @unlink($markerPath);
            rename($backupPath, $yamlPath);
        }
    }

    /**
     * @param array<int, object> $resources
     */
    private function hasManifestResource(array $resources, string $manifestPath): bool
    {
        foreach ($resources as $resource) {
            if ($resource instanceof FileResource && $resource->getResource() === $manifestPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, object> $resources
     */
    private function hasPluginDirectoryResource(array $resources, string $pluginsDir): bool
    {
        foreach ($resources as $resource) {
            if (
                $resource instanceof DirectoryResource
                && $resource->getResource() === $pluginsDir
                && $resource->getPattern() === '/^composer\.json$/'
            ) {
                return true;
            }
        }

        return false;
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

final class TestKernel extends Kernel
{
    /**
     * @param iterable<BundleInterface> $bundles
     */
    public function __construct(
        private readonly string $projectDirOverride,
        private readonly iterable $testBundles = [],
    ) {
        parent::__construct('test', true);
    }

    #[\Override]
    public function getProjectDir(): string
    {
        return $this->projectDirOverride;
    }

    /**
     * @return iterable<BundleInterface>
     */
    #[\Override]
    public function getBundles(): array
    {
        return is_array($this->testBundles) ? $this->testBundles : iterator_to_array($this->testBundles);
    }

    public function exposeConfigureRoutes(RoutingConfigurator $routes): void
    {
        $this->configureRoutes($routes);
    }

    public function exposeConfigureContainer(ContainerConfigurator $container): void
    {
        $this->configureContainer($container);
    }
}
