<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Command;

use AvalynxOffice\Command\UserCreateCommand;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Repository\RecoveryAddressRepository;
use AvalynxOffice\Repository\UserRepository;
use AvalynxOffice\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserCreateCommandTest extends TestCase
{
    private const ENV_VARIABLES = ['OFFICE_ADMIN_EMAIL', 'OFFICE_ADMIN_PASSWORD'];

    /** @var list<User> */
    private array $persistedUsers = [];

    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        // The command falls back to OFFICE_ADMIN_* from the environment; the
        // tests must not depend on what the surrounding container defines.
        foreach (self::ENV_VARIABLES as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            $this->setEnvironmentVariable($name, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            $this->setEnvironmentVariable($name, false === $value ? null : $value);
        }
    }

    private function setEnvironmentVariable(string $name, ?string $value): void
    {
        if (null === $value) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }

        putenv($name.'='.$value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    private function createRepositoryStub(?User $existing): UserRepository
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findOneBy')->willReturn($existing);

        return $repository;
    }

    private function createEntityManagerStub(): EntityManagerInterface
    {
        $this->persistedUsers = [];

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->willReturn($this->createRepositoryStub(null));
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static function (callable $transaction): void {
                $transaction();
            });
        $entityManager->method('persist')
            ->willReturnCallback(function (object $object): void {
                \assert($object instanceof User);
                $this->persistedUsers[] = $object;
            });

        return $entityManager;
    }

    private function createHasherStub(): UserPasswordHasherInterface
    {
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')
            ->willReturnCallback(static fn (User $user, string $password): string => 'hashed:'.$password);

        return $hasher;
    }

    private function createUserService(array $activeAdmins): UserService
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findActiveAdmins')->willReturn($activeAdmins);

        return new UserService(
            $repository,
            $this->createStub(RecoveryAddressRepository::class),
            $this->createStub(MailerInterface::class),
            'noreply@example.com',
            $this->createStub(UriSigner::class),
            $this->createStub(UserPasswordHasherInterface::class),
        );
    }

    public function testCreatesAdministratorWhenEmailIsUnknown(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getRepository')
            ->with(User::class)
            ->willReturn($this->createRepositoryStub(null));
        $entityManager->method('wrapInTransaction')
            ->willReturnCallback(static function (callable $transaction): void {
                $transaction();
            });
        $entityManager->method('persist')
            ->willReturnCallback(function (object $object): void {
                \assert($object instanceof User);
                $this->persistedUsers[] = $object;
            });
        $entityManager->expects(self::once())->method('flush');

        $licenseEnforcement = $this->createMock(LicenseEnforcementInterface::class);
        $licenseEnforcement->expects(self::once())->method('assertUserSeatAvailable');

        $command = new UserCreateCommand($entityManager, $this->createHasherStub(), $licenseEnforcement, $this->createUserService([]));
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--email' => 'admin@example.com',
            '--password' => 'secret',
        ]));
        self::assertStringContainsString('User admin@example.com created successfully (administrator).', $tester->getDisplay());

        self::assertCount(1, $this->persistedUsers);
        $user = $this->persistedUsers[0];
        self::assertSame('admin@example.com', $user->getEmail());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
        self::assertSame('hashed:secret', $user->getPassword());
    }

    public function testCreatesRegularUserWhenAnAdminAlreadyExists(): void
    {
        $command = new UserCreateCommand(
            $this->createEntityManagerStub(),
            $this->createHasherStub(),
            $this->createStub(LicenseEnforcementInterface::class),
            $this->createUserService([new User()]),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--email' => 'user@example.com',
            '--password' => 'secret',
        ]));
        self::assertStringContainsString('User user@example.com created successfully (user).', $tester->getDisplay());

        self::assertCount(1, $this->persistedUsers);
        self::assertSame(['ROLE_USER'], $this->persistedUsers[0]->getRoles());
    }

    public function testForcesAdministratorRoleWithAdminFlag(): void
    {
        $command = new UserCreateCommand(
            $this->createEntityManagerStub(),
            $this->createHasherStub(),
            $this->createStub(LicenseEnforcementInterface::class),
            $this->createUserService([new User()]),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--admin' => true,
            '--email' => 'admin@example.com',
            '--password' => 'secret',
        ]));

        self::assertCount(1, $this->persistedUsers);
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $this->persistedUsers[0]->getRoles());
    }

    public function testSkipsCreationWhenEmailAlreadyExists(): void
    {
        $existing = new User();
        $existing->setEmail('admin@example.com');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('getRepository')
            ->with(User::class)
            ->willReturn($this->createRepositoryStub($existing));
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $licenseEnforcement = $this->createMock(LicenseEnforcementInterface::class);
        $licenseEnforcement->expects(self::never())->method('assertUserSeatAvailable');

        $command = new UserCreateCommand($entityManager, $this->createHasherStub(), $licenseEnforcement, $this->createUserService([]));
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--email' => 'admin@example.com',
            '--password' => 'secret',
        ]));
        self::assertStringContainsString('already exists. Creation skipped.', $tester->getDisplay());
    }

    public function testFailsOnInvalidEmail(): void
    {
        $command = new UserCreateCommand(
            $this->createEntityManagerStub(),
            $this->createHasherStub(),
            $this->createStub(LicenseEnforcementInterface::class),
            $this->createUserService([]),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--email' => 'not-an-email',
            '--password' => 'secret',
        ]));
        self::assertStringContainsString('A valid email address must be provided', $tester->getDisplay());
    }

    public function testFailsOnEmptyEmail(): void
    {
        $command = new UserCreateCommand(
            $this->createEntityManagerStub(),
            $this->createHasherStub(),
            $this->createStub(LicenseEnforcementInterface::class),
            $this->createUserService([]),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['--password' => 'secret']));
        self::assertStringContainsString('A valid email address must be provided', $tester->getDisplay());
    }

    public function testFailsOnEmptyPassword(): void
    {
        $command = new UserCreateCommand(
            $this->createEntityManagerStub(),
            $this->createHasherStub(),
            $this->createStub(LicenseEnforcementInterface::class),
            $this->createUserService([]),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([
            '--email' => 'admin@example.com',
            '--password' => '',
        ]));
        self::assertStringContainsString('The password must not be empty', $tester->getDisplay());
    }

    public function testFailsOnMissingPassword(): void
    {
        $command = new UserCreateCommand(
            $this->createEntityManagerStub(),
            $this->createHasherStub(),
            $this->createStub(LicenseEnforcementInterface::class),
            $this->createUserService([]),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['--email' => 'admin@example.com']));
        self::assertStringContainsString('The password must not be empty', $tester->getDisplay());
    }

    public function testReadsEmailAndPasswordFromEnvironmentWhenOptionsAreMissing(): void
    {
        $this->setEnvironmentVariable('OFFICE_ADMIN_EMAIL', 'env-admin@example.com');
        $this->setEnvironmentVariable('OFFICE_ADMIN_PASSWORD', 'p4$$ "word" `with` $(chars)');

        $command = new UserCreateCommand(
            $this->createEntityManagerStub(),
            $this->createHasherStub(),
            $this->createStub(LicenseEnforcementInterface::class),
            $this->createUserService([]),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute(['--admin' => true]));
        self::assertStringContainsString('User env-admin@example.com created successfully (administrator).', $tester->getDisplay());

        self::assertCount(1, $this->persistedUsers);
        self::assertSame('env-admin@example.com', $this->persistedUsers[0]->getEmail());
        self::assertSame('hashed:p4$$ "word" `with` $(chars)', $this->persistedUsers[0]->getPassword());
    }

    public function testOptionsTakePrecedenceOverEnvironment(): void
    {
        $this->setEnvironmentVariable('OFFICE_ADMIN_EMAIL', 'env-admin@example.com');
        $this->setEnvironmentVariable('OFFICE_ADMIN_PASSWORD', 'env-secret');

        $command = new UserCreateCommand(
            $this->createEntityManagerStub(),
            $this->createHasherStub(),
            $this->createStub(LicenseEnforcementInterface::class),
            $this->createUserService([]),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--email' => 'option@example.com',
            '--password' => 'option-secret',
        ]));

        self::assertCount(1, $this->persistedUsers);
        self::assertSame('option@example.com', $this->persistedUsers[0]->getEmail());
        self::assertSame('hashed:option-secret', $this->persistedUsers[0]->getPassword());
    }
}
