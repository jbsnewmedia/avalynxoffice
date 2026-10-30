<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Service\PluginManifestService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginManifestServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/mailer-plugin-manifest-'.bin2hex(random_bytes(8));
        mkdir($this->projectDir.'/plugins/acme/demo-bundle/src/Controller', 0777, true);
        mkdir($this->projectDir.'/plugins/acme/view-bundle/src', 0777, true);

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
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "environments": ["dev", "test"]
        }
    }
}
JSON);

        file_put_contents($this->projectDir.'/plugins/acme/view-bundle/composer.json', <<<'JSON'
{
    "name": "acme/view-bundle",
    "autoload": {
        "psr-4": {
            "Acme\\ViewBundle\\": "src/"
        }
    },
    "extra": {
        "symfony": {
            "bundle": "Acme\\ViewBundle\\ViewBundle"
        }
    }
}
JSON);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function testRefreshManifestWritesBundleAutoloadAndRouteMetadata(): void
    {
        $service = new PluginManifestService($this->projectDir);

        $manifest = $service->refreshManifest();

        self::assertFileExists($service->getManifestPath());
        self::assertCount(2, $manifest['plugins']);
        self::assertSame('acme/demo-bundle', $manifest['plugins'][0]['package']);
        self::assertSame('Acme\\DemoBundle\\DemoBundle', $manifest['plugins'][0]['bundle']);
        self::assertTrue($manifest['plugins'][0]['active']);
        self::assertSame(['dev', 'test'], $manifest['plugins'][0]['environments']);
        self::assertSame(
            ['Acme\\DemoBundle\\' => ['plugins/acme/demo-bundle/src']],
            $manifest['plugins'][0]['autoload']['psr-4'],
        );
        self::assertSame(
            [
                'resource' => [
                    'path' => 'plugins/acme/demo-bundle/src/Controller',
                    'namespace' => 'Acme\\DemoBundle\\Controller',
                ],
                'type' => 'attribute',
            ],
            $manifest['plugins'][0]['routes'],
        );
        self::assertSame('acme/view-bundle', $manifest['plugins'][1]['package']);
        self::assertSame('Acme\\ViewBundle\\ViewBundle', $manifest['plugins'][1]['bundle']);
        self::assertTrue($manifest['plugins'][1]['active']);
        self::assertArrayNotHasKey('routes', $manifest['plugins'][1]);
    }

    public function testRefreshManifestPreservesExistingActiveState(): void
    {
        $service = new PluginManifestService($this->projectDir);

        file_put_contents($service->getManifestPath(), <<<'JSON'
{
    "plugins": [
        {
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": false
        }
    ]
}
JSON);

        $manifest = $service->refreshManifest();

        self::assertFalse($manifest['plugins'][0]['active']);
    }

    public function testLoadManifestReturnsFreshManifestAndResolvesAbsolutePaths(): void
    {
        $service = new PluginManifestService($this->projectDir);
        $expected = $service->refreshManifest();

        self::assertSame($expected, $service->loadManifest());
        self::assertSame(
            $this->projectDir.'/plugins/acme/demo-bundle',
            $service->toAbsolutePath('/plugins/acme/demo-bundle'),
        );
    }

    public function testRefreshManifestSkipsPluginsWithoutBundleAndNormalizesMetadata(): void
    {
        mkdir($this->projectDir.'/plugins/acme/extra-bundle/config', 0777, true);
        mkdir($this->projectDir.'/plugins/acme/extra-bundle/src', 0777, true);
        mkdir($this->projectDir.'/plugins/acme/no-bundle/src', 0777, true);

        file_put_contents($this->projectDir.'/plugins/acme/extra-bundle/config/routes.yaml', "demo: ~\n");
        file_put_contents($this->projectDir.'/plugins/acme/extra-bundle/composer.json', <<<'JSON'
{
    "name": "acme/extra-bundle",
    "autoload": {
        "psr-4": {
            "Acme\\ExtraBundle\\": ["src/", "src/"]
        }
    },
    "extra": {
        "symfony": {
            "bundle": "Acme\\ExtraBundle\\ExtraBundle",
            "environments": ["prod", "", "prod"]
        }
    }
}
JSON);
        file_put_contents($this->projectDir.'/plugins/acme/no-bundle/composer.json', <<<'JSON'
{
    "name": "acme/no-bundle",
    "autoload": {
        "psr-4": {
            "Acme\\NoBundle\\": "src/"
        }
    }
}
JSON);

        $service = new PluginManifestService($this->projectDir);
        $manifest = $service->refreshManifest();

        self::assertCount(3, $manifest['plugins']);
        self::assertSame('acme/extra-bundle', $manifest['plugins'][1]['package']);
        self::assertSame(['prod'], $manifest['plugins'][1]['environments']);
        self::assertSame(
            ['Acme\\ExtraBundle\\' => ['plugins/acme/extra-bundle/src']],
            $manifest['plugins'][1]['autoload']['psr-4'],
        );
        self::assertArrayNotHasKey('routes', $manifest['plugins'][1]);
        self::assertSame(['all'], $manifest['plugins'][2]['environments']);
    }

    public function testRefreshManifestThrowsForInvalidComposerJson(): void
    {
        file_put_contents($this->projectDir.'/plugins/acme/view-bundle/composer.json', '{invalid');

        $service = new PluginManifestService($this->projectDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');
        $service->refreshManifest();
    }

    public function testLoadManifestRefreshesWhenStoredManifestIsInvalid(): void
    {
        $service = new PluginManifestService($this->projectDir);

        file_put_contents($service->getManifestPath(), '{invalid');
        $manifestFromInvalidJson = $service->loadManifest();
        self::assertCount(2, $manifestFromInvalidJson['plugins']);

        file_put_contents($service->getManifestPath(), '{"plugins":"invalid"}');
        $manifestFromInvalidFormat = $service->loadManifest();
        self::assertCount(2, $manifestFromInvalidFormat['plugins']);
    }

    public function testLoadManifestThrowsForFreshManifestWithInvalidJsonOrFormat(): void
    {
        $manifestPath = $this->projectDir.'/plugins/plugins.json';
        self::assertTrue(mkdir(dirname($manifestPath), 0777, true) || is_dir(dirname($manifestPath)));
        self::assertNotFalse(file_put_contents($manifestPath, '{}'));

        $invalidJsonService = new TestablePluginManifestService(
            $this->projectDir,
            isManifestFresh: static fn (): bool => true,
            readJsonFile: static function (): array {
                throw new \JsonException('broken');
            },
        );

        try {
            $invalidJsonService->loadManifest();
            self::fail('Expected invalid JSON exception.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('is not valid JSON', $exception->getMessage());
        }

        $invalidFormatService = new TestablePluginManifestService(
            $this->projectDir,
            isManifestFresh: static fn (): bool => true,
            readJsonFile: static function (): array {
                return ['plugins' => 'invalid'];
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected format');
        $invalidFormatService->loadManifest();
    }

    public function testPrivateManifestHelpersHandleMalformedManifestFiles(): void
    {
        $service = new PluginManifestService($this->projectDir);

        $isManifestFresh = new \ReflectionMethod(PluginManifestService::class, 'isManifestFresh');
        self::assertFalse($isManifestFresh->invoke($service));

        file_put_contents($service->getManifestPath(), '{invalid');
        self::assertFalse($isManifestFresh->invoke($service));

        file_put_contents($service->getManifestPath(), '{"plugins":"invalid"}');
        self::assertFalse($isManifestFresh->invoke($service));

        $manifest = [
            'plugins' => [
                ['path' => 'plugins/acme/demo-bundle'],
                ['path' => 'plugins/acme/view-bundle'],
            ],
        ];
        file_put_contents($service->getManifestPath(), json_encode($manifest, JSON_THROW_ON_ERROR));
        touch($service->getManifestPath(), time() - 10);
        touch($this->projectDir.'/plugins/acme/demo-bundle/composer.json', time());
        self::assertFalse($isManifestFresh->invoke($service));

        $readManifestFile = new \ReflectionMethod(PluginManifestService::class, 'readManifestFile');

        file_put_contents($service->getManifestPath(), '{invalid');
        self::assertNull($readManifestFile->invoke($service));

        file_put_contents($service->getManifestPath(), '{"plugins":"invalid"}');
        self::assertNull($readManifestFile->invoke($service));
    }

    public function testPrivateComposerAndPathHelpersCoverEdgeCases(): void
    {
        $service = new PluginManifestService($this->projectDir);

        $composerFile = $this->projectDir.'/plugins/acme/demo-bundle/composer.json';
        file_put_contents($composerFile, '123');

        $decodeComposerFile = new \ReflectionMethod(PluginManifestService::class, 'decodeComposerFile');
        try {
            $decodeComposerFile->invoke($service, $composerFile);
            self::fail('Expected invalid composer format exception.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('expected format', (string) $exception->getMessage());
        }

        $normalizePsr4Autoload = new \ReflectionMethod(PluginManifestService::class, 'normalizePsr4Autoload');
        self::assertSame([
            'Acme\\Demo\\' => ['plugins/acme/demo-bundle/src'],
        ], $normalizePsr4Autoload->invoke($service, [
            123 => ['src'],
            'Acme\\Broken\\' => [123],
            'Acme\\Demo\\' => ['src/', '', 'src/'],
        ], $this->projectDir.'/plugins/acme/demo-bundle'));

        self::assertTrue(mkdir($this->projectDir.'/plugins/acme/controller-only/src/Controller', 0777, true) || is_dir($this->projectDir.'/plugins/acme/controller-only/src/Controller'));
        $createRouteFallback = new \ReflectionMethod(PluginManifestService::class, 'createRouteFallback');
        self::assertNull($createRouteFallback->invoke(
            $service,
            'InvalidBundleClass',
            $this->projectDir.'/plugins/acme/controller-only',
            'plugins/acme/controller-only',
        ));

        $getNestedString = new \ReflectionMethod(PluginManifestService::class, 'getNestedString');
        self::assertNull($getNestedString->invoke($service, ['key' => 'value']));

        $toRelativePath = new \ReflectionMethod(PluginManifestService::class, 'toRelativePath');
        self::assertSame('var/outside/project', $toRelativePath->invoke($service, '/var/outside/project'));
    }

    public function testManifestHelpersValidateShapesAndDefaultPluginState(): void
    {
        $service = new PluginManifestService($this->projectDir);

        $stringKeyedArray = new \ReflectionMethod(PluginManifestService::class, 'stringKeyedArray');
        self::assertNull($stringKeyedArray->invoke($service, 'nope'));
        self::assertNull($stringKeyedArray->invoke($service, [123 => 'int-keyed']));
        self::assertSame(['key' => 'value'], $stringKeyedArray->invoke($service, ['key' => 'value']));

        $extractPluginList = new \ReflectionMethod(PluginManifestService::class, 'extractPluginList');
        self::assertNull($extractPluginList->invoke($service, []));
        self::assertNull($extractPluginList->invoke($service, ['plugins' => 'invalid']));
        self::assertNull($extractPluginList->invoke($service, ['plugins' => [0 => ['path' => 'a'], 2 => ['path' => 'b']]]));
        self::assertNull($extractPluginList->invoke($service, ['plugins' => [['path' => 'a'], 'no-array']]));
        self::assertSame([], $extractPluginList->invoke($service, ['plugins' => []]));
        self::assertSame([['path' => 'a']], $extractPluginList->invoke($service, ['plugins' => [['path' => 'a']]]));

        $discoverPlugins = new \ReflectionMethod(PluginManifestService::class, 'discoverPlugins');
        /** @var list<array<string, mixed>> $plugins */
        $plugins = $discoverPlugins->invoke($service, []);

        $demoBundle = null;
        foreach ($plugins as $plugin) {
            if ('plugins/acme/demo-bundle' === $plugin['path']) {
                $demoBundle = $plugin;
            }
        }

        self::assertNotNull($demoBundle);
        self::assertTrue($demoBundle['active']);

        /** @var list<array<string, mixed>> $pluginsWithBundleState */
        $pluginsWithBundleState = $discoverPlugins->invoke($service, ['Acme\\DemoBundle\\DemoBundle' => false]);

        foreach ($pluginsWithBundleState as $plugin) {
            if ('plugins/acme/demo-bundle' === $plugin['path']) {
                self::assertFalse($plugin['active']);
            }
        }
    }

    public function testToAbsolutePathResolvesPathsInsideThePluginsDirectory(): void
    {
        $service = new PluginManifestService($this->projectDir);

        self::assertSame($this->projectDir.'/plugins/acme/demo-bundle/src', $service->toAbsolutePath('plugins/acme/demo-bundle/src'));
        self::assertSame($this->projectDir.'/plugins/acme/demo-bundle/src', $service->toAbsolutePath('/plugins/acme/./demo-bundle/src/'));
        self::assertSame($this->projectDir.'/plugins/acme/demo-bundle', $service->toAbsolutePath('plugins/acme/view-bundle/../demo-bundle'));
        self::assertSame($this->projectDir.'/plugins', $service->toAbsolutePath('plugins'));
        self::assertSame($this->projectDir.'/plugins/acme/missing', $service->toAbsolutePath('plugins/acme/missing'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideTraversalPaths(): iterable
    {
        yield 'parent traversal' => ['plugins/../src'];
        yield 'deep traversal' => ['plugins/acme/../../../etc'];
        yield 'project root file' => ['config/services.yaml'];
        yield 'absolute path' => ['/etc/passwd'];
        yield 'plugins prefix without separator' => ['plugins-evil/src'];
        yield 'empty path' => [''];
    }

    #[DataProvider('provideTraversalPaths')]
    public function testToAbsolutePathRejectsPathsOutsideThePluginsDirectory(string $path): void
    {
        $service = new PluginManifestService($this->projectDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside of the plugins directory');
        $service->toAbsolutePath($path);
    }

    public function testToAbsolutePathRejectsSymlinksEscapingThePluginsDirectory(): void
    {
        mkdir($this->projectDir.'/secret', 0777, true);
        self::assertTrue(symlink($this->projectDir.'/secret', $this->projectDir.'/plugins/escape'));

        $service = new PluginManifestService($this->projectDir);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resolves outside of the plugins directory');
        $service->toAbsolutePath('plugins/escape');
    }

    public function testLoadManifestWithoutAutoRefreshThrowsWhenManifestIsMissing(): void
    {
        $service = new PluginManifestService($this->projectDir, autoRefresh: false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('app:plugin:manifest');
        $service->loadManifest();
    }

    public function testLoadManifestWithoutAutoRefreshReadsStaleManifestWithoutRewritingIt(): void
    {
        $service = new PluginManifestService($this->projectDir, autoRefresh: false);
        $staleManifest = <<<'JSON'
{
    "plugins": [
        {
            "path": "plugins/acme/demo-bundle",
            "bundle": "Acme\\DemoBundle\\DemoBundle",
            "active": true
        }
    ]
}
JSON;
        file_put_contents($service->getManifestPath(), $staleManifest);
        // Make the manifest older than the plugin composer files: with
        // auto-refresh enabled this would trigger a regeneration.
        touch($service->getManifestPath(), time() - 3600);

        $manifest = $service->loadManifest();

        self::assertCount(1, $manifest['plugins']);
        self::assertSame($staleManifest, file_get_contents($service->getManifestPath()));
    }

    public function testLoadManifestWithAutoRefreshRegeneratesStaleManifest(): void
    {
        $service = new PluginManifestService($this->projectDir);
        file_put_contents($service->getManifestPath(), '{"plugins": []}');
        touch($service->getManifestPath(), time() - 3600);

        $manifest = $service->loadManifest();

        self::assertCount(2, $manifest['plugins']);
    }

    public function testRefreshManifestThrowsWhenManifestCannotBeWritten(): void
    {
        $service = new TestablePluginManifestService(
            $this->projectDir,
            writeFile: static fn (): bool => false,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not be written');
        $service->refreshManifest();
    }

    public function testPrivateManifestFreshnessReturnsFalseWhenManifestTimestampCannotBeRead(): void
    {
        $manifestPath = $this->projectDir.'/plugins/plugins.json';
        $manifestDirectory = dirname($manifestPath);
        self::assertTrue(is_dir($manifestDirectory) || mkdir($manifestDirectory, 0777, true));
        self::assertNotFalse(file_put_contents($manifestPath, json_encode(['plugins' => []], JSON_THROW_ON_ERROR)));

        $service = new TestablePluginManifestService(
            $this->projectDir,
            getFileMTime: static fn (string $path): int|false => str_ends_with($path, 'plugins/plugins.json') ? false : filemtime($path),
        );

        $isManifestFresh = new \ReflectionMethod(PluginManifestService::class, 'isManifestFresh');
        self::assertFalse($isManifestFresh->invoke($service));
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

final class TestablePluginManifestService extends PluginManifestService
{
    /**
     * @param null|callable(string): array<string, mixed>|list<mixed> $readJsonFile
     * @param null|callable(string, string): bool $writeFile
     * @param null|callable(string): int|false $getFileMTime
     * @param null|callable(): bool $isManifestFresh
     */
    public function __construct(
        string $projectDir,
        private readonly mixed $readJsonFile = null,
        private readonly mixed $writeFile = null,
        private readonly mixed $getFileMTime = null,
        private readonly mixed $isManifestFresh = null,
    ) {
        parent::__construct($projectDir);
    }

    #[\Override]
    protected function readJsonFile(string $path): array
    {
        if (null !== $this->readJsonFile) {
            return ($this->readJsonFile)($path);
        }

        return parent::readJsonFile($path);
    }

    #[\Override]
    protected function writeFile(string $path, string $contents): bool
    {
        if (null !== $this->writeFile) {
            return ($this->writeFile)($path, $contents);
        }

        return parent::writeFile($path, $contents);
    }

    #[\Override]
    protected function getFileMTime(string $path): int|false
    {
        if (null !== $this->getFileMTime) {
            return ($this->getFileMTime)($path);
        }

        return parent::getFileMTime($path);
    }

    #[\Override]
    protected function isManifestFresh(): bool
    {
        if (null !== $this->isManifestFresh) {
            return ($this->isManifestFresh)();
        }

        return parent::isManifestFresh();
    }
}
