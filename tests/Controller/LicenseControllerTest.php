<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Licensing\Entity\StoredLicenseKey;
use AvalynxOffice\Licensing\Service\LicenseManager;
use AvalynxOffice\Tests\Licensing\Support\TestLicenseIssuer;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('functional')]
final class LicenseControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private static TestLicenseIssuer $issuer;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    private User $user;

    public static function setUpBeforeClass(): void
    {
        self::$issuer = new TestLicenseIssuer();
    }

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $_ENV['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = json_encode(['test-2026-01' => self::$issuer->publicKeyBase64()], JSON_THROW_ON_ERROR);
        $_SERVER['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = $_ENV['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'];
        $_ENV['AVALYNXOFFICE_ORGANIZATION_ID'] = 'test-organization';
        $_SERVER['AVALYNXOFFICE_ORGANIZATION_ID'] = 'test-organization';
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
        $this->seedUsers();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $_ENV['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = '';
    }

    private function seedUsers(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'admin'));
        $this->admin->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->user = new User();
        $this->user->setEmail('user@example.com');
        $this->user->setPassword($hasher->hashPassword($this->user, 'user'));
        $this->user->setLastAuthAt(new \DateTimeImmutable('-1 hour'));

        $this->em->persist($this->admin);
        $this->em->persist($this->user);
        $this->em->flush();
    }

    public function testLicenseInfoPageShowsCommunityStatusForUsers(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/license');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Community');
        self::assertSelectorTextContains('body', 'Active Seats (last 30 days)');
    }

    public function testLicenseInfoPageShowsValidLicenseAndSeats(): void
    {
        $key = self::$issuer->issue(['additional_seats' => 2]);
        $this->em->persist(new StoredLicenseKey($key));
        $this->em->flush();

        $this->client->loginUser($this->user);
        $this->client->request('GET', '/license');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Licensed');
        self::assertSelectorTextContains('body', 'LIC-TEST-000001');
        // Full keys are never displayed.
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString($key, $content);
    }

    public function testAdminPageRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/license');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminPageShowsStatusKeysAndDiagnostics(): void
    {
        $key = self::$issuer->issue(['additional_seats' => 7]);
        $this->em->persist(new StoredLicenseKey($key));
        $this->em->persist(new StoredLicenseKey('garbage-key'));
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/license');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('License &amp; Usage', $content);
        self::assertStringContainsString('Licensed seats', $content);
        self::assertStringContainsString('LIC-TEST-000001', $content);
        self::assertStringContainsString('Invalid format', $content);
        self::assertStringNotContainsString($key, $content);
        // The user accounts live on their own section now.
        self::assertStringNotContainsString('admin@example.com', $content);
    }

    public function testUsersPageRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin/license/users');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUsersPageShowsAccountsWithRolesAndLastAuth(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/license/users');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="adminNavDropdown"', $content);
        self::assertStringContainsString('admin@example.com', $content);
        self::assertStringContainsString('user@example.com', $content);
        self::assertStringContainsString('Last authentication', $content);
        // Roles are rendered as localized labels, not raw identifiers.
        self::assertStringContainsString('Administrator', $content);
    }

    public function testAddKeysStoresKeysAndRaisesSeats(): void
    {
        $keyA = self::$issuer->issue(['additional_seats' => 2, 'license_id' => 'LIC-A']);
        $keyB = self::$issuer->issue(['additional_seats' => 7, 'license_id' => 'LIC-B']);

        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('add_license_keys');
        $this->client->request('POST', '/admin/license/keys', [
            '_token' => $token,
            'license_keys' => $keyA.','.$keyB,
        ]);

        self::assertResponseRedirects('/admin/license');

        $manager = static::getContainer()->get(LicenseManager::class);
        $manager->reset();
        $diag = array_map(static fn ($d) => [$d->status->value, $d->licenseId, $d->keyId], $manager->getDiagnostics());
        fwrite(STDERR, "\nDBG diag=" . json_encode($diag) . " valid=" . count($manager->getValidLicenses()) . " org=" . $manager->getOrganizationId() . " trusted=" . json_encode($manager->areTrustedKeysConfigured()) . "\n");
        self::assertSame(12, $manager->getAllowedSeats());

        $stored = $this->em->getRepository(StoredLicenseKey::class)->findAll();
        self::assertCount(2, $stored);
    }

    public function testAddKeysRequiresCsrfToken(): void
    {
        $key = self::$issuer->issue();

        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/license/keys', [
            'license_keys' => $key,
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em->getRepository(StoredLicenseKey::class)->findAll());
    }

    public function testDuplicateKeysAreStoredOnce(): void
    {
        $key = self::$issuer->issue();

        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('add_license_keys');
        $this->client->request('POST', '/admin/license/keys', [
            '_token' => $token,
            'license_keys' => $key."\n".$key,
        ]);

        self::assertResponseRedirects('/admin/license');
        self::assertCount(1, $this->em->getRepository(StoredLicenseKey::class)->findAll());

        // A second submission with the same key must not add anything; the
        // deduplication loop over the stored keys runs this time.
        $this->client->request('POST', '/admin/license/keys', [
            '_token' => $this->setCsrfToken('add_license_keys'),
            'license_keys' => $key,
        ]);

        self::assertResponseRedirects('/admin/license');
        self::assertCount(1, $this->em->getRepository(StoredLicenseKey::class)->findAll());
    }

    public function testDeleteStoredKey(): void
    {
        $stored = new StoredLicenseKey(self::$issuer->issue());
        $this->em->persist($stored);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/license');
        $token = $this->setCsrfToken('delete_license_key_'.$stored->getId());
        $this->client->request('POST', '/admin/license/keys/'.$stored->getId().'/delete', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/license');
        self::assertCount(0, $this->em->getRepository(StoredLicenseKey::class)->findAll());
    }

    public function testDeleteKeyRequiresCsrfToken(): void
    {
        $stored = new StoredLicenseKey(self::$issuer->issue());
        $this->em->persist($stored);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/license/keys/'.$stored->getId().'/delete');

        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->em->getRepository(StoredLicenseKey::class)->findAll());
    }

    public function testDeactivatedUserCannotLogIn(): void
    {
        $this->user->setActive(false);
        $this->em->flush();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => 'user@example.com',
            '_password' => 'user',
        ]);

        $this->client->request('GET', '/mails');
        self::assertResponseRedirects('/login');
    }

    public function testSuccessfulLoginRecordsSeatActivity(): void
    {
        $this->user->setLastAuthAt(null);
        $this->em->flush();
        $userId = $this->user->getId();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => 'user@example.com',
            '_password' => 'user',
        ]);

        $this->client->request('GET', '/mails');
        self::assertResponseIsSuccessful();

        $this->em->clear();
        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $userId);
        self::assertNotNull($reloaded->getLastAuthAt());
    }

    public function testFailedLoginDoesNotRecordSeatActivity(): void
    {
        $this->user->setLastAuthAt(null);
        $this->em->flush();
        $userId = $this->user->getId();

        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', [
            '_username' => 'user@example.com',
            '_password' => 'wrong',
        ]);

        $this->em->clear();
        /** @var User $reloaded */
        $reloaded = $this->em->find(User::class, $userId);
        self::assertNull($reloaded->getLastAuthAt());
    }

    public function testDeactivatedUserCountsNoSeats(): void
    {
        $this->user->setActive(false);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/license/users');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Deactivated', $content);
    }

    public function testToggleUserDeactivationAndReactivation(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/admin/license');
        $deactivateToken = $this->setCsrfToken('toggle_user_active_'.$this->user->getId());
        $this->client->request('POST', '/admin/license/users/'.$this->user->getId().'/active', [
            '_token' => $deactivateToken,
        ]);
        self::assertResponseRedirects('/admin/license/users');

        /** @var User $reloaded */
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $this->user->getId());
        self::assertFalse($reloaded->isActive());

        $activateToken = $this->setCsrfToken('toggle_user_active_'.$this->user->getId());
        $this->client->request('POST', '/admin/license/users/'.$this->user->getId().'/active', [
            '_token' => $activateToken,
        ]);
        self::assertResponseRedirects('/admin/license/users');

        /** @var User $reactivated */
        $this->em->clear();
        $reactivated = $this->em->find(User::class, $this->user->getId());
        self::assertTrue($reactivated->isActive());
    }

    public function testToggleUserRequiresCsrfToken(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/license/users/'.$this->user->getId().'/active');

        self::assertResponseStatusCodeSame(403);
    }

    public function testToggleUserRequiresAdmin(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/mails');
        $token = $this->setCsrfToken('toggle_user_active_'.$this->user->getId());
        $this->client->request('POST', '/admin/license/users/'.$this->user->getId().'/active', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testToggleUnknownUserReturnsNotFound(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('toggle_user_active_00000000-0000-7000-8000-000000000000');
        $this->client->request('POST', '/admin/license/users/00000000-0000-7000-8000-000000000000/active', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteUnknownKeyReturnsNotFound(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('delete_license_key_00000000-0000-7000-8000-000000000000');
        $this->client->request('POST', '/admin/license/keys/00000000-0000-7000-8000-000000000000/delete', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testAddKeysIgnoresEmptySegments(): void
    {
        $key = self::$issuer->issue();

        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('add_license_keys');
        $this->client->request('POST', '/admin/license/keys', [
            '_token' => $token,
            'license_keys' => ",,  ,\n".$key.",\n \n",
        ]);

        self::assertResponseRedirects('/admin/license');
        self::assertCount(1, $this->em->getRepository(StoredLicenseKey::class)->findAll());
    }

    /**
     * Stores a CSRF token directly in the client session and returns it.
     * The value is then accepted for the given token id.
     */
    private function setCsrfToken(string $tokenId): string
    {
        $this->client->request('GET', '/mails');
        $session = $this->client->getRequest()->getSession();
        $value = 'test-csrf-token-'.bin2hex(random_bytes(8));
        $session->set('_csrf/'.$tokenId, $value);
        $session->save();

        return $value;
    }

    public function testLastActiveAdminCannotBeDeactivated(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('toggle_user_active_'.$this->admin->getId()->toRfc4122());
        $this->client->request('POST', '/admin/license/users/'.$this->admin->getId()->toRfc4122().'/active', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->admin->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertTrue($refreshed->isActive());
    }

    public function testLastAdminCannotLoseAdminRole(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('toggle_user_admin_'.$this->admin->getId()->toRfc4122());
        $this->client->request('POST', '/admin/license/users/'.$this->admin->getId()->toRfc4122().'/toggle-admin', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->admin->getId());
        self::assertInstanceOf(User::class, $refreshed);
        self::assertContains('ROLE_ADMIN', $refreshed->getRoles());
    }

    public function testToggleAdminRoleUnknownUserReturnsNotFound(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('toggle_user_admin_00000000-0000-7000-8000-000000000000');
        $this->client->request('POST', '/admin/license/users/00000000-0000-7000-8000-000000000000/toggle-admin', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testToggleAdminRoleGrantsAdminRoleToRegularUser(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('toggle_user_admin_'.$this->user->getId()->toRfc4122());
        $this->client->request('POST', '/admin/license/users/'.$this->user->getId()->toRfc4122().'/toggle-admin', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/license/users');
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertNotNull($refreshed);
        self::assertContains('ROLE_ADMIN', $refreshed->getRoles());
    }

    public function testToggleAdminRoleRevokesAdminRoleWhenOtherAdminsRemain(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $secondAdmin = new User();
        $secondAdmin->setEmail('admin2@example.com');
        $secondAdmin->setRoles(['ROLE_ADMIN']);
        $secondAdmin->setPassword($hasher->hashPassword($secondAdmin, 'admin2'));
        $secondAdmin->setLastAuthAt(new \DateTimeImmutable('-1 hour'));
        $this->em->persist($secondAdmin);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('toggle_user_admin_'.$secondAdmin->getId()->toRfc4122());
        $this->client->request('POST', '/admin/license/users/'.$secondAdmin->getId()->toRfc4122().'/toggle-admin', [
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/admin/license/users');
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $secondAdmin->getId());
        self::assertNotNull($refreshed);
        self::assertNotContains('ROLE_ADMIN', $refreshed->getRoles());
    }

    public function testToggleAdminRoleRejectsRevokingTheLastActiveAdmin(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('toggle_user_admin_'.$this->admin->getId()->toRfc4122());
        $this->client->request('POST', '/admin/license/users/'.$this->admin->getId()->toRfc4122().'/toggle-admin', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->admin->getId());
        self::assertNotNull($refreshed);
        self::assertContains('ROLE_ADMIN', $refreshed->getRoles());
    }

    public function testToggleAdminRoleBlocksRoleChangesWhileReadOnly(): void
    {
        // Must happen before the first request: the entitlement is cached
        // inside the license manager once computed.
        $this->enterReadOnly();
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('toggle_user_admin_'.$this->user->getId()->toRfc4122());
        $this->client->request('POST', '/admin/license/users/'.$this->user->getId()->toRfc4122().'/toggle-admin', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->user->getId());
        self::assertNotNull($refreshed);
        self::assertNotContains('ROLE_ADMIN', $refreshed->getRoles());
    }

    public function testToggleAdminRoleRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $token = $this->setCsrfToken('toggle_user_admin_'.$this->user->getId()->toRfc4122());
        $this->client->request('POST', '/admin/license/users/'.$this->user->getId()->toRfc4122().'/toggle-admin', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testToggleAdminRoleRequiresCsrfToken(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/license/users/'.$this->user->getId()->toRfc4122().'/toggle-admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeactivateRejectsDeactivatingTheLastActiveAdmin(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('toggle_user_active_'.$this->admin->getId()->toRfc4122());
        $this->client->request('POST', '/admin/license/users/'.$this->admin->getId()->toRfc4122().'/active', [
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        $refreshed = $this->em->find(User::class, $this->admin->getId());
        self::assertNotNull($refreshed);
        self::assertTrue($refreshed->isActive());
    }

    private function enterReadOnly(): void
    {
        // Community mode allows 3 seats; four active accounts exceed it and
        // put the installation into violation, the expired grace period
        // turns it read-only.
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        for ($i = 1; 2 >= $i; ++$i) {
            $extra = new User();
            $extra->setEmail(sprintf('extra-readonly-%d@example.com', $i));
            $extra->setPassword($hasher->hashPassword($extra, 'secret'));
            $extra->setLastAuthAt(new \DateTimeImmutable('-1 hour'));
            $this->em->persist($extra);
        }
        $this->em->flush();

        $state = static::getContainer()->get(\AvalynxOffice\Licensing\Service\LicenseGracePeriodManager::class)->getState(null);
        $state->setViolationStartedAt(new \DateTimeImmutable('-100 days', new \DateTimeZone('UTC')));
        $state->setGracePeriodEndsAt(new \DateTimeImmutable('-70 days', new \DateTimeZone('UTC')));
        $this->em->flush();
    }
}
