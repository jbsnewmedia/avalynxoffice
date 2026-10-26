<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\SyncRun;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[Group('integration')]
#[Group('functional')]
final class AdminAreaControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
        $this->seedUsers();
    }

    public function testDashboardRedirectsToLoginForGuests(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    public function testDashboardShowsSystemStatusPlaceholderWhenServiceNeverRan(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('System status', $content);
        self::assertStringContainsString('No pass yet', $content);
    }

    public function testDashboardShowsLatestSyncRun(): void
    {
        $run = new SyncRun();
        $run->setStatus(SyncRun::STATUS_SUCCESS);
        $run->setTrigger(SyncRun::TRIGGER_DAEMON);
        $run->setFinishedAt(new \DateTimeImmutable('+10 seconds'));
        $run->setMailsImported(12);
        $run->setMailsSkipped(3);
        $run->setMailsErrors(1);
        $run->setContactsCount(0);
        $run->setCalendarCount(0);
        $this->em->persist($run);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Successful', $content);
        self::assertStringContainsString('Sync service', $content);
        self::assertStringContainsString('12 imported, 3 skipped, 1 errors', $content);
    }

    public function testDashboardShowsErroredSyncRun(): void
    {
        $run = new SyncRun();
        $run->setStatus(SyncRun::STATUS_ERROR);
        $run->setTrigger(SyncRun::TRIGGER_MANUAL);
        $run->setFinishedAt(new \DateTimeImmutable('+5 seconds'));
        $run->setMessage('POP3 host unreachable.');
        $this->em->persist($run);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Error', $content);
        self::assertStringContainsString('POP3 host unreachable.', $content);
        self::assertStringContainsString('Manual', $content);
    }

    public function testDashboardRequiresAdminRole(): void
    {
        $this->client->loginUser($this->user);
        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardRendersNavAndNeutralExplanationForAdmins(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Administrator', $content);
        self::assertStringContainsString('Overview', $content);
        self::assertStringContainsString('User forgot password', $content);
        self::assertStringContainsString('License &amp; Usage', $content);
        self::assertStringContainsString('Users', $content);
        self::assertStringContainsString('Connectors', $content);
        self::assertStringContainsString('Legal', $content);
        self::assertStringContainsString('id="adminNavDropdown"', $content);
        // The overview is a neutral dashboard: it links to every section
        // through the dropdown only, without content cards.
        self::assertStringContainsString($this->generateUrl('app_admin_password_resets'), $content);
        self::assertStringContainsString($this->generateUrl('license_admin'), $content);
        self::assertStringContainsString($this->generateUrl('license_admin_users'), $content);
        self::assertStringContainsString($this->generateUrl('connector_index'), $content);
        self::assertStringContainsString($this->generateUrl('app_settings_legal'), $content);
    }

    public function testAdminSectionPagesShareTheAdminNav(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', $this->generateUrl('app_admin_password_resets'));
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="adminNavDropdown"', $content);
        self::assertStringContainsString($this->generateUrl('app_admin'), $content);

        $this->client->request('GET', $this->generateUrl('license_admin'));
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="adminNavDropdown"', $content);
        self::assertStringContainsString($this->generateUrl('app_admin'), $content);

        $this->client->request('GET', $this->generateUrl('license_admin_users'));
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="adminNavDropdown"', $content);
        self::assertStringContainsString($this->generateUrl('app_admin'), $content);

        $this->client->request('GET', $this->generateUrl('connector_index'));
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="adminNavDropdown"', $content);
        self::assertStringContainsString($this->generateUrl('app_admin'), $content);

        $this->client->request('GET', $this->generateUrl('app_settings_legal'));
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="adminNavDropdown"', $content);
        self::assertStringContainsString($this->generateUrl('app_admin'), $content);
    }

    private function generateUrl(string $route): string
    {
        /** @var \Symfony\Component\Routing\RouterInterface $router */
        $router = static::getContainer()->get('router');

        return $router->generate($route);
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
}
