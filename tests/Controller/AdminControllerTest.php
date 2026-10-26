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

        $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $connector->getId()));
        self::assertResponseRedirects('/login');

        $this->client->request('POST', sprintf('/admin/connectors/%s/settings/pop3', $connector->getId()));
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

        // The create/edit forms are modal fragments: they are only served
        // to XMLHttpRequests.
        $this->client->request('GET', '/admin/accounts/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/admin/accounts/%s/edit', $account->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/admin/connectors');
        self::assertResponseIsSuccessful();

        // The create form is a modal fragment: it is only served to
        // XMLHttpRequests.
        $this->client->request('GET', '/admin/connectors/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $connector->getId()));
        self::assertResponseIsSuccessful();

        // The base connector form is only editable on the settings page and
        // accepts POST requests only.
        $this->client->request('GET', sprintf('/admin/connectors/%s/edit', $connector->getId()));
        self::assertResponseStatusCodeSame(405);
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

        $token = $this->fetchDeleteTokenForAccount('updated@example.com');
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

        $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $connector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        // Base form with missing domain keeps the form open with field errors.
        $settingsCrawler = $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $connector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $baseForm = $settingsCrawler->filter('form#connector-form')->form();
        $baseValues = $baseForm->getPhpValues();
        $baseValues['connector']['domain'] = '';

        $this->client->request('POST', sprintf('/admin/connectors/%s/edit', $connector->getId()), $baseValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
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

        // An unknown connector plugin is answered with a 404.
        $this->client->request('POST', sprintf('/admin/connectors/%s/settings/unknown-plugin', $connector->getId()), [], server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(404);

        // An invalid plugin form keeps the dialog open with field errors.
        $settingsCrawler = $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $connector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $pluginForm = $settingsCrawler->filter('form#pop3-connector-form')->form();
        $pluginValues = $pluginForm->getPhpValues();
        $pluginValues['pop3_connector_settings']['pop3Port'] = 'not-a-number';

        $this->client->request('POST', sprintf('/admin/connectors/%s/settings/pop3', $connector->getId()), $pluginValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('pop3_connector_settings[pop3Port]', $data['invalid']);

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

        $this->client->request('POST', '/admin/connectors/new', $createValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $createdConnector = $this->entityManager->getRepository(Connector::class)->findOneBy(['domain' => 'created-connector.example.com']);
        self::assertInstanceOf(Connector::class, $createdConnector);
        self::assertSame($account->getId()?->toRfc4122(), $createdConnector->getDefaultAccount()?->getId()?->toRfc4122());

        // Configure the POP3 plugin in the settings dialog (gear).
        $settingsCrawler = $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $createdConnector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $pluginForm = $settingsCrawler->filter('form#pop3-connector-form')->form();
        $pluginValues = $pluginForm->getPhpValues();
        $pluginValues['pop3_connector_settings']['pop3Host'] = 'pop.example.com';
        $pluginValues['pop3_connector_settings']['pop3Port'] = '995';
        $pluginValues['pop3_connector_settings']['pop3Username'] = 'pop-user';
        $pluginValues['pop3_connector_settings']['pop3Password'] = 'pop-secret';
        $pluginValues['pop3_connector_settings']['pop3Security'] = 'ssl';
        $pluginValues['pop3_connector_settings']['pop3DeleteAfterFetch'] = '1';
        $pluginValues['pop3_connector_settings']['smtpHost'] = 'smtp.example.com';
        $pluginValues['pop3_connector_settings']['smtpPort'] = '587';
        $pluginValues['pop3_connector_settings']['smtpUsername'] = 'smtp-user';
        $pluginValues['pop3_connector_settings']['smtpPassword'] = 'smtp-secret';
        $pluginValues['pop3_connector_settings']['smtpSecurity'] = 'tls';

        $this->client->request('POST', sprintf('/admin/connectors/%s/settings/pop3', $createdConnector->getId()), $pluginValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $configRepository = $this->entityManager->getRepository(\AvalynxOffice\Entity\ConnectorConfig::class);
        $savedConfig = $configRepository->findOneBy(['connector' => $createdConnector, 'pluginKey' => 'pop3']);
        self::assertInstanceOf(\AvalynxOffice\Entity\ConnectorConfig::class, $savedConfig);
        $config = $savedConfig->getConfig();
        self::assertSame('pop.example.com', $config['pop3Host']);
        self::assertSame('pop-secret', $config['pop3Password']);
        self::assertSame('smtp-secret', $config['smtpPassword']);
        self::assertTrue($config['pop3DeleteAfterFetch']);

        // The repository helper returns the stored config as plain array.
        self::assertSame($config, $configRepository->getConfig($createdConnector, 'pop3'));
        self::assertSame([], $configRepository->getConfig($createdConnector, 'unknown'));

        // Re-saving with empty password fields keeps the stored passwords.
        $settingsCrawler = $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $createdConnector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $pluginForm = $settingsCrawler->filter('form#pop3-connector-form')->form();
        $pluginValues = $pluginForm->getPhpValues();
        $pluginValues['pop3_connector_settings']['pop3Host'] = 'pop-updated.example.com';
        $pluginValues['pop3_connector_settings']['pop3Password'] = '';
        $pluginValues['pop3_connector_settings']['smtpPassword'] = '';

        $this->client->request('POST', sprintf('/admin/connectors/%s/settings/pop3', $createdConnector->getId()), $pluginValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);

        $this->entityManager->clear();
        $savedConfig = $configRepository->findOneBy(['connector' => $createdConnector, 'pluginKey' => 'pop3']);
        self::assertInstanceOf(\AvalynxOffice\Entity\ConnectorConfig::class, $savedConfig);
        $config = $savedConfig->getConfig();
        self::assertSame('pop-updated.example.com', $config['pop3Host']);
        self::assertSame('pop-secret', $config['pop3Password']);
        self::assertSame('smtp-secret', $config['smtpPassword']);

        // A duplicate domain keeps the modal open with a field error.
        $dupCrawler = $this->client->request('GET', '/admin/connectors/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $dupForm = $dupCrawler->filter('form')->form();
        $dupValues = $dupForm->getPhpValues();
        $dupValues['connector']['name'] = 'Duplicate Connector';
        $dupValues['connector']['domain'] = 'created-connector.example.com';

        $this->client->request('POST', '/admin/connectors/new', $dupValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('connector[domain]', $data['invalid']);
        self::assertCount(1, $this->entityManager->getRepository(Connector::class)->findBy(['domain' => 'created-connector.example.com']));

        // The settings page updates the base data of the connector.
        $settingsCrawler = $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $createdConnector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $baseForm = $settingsCrawler->filter('form#connector-form')->form();
        $baseValues = $baseForm->getPhpValues();
        $baseValues['connector']['name'] = 'Renamed Connector';

        $this->client->request('POST', sprintf('/admin/connectors/%s/edit', $createdConnector->getId()), $baseValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $renamed = $this->entityManager->find(Connector::class, (string) $createdConnector->getId());
        self::assertInstanceOf(Connector::class, $renamed);
        self::assertSame('Renamed Connector', $renamed->getName());

        // The datatable row links to the settings dialog.
        $payload = $this->fetchConnectorData(['search' => 'created-connector.example.com']);
        self::assertSame(1, $payload['count']['filtered']);
        $row = $payload['data'][0]['data'];
        self::assertSame('Renamed Connector', $row['name']);
        self::assertStringContainsString($this->generateConnectorSettingsUrl((string) $createdConnector->getId()), $row['options']);
        self::assertStringContainsString('fa-gear', $row['options']);

        $tokenCrawler = $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $createdConnector->getId()));
        $token = $tokenCrawler->filter(sprintf('form[action="/admin/connectors/%s"] input[name="_token"]', $createdConnector->getId()))->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', sprintf('/admin/connectors/%s', $createdConnector->getId()), ['_token' => $token], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/admin/connectors');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Connector::class, (string) $createdConnector->getId()));
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
    }

    /**
     * Fetches the admin account datatable payload.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function fetchAccountData(array $params = []): array
    {
        $this->client->request('POST', '/admin/accounts/data', $params);

        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(is_array($decoded));

        return $decoded;
    }

    /**
     * Extracts the delete CSRF token for the account with the given email
     * from the datatable row options.
     */
    private function fetchDeleteTokenForAccount(string $email): ?string
    {
        $payload = $this->fetchAccountData(['search' => $email]);

        foreach ($payload['data'] as $row) {
            \assert(is_array($row) && is_array($row['data']));
            if ($row['data']['email'] === $email) {
                preg_match('/name="_token" value="([^"]+)"/', (string) $row['data']['options'], $matches);

                return $matches[1] ?? null;
            }
        }

        return null;
    }

    public function testAccountDataEndpointReturnsRowsWithRelations(): void
    {
        $this->loginAdmin();

        $user = (new User())
            ->setEmail('member@example.com')
            ->setPassword('secret');
        $this->entityManager->persist($user);

        $account = $this->createAccount('Zeta Account', 'zeta@example.com');
        $connector = $this->createConnector($account, 'Zeta Connector', 'zeta@example.com');
        $alias = (new AccountAlias())->setAccount($account)->setConnector($connector)->setLocalPart('info');
        $account->addAlias($alias);
        $this->entityManager->persist($alias);
        $account->addUser($user);

        // A folder without any function is rendered with the "none" label.
        $plainFolder = (new Folder())->setName('Archiv');
        $this->entityManager->persist($plainFolder);
        $account->addFolder($plainFolder);
        $this->entityManager->flush();

        $payload = $this->fetchAccountData();

        self::assertSame(1, $payload['count']['total']);
        self::assertSame(1, $payload['count']['all']);
        self::assertSame(['name' => 'asc'], $payload['sorting']);
        self::assertCount(1, $payload['data']);

        $row = $payload['data'][0]['data'];
        self::assertSame('Zeta Account', $row['name']);
        self::assertSame('zeta@example.com', $row['email']);
        self::assertStringContainsString('member@example.com', $row['users']);
        self::assertStringContainsString('info@example.com', $row['aliases']);
        self::assertStringContainsString('Archiv', $row['folders']);
        self::assertStringContainsString('No function', $row['folders']);
        self::assertStringContainsString($this->generateAccountEditUrl((string) $account->getId()), $row['options']);
        self::assertStringContainsString('btn-primary', $row['options']);
        self::assertStringContainsString('btn-danger', $row['options']);
    }

    public function testCreateWithInvalidBaseFieldsKeepsFormOpen(): void
    {
        $this->loginAdmin();

        $crawler = $this->client->request('GET', '/admin/accounts/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $values = $form->getPhpValues();
        $values['account']['name'] = '';
        $values['account']['email'] = 'broken';

        $this->client->request('POST', '/admin/accounts/new', $values, server: ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('account[name]', $data['invalid']);
        self::assertArrayHasKey('account[email]', $data['invalid']);
        self::assertNull($this->entityManager->getRepository(Account::class)->findOneBy(['email' => 'broken']));
    }

    public function testAccountDataEndpointSearchesFiltersAndValidatesSorting(): void
    {
        $this->loginAdmin();
        $this->createAccount('Alpha Account', 'alpha@example.com');

        $filtered = $this->fetchAccountData(['search' => 'alpha']);
        self::assertSame(1, $filtered['count']['filtered']);
        self::assertSame('alpha@example.com', $filtered['data'][0]['data']['email']);

        $noMatch = $this->fetchAccountData(['search' => 'nothing-matches']);
        self::assertSame(0, $noMatch['count']['filtered']);
        self::assertSame([], $noMatch['data']);
        self::assertSame(0, $noMatch['count']['start']);

        $invalidJson = $this->fetchAccountData(['sorting' => '{not-json']);
        self::assertSame(['name' => 'asc'], $invalidJson['sorting']);

        $unknownColumn = $this->fetchAccountData(['sorting' => json_encode(['hacker' => 'desc'])]);
        self::assertSame(['name' => 'asc'], $unknownColumn['sorting']);

        $byEmail = $this->fetchAccountData(['sorting' => json_encode(['email' => 'desc'])]);
        self::assertSame(['email' => 'desc'], $byEmail['sorting']);

        $invalidPerPage = $this->fetchAccountData(['perpage' => '13']);
        self::assertSame(10, $invalidPerPage['count']['perpage']);
    }

    public function testAccountDataEndpointRequiresAdminRole(): void
    {
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('secret');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/admin/accounts/data');

        self::assertResponseStatusCodeSame(403);
    }

    private function generateAccountEditUrl(string $id): string
    {
        /** @var \Symfony\Component\Routing\RouterInterface $router */
        $router = static::getContainer()->get('router');

        return $router->generate('account_edit', ['id' => $id]);
    }

    /**
     * Fetches the admin connector datatable payload.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function fetchConnectorData(array $params = []): array
    {
        $this->client->request('POST', '/admin/connectors/data', $params);

        self::assertResponseIsSuccessful();

        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(is_array($decoded));

        return $decoded;
    }

    private function generateConnectorSettingsUrl(string $id): string
    {
        /** @var \Symfony\Component\Routing\RouterInterface $router */
        $router = static::getContainer()->get('router');

        return $router->generate('connector_settings', ['id' => $id]);
    }

    public function testConnectorDataEndpointReturnsRowsWithStatusAndOptions(): void
    {
        $this->loginAdmin();

        $account = $this->createAccount('Connector Account', 'connector-account@example.com');
        $connector = $this->createConnector($account, 'Zeta Connector', 'zeta@zeta-connector.example.com');

        $payload = $this->fetchConnectorData();

        self::assertSame(1, $payload['count']['total']);
        self::assertSame(1, $payload['count']['all']);
        self::assertSame(['name' => 'asc'], $payload['sorting']);
        self::assertCount(1, $payload['data']);

        $row = $payload['data'][0]['data'];
        self::assertSame('Zeta Connector', $row['name']);
        self::assertSame('zeta-connector.example.com', $row['domain']);
        self::assertStringContainsString('Connector Account', $row['defaultAccount']);
        self::assertStringContainsString('connector-account@example.com', $row['defaultAccount']);
        // Nothing configured yet: input and output stay unconfigured.
        self::assertStringContainsString('text-bg-secondary', $row['input']);
        self::assertStringContainsString('text-bg-secondary', $row['output']);
        self::assertStringContainsString('Mails', $row['status']);
        self::assertStringContainsString($this->generateConnectorSettingsUrl((string) $connector->getId()), $row['options']);
        self::assertStringContainsString('fa-gear', $row['options']);
        self::assertStringContainsString('btn-danger', $row['options']);
        self::assertStringContainsString('confirm-action', $row['options']);
        self::assertStringNotContainsString('disabled', $row['options']);
    }

    public function testConnectorDataEndpointSearchesAndValidatesSorting(): void
    {
        $this->loginAdmin();
        $account = $this->createAccount('Search Account', 'search-account@example.com');
        $connector = $this->createConnector($account, 'Alpha Connector', 'alpha-connector.example.com');

        // A connector without a default account renders the fallback note.
        $connector->setDefaultAccount(null);
        $this->entityManager->flush();

        $filtered = $this->fetchConnectorData(['search' => 'alpha']);
        self::assertSame(1, $filtered['count']['filtered']);
        self::assertSame('Alpha Connector', $filtered['data'][0]['data']['name']);
        self::assertStringContainsString('No default account', $filtered['data'][0]['data']['defaultAccount']);

        $noMatch = $this->fetchConnectorData(['search' => 'nothing-matches']);
        self::assertSame(0, $noMatch['count']['filtered']);
        self::assertSame([], $noMatch['data']);

        $invalidJson = $this->fetchConnectorData(['sorting' => '{not-json']);
        self::assertSame(['name' => 'asc'], $invalidJson['sorting']);

        $unknownColumn = $this->fetchConnectorData(['sorting' => json_encode(['hacker' => 'desc'])]);
        self::assertSame(['name' => 'asc'], $unknownColumn['sorting']);

        $byDomain = $this->fetchConnectorData(['sorting' => json_encode(['domain' => 'desc'])]);
        self::assertSame(['domain' => 'desc'], $byDomain['sorting']);

        $invalidPerPage = $this->fetchConnectorData(['perpage' => '13']);
        self::assertSame(10, $invalidPerPage['count']['perpage']);
    }

    public function testConnectorDataEndpointRequiresAdminRole(): void
    {
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('secret');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->client->loginUser($user);
        $this->client->request('POST', '/admin/connectors/data');

        self::assertResponseStatusCodeSame(403);
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

        $this->client->request('GET', sprintf('/admin/connectors/%s/settings', $connector->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/admin/connectors/%s/settings/pop3', $connector->getId()));
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
