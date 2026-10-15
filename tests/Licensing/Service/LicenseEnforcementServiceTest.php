<?php

declare(strict_types=1);

namespace App\Tests\Licensing\Service;

use App\Entity\User;
use App\Licensing\Service\LicenseEnforcementInterface;
use App\Licensing\Service\LicenseOperation;
use App\Tests\Support\RefreshDatabaseTrait;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[\PHPUnit\Framework\Attributes\Group('integration')]
final class LicenseEnforcementServiceTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
    }

    private function enforcement(): LicenseEnforcementInterface
    {
        return static::getContainer()->get(LicenseEnforcementInterface::class);
    }

    private function createStateRow(): void
    {
        // Ensure the license state singleton exists before locking.
        static::getContainer()->get(\App\Licensing\Service\LicenseGracePeriodManager::class)
            ->getState(null);
    }

    public function testUserCreationWithinOpenTransactionIsAllowedAndCountsUncommittedSeats(): void
    {
        $this->createStateRow();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->em->wrapInTransaction(function () use ($hasher): void {
            $this->enforcement()->assertUserSeatAvailable();

            $user = new User();
            $user->setEmail('tx-user@example.com');
            $user->setPassword($hasher->hashPassword($user, 'password'));
            $this->em->persist($user);
            $this->em->flush();

            // Within the same transaction the new seat is already counted.
            self::assertSame(1, $this->em->getRepository(User::class)->countActiveUsers());
        });

        self::assertSame(1, $this->em->getRepository(User::class)->countActiveUsers());
    }

    public function testConcurrentActivationsAreSerializedByLicenseStateRowLock(): void
    {
        $this->createStateRow();

        $params = $this->em->getConnection()->getParams();
        $params = array_filter($params, static fn ($key): bool => !str_starts_with((string) $key, 'slave') && 'servers' !== $key, ARRAY_FILTER_USE_KEY);
        $secondConnection = DriverManager::getConnection($params);

        // The first session holds the row lock inside an open transaction.
        $this->em->beginTransaction();
        $this->em->getConnection()->executeQuery('SELECT id FROM license_state WHERE id = 1 FOR UPDATE');

        $secondConnection->executeStatement('SET lock_timeout = \'300ms\'');
        $secondConnection->beginTransaction();
        try {
            $secondConnection->executeQuery('SELECT id FROM license_state WHERE id = 1 FOR UPDATE');
            self::fail('The second connection should have been blocked by the row lock.');
        } catch (\Doctrine\DBAL\Exception\DriverException $exception) {
            // 55P03 lock_not_available: the lock serializes seat accounting.
            self::assertStringContainsString('55P03', $exception->getMessage());
        } finally {
            $secondConnection->rollBack();
            $secondConnection->close();
        }

        // After the first session finishes, the lock is free again.
        $this->em->rollBack();
        $this->em->wrapInTransaction(function (): void {
            static::getContainer()->get(\App\Licensing\Service\LicenseGracePeriodManager::class)->lockStateForUpdate();
            self::addToAssertionCount(1);
        });
    }

    public function testExceedingQuotaIsNotHardBlockedButRecorded(): void
    {
        // Community mode: exceeding the quota starts the grace period instead
        // of throwing. Creation itself stays possible.
        static::getContainer()->get(\App\Licensing\Service\LicenseManager::class)->reset();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        for ($i = 1; $i <= 4; ++$i) {
            $this->em->wrapInTransaction(function () use ($hasher, $i): void {
                $this->enforcement()->assertUserSeatAvailable();
                $user = new User();
                $user->setEmail(sprintf('seat-%d@example.com', $i));
                $user->setPassword($hasher->hashPassword($user, 'password'));
                $this->em->persist($user);
                $this->em->flush();
            });
        }

        $manager = static::getContainer()->get(\App\Licensing\Service\LicenseManager::class);
        $manager->reset();
        self::assertSame(4, $this->em->getRepository(User::class)->countActiveUsers());
        self::assertSame(\App\Licensing\Model\LicenseStatus::GRACE_PERIOD, $manager->getStatus());
    }

    public function testOperationEnumCoversAllBlockedWrites(): void
    {
        self::assertSame([
            'MAIL_SEND',
            'MAIL_MODIFY',
            'MAIL_DELETE',
            'CALENDAR_WRITE',
            'CONTACT_WRITE',
            'USER_CREATE',
            'SETTINGS_WRITE',
        ], array_map(static fn (LicenseOperation $case): string => $case->value, LicenseOperation::cases()));
    }
}
