<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service;

use AvalynxOffice\Repository\AppSettingRepository;
use AvalynxOffice\Service\ThemeService;
use JBSNewMedia\BootstrapBundle\Service\ScssCompilerFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class ThemeServiceTest extends TestCase
{
    private string $projectDir;

    private string $storedColor = '';

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    #[Group('unit')]
    public function testGetPrimaryColorFallsBackToDefault(): void
    {
        $service = $this->createService();

        self::assertSame(ThemeService::DEFAULT_PRIMARY_COLOR, $service->getPrimaryColor());
    }

    #[Group('unit')]
    public function testGetPrimaryColorReturnsStoredValue(): void
    {
        $this->storedColor = '#FF8800';
        $service = $this->createService();

        self::assertSame('#ff8800', $service->getPrimaryColor());
    }

    #[Group('unit')]
    public function testGetPrimaryColorFallsBackToDefaultForInvalidStoredValue(): void
    {
        $this->storedColor = 'blue';
        $service = $this->createService();

        self::assertSame(ThemeService::DEFAULT_PRIMARY_COLOR, $service->getPrimaryColor());
    }

    #[Group('unit')]
    public function testSetPrimaryColorRejectsInvalidColors(): void
    {
        $service = $this->createService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid primary color');
        $service->setPrimaryColor('blue');
    }

    #[Group('unit')]
    public function testSetPrimaryColorStoresAndCompiles(): void
    {
        $service = $this->createService();

        $service->setPrimaryColor('#FF8800');

        self::assertSame('#ff8800', $this->storedColor);
        self::assertFileExists($this->projectDir.'/assets/css/bootstrap.css');
        self::assertFileExists($this->projectDir.'/assets/css/bootstrap.min.css');
        self::assertStringContainsString('color: #ff8800;', (string) file_get_contents($this->projectDir.'/assets/css/bootstrap.css'));
        self::assertStringContainsString('$primary: #ff8800;', (string) file_get_contents($this->projectDir.'/var/theme/bootstrap.scss'));
    }

    #[Group('unit')]
    public function testReplacePrimaryColorRewritesVariable(): void
    {
        $service = $this->createService();

        $result = $service->replacePrimaryColor('$primary: #2563EB;', '#112233');

        self::assertSame('$primary: #112233;', $result);
    }

    #[Group('unit')]
    public function testReplacePrimaryColorFailsWhenVariableIsMissing(): void
    {
        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to locate the $primary variable');
        $service->replacePrimaryColor('body { color: red; }', '#112233');
    }

    #[Group('unit')]
    public function testCompileFailsWhenSourceIsMissing(): void
    {
        unlink($this->projectDir.'/assets/scss/bootstrap.scss');
        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to read');
        $service->compile('#ff8800');
    }

    #[Group('unit')]
    public function testCompileFailsWhenThemeDirectoryIsAFile(): void
    {
        @mkdir($this->projectDir.'/var', 0777, true);
        touch($this->projectDir.'/var/theme');
        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to create theme directory');
        $service->compile('#ff8800');
    }

    #[Group('unit')]
    public function testCompileFailsOnBrokenScss(): void
    {
        file_put_contents(
            $this->projectDir.'/assets/scss/bootstrap.scss',
            '$primary: #2563eb;'.PHP_EOL."@import 'this-file-does-not-exist';".PHP_EOL,
        );
        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SCSS compilation failed');
        $service->compile('#ff8800');
    }

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/avalynxoffice-theme-test-'.bin2hex(random_bytes(4));
        @mkdir($this->projectDir.'/assets/scss', 0777, true);
        @mkdir($this->projectDir.'/assets/css', 0777, true);
        file_put_contents(
            $this->projectDir.'/assets/scss/bootstrap.scss',
            '$primary: #2563eb;'.PHP_EOL.'body { color: $primary; }'.PHP_EOL,
        );
    }

    private function createService(): ThemeService
    {
        $repository = $this->createStub(AppSettingRepository::class);
        $repository->method('getValue')->willReturnCallback(function (string $key): ?string {
            return '' === $this->storedColor ? null : $this->storedColor;
        });
        $repository->method('setValue')->willReturnCallback(function (string $key, string $value): void {
            $this->storedColor = $value;
        });

        return new ThemeService($repository, new ScssCompilerFactory(), $this->projectDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
