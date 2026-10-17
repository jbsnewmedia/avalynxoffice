<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Licensing\Service;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Licensing\Entity\LicenseState;
use AvalynxOffice\Licensing\Model\License;
use AvalynxOffice\Licensing\Model\LicenseStatus;
use AvalynxOffice\Licensing\Service\LicenseManager;
use AvalynxOffice\Repository\UserRepository;
use AvalynxOffice\Tests\Licensing\Support\TestLicenseIssuer;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[\PHPUnit\Framework\Attributes\Group('integration')]
final class LicenseManagerTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private static TestLicenseIssuer $issuer;

    private EntityManagerInterface $em;

    public static function setUpBeforeClass(): void
    {
        self::$issuer = new TestLicenseIssuer();
    }

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $_ENV['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = json_encode(['test-2026-01' => self::$issuer->publicKeyBase64()], JSON_THROW_ON_ERROR);
        $_SERVER['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = $_ENV['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'];
        $_ENV['AVALYNXOFFICE_LICENSE_KEYS'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_KEYS'] = '';
        $_ENV['AVALYNXOFFICE_LICENSE_FILE'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_FILE'] = '';
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $_ENV['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = '';
        $_ENV['AVALYNXOFFICE_LICENSE_FILE'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_FILE'] = '';
    }

    private function manager(): LicenseManager
    {
        return static::getContainer()->get(LicenseManager::class);
    }

    /**
     * @return list<User>
     */
    private function createUsers(int $count, bool $active = true, bool $authenticated = true): array
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $users = [];
        for ($i = 1; $i <= $count; ++$i) {
            $user = new User();
            $user->setEmail(sprintf('user-%d-%s@example.com', $i, bin2hex(random_bytes(4))));
            $user->setPassword($hasher->hashPassword($user, 'password'));
            $user->setActive($active);
            if ($authenticated) {
                $user->setLastAuthAt(new \DateTimeImmutable('now'));
            }
            $this->em->persist($user);
            $users[] = $user;
        }
        $this->em->flush();

        return $users;
    }

    /**
     * @param list<User> $users
     */
    private function deactivateUsers(array $users): void
    {
        foreach ($users as $user) {
            $user->setActive(false);
        }
        $this->em->flush();
        $this->manager()->reset();
    }

    private function configureEnvKeys(string $keys): void
    {
        $_ENV['AVALYNXOFFICE_LICENSE_KEYS'] = $keys;
        $_SERVER['AVALYNXOFFICE_LICENSE_KEYS'] = $keys;
    }

    public function testWithoutLicenseThreeSeatsAreAllowedAndCommunity(): void
    {
        $this->createUsers(3);
        $manager = $this->manager();
        $manager->reset();

        self::assertSame(3, $manager->getAllowedSeats());
        self::assertSame(3, $manager->getUsedSeats());
        self::assertSame(LicenseStatus::COMMUNITY, $manager->getStatus());
        self::assertFalse($manager->isReadOnly());
        self::assertSame([], $manager->getValidLicenses());
        self::assertSame(0, $manager->getEntitlement()->getFreeSeats());
    }

    public function testDeactivatedUsersDoNotCountAsSeats(): void
    {
        $this->createUsers(5);
        $users = $this->em->getRepository(User::class)->findAll();
        foreach ($users as $user) {
            $user->setActive(false);
        }
        $this->em->flush();
        $manager = $this->manager();
        $manager->reset();

        self::assertSame(0, $manager->getUsedSeats());
        self::assertSame(LicenseStatus::COMMUNITY, $manager->getStatus());
    }

    public function testUsersWithoutAuthenticationDoNotCountAsSeats(): void
    {
        // Accounts that never authenticated are not Active Seats yet (APL
        // 1.2 § 1): they start consuming a seat with their first login.
        $this->createUsers(5, true, false);

        $manager = $this->manager();
        self::assertSame(0, $manager->getUsedSeats());
        self::assertSame(LicenseStatus::COMMUNITY, $manager->getStatus());
    }

    public function testStaleAuthenticationsDoNotCountAsSeats(): void
    {
        // The seat activity window is 30 calendar days; authentications
        // outside the window and missing authentications do not count.
        $this->createUsers(4);
        $users = $this->em->getRepository(User::class)->findAll();
        $users[0]->setLastAuthAt(new \DateTimeImmutable('-45 days'));
        $users[1]->setLastAuthAt(new \DateTimeImmutable('-31 days'));
        $users[2]->setLastAuthAt(null);
        $this->em->flush();
        $manager = $this->manager();
        $manager->reset();

        self::assertSame(1, $manager->getUsedSeats());
        self::assertSame(LicenseStatus::COMMUNITY, $manager->getStatus());
    }

    public function testSeatWindowBoundaryIsInclusive(): void
    {
        $this->createUsers(3);
        $users = $this->em->getRepository(User::class)->findAll();
        $now = new \DateTimeImmutable('2026-10-16 12:00:00');
        $users[0]->setLastAuthAt($now->modify('-30 days'));
        $users[1]->setLastAuthAt($now->modify('-30 days')->modify('-1 second'));
        $users[2]->setLastAuthAt(null);
        $this->em->flush();

        /** @var UserRepository $repository */
        $repository = $this->em->getRepository(User::class);

        // Exactly 30 days is still inside (inclusive boundary), one second
        // more is outside.
        self::assertSame(1, $repository->countActiveSeats($now));
    }

    public function testExceedingQuotaStartsGracePeriod(): void
    {
        $this->createUsers(4);
        $manager = $this->manager();
        $manager->reset();

        self::assertSame(4, $manager->getUsedSeats());
        self::assertSame(LicenseStatus::GRACE_PERIOD, $manager->getStatus());
        self::assertFalse($manager->isReadOnly());

        $state = $this->em->find(LicenseState::class, LicenseState::SINGLETON_ID);
        self::assertNotNull($state);
        self::assertNotNull($state->getViolationStartedAt());
        self::assertNotNull($state->getGracePeriodEndsAt());
        self::assertSame(LicenseStatus::GRACE_PERIOD->value, $state->getLastKnownStatus());
    }

    public function testRestartDoesNotResetGracePeriod(): void
    {
        $this->createUsers(4);
        $first = $this->manager();
        self::assertSame(LicenseStatus::GRACE_PERIOD, $first->getStatus());
        $violationStart = $this->em->find(LicenseState::class, LicenseState::SINGLETON_ID)?->getViolationStartedAt();
        self::assertNotNull($violationStart);

        // Simulate a container restart: shutdown + fresh boot.
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $second = $this->manager();
        self::assertSame(LicenseStatus::GRACE_PERIOD, $second->getStatus());
        self::assertSame(
            $violationStart->format('Y-m-d H:i:s'),
            $this->em->find(LicenseState::class, LicenseState::SINGLETON_ID)?->getViolationStartedAt()?->format('Y-m-d H:i:s'),
        );
    }

    public function testAfterThirtyDaysInstallationBecomesReadOnly(): void
    {
        $this->createUsers(4);
        self::assertSame(LicenseStatus::GRACE_PERIOD, $this->manager()->getStatus());

        $state = $this->em->find(LicenseState::class, LicenseState::SINGLETON_ID);
        self::assertNotNull($state);
        $state->setViolationStartedAt(new \DateTimeImmutable('-31 days', new \DateTimeZone('UTC')));
        $state->setGracePeriodEndsAt(new \DateTimeImmutable('-1 day', new \DateTimeZone('UTC')));
        $this->em->flush();

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $manager = $this->manager();
        self::assertSame(LicenseStatus::READ_ONLY, $manager->getStatus());
        self::assertTrue($manager->isReadOnly());
    }

    public function testLicenseUpgradeEndsReadOnly(): void
    {
        static::getContainer()->get(\AvalynxOffice\Licensing\Service\LicenseGracePeriodManager::class)->getState(null);
        $this->createUsers(8);
        $state = $this->em->find(LicenseState::class, LicenseState::SINGLETON_ID);
        self::assertNotNull($state);
        $state->setViolationStartedAt(new \DateTimeImmutable('-100 days', new \DateTimeZone('UTC')));
        $state->setGracePeriodEndsAt(new \DateTimeImmutable('-70 days', new \DateTimeZone('UTC')));
        $this->em->flush();

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        self::assertTrue($this->manager()->isReadOnly());

        // The operator adds a license with 8 additional seats. The kernel is
        // rebooted so the license key provider re-reads its configuration.
        $key = self::$issuer->issue(['additional_seats' => 8, 'license_id' => 'LIC-UPGRADE']);
        $_ENV['AVALYNXOFFICE_LICENSE_KEYS'] = $key;
        $_SERVER['AVALYNXOFFICE_LICENSE_KEYS'] = $key;
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $manager = $this->manager();

        self::assertFalse($manager->isReadOnly());
        self::assertSame(LicenseStatus::VALID, $manager->getStatus());
        self::assertSame(11, $manager->getAllowedSeats());
    }

    public function testDeactivatingExcessUsersEndsReadOnly(): void
    {
        static::getContainer()->get(\AvalynxOffice\Licensing\Service\LicenseGracePeriodManager::class)->getState(null);
        $this->createUsers(6);
        $state = $this->em->find(LicenseState::class, LicenseState::SINGLETON_ID);
        self::assertNotNull($state);
        $state->setViolationStartedAt(new \DateTimeImmutable('-100 days', new \DateTimeZone('UTC')));
        $state->setGracePeriodEndsAt(new \DateTimeImmutable('-70 days', new \DateTimeZone('UTC')));
        $this->em->flush();

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $manager = $this->manager();
        self::assertTrue($manager->isReadOnly());

        $users = $this->em->getRepository(User::class)->findAll();
        $kept = 0;
        foreach ($users as $user) {
            if ($kept < 3) {
                ++$kept;

                continue;
            }
            $user->setActive(false);
        }
        $this->em->flush();
        $manager->reset();

        self::assertFalse($manager->isReadOnly());
        self::assertSame(LicenseStatus::COMMUNITY, $manager->getStatus());
    }

    public function testKeyWithTwoSeatsAllowsFiveUsers(): void
    {
        $key = self::$issuer->issue(['additional_seats' => 2, 'license_id' => 'LIC-TEAM-5']);
        $this->configureEnvKeys($key);
        $this->createUsers(5);

        $manager = $this->manager();
        self::assertSame(5, $manager->getAllowedSeats());
        self::assertSame(LicenseStatus::VALID, $manager->getStatus());
        self::assertCount(1, $manager->getValidLicenses());
    }

    public function testKeyWithSevenSeatsAllowsTenUsers(): void
    {
        $key = self::$issuer->issue(['additional_seats' => 7, 'license_id' => 'LIC-TEAM-10']);
        $this->configureEnvKeys($key);
        $this->createUsers(10);

        $manager = $this->manager();
        self::assertSame(10, $manager->getAllowedSeats());
        self::assertSame(LicenseStatus::VALID, $manager->getStatus());
    }

    public function testMultipleValidKeysAreAdded(): void
    {
        $keyA = self::$issuer->issue(['additional_seats' => 7, 'license_id' => 'LIC-A']);
        $keyB = self::$issuer->issue(['additional_seats' => 10, 'license_id' => 'LIC-B']);
        $this->configureEnvKeys($keyA.','.$keyB);

        $manager = $this->manager();
        self::assertSame(20, $manager->getAllowedSeats());
        self::assertCount(2, $manager->getValidLicenses());
    }

    public function testIdenticalLicenseIdIsOnlyCountedOnce(): void
    {
        $keyA = self::$issuer->issue(['additional_seats' => 7, 'license_id' => 'LIC-SAME']);
        $keyB = self::$issuer->issue(['additional_seats' => 10, 'license_id' => 'LIC-SAME']);
        $this->configureEnvKeys($keyA."\n".$keyB);

        $manager = $this->manager();
        self::assertSame(10, $manager->getAllowedSeats());
        self::assertCount(1, $manager->getValidLicenses());

        $statuses = array_map(static fn ($d) => $d->status->value, $manager->getDiagnostics());
        self::assertContains('duplicate', $statuses);
    }

    public function testTamperedKeyIsDiscardedWithoutQuota(): void
    {
        $key = self::$issuer->issue();
        $tampered = substr($key, 0, -8).'AAAAAAAA';
        $this->configureEnvKeys($tampered);
        $this->createUsers(4);

        $manager = $this->manager();
        self::assertSame(3, $manager->getAllowedSeats());
        self::assertSame([], $manager->getValidLicenses());
        self::assertSame(LicenseStatus::GRACE_PERIOD, $manager->getStatus());

        $statuses = array_map(static fn ($d) => $d->status->value, $manager->getDiagnostics());
        self::assertContains('invalid_signature', $statuses);
    }

    public function testUnknownKeyIdIsDiscarded(): void
    {
        $key = self::$issuer->issue(['key_id' => 'unknown-issuer']);
        $this->configureEnvKeys($key);

        $manager = $this->manager();
        self::assertSame(3, $manager->getAllowedSeats());
        $statuses = array_map(static fn ($d) => $d->status->value, $manager->getDiagnostics());
        self::assertContains('unknown_key_id', $statuses);
    }

    public function testLicenseOfForeignOrganizationIsDiscarded(): void
    {
        $key = self::$issuer->issue(['organization_id' => 'org-foreign']);
        $this->configureEnvKeys($key);
        $this->createUsers(4);

        $manager = $this->manager();
        self::assertSame(3, $manager->getAllowedSeats());
        self::assertSame(LicenseStatus::GRACE_PERIOD, $manager->getStatus());
        $statuses = array_map(static fn ($d) => $d->status->value, $manager->getDiagnostics());
        self::assertContains('wrong_organization', $statuses);
    }

    public function testExpiredKeyProvidesNoQuota(): void
    {
        $key = self::$issuer->issue([
            'license_id' => 'LIC-EXPIRED',
            'valid_from' => (new \DateTimeImmutable('-2 years'))->format(DATE_ATOM),
            'expires_at' => (new \DateTimeImmutable('-1 year'))->format(DATE_ATOM),
        ]);
        $this->configureEnvKeys($key);
        $this->createUsers(4);

        $manager = $this->manager();
        self::assertSame(3, $manager->getAllowedSeats());
        self::assertSame([], $manager->getValidLicenses());
        $statuses = array_map(static fn ($d) => $d->status->value, $manager->getDiagnostics());
        self::assertContains('expired', $statuses);
    }

    public function testFutureKeyIsNotYetActive(): void
    {
        $key = self::$issuer->issue([
            'license_id' => 'LIC-FUTURE',
            'valid_from' => (new \DateTimeImmutable('+1 month'))->format(DATE_ATOM),
            'expires_at' => (new \DateTimeImmutable('+1 year'))->format(DATE_ATOM),
        ]);
        $this->configureEnvKeys($key);
        $this->createUsers(4);

        $manager = $this->manager();
        self::assertSame(3, $manager->getAllowedSeats());
        $statuses = array_map(static fn ($d) => $d->status->value, $manager->getDiagnostics());
        self::assertContains('not_yet_valid', $statuses);
    }

    public function testMissingEnvValuesDoNotCauseErrors(): void
    {
        $_ENV['AVALYNXOFFICE_LICENSE_KEYS'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_KEYS'] = '';
        $manager = $this->manager();

        self::assertSame(3, $manager->getAllowedSeats());
        self::assertSame(LicenseStatus::COMMUNITY, $manager->getStatus());
    }

    public function testLicenseKeysFromFileAreLoaded(): void
    {
        $key = self::$issuer->issue(['license_id' => 'LIC-FILE']);
        $path = sys_get_temp_dir().'/license-test-'.bin2hex(random_bytes(4)).'.keys';
        file_put_contents($path, "# comment is not supported, keys only\n".$key);

        $_ENV['AVALYNXOFFICE_LICENSE_FILE'] = $path;
        $_SERVER['AVALYNXOFFICE_LICENSE_FILE'] = $path;

        $manager = $this->manager();
        self::assertSame(10, $manager->getAllowedSeats());

        $licenses = $manager->getValidLicenses();
        self::assertCount(1, $licenses);
        self::assertSame('FILE', $licenses[0]->source->value);

        unlink($path);
        $_ENV['AVALYNXOFFICE_LICENSE_FILE'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_FILE'] = '';
    }

    public function testMissingLicenseFileIsIgnored(): void
    {
        $_ENV['AVALYNXOFFICE_LICENSE_FILE'] = '/nonexistent/license.keys';
        $_SERVER['AVALYNXOFFICE_LICENSE_FILE'] = '/nonexistent/license.keys';

        $manager = $this->manager();
        self::assertSame(3, $manager->getAllowedSeats());

        $_ENV['AVALYNXOFFICE_LICENSE_FILE'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_FILE'] = '';
    }

    public function testLicenseKeysFromDatabaseAreLoaded(): void
    {
        $storedRepository = $this->em->getRepository(\AvalynxOffice\Licensing\Entity\StoredLicenseKey::class);
        $key = self::$issuer->issue(['license_id' => 'LIC-DB', 'additional_seats' => 2]);
        $this->em->persist(new \AvalynxOffice\Licensing\Entity\StoredLicenseKey($key));
        $this->em->flush();
        $manager = $this->manager();

        self::assertSame(5, $manager->getAllowedSeats());
        $licenses = $manager->getValidLicenses();
        self::assertCount(1, $licenses);
        self::assertSame('DATABASE', $licenses[0]->source->value);
        self::assertNotNull($storedRepository->findAll());
    }

    public function testDiagnosticsNeverContainFullKeys(): void
    {
        $key = self::$issuer->issue(['license_id' => 'LIC-DIAG']);
        $tampered = substr($key, 0, -4).'BBBB';
        $this->configureEnvKeys($key.','.$tampered);

        foreach ($this->manager()->getDiagnostics() as $diagnostic) {
            self::assertStringNotContainsString($key, $diagnostic->keyPreview);
            self::assertStringNotContainsString($tampered, $diagnostic->keyPreview);
            self::assertLessThanOrEqual(25, mb_strlen($diagnostic->keyPreview));
        }
    }

    public function testOrganizationIdIsStableAcrossRestarts(): void
    {
        $manager = $this->manager();
        $organizationId = $manager->getOrganizationId();
        self::assertNotSame('', $organizationId);

        self::ensureKernelShutdown();
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        self::assertSame($organizationId, $this->manager()->getOrganizationId());
    }

    public function testConfiguredOrganizationIdWins(): void
    {
        $_ENV['AVALYNXOFFICE_ORGANIZATION_ID'] = 'org-configured';
        $_SERVER['AVALYNXOFFICE_ORGANIZATION_ID'] = 'org-configured';

        $key = self::$issuer->issue(['organization_id' => 'org-configured']);
        $this->configureEnvKeys($key);

        $manager = $this->manager();
        self::assertSame('org-configured', $manager->getOrganizationId());
        self::assertCount(1, $manager->getValidLicenses());

        $_ENV['AVALYNXOFFICE_ORGANIZATION_ID'] = 'test-organization';
        $_SERVER['AVALYNXOFFICE_ORGANIZATION_ID'] = 'test-organization';
    }

    public function testUserRepositoryCountsOnlyActiveSeats(): void
    {
        $this->createUsers(3);
        $this->createUsers(2, false);
        $this->createUsers(1, true, false);
        /** @var UserRepository $repository */
        $repository = $this->em->getRepository(User::class);
        $now = new \DateTimeImmutable('now');

        self::assertSame(3, $repository->countActiveSeats($now));
    }

    public function testRecordAuthenticationSkipsRecentActivity(): void
    {
        $user = $this->createUsers(1)[0];
        $now = new \DateTimeImmutable('now');
        /** @var UserRepository $repository */
        $repository = $this->em->getRepository(User::class);
        $repository->recordAuthentication($user, $now);
        $this->em->clear();

        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $user->getId());
        self::assertSame(
            $now->format('Y-m-d H:i:s'),
            $reloaded->getLastAuthAt()?->format('Y-m-d H:i:s'),
        );

        // Within the refresh interval the stored value is not overwritten.
        $repository->recordAuthentication($reloaded, $now->modify('+10 minutes'));
        $this->em->clear();

        /** @var User $unchanged */
        $unchanged = $this->em->find(User::class, $user->getId());
        self::assertSame(
            $now->format('Y-m-d H:i:s'),
            $unchanged->getLastAuthAt()?->format('Y-m-d H:i:s'),
        );
    }

    public function testRecordAuthenticationRefreshesStaleValue(): void
    {
        $user = $this->createUsers(1)[0];
        $user->setLastAuthAt(new \DateTimeImmutable('-2 hours'));
        $this->em->flush();

        $now = new \DateTimeImmutable('now');
        /** @var UserRepository $repository */
        $repository = $this->em->getRepository(User::class);
        $repository->recordAuthentication($user, $now);
        $this->em->clear();

        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $user->getId());
        self::assertSame(
            $now->format('Y-m-d H:i:s'),
            $reloaded->getLastAuthAt()?->format('Y-m-d H:i:s'),
        );
    }

    public function testRecordAuthenticationIgnoresUnsavedUser(): void
    {
        $repository = $this->em->getRepository(User::class);
        $repository->recordAuthentication(new User(), new \DateTimeImmutable('now'));

        self::assertSame(0, $repository->countActiveSeats(new \DateTimeImmutable('now')));
    }
}
