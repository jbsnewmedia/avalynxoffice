<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class AdminControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $adminUser;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->refreshDatabase($this->entityManager);
        $this->adminUser = $this->createAdminUser();
    }

    public function testAccountAndConnectorRoutesRedirectToLoginForGuests(): void
    {
        $account = $this->createAccount('Redirect Account', 'redirect-account@example.com');
        $connector = $this->createConnector($account, 'Redirect Connector', 'redirect-connector@example.com');

        $this->client->request('GET', '/admin/accounts');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/admin/accounts/view');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/admin/accounts/new');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', sprintf('/admin/accounts/%s/edit', $account->getId()));
        self::assertResponseRedirects('/login');

        $this->client->request('POST', sprintf('/admin/accounts/%s', $account->getId()));
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/admin/connectors');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/admin/connectors/new');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', sprintf('/admin/connectors/%s/edit', $connector->getId()));
        self::assertResponseRedirects('/login');

        $this->client->request('POST', sprintf('/admin/connectors/%s', $connector->getId()));
        self::assertResponseRedirects('/login');
    }

    public function testAccountAndConnectorPagesRenderForAdmins(): void
    {
        $this->loginAdmin();

        $account = $this->createAccount('Page Account', 'page-account@example.com');
        $connector = $this->createConnector($account, 'Page Connector', 'page-connector@example.com');

        $this->client->request('GET', '/admin/accounts');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/accounts/view');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/accounts/new');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/admin/accounts/%s/edit', $account->getId()));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/connectors');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/connectors/new');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/admin/connectors/%s/edit', $connector->getId()));
        self::assertResponseIsSuccessful();
    }

    public function testAccountControllerSupportsCreateEditAndDeleteFlows(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/admin/accounts/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/admin/accounts/view', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $account = $this->createAccountWithBlockedFolder('Support', 'support-edit@example.com');
        $this->client->request('GET', sprintf('/admin/accounts/%s/edit', $account->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $invalidToken = 'invalid';
        $this->client->request('POST', sprintf('/admin/accounts/%s', $account->getId()), ['_token' => $invalidToken], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/admin/accounts');
        self::assertNotNull($this->entityManager->find(Account::class, (string) $account->getId()));
    }

    public function testAccountControllerSupportsSuccessfulCreateEditAndDelete(): void
    {
        $this->loginAdmin();

        $createCrawler = $this->client->request('GET', '/admin/accounts/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $createForm = $createCrawler->filter('form')->form();
        $createValues = $createForm->getPhpValues();
        $createValues['account']['name'] = 'Created Account';
        $createValues['account']['email'] = 'created@example.com';
        $connector = $this->createConnector($this->createAccount('Alias Source', 'alias-source@example.com'), 'Example', 'alias@example.com');
        $createValues['account']['aliases'] = json_encode([
            ['connectorId' => (string) $connector->getId(), 'localPart' => 'alias'],
        ], JSON_THROW_ON_ERROR);
        $createValues['account']['folders'] = $this->requiredFoldersPayload();

        $this->client->request('POST', '/admin/accounts/new', $createValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $createdAccount = $this->entityManager->getRepository(Account::class)->findOneBy(['email' => 'created@example.com']);
        self::assertInstanceOf(Account::class, $createdAccount);
        self::assertSame('Created Account', $createdAccount->getName());
        self::assertCount(4, $createdAccount->getFolders());
        self::assertCount(1, $createdAccount->getAliases());

        $editCrawler = $this->client->request('GET', sprintf('/admin/accounts/%s/edit', $createdAccount->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $editForm = $editCrawler->filter('form')->form();
        $editValues = $editForm->getPhpValues();
        $editValues['account']['name'] = 'Updated Account';
        $editValues['account']['email'] = 'updated@example.com';
        $editValues['account']['aliases'] = '[]';
        $editValues['account']['folders'] = $this->requiredFoldersPayload();

        $this->client->request('POST', sprintf('/admin/accounts/%s/edit', $createdAccount->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $updatedAccount = $this->entityManager->find(Account::class, (string) $createdAccount->getId());
        self::assertInstanceOf(Account::class, $updatedAccount);
        self::assertSame('Updated Account', $updatedAccount->getName());
        self::assertSame('updated@example.com', $updatedAccount->getEmail());

        $tokenCrawler = $this->client->request('GET', '/admin/accounts', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $token = $tokenCrawler->filter(sprintf('form[action="/admin/accounts/%s"] input[name="_token"]', $updatedAccount->getId()))->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', sprintf('/admin/accounts/%s', $updatedAccount->getId()), ['_token' => $token], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/admin/accounts');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Account::class, (string) $updatedAccount->getId()));
    }

    public function testAccountControllerKeepsFormOpenForValidationErrors(): void
    {
        $this->loginAdmin();

        $createCrawler = $this->client->request('GET', '/admin/accounts/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $createForm = $createCrawler->filter('form')->form();
        $createValues = $createForm->getPhpValues();
        $createValues['account']['name'] = 'Invalid Account';
        $createValues['account']['email'] = 'invalid@example.com';
        $createValues['account']['aliases'] = json_encode([
            ['connectorId' => 'unknown-id', 'localPart' => 'broken'],
        ], JSON_THROW_ON_ERROR);
        $createValues['account']['folders'] = json_encode([
            [
                'name' => 'Posteingang',
                'sortOrder' => -90,
                'functions' => [FolderFunction::INBOX->value],
            ],
            [
                'name' => 'Second Inbox',
                'sortOrder' => -80,
                'functions' => [FolderFunction::INBOX->value],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/admin/accounts/new', $createValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('account[aliases]', $data['invalid']);
        self::assertArrayHasKey('account[folders]', $data['invalid']);
        self::assertNull($this->entityManager->getRepository(Account::class)->findOneBy(['email' => 'invalid@example.com']));

        // Create: conflicting alias that already belongs to another account.
        $conflictSource = $this->createAccount('Conflict Source', 'conflict-source@example.com');
        $conflictConnector = $this->createConnector($conflictSource, 'Example', 'conflict@example.com');
        $conflictAlias = (new AccountAlias())->setAccount($conflictSource)->setConnector($conflictConnector)->setLocalPart('conflict');
        $conflictSource->addAlias($conflictAlias);
        $this->entityManager->persist($conflictAlias);
        $this->entityManager->flush();

        $createCrawler = $this->client->request('GET', '/admin/accounts/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $createForm = $createCrawler->filter('form')->form();
        $createValues = $createForm->getPhpValues();
        $createValues['account']['name'] = 'Conflict Account';
        $createValues['account']['email'] = 'conflict-account@example.com';
        $createValues['account']['aliases'] = json_encode([
            ['connectorId' => (string) $conflictConnector->getId(), 'localPart' => 'conflict'],
        ], JSON_THROW_ON_ERROR);
        $createValues['account']['folders'] = $this->requiredFoldersPayload();

        $this->client->request('POST', '/admin/accounts/new', $createValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('account[aliases]', $data['invalid']);

        $account = $this->createAccountWithBlockedFolder('Blocked Account', 'blocked@example.com');
        $editCrawler = $this->client->request('GET', sprintf('/admin/accounts/%s/edit', $account->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $editForm = $editCrawler->filter('form')->form();
        $editValues = $editForm->getPhpValues();
        $editValues['account']['name'] = 'Blocked Account';
        $editValues['account']['email'] = 'blocked@example.com';
        $editValues['account']['aliases'] = '[]';
        $editValues['account']['folders'] = $this->requiredFoldersPayload();

        $this->client->request('POST', sprintf('/admin/accounts/%s/edit', $account->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('account[folders]', $data['invalid']);
    }

    public function testAccountEditKeepsFormOpenForAliasDuplicateAndMissingFunctionErrors(): void
    {
        $this->loginAdmin();

        $account = $this->createAccount('Editable Account', 'editable@example.com');
        $editCrawler = $this->client->request('GET', sprintf('/admin/accounts/%s/edit', $account->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $editForm = $editCrawler->filter('form')->form();
        $editValues = $editForm->getPhpValues();
        $editValues['account']['name'] = 'Editable Account';
        $editValues['account']['email'] = 'editable@example.com';
        $sourceAccount = $this->createAccount('Duplicate Source', 'duplicate-source@example.com');
        $connector = $this->createConnector($sourceAccount, 'Example', 'taken@example.com');
        $takenAlias = (new AccountAlias())->setAccount($sourceAccount)->setConnector($connector)->setLocalPart('taken');
        $sourceAccount->addAlias($takenAlias);
        $this->entityManager->persist($takenAlias);
        $this->entityManager->flush();
        $editValues['account']['aliases'] = json_encode([
            ['connectorId' => (string) $connector->getId(), 'localPart' => 'taken'],
        ], JSON_THROW_ON_ERROR);
        $editValues['account']['folders'] = json_encode([
            [
                'name' => 'Posteingang',
                'sortOrder' => -90,
                'functions' => [FolderFunction::INBOX->value],
            ],
            [
                'name' => 'Second Inbox',
                'sortOrder' => -80,
                'functions' => [FolderFunction::INBOX->value],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->client->request('POST', sprintf('/admin/accounts/%s/edit', $account->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('account[aliases]', $data['invalid']);

        // Edit: alias with an unknown connector is invalid as well.
        $editValues['account']['aliases'] = json_encode([
            ['connectorId' => 'unknown-connector-id', 'localPart' => 'broken'],
        ], JSON_THROW_ON_ERROR);

        $this->client->request('POST', sprintf('/admin/accounts/%s/edit', $account->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('account[aliases]', $data['invalid']);
    }

    public function testConnectorControllerSupportsCreateEditAndDeleteFlows(): void
    {
        $this->loginAdmin();

        $account = $this->createAccount('Connectors', 'connectors@example.com');
        $connector = $this->createConnector($account, 'Old Connector', 'old-connector@example.com');

        $this->client->request('GET', '/admin/connectors/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/admin/connectors/%s/edit', $connector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        // Edit with missing domain keeps the form open with field errors.
        $editCrawler = $this->client->request('GET', sprintf('/admin/connectors/%s/edit', $connector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $editForm = $editCrawler->filter('form')->form();
        $editValues = $editForm->getPhpValues();
        $editValues['connector']['domain'] = '';

        $this->client->request('POST', sprintf('/admin/connectors/%s/edit', $connector->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('connector[domain]', $data['invalid']);

        // Create with missing domain keeps the form open with field errors.
        $createCrawler = $this->client->request('GET', '/admin/connectors/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $createForm = $createCrawler->filter('form')->form();
        $createValues = $createForm->getPhpValues();
        $createValues['connector']['domain'] = '';
        $createValues['connector']['name'] = 'Broken Connector';

        $this->client->request('POST', '/admin/connectors/new', $createValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('connector[domain]', $data['invalid']);

        $this->client->request('POST', sprintf('/admin/connectors/%s', $connector->getId()), ['_token' => 'invalid'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/admin/connectors');
        self::assertNotNull($this->entityManager->find(Connector::class, (string) $connector->getId()));
    }

    public function testConnectorControllerSupportsSuccessfulCreateEditAndDelete(): void
    {
        $this->loginAdmin();

        $account = $this->createAccount('Primary Account', 'primary@example.com');

        $createCrawler = $this->client->request('GET', '/admin/connectors/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $createForm = $createCrawler->filter('form')->form();
        $createValues = $createForm->getPhpValues();
        $createValues['connector']['defaultAccount'] = (string) $account->getId();
        $createValues['connector']['name'] = 'Created Connector';
        $createValues['connector']['domain'] = 'created-connector.example.com';
        $createValues['connector']['pop3Host'] = 'pop.example.com';
        $createValues['connector']['pop3Port'] = '995';
        $createValues['connector']['pop3Username'] = 'pop-user';
        $createValues['connector']['pop3Password'] = 'pop-secret';
        $createValues['connector']['pop3Security'] = 'ssl';
        $createValues['connector']['smtpHost'] = 'smtp.example.com';
        $createValues['connector']['smtpPort'] = '587';
        $createValues['connector']['smtpUsername'] = 'smtp-user';
        $createValues['connector']['smtpPassword'] = 'smtp-secret';
        $createValues['connector']['smtpSecurity'] = 'tls';

        $this->client->request('POST', '/admin/connectors/new', $createValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $createdConnector = $this->entityManager->getRepository(Connector::class)->findOneBy(['domain' => 'created-connector.example.com']);
        self::assertInstanceOf(Connector::class, $createdConnector);
        self::assertSame('pop-secret', $createdConnector->getPop3Password());
        self::assertSame('smtp-secret', $createdConnector->getSmtpPassword());

        $editCrawler = $this->client->request('GET', sprintf('/admin/connectors/%s/edit', $createdConnector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $editForm = $editCrawler->filter('form')->form();
        $editValues = $editForm->getPhpValues();
        $editValues['connector']['defaultAccount'] = (string) $account->getId();
        $editValues['connector']['name'] = 'Updated Connector';
        $editValues['connector']['domain'] = 'updated-connector.example.com';
        $editValues['connector']['pop3Host'] = 'pop-updated.example.com';
        $editValues['connector']['pop3Port'] = '110';
        $editValues['connector']['pop3Username'] = 'updated-pop-user';
        $editValues['connector']['pop3Password'] = 'updated-pop-secret';
        $editValues['connector']['pop3Security'] = 'tls';
        $editValues['connector']['smtpHost'] = 'smtp-updated.example.com';
        $editValues['connector']['smtpPort'] = '25';
        $editValues['connector']['smtpUsername'] = 'updated-smtp-user';
        $editValues['connector']['smtpPassword'] = 'updated-smtp-secret';
        $editValues['connector']['smtpSecurity'] = 'ssl';

        $this->client->request('POST', sprintf('/admin/connectors/%s/edit', $createdConnector->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $updatedConnector = $this->entityManager->find(Connector::class, (string) $createdConnector->getId());
        self::assertInstanceOf(Connector::class, $updatedConnector);
        self::assertSame('Updated Connector', $updatedConnector->getName());
        self::assertSame('updated-connector.example.com', $updatedConnector->getDomain());
        self::assertSame('updated-pop-secret', $updatedConnector->getPop3Password());
        self::assertSame('updated-smtp-secret', $updatedConnector->getSmtpPassword());

        $tokenCrawler = $this->client->request('GET', '/admin/connectors', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $token = $tokenCrawler->filter(sprintf('form[action="/admin/connectors/%s"] input[name="_token"]', $updatedConnector->getId()))->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', sprintf('/admin/connectors/%s', $updatedConnector->getId()), ['_token' => $token], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/admin/connectors');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Connector::class, (string) $updatedConnector->getId()));
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
    }

    public function testConnectorRoutesRequireAdminRole(): void
    {
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('secret');

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $account = $this->createAccount('Restricted Account', 'restricted-account@example.com');
        $connector = $this->createConnector($account, 'Restricted Connector', 'restricted-connector@example.com');

        $this->client->loginUser($user);

        $this->client->request('GET', '/admin/connectors');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/connectors/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/admin/connectors/%s/edit', $connector->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/connectors/%s', $connector->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    private function createAccount(string $name, string $email): Account
    {
        $account = (new Account())
            ->setName($name)
            ->setEmail($email);

        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function createAccountWithBlockedFolder(string $name, string $email): Account
    {
        $account = $this->createAccount($name, $email);
        $folder = (new Folder())->setName('Archive');
        $this->entityManager->persist($folder);

        $emailEntity = (new Email())
            ->setAccount($account)
            ->setSubject('Archived mail')
            ->setReceivedAt(new \DateTimeImmutable('2026-01-01 10:00:00'));
        $this->entityManager->persist($emailEntity);

        $link = (new EmailFolder())
            ->setEmail($emailEntity)
            ->setFolder($folder);
        $this->entityManager->persist($link);

        $account->addFolder($folder);
        $this->entityManager->flush();

        return $account;
    }

    private function createConnector(Account $account, string $name, string $email): Connector
    {
        $domain = substr($email, (int) strrpos($email, '@') + 1);
        $connector = (new Connector())
            ->setName($name)
            ->setDomain($domain)
            ->setDefaultAccount($account);

        $this->entityManager->persist($connector);
        $this->entityManager->flush();

        return $connector;
    }

    private function createAdminUser(): User
    {
        $user = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_ADMIN']);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function requiredFoldersPayload(): string
    {
        return json_encode([
            [
                'name' => 'Postfach',
                'sortOrder' => -100,
                'functions' => [FolderFunction::MAILBOX->value],
            ],
            [
                'name' => 'Posteingang',
                'sortOrder' => -90,
                'functions' => [FolderFunction::INBOX->value],
            ],
            [
                'name' => 'Postausgang',
                'sortOrder' => -80,
                'functions' => [FolderFunction::OUTBOX->value],
            ],
            [
                'name' => 'Entwürfe',
                'sortOrder' => -70,
                'functions' => [FolderFunction::DRAFTS->value],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
