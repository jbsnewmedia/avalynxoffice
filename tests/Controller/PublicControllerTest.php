<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Address;
use App\Entity\Email;
use App\Entity\EmailFolder;
use App\Entity\Folder;
use App\Entity\Mailbox;
use App\Entity\MailboxConnector;
use App\Entity\User;
use App\Tests\Support\RefreshDatabaseTrait;
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

    public function testAuthenticatedControllersRedirectToMailIndexWhenNotXmlHttpRequest(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/about');
        self::assertResponseRedirects('/mails');

        $this->client->request('GET', '/help');
        self::assertResponseRedirects('/mails');

        $this->client->request('GET', '/profile');
        self::assertResponseRedirects('/mails');

        $this->client->request('GET', '/settings');
        self::assertResponseRedirects('/mails');
    }

    public function testXmlHttpControllersRenderExpectedContent(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/about', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('img[src="/assets/svg/app-logo.svg"]');

        $this->client->request('GET', '/help', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h2');

        $this->client->request('GET', '/profile', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('admin@example.com', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('ROLE_ADMIN', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/settings', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#newsletterSwitchModal');
    }

    public function testLocaleControllerSetsSessionAndRedirects(): void
    {
        $this->client->request('GET', '/locale/de?r=/settings');
        self::assertResponseRedirects('/settings');

        $this->client->request('GET', '/locale/en', server: ['HTTP_REFERER' => '/fallback']);
        self::assertResponseRedirects('/fallback');
    }

    public function testSettingsNewsletterToggleAndDataEndpoint(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/settings', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/settings/newsletter', ['newsletter_optin' => '1'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"success":true}', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/settings/newsletter');
        self::assertResponseRedirects('/settings');

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
        (new \App\Controller\SecurityController())->logout();
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
