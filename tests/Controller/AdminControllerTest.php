<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\FolderFunction;
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

    public function testMailboxAndConnectorRoutesRedirectToLoginForGuests(): void
    {
        $mailbox = $this->createMailbox('Redirect Mailbox', 'redirect-mailbox@example.com');
        $connector = $this->createConnector($mailbox, 'Redirect Connector', 'redirect-connector@example.com');

        $this->client->request('GET', '/settings/mailboxes');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/settings/mailboxes/view');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/settings/mailboxes/new');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', sprintf('/settings/mailboxes/%s/edit', $mailbox->getId()));
        self::assertResponseRedirects('/login');

        $this->client->request('POST', sprintf('/settings/mailboxes/%s', $mailbox->getId()));
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/settings/connectors');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/settings/connectors/new');
        self::assertResponseRedirects('/login');

        $this->client->request('GET', sprintf('/settings/connectors/%s/edit', $connector->getId()));
        self::assertResponseRedirects('/login');

        $this->client->request('POST', sprintf('/settings/connectors/%s', $connector->getId()));
        self::assertResponseRedirects('/login');
    }

    public function testMailboxAndConnectorPagesRenderForAdmins(): void
    {
        $this->loginAdmin();

        $mailbox = $this->createMailbox('Page Mailbox', 'page-mailbox@example.com');
        $connector = $this->createConnector($mailbox, 'Page Connector', 'page-connector@example.com');

        $this->client->request('GET', '/settings/mailboxes');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/settings/mailboxes/view');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/settings/mailboxes/new');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/settings/mailboxes/%s/edit', $mailbox->getId()));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/settings/connectors');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/settings/connectors/new');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/settings/connectors/%s/edit', $connector->getId()));
        self::assertResponseIsSuccessful();
    }

    public function testMailboxControllerSupportsCreateEditAndDeleteFlows(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/settings/mailboxes/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/settings/mailboxes/view', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $mailbox = $this->createMailboxWithBlockedFolder('Support', 'support-edit@example.com');
        $this->client->request('GET', sprintf('/settings/mailboxes/%s/edit', $mailbox->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $invalidToken = 'invalid';
        $this->client->request('POST', sprintf('/settings/mailboxes/%s', $mailbox->getId()), ['_token' => $invalidToken], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/settings/mailboxes');
        self::assertNotNull($this->entityManager->find(Mailbox::class, (string) $mailbox->getId()));
    }

    public function testMailboxControllerSupportsSuccessfulCreateEditAndDelete(): void
    {
        $this->loginAdmin();

        $createCrawler = $this->client->request('GET', '/settings/mailboxes/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $createForm = $createCrawler->filter('form')->form();
        $createValues = $createForm->getPhpValues();
        $createValues['mailbox']['name'] = 'Created Mailbox';
        $createValues['mailbox']['email'] = 'created@example.com';
        $createValues['mailbox']['aliases'] = json_encode([
            ['email' => 'alias@example.com', 'name' => 'Alias'],
        ], JSON_THROW_ON_ERROR);
        $createValues['mailbox']['folders'] = $this->requiredFoldersPayload();

        $this->client->request('POST', '/settings/mailboxes/new', $createValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $createdMailbox = $this->entityManager->getRepository(Mailbox::class)->findOneBy(['email' => 'created@example.com']);
        self::assertInstanceOf(Mailbox::class, $createdMailbox);
        self::assertSame('Created Mailbox', $createdMailbox->getName());
        self::assertCount(4, $createdMailbox->getFolders());

        $editCrawler = $this->client->request('GET', sprintf('/settings/mailboxes/%s/edit', $createdMailbox->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $editForm = $editCrawler->filter('form')->form();
        $editValues = $editForm->getPhpValues();
        $editValues['mailbox']['name'] = 'Updated Mailbox';
        $editValues['mailbox']['email'] = 'updated@example.com';
        $editValues['mailbox']['aliases'] = '[]';
        $editValues['mailbox']['folders'] = $this->requiredFoldersPayload();

        $this->client->request('POST', sprintf('/settings/mailboxes/%s/edit', $createdMailbox->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $updatedMailbox = $this->entityManager->find(Mailbox::class, (string) $createdMailbox->getId());
        self::assertInstanceOf(Mailbox::class, $updatedMailbox);
        self::assertSame('Updated Mailbox', $updatedMailbox->getName());
        self::assertSame('updated@example.com', $updatedMailbox->getEmail());

        $tokenCrawler = $this->client->request('GET', '/settings/mailboxes', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $token = $tokenCrawler->filter(sprintf('form[action="/settings/mailboxes/%s"] input[name="_token"]', $updatedMailbox->getId()))->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', sprintf('/settings/mailboxes/%s', $updatedMailbox->getId()), ['_token' => $token], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/settings/mailboxes');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(Mailbox::class, (string) $updatedMailbox->getId()));
    }

    public function testMailboxControllerKeepsFormOpenForValidationErrors(): void
    {
        $this->loginAdmin();

        $createCrawler = $this->client->request('GET', '/settings/mailboxes/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $createForm = $createCrawler->filter('form')->form();
        $createValues = $createForm->getPhpValues();
        $createValues['mailbox']['name'] = 'Invalid Mailbox';
        $createValues['mailbox']['email'] = 'invalid@example.com';
        $createValues['mailbox']['aliases'] = json_encode([
            ['email' => 'not-an-email', 'name' => 'Broken'],
        ], JSON_THROW_ON_ERROR);
        $createValues['mailbox']['folders'] = json_encode([
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

        $this->client->request('POST', '/settings/mailboxes/new', $createValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('mailbox[aliases]', $data['invalid']);
        self::assertArrayHasKey('mailbox[folders]', $data['invalid']);
        self::assertNull($this->entityManager->getRepository(Mailbox::class)->findOneBy(['email' => 'invalid@example.com']));

        $mailbox = $this->createMailboxWithBlockedFolder('Blocked Mailbox', 'blocked@example.com');
        $editCrawler = $this->client->request('GET', sprintf('/settings/mailboxes/%s/edit', $mailbox->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $editForm = $editCrawler->filter('form')->form();
        $editValues = $editForm->getPhpValues();
        $editValues['mailbox']['name'] = 'Blocked Mailbox';
        $editValues['mailbox']['email'] = 'blocked@example.com';
        $editValues['mailbox']['aliases'] = '[]';
        $editValues['mailbox']['folders'] = $this->requiredFoldersPayload();

        $this->client->request('POST', sprintf('/settings/mailboxes/%s/edit', $mailbox->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('mailbox[folders]', $data['invalid']);
    }

    public function testMailboxEditKeepsFormOpenForAliasDuplicateAndMissingFunctionErrors(): void
    {
        $this->loginAdmin();

        $mailbox = $this->createMailbox('Editable Mailbox', 'editable@example.com');
        $editCrawler = $this->client->request('GET', sprintf('/settings/mailboxes/%s/edit', $mailbox->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $editForm = $editCrawler->filter('form')->form();
        $editValues = $editForm->getPhpValues();
        $editValues['mailbox']['name'] = 'Editable Mailbox';
        $editValues['mailbox']['email'] = 'editable@example.com';
        $editValues['mailbox']['aliases'] = json_encode([
            ['email' => 'not-an-email', 'name' => 'Broken'],
        ], JSON_THROW_ON_ERROR);
        $editValues['mailbox']['folders'] = json_encode([
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

        $this->client->request('POST', sprintf('/settings/mailboxes/%s/edit', $mailbox->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($data['success']);
        self::assertArrayHasKey('mailbox[aliases]', $data['invalid']);
    }

    public function testConnectorControllerSupportsCreateEditAndDeleteFlows(): void
    {
        $this->loginAdmin();

        $mailbox = $this->createMailbox('Connectors', 'connectors@example.com');
        $connector = $this->createConnector($mailbox, 'Old Connector', 'old-connector@example.com');

        $this->client->request('GET', '/settings/connectors/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/settings/connectors/%s/edit', $connector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', sprintf('/settings/connectors/%s', $connector->getId()), ['_token' => 'invalid'], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/settings/connectors');
        self::assertNotNull($this->entityManager->find(MailboxConnector::class, (string) $connector->getId()));
    }

    public function testConnectorControllerSupportsSuccessfulCreateEditAndDelete(): void
    {
        $this->loginAdmin();

        $mailbox = $this->createMailbox('Primary Mailbox', 'primary@example.com');

        $createCrawler = $this->client->request('GET', '/settings/connectors/new', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $createForm = $createCrawler->filter('form')->form();
        $createValues = $createForm->getPhpValues();
        $createValues['mailbox_connector']['mailbox'] = (string) $mailbox->getId();
        $createValues['mailbox_connector']['name'] = 'Created Connector';
        $createValues['mailbox_connector']['email'] = 'created-connector@example.com';
        $createValues['mailbox_connector']['pop3Host'] = 'pop.example.com';
        $createValues['mailbox_connector']['pop3Port'] = '995';
        $createValues['mailbox_connector']['pop3Username'] = 'pop-user';
        $createValues['mailbox_connector']['pop3Password'] = 'pop-secret';
        $createValues['mailbox_connector']['pop3Security'] = 'ssl';
        $createValues['mailbox_connector']['smtpHost'] = 'smtp.example.com';
        $createValues['mailbox_connector']['smtpPort'] = '587';
        $createValues['mailbox_connector']['smtpUsername'] = 'smtp-user';
        $createValues['mailbox_connector']['smtpPassword'] = 'smtp-secret';
        $createValues['mailbox_connector']['smtpSecurity'] = 'tls';
        $createValues['mailbox_connector']['isPrimary'] = '1';

        $this->client->request('POST', '/settings/connectors/new', $createValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $createdConnector = $this->entityManager->getRepository(MailboxConnector::class)->findOneBy(['email' => 'created-connector@example.com']);
        self::assertInstanceOf(MailboxConnector::class, $createdConnector);
        self::assertSame('pop-secret', $createdConnector->getPop3Password());
        self::assertSame('smtp-secret', $createdConnector->getSmtpPassword());

        $editCrawler = $this->client->request('GET', sprintf('/settings/connectors/%s/edit', $createdConnector->getId()), server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();

        $editForm = $editCrawler->filter('form')->form();
        $editValues = $editForm->getPhpValues();
        $editValues['mailbox_connector']['mailbox'] = (string) $mailbox->getId();
        $editValues['mailbox_connector']['name'] = 'Updated Connector';
        $editValues['mailbox_connector']['email'] = 'updated-connector@example.com';
        $editValues['mailbox_connector']['pop3Host'] = 'pop-updated.example.com';
        $editValues['mailbox_connector']['pop3Port'] = '110';
        $editValues['mailbox_connector']['pop3Username'] = 'updated-pop-user';
        $editValues['mailbox_connector']['pop3Password'] = 'updated-pop-secret';
        $editValues['mailbox_connector']['pop3Security'] = 'tls';
        $editValues['mailbox_connector']['smtpHost'] = 'smtp-updated.example.com';
        $editValues['mailbox_connector']['smtpPort'] = '25';
        $editValues['mailbox_connector']['smtpUsername'] = 'updated-smtp-user';
        $editValues['mailbox_connector']['smtpPassword'] = 'updated-smtp-secret';
        $editValues['mailbox_connector']['smtpSecurity'] = 'ssl';

        $this->client->request('POST', sprintf('/settings/connectors/%s/edit', $createdConnector->getId()), $editValues, server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($data['success']);
        self::assertArrayHasKey('redirect', $data);

        $this->entityManager->clear();
        $updatedConnector = $this->entityManager->find(MailboxConnector::class, (string) $createdConnector->getId());
        self::assertInstanceOf(MailboxConnector::class, $updatedConnector);
        self::assertSame('Updated Connector', $updatedConnector->getName());
        self::assertSame('updated-connector@example.com', $updatedConnector->getEmail());
        self::assertSame('updated-pop-secret', $updatedConnector->getPop3Password());
        self::assertSame('updated-smtp-secret', $updatedConnector->getSmtpPassword());

        $tokenCrawler = $this->client->request('GET', '/settings/connectors', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $token = $tokenCrawler->filter(sprintf('form[action="/settings/connectors/%s"] input[name="_token"]', $updatedConnector->getId()))->attr('value');
        self::assertNotNull($token);
        $this->client->request('POST', sprintf('/settings/connectors/%s', $updatedConnector->getId()), ['_token' => $token], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        self::assertResponseRedirects('/settings/connectors');

        $this->entityManager->clear();
        self::assertNull($this->entityManager->find(MailboxConnector::class, (string) $updatedConnector->getId()));
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

        $mailbox = $this->createMailbox('Restricted Mailbox', 'restricted-mailbox@example.com');
        $connector = $this->createConnector($mailbox, 'Restricted Connector', 'restricted-connector@example.com');

        $this->client->loginUser($user);

        $this->client->request('GET', '/settings/connectors');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/settings/connectors/new');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', sprintf('/settings/connectors/%s/edit', $connector->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/settings/connectors/%s', $connector->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    private function createMailbox(string $name, string $email): Mailbox
    {
        $mailbox = (new Mailbox())
            ->setName($name)
            ->setEmail($email);

        $this->entityManager->persist($mailbox);
        $this->entityManager->flush();

        return $mailbox;
    }

    private function createMailboxWithBlockedFolder(string $name, string $email): Mailbox
    {
        $mailbox = $this->createMailbox($name, $email);
        $folder = (new Folder())->setName('Archive');
        $this->entityManager->persist($folder);

        $emailEntity = (new Email())
            ->setMailbox($mailbox)
            ->setSubject('Archived mail')
            ->setReceivedAt(new \DateTimeImmutable('2026-01-01 10:00:00'));
        $this->entityManager->persist($emailEntity);

        $link = (new EmailFolder())
            ->setEmail($emailEntity)
            ->setFolder($folder);
        $this->entityManager->persist($link);

        $mailbox->addFolder($folder);
        $this->entityManager->flush();

        return $mailbox;
    }

    private function createConnector(Mailbox $mailbox, string $name, string $email): MailboxConnector
    {
        $connector = (new MailboxConnector())
            ->setMailbox($mailbox)
            ->setName($name)
            ->setEmail($email);

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
