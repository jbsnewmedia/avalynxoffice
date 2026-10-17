<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Command;

use AvalynxOffice\Command\InitCommand;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\DatabasePurger;
use AvalynxOffice\Tests\Support\AllowAllLicenseEnforcement;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class InitCommandTest extends TestCase
{
    public function testExecuteAbortsWhenMigrationsFail(): void
    {
        $command = $this->createCommand(migrateResult: Command::FAILURE);

        $tester = new CommandTester($command);
        $tester->setInputs(['no']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('migrations could not be executed', $tester->getDisplay());
    }

    public function testExecuteLoadsFixturesWhenTestDataIsConfirmed(): void
    {
        $command = $this->createCommand(migrateResult: Command::SUCCESS, fixturesResult: Command::SUCCESS);

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('CALLED:doctrine:fixtures:load', $display);
        self::assertStringContainsString('Test data generated successfully.', $display);
        self::assertStringContainsString('admin@example.com', $display);
        self::assertStringContainsString('admin', $display);
    }

    public function testExecuteReportsFailureWhenFixturesFail(): void
    {
        $command = $this->createCommand(migrateResult: Command::SUCCESS, fixturesResult: Command::FAILURE);

        $tester = new CommandTester($command);
        $tester->setInputs(['yes']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('test data could not be loaded', $tester->getDisplay());
    }

    public function testExecuteCreatesAdminUserInteractivelyWhenNoUserExists(): void
    {
        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBy')->with([])->willReturn(null);
        $entityManager->expects(self::any())->method('getRepository')->with(User::class)->willReturn($repository);
        $entityManager->expects(self::once())
            ->method('persist')
            ->willReturnCallback(static function (object $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())->method('flush');
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback): void {
                $callback();
            }
        );

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hashPassword')->willReturn('hashed-password');

        $command = $this->createCommand(
            migrateResult: Command::SUCCESS,
            entityManager: $entityManager,
            passwordHasher: $hasher,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['no', 'admin@company.example', 's3cret-pw']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        self::assertCount(1, $persisted);
        $user = $persisted[0];
        self::assertInstanceOf(User::class, $user);
        self::assertSame('admin@company.example', $user->getEmail());
        self::assertContains('ROLE_ADMIN', $user->getRoles());
        self::assertSame('hashed-password', $user->getPassword());
        self::assertStringContainsString('created successfully', $tester->getDisplay());
    }

    public function testExecuteKeepsDataWhenChoiceIsKeep(): void
    {
        $existingUser = (new User())->setEmail('existing@example.com')->setPassword('hash');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBy')->with([])->willReturn($existingUser);
        $entityManager->expects(self::any())->method('getRepository')->with(User::class)->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $purger = $this->createMock(DatabasePurger::class);
        $purger->expects(self::never())->method('purge');

        $command = $this->createCommand(
            migrateResult: Command::SUCCESS,
            entityManager: $entityManager,
            databasePurger: $purger,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['no', 'keep']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('A user already exists', $tester->getDisplay());
        self::assertStringContainsString('existing@example.com', $tester->getDisplay());
    }

    public function testExecutePurgesEverythingExceptUsersWhenChoiceIsUsers(): void
    {
        $existingUser = (new User())->setEmail('existing@example.com')->setPassword('hash');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBy')->with([])->willReturn($existingUser);
        $entityManager->expects(self::any())->method('getRepository')->with(User::class)->willReturn($repository);
        $entityManager->expects(self::once())->method('clear');
        $entityManager->expects(self::never())->method('persist');

        $purger = $this->createMock(DatabasePurger::class);
        $purger->expects(self::once())->method('purge')->with(true)->willReturn(['mailbox', 'email']);

        $command = $this->createCommand(
            migrateResult: Command::SUCCESS,
            entityManager: $entityManager,
            databasePurger: $purger,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['no', 'users']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('All data except users has been removed', $display);
        self::assertStringContainsString('A user already exists', $display);
    }

    public function testExecutePurgesAllAndCreatesAdminWhenChoiceIsAll(): void
    {
        $existingUser = (new User())->setEmail('existing@example.com')->setPassword('hash');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findOneBy')
            ->with([])
            ->willReturnOnConsecutiveCalls($existingUser, null);
        $entityManager->expects(self::any())->method('getRepository')->with(User::class)->willReturn($repository);
        $entityManager->expects(self::once())->method('clear');
        $entityManager->expects(self::once())->method('persist');
        $entityManager->expects(self::once())->method('flush');
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static function (callable $callback): void {
                $callback();
            }
        );

        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects(self::once())->method('hashPassword')->willReturn('hashed-password');

        $purger = $this->createMock(DatabasePurger::class);
        $purger->expects(self::once())->method('purge')->with(false)->willReturn(['app_user', 'mailbox']);

        $command = $this->createCommand(
            migrateResult: Command::SUCCESS,
            entityManager: $entityManager,
            passwordHasher: $hasher,
            databasePurger: $purger,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['no', 'all', 'fresh@example.com', 'new-secret']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('cleared completely', $display);
        self::assertStringContainsString('created successfully', $display);
    }

    public function testExecuteFailsWhenPurgeThrows(): void
    {
        $existingUser = (new User())->setEmail('existing@example.com')->setPassword('hash');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('findOneBy')->with([])->willReturn($existingUser);
        $entityManager->expects(self::any())->method('getRepository')->with(User::class)->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');

        $purger = $this->createMock(DatabasePurger::class);
        $purger->expects(self::once())->method('purge')->willThrowException(new \RuntimeException('boom'));

        $command = $this->createCommand(
            migrateResult: Command::SUCCESS,
            entityManager: $entityManager,
            databasePurger: $purger,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['no', 'all']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('could not be cleared', $tester->getDisplay());
        self::assertStringContainsString('boom', $tester->getDisplay());
    }

    public function testExecuteFailsWhenEmailIsEmpty(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBy')->with([])->willReturn(null);
        $entityManager->expects(self::any())->method('getRepository')->with(User::class)->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');

        $command = $this->createCommand(
            migrateResult: Command::SUCCESS,
            entityManager: $entityManager,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['no', '']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('valid email address', $tester->getDisplay());
    }

    public function testExecuteFailsWhenPasswordIsEmpty(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::exactly(2))->method('findOneBy')->with([])->willReturn(null);
        $entityManager->expects(self::any())->method('getRepository')->with(User::class)->willReturn($repository);
        $entityManager->expects(self::never())->method('persist');

        $command = $this->createCommand(
            migrateResult: Command::SUCCESS,
            entityManager: $entityManager,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['no', 'admin@example.com', '']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('password must not be empty', $tester->getDisplay());
    }

    public function testExecuteSyncsMigrationVersionsWhenSchemaExistsWithoutRecordedMigration(): void
    {
        $existingUser = (new User())->setEmail('existing@example.com')->setPassword('hash');
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($existingUser);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $command = $this->createCommand(
            migrateResult: Command::SUCCESS,
            fixturesResult: Command::SUCCESS,
            entityManager: $entityManager,
            migrationSync: true,
        );

        $tester = new CommandTester($command);
        $tester->setInputs(['no', 'keep']);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('CALLED:doctrine:migrations:sync-metadata-storage', $display);
        self::assertStringContainsString('CALLED:doctrine:migrations:version', $display);
        self::assertStringContainsString('CALLED:doctrine:migrations:migrate', $display);
        self::assertStringContainsString('migration version table will be synchronized', $display);
    }

    public function testExecuteFailsWhenMetadataStorageSyncFails(): void
    {
        $command = $this->createCommand(
            migrationSync: true,
            metadataResult: Command::FAILURE,
        );

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('migration metadata could not be synchronized', $tester->getDisplay());
        self::assertStringNotContainsString('CALLED:doctrine:migrations:version', $tester->getDisplay());
    }

    public function testExecuteFailsWhenMigrationSyncFails(): void
    {
        $command = $this->createCommand(
            migrateResult: Command::SUCCESS,
            versionResult: Command::FAILURE,
            migrationSync: true,
        );

        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('could not be synchronized', $tester->getDisplay());
        self::assertStringNotContainsString('CALLED:doctrine:migrations:migrate', $tester->getDisplay());
    }

    public function testNeedsMigrationSyncReturnsFalseForFreshDatabase(): void
    {
        $command = $this->createCommandWithConnection(['doctrine_migration_versions']);

        self::assertFalse($this->callNeedsMigrationSync($command));
    }

    public function testNeedsMigrationSyncReturnsTrueWhenManagedTablesExistWithoutVersionTable(): void
    {
        $command = $this->createCommandWithConnection(['app_user', 'mailbox']);

        self::assertTrue($this->callNeedsMigrationSync($command));
    }

    public function testNeedsMigrationSyncReturnsTrueWhenVersionTableIsEmpty(): void
    {
        $command = $this->createCommandWithConnection(['app_user', 'doctrine_migration_versions'], '0');

        self::assertTrue($this->callNeedsMigrationSync($command));
    }

    public function testNeedsMigrationSyncReturnsFalseWhenVersionsRecorded(): void
    {
        $command = $this->createCommandWithConnection(['app_user', 'doctrine_migration_versions'], '1');

        self::assertFalse($this->callNeedsMigrationSync($command));
    }

    public function testRunSymfonyCommandExecutesRegisteredApplicationCommand(): void
    {
        $kernel = $this->createStub(KernelInterface::class);

        $command = new class(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(UserPasswordHasherInterface::class),
            $kernel,
            $this->createStub(DatabasePurger::class),
            new AllowAllLicenseEnforcement(),
        ) extends InitCommand {
            public function exposeRun(string $name, array $arguments, OutputInterface $output): int
            {
                return $this->runSymfonyCommand($name, $arguments, $output);
            }
        };

        $output = new BufferedOutput();
        $result = $command->exposeRun('list', [], $output);

        self::assertSame(Command::SUCCESS, $result);
        self::assertNotEmpty($output->fetch());
    }

    private function createCommand(
        int $migrateResult = Command::SUCCESS,
        int $fixturesResult = Command::SUCCESS,
        ?EntityManagerInterface $entityManager = null,
        ?UserPasswordHasherInterface $passwordHasher = null,
        ?DatabasePurger $databasePurger = null,
        bool $migrationSync = false,
        int $versionResult = Command::SUCCESS,
        int $metadataResult = Command::SUCCESS,
    ): InitCommand {
        return new class(
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $passwordHasher ?? $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(KernelInterface::class),
            $databasePurger ?? $this->createStub(DatabasePurger::class),
            new AllowAllLicenseEnforcement(),
            $migrateResult,
            $fixturesResult,
            $migrationSync,
            $versionResult,
            $metadataResult,
        ) extends InitCommand {
            public function __construct(
                EntityManagerInterface $entityManager,
                UserPasswordHasherInterface $passwordHasher,
                KernelInterface $kernel,
                DatabasePurger $databasePurger,
                AllowAllLicenseEnforcement $licenseEnforcement,
                private readonly int $migrateResult,
                private readonly int $fixturesResult,
                private readonly bool $migrationSync,
                private readonly int $versionResult,
                private readonly int $metadataResult,
            ) {
                parent::__construct($entityManager, $passwordHasher, $kernel, $databasePurger, $licenseEnforcement);
            }

            #[\Override]
            protected function needsMigrationSync(): bool
            {
                return $this->migrationSync;
            }

            #[\Override]
            protected function runSymfonyCommand(string $name, array $arguments, OutputInterface $output): int
            {
                $output->writeln('CALLED:'.$name);

                return match ($name) {
                    'doctrine:migrations:migrate' => $this->migrateResult,
                    'doctrine:fixtures:load' => $this->fixturesResult,
                    'doctrine:migrations:version' => $this->versionResult,
                    'doctrine:migrations:sync-metadata-storage' => $this->metadataResult,
                    default => Command::SUCCESS,
                };
            }
        };
    }

    /**
     * @param list<string> $tables
     */
    private function createCommandWithConnection(array $tables, string $versionCount = '0'): InitCommand
    {
        $result = $this->createStub(\Doctrine\DBAL\Result::class);
        $result->method('fetchOne')->willReturn($versionCount);

        $schemaManager = $this->createStub(\Doctrine\DBAL\Schema\AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn($tables);

        $connection = $this->createStub(\Doctrine\DBAL\Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);
        $connection->method('executeQuery')->willReturn($result);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return new InitCommand(
            $entityManager,
            $this->createStub(UserPasswordHasherInterface::class),
            $this->createStub(KernelInterface::class),
            $this->createStub(DatabasePurger::class),
            new AllowAllLicenseEnforcement(),
        );
    }

    private function callNeedsMigrationSync(InitCommand $command): bool
    {
        $closure = \Closure::bind(
            static fn (InitCommand $command): bool => $command->needsMigrationSync(),
            null,
            InitCommand::class,
        );
        self::assertInstanceOf(\Closure::class, $closure);

        return $closure($command);
    }
}
