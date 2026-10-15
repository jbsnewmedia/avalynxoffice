<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\UserCreateCommand;
use App\Entity\User;
use App\Licensing\Service\LicenseEnforcementInterface;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserCreateCommandTest extends TestCase
{
    /** @var list<User> */
    private array $persistedUsers = [];

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

    public function testCreatesAdministratorWhenEmailIsUnknown(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
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

        $command = new UserCreateCommand($entityManager, $this->createHasherStub(), $licenseEnforcement);
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--email' => 'admin@example.com',
            '--password' => 'secret',
        ]));
        self::assertStringContainsString('Administrator admin@example.com created successfully.', $tester->getDisplay());

        self::assertCount(1, $this->persistedUsers);
        $user = $this->persistedUsers[0];
        self::assertSame('admin@example.com', $user->getEmail());
        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
        self::assertSame('hashed:secret', $user->getPassword());
    }

    public function testSkipsCreationWhenEmailAlreadyExists(): void
    {
        $existing = new User();
        $existing->setEmail('admin@example.com');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')
            ->with(User::class)
            ->willReturn($this->createRepositoryStub($existing));
        $entityManager->expects(self::never())->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $licenseEnforcement = $this->createMock(LicenseEnforcementInterface::class);
        $licenseEnforcement->expects(self::never())->method('assertUserSeatAvailable');

        $command = new UserCreateCommand($entityManager, $this->createHasherStub(), $licenseEnforcement);
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
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['--email' => 'admin@example.com']));
        self::assertStringContainsString('The password must not be empty', $tester->getDisplay());
    }
}
