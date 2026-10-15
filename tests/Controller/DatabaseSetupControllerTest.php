<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\DatabaseSetupController;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DatabaseSetupControllerTest extends KernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->projectDir = sys_get_temp_dir().'/mailer-db-setup-'.bin2hex(random_bytes(4));
        mkdir($this->projectDir.'/bin', 0777, true);
        mkdir($this->projectDir.'/migrations', 0777, true);
        file_put_contents($this->projectDir.'/migrations/Version0001.php', '<?php return true;');
        file_put_contents($this->projectDir.'/bin/console', <<<'PHP'
<?php
$command = $argv[1] ?? '';
fwrite(STDOUT, "\033[31mansi\033[0m\n");
fwrite(STDOUT, "{\"channel\":\"deprecation\"}\n");
fwrite(STDOUT, "User Deprecated: legacy call\n");
if ($command === 'make:migration') {
    fwrite(STDOUT, "No changes detected\n");
    exit(1);
}
fwrite(STDOUT, "ran ".$command."\n");
exit(0);
PHP);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (!is_dir($this->projectDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($this->projectDir);
    }

    public function testSetupExecutesPrepareAndCommandStepsForWritableProject(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []): string => strtr($id, $parameters));

        $controller = new DatabaseSetupController($translator);
        static::getContainer()->get('request_stack')->push(Request::create('/admin/database/setup'));
        $controller->setContainer(new ServiceLocator([
            'twig' => static fn () => static::getContainer()->get('twig'),
        ]));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);
        $kernel->method('getEnvironment')->willReturn('dev');

        $response = $controller->setup($kernel);

        self::assertStringContainsString('database_setup.migrations_cleared', $response->getContent());
        self::assertStringContainsString('database_setup.migrations_writable', $response->getContent());
        self::assertStringContainsString('ran doctrine:database:drop', $response->getContent());
        self::assertStringContainsString('No changes detected', $response->getContent());
        self::assertStringContainsString('ran doctrine:migrations:migrate', $response->getContent());
        self::assertStringContainsString('ran doctrine:fixtures:load', $response->getContent());
        self::assertStringNotContainsString('"channel":"deprecation"', $response->getContent());
        self::assertStringNotContainsString('User Deprecated:', $response->getContent());
        self::assertFileDoesNotExist($this->projectDir.'/migrations/Version0001.php');
    }

    public function testSetupKeepsExistingMigrationsInProduction(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []): string => strtr($id, $parameters));

        $controller = new DatabaseSetupController($translator);
        static::getContainer()->get('request_stack')->push(Request::create('/admin/database/setup'));
        $controller->setContainer(new ServiceLocator([
            'twig' => static fn () => static::getContainer()->get('twig'),
        ]));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);
        $kernel->method('getEnvironment')->willReturn('prod');

        $response = $controller->setup($kernel);

        self::assertStringContainsString('database_setup.migrations_kept', $response->getContent());
        self::assertStringNotContainsString('make:migration', $response->getContent());
        self::assertFileExists($this->projectDir.'/migrations/Version0001.php');
    }

    public function testSetupReturnsPreparationErrorWhenMigrationsDirectoryCannotBeCreated(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []): string => strtr($id, $parameters));

        $brokenProjectDir = sys_get_temp_dir().'/mailer-db-setup-broken-'.bin2hex(random_bytes(4));
        mkdir($brokenProjectDir.'/bin', 0777, true);
        file_put_contents($brokenProjectDir.'/bin/console', '<?php exit(0);');
        file_put_contents($brokenProjectDir.'/migrations', 'not-a-directory');

        $controller = new DatabaseSetupController($translator);
        static::getContainer()->get('request_stack')->push(Request::create('/admin/database/setup'));
        $controller->setContainer(new ServiceLocator([
            'twig' => static fn () => static::getContainer()->get('twig'),
        ]));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($brokenProjectDir);
        $kernel->method('getEnvironment')->willReturn('dev');

        $handlerInstalled = false;
        try {
            set_error_handler(static fn (): bool => true);
            $handlerInstalled = true;
            $response = $controller->setup($kernel);
            restore_error_handler();
            $handlerInstalled = false;
            self::assertStringContainsString('database_setup.error_prefix', $response->getContent());
            self::assertStringNotContainsString('doctrine:database:drop', $response->getContent());
        } finally {
            if ($handlerInstalled) {
                restore_error_handler();
            }
            unlink($brokenProjectDir.'/migrations');
            unlink($brokenProjectDir.'/bin/console');
            rmdir($brokenProjectDir.'/bin');
            rmdir($brokenProjectDir);
        }
    }

    public function testSetupCreatesMigrationsDirectoryWhenMissing(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []): string => strtr($id, $parameters));

        $freshProjectDir = sys_get_temp_dir().'/mailer-db-setup-fresh-'.bin2hex(random_bytes(4));
        mkdir($freshProjectDir.'/bin', 0777, true);
        file_put_contents($freshProjectDir.'/bin/console', '<?php fwrite(STDOUT, "ran ".($argv[1] ?? "")."\n"); exit(0);');

        $controller = new DatabaseSetupController($translator);
        static::getContainer()->get('request_stack')->push(Request::create('/admin/database/setup'));
        $controller->setContainer(new ServiceLocator([
            'twig' => static fn () => static::getContainer()->get('twig'),
        ]));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($freshProjectDir);
        $kernel->method('getEnvironment')->willReturn('dev');

        try {
            $response = $controller->setup($kernel);

            self::assertStringContainsString('database_setup.migrations_directory_created', $response->getContent());
            self::assertDirectoryExists($freshProjectDir.'/migrations');
        } finally {
            $this->removeDirectory($freshProjectDir);
        }
    }

    public function testSetupReturnsPreparationErrorWhenMigrationsDirectoryIsNotWritable(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []): string => strtr($id, $parameters));

        $readOnlyProjectDir = sys_get_temp_dir().'/mailer-db-setup-ro-'.bin2hex(random_bytes(4));
        mkdir($readOnlyProjectDir.'/bin', 0777, true);
        file_put_contents($readOnlyProjectDir.'/bin/console', '<?php exit(0);');
        mkdir($readOnlyProjectDir.'/migrations', 0777, true);
        chmod($readOnlyProjectDir.'/migrations', 0555);
        clearstatcache();

        $controller = new DatabaseSetupController($translator);
        static::getContainer()->get('request_stack')->push(Request::create('/admin/database/setup'));
        $controller->setContainer(new ServiceLocator([
            'twig' => static fn () => static::getContainer()->get('twig'),
        ]));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($readOnlyProjectDir);
        $kernel->method('getEnvironment')->willReturn('prod');

        try {
            $response = $controller->setup($kernel);

            self::assertStringContainsString('database_setup.error_prefix', $response->getContent());
            self::assertStringNotContainsString('doctrine:database:drop', $response->getContent());
        } finally {
            chmod($readOnlyProjectDir.'/migrations', 0777);
            $this->removeDirectory($readOnlyProjectDir);
        }
    }

    public function testSetupReturnsPreparationErrorWhenMigrationFileCannotBeDeleted(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []): string => strtr($id, $parameters));

        $lockedProjectDir = sys_get_temp_dir().'/mailer-db-setup-locked-'.bin2hex(random_bytes(4));
        mkdir($lockedProjectDir.'/bin', 0777, true);
        file_put_contents($lockedProjectDir.'/bin/console', '<?php exit(0);');
        mkdir($lockedProjectDir.'/migrations', 0777, true);
        file_put_contents($lockedProjectDir.'/migrations/Version0001.php', '<?php return true;');
        chmod($lockedProjectDir.'/migrations', 0555);
        clearstatcache();

        $controller = new DatabaseSetupController($translator);
        static::getContainer()->get('request_stack')->push(Request::create('/admin/database/setup'));
        $controller->setContainer(new ServiceLocator([
            'twig' => static fn () => static::getContainer()->get('twig'),
        ]));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($lockedProjectDir);
        $kernel->method('getEnvironment')->willReturn('dev');

        try {
            $response = $controller->setup($kernel);

            self::assertStringContainsString('database_setup.error_prefix', $response->getContent());
            self::assertStringNotContainsString('doctrine:database:drop', $response->getContent());
        } finally {
            chmod($lockedProjectDir.'/migrations', 0777);
            $this->removeDirectory($lockedProjectDir);
        }
    }

    public function testSetupRecordsErrorWhenConsoleProcessThrows(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')
            ->willReturnCallback(static fn (string $id, array $parameters = []): string => strtr($id, $parameters));

        $controller = new class($translator) extends DatabaseSetupController {
            protected function runConsoleProcess(array $args): array
            {
                throw new \RuntimeException('process execution failed', 42);
            }
        };
        static::getContainer()->get('request_stack')->push(Request::create('/admin/database/setup'));
        $controller->setContainer(new ServiceLocator([
            'twig' => static fn () => static::getContainer()->get('twig'),
        ]));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);
        $kernel->method('getEnvironment')->willReturn('prod');

        $response = $controller->setup($kernel);

        self::assertStringContainsString('process execution failed', $response->getContent());
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
