<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The account create dialog collects the basics only (name, email, users):
 * the required system folders are created automatically and aliases plus
 * folders are managed afterwards in the edit dialog.
 */
#[Group('integration')]
final class AccountAdminControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->entityManager);

        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setPassword('secret');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($this->admin);
        $this->entityManager->flush();
    }

    private function csrfToken(): string
    {
        // The form CSRF token id is configured globally as "submit".
        $this->client->request('GET', '/admin/accounts');
        $session = $this->client->getRequest()->getSession();
        $value = 'test-csrf-token-'.bin2hex(random_bytes(8));
        $session->set('_csrf/submit', $value);
        $session->save();

        return $value;
    }

    public function testCreateModalHasAliasEditorButNoFolderEditor(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/accounts/new', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('account-form', $content);
        self::assertStringContainsString('data-account-list-type="alias"', $content);
        self::assertStringNotContainsString('data-account-list-type="folder"', $content);
        self::assertStringContainsString('data-user-autocomplete', $content);
        self::assertStringContainsString('system folders', $content);
    }

    public function testEditModalShowsAliasAndFolderEditors(): void
    {
        $account = new Account();
        $account->setName('Support')->setEmail('support@example.com');
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/accounts/'.$account->getId()?->toRfc4122().'/edit', [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-account-list-type="alias"', $content);
        self::assertStringContainsString('data-account-list-type="folder"', $content);
    }

    public function testCreateSavesBasicsCreatesAliasAndRequiredSystemFolders(): void
    {
        // The alias routes incoming mails from a connector to the account,
        // so it is part of the create dialog and needs a connector.
        $sourceAccount = new Account();
        $sourceAccount->setName('Alias Source')->setEmail('alias-source@example.com');
        $connector = (new Connector())
            ->setName('Example')
            ->setDomain('example.com')
            ->setDefaultAccount($sourceAccount);
        $this->entityManager->persist($sourceAccount);
        $this->entityManager->persist($connector);
        $this->entityManager->flush();
        $connectorId = (string) $connector->getId();

        $this->client->loginUser($this->admin);
        $csrf = $this->csrfToken();

        $this->client->request('POST', '/admin/accounts/new', [
            'account' => [
                'name' => 'Support',
                'email' => 'support@example.com',
                'users' => (string) $this->admin->getId(),
                'aliases' => (string) json_encode([
                    ['connectorId' => $connectorId, 'localPart' => 'support'],
                ], JSON_THROW_ON_ERROR),
                '_token' => $csrf,
            ],
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);

        $this->entityManager->clear();
        $account = $this->entityManager->getRepository(Account::class)->findOneBy(['email' => 'support@example.com']);
        self::assertInstanceOf(Account::class, $account);
        self::assertSame('Support', $account->getName());
        self::assertCount(1, $account->getAliases());
        self::assertSame([$this->admin->getId()?->toRfc4122()], array_map(
            static fn (User $user): string => (string) $user->getId(),
            $account->getUsers()->toArray(),
        ));

        // The required system folders (Pflichtordner) were created
        // automatically and Postfach is wired as the account's mailbox.
        $folders = [];
        foreach ($account->getFolderLinks() as $link) {
            $folder = $link->getFolder();
            if ($folder instanceof Folder) {
                $folders[] = $folder;
            }
        }
        self::assertCount(6, $folders);

        $functions = [];
        foreach ($folders as $folder) {
            foreach ($folder->getFunctions() as $function) {
                $functions[] = $function;
            }
        }
        sort($functions);
        self::assertSame(['drafts', 'inbox', 'mailbox', 'outbox', 'scheduled', 'trash'], $functions);
        self::assertNotNull($account->getInboxFolder());
        self::assertSame('Postfach', $account->getInboxFolder()?->getName());
    }

    public function testCreateRejectsMissingName(): void
    {
        $this->client->loginUser($this->admin);
        $csrf = $this->csrfToken();

        $this->client->request('POST', '/admin/accounts/new', [
            'account' => [
                'name' => '',
                'email' => 'broken@example.com',
                '_token' => $csrf,
            ],
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($payload['success']);
        self::assertArrayHasKey('account[name]', $payload['invalid']);
    }

    public function testUpdateSavesAliasesAndFoldersFromEditors(): void
    {
        $account = new Account();
        $account->setName('Support')->setEmail('support@example.com');
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);
        $csrf = $this->csrfToken();
        $id = (string) $account->getId();

        $this->client->request('POST', '/admin/accounts/'.$id.'/edit', [
            'account' => [
                'name' => 'Support renamed',
                'email' => 'support@example.com',
                'users' => (string) $this->admin->getId(),
                'aliases' => '[]',
                'folders' => '[{"name":"Postfach","sortOrder":-100,"functions":["mailbox"]},'
                    .'{"name":"Posteingang","sortOrder":-90,"functions":["inbox"]},'
                    .'{"name":"Postausgang","sortOrder":-80,"functions":["outbox"]},'
                    .'{"name":"Geplant","sortOrder":-70,"functions":["scheduled"]},'
                    .'{"name":"Entw\u00fcrfe","sortOrder":-60,"functions":["drafts"]},'
                    .'{"name":"Papierkorb","sortOrder":-50,"functions":["trash"]},'
                    .'{"name":"Archiv","sortOrder":10,"functions":[]}]',
                '_token' => $csrf,
            ],
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($payload['success']);

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Account::class, $account->getId());
        self::assertInstanceOf(Account::class, $refreshed);
        self::assertSame('Support renamed', $refreshed->getName());

        $folders = [];
        foreach ($refreshed->getFolderLinks() as $link) {
            $folder = $link->getFolder();
            if ($folder instanceof Folder) {
                $folders[] = $folder;
            }
        }
        self::assertCount(7, $folders);
        $mailbox = $this->entityManager->getRepository(Folder::class)->findOneBy(['name' => 'Postfach']);
        self::assertInstanceOf(Folder::class, $mailbox);
        self::assertContains('mailbox', $mailbox->getFunctions());
        self::assertNotNull($refreshed->getInboxFolder());
    }

    public function testUserSuggestionsEndpointSearchesByEmailAndDecodesIdn(): void
    {
        $idnUser = new User();
        $idnUser->setEmail('info@xn--bstoff-bua.de');
        $idnUser->setPassword('secret');
        $this->entityManager->persist($idnUser);
        $this->entityManager->flush();

        $this->client->loginUser($this->admin);

        // Punycode substring matches the stored form; the display value is
        // the decoded unicode email.
        $this->client->request('GET', '/admin/accounts/users/suggestions', ['search' => 'bstoff'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            ['key' => (string) $idnUser->getId(), 'value' => 'info@bästoff.de'],
        ], $payload);

        // Plain email searches still find the ASCII users.
        $this->client->request('GET', '/admin/accounts/users/suggestions', ['search' => 'admin'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            ['key' => (string) $this->admin->getId(), 'value' => 'admin@example.com'],
        ], $payload);

        // Typing the decoded unicode form finds the punycode-stored user too.
        $this->client->request('GET', '/admin/accounts/users/suggestions', ['search' => 'info@bästoff.de'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame([
            ['key' => (string) $idnUser->getId(), 'value' => 'info@bästoff.de'],
        ], $payload);

        $this->client->request('GET', '/admin/accounts/users/suggestions', ['search' => 'nobody'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR));
    }
}
