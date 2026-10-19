<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Entity\MailboxConnector;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class PublicControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $adminUser;
    private Mailbox $mailbox;
    private Folder $folder;
    private Email $email;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->refreshDatabase($this->entityManager);
        [$this->adminUser, $this->mailbox, $this->folder, $this->email] = $this->seedData();
    }

    public function testAnonymousControllersRedirectToLoginWhenNotXmlHttpRequest(): void
    {
        $this->client->request('GET', '/about');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/help');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/profile');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/settings');
        self::assertResponseRedirects('/login');
    }

    public function testAuthenticatedPagesRenderForAuthenticatedUsers(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/about');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/help');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/settings');
        self::assertResponseIsSuccessful();
    }

    public function testSettingsPagesRenderExpectedContent(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/about');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('app-logo.svg', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/help');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/profile');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('admin@example.com', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/settings');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('primary_color', (string) $this->client->getResponse()->getContent());
    }

    public function testLocaleControllerSetsSessionAndRedirects(): void
    {
        $this->client->request('GET', '/locale/de?r=/settings');
        self::assertResponseRedirects('/settings');

        $this->client->request('GET', '/locale/en', server: ['HTTP_REFERER' => '/fallback']);
        self::assertResponseRedirects('/fallback');
    }

    public function testDataEndpointsReturnStructuredMailData(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/settings');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/data/all');
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertNotEmpty($data);
        self::assertArrayHasKey('folders', $data[0]);

        $this->client->request('GET', '/data/address-suggestions?search=main');
        self::assertResponseIsSuccessful();
        $suggestions = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($suggestions);
        self::assertSame('main@example.com', $suggestions[0]['key'] ?? null);
    }

    public function testAssetsAreServedWithExpectedHeaders(): void
    {
        $assets = [
            ['/assets/svg/app-logo.svg', 'image/svg+xml', 'app-logo.svg'],
            ['/assets/png/app-logo.png', 'image/png', 'app-logo.png'],
            ['/assets/svg/jbsnewmedia-logo.svg', 'image/svg+xml', 'jbsnewmedia-logo.svg'],
            ['/assets/svg/jbsnewmedia-symbol.svg', 'image/svg+xml', 'jbsnewmedia-symbol.svg'],
        ];

        foreach ($assets as [$uri, $contentType, $filename]) {
            $this->client->request('GET', $uri);
            self::assertResponseIsSuccessful();
            self::assertSame($contentType, (string) $this->client->getResponse()->headers->get('Content-Type'));
            self::assertStringContainsString('inline', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringContainsString($filename, (string) $this->client->getResponse()->headers->get('Content-Disposition'));
            self::assertStringContainsString('s-maxage=31536000', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        }
    }

    public function testLoginPageIsRenderableAndLogoutThrows(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#inputEmail');

        self::expectException(\LogicException::class);
        (new \AvalynxOffice\Controller\SecurityController())->logout();
    }

    public function testDatabaseSetupShowsErrorWhenMigrationsDirectoryIsReadOnly(): void
    {
        $migrationsDir = $this->projectDir().'/migrations';
        $originalPermissions = fileperms($migrationsDir) ?: null;
        chmod($migrationsDir, 0555);

        try {
            $this->client->request('GET', '/admin/database/setup');
            self::assertResponseIsSuccessful();
            self::assertSelectorExists('.alert-danger');
        } finally {
            if (null !== $originalPermissions) {
                chmod($migrationsDir, $originalPermissions & 0777);
            }
        }
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
    }

    private function projectDir(): string
    {
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');

        return is_string($projectDir) ? $projectDir : '';
    }

    /**
     * @return array{0: User, 1: Mailbox, 2: Folder, 3: Email}
     */
    private function seedData(): array
    {
        $admin = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_ADMIN']);

        $mailbox = (new Mailbox())
            ->setName('Main')
            ->setEmail('main@example.com');

        $address = (new Address())
            ->setEmail('main@example.com')
            ->setName('Main');
        $mailbox->addAddress($address);

        $connector = (new MailboxConnector())
            ->setName('Main Connector')
            ->setEmail('connector@example.com')
            ->setIsPrimary(true)
            ->setMailbox($mailbox);
        $mailbox->addConnector($connector);

        $folder = (new Folder())
            ->setName('Posteingang')
            ->setFunctions(['inbox']);
        $mailbox->addFolder($folder);

        $email = (new Email())
            ->setMailbox($mailbox)
            ->setSubject('Willkommen')
            ->setPreviewBody('Vorschau')
            ->setReceivedAt(new \DateTimeImmutable('2026-01-01 10:00:00'));

        $emailFolder = (new EmailFolder())
            ->setEmail($email)
            ->setFolder($folder);
        $email->addEmailFolder($emailFolder);

        $admin->addMailbox($mailbox);

        $this->entityManager->persist($admin);
        $this->entityManager->persist($mailbox);
        $this->entityManager->persist($address);
        $this->entityManager->persist($connector);
        $this->entityManager->persist($folder);
        $this->entityManager->persist($email);
        $this->entityManager->persist($emailFolder);
        $this->entityManager->flush();

        return [$admin, $mailbox, $folder, $email];
    }
}
