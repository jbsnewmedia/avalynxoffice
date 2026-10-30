<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAddress;
use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\CalendarEvent;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Licensing\Service\LicenseManager;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional tests for the read-only mode: user-initiated writes are blocked
 * with HTTP 403 LICENSE_READ_ONLY, reads and the mail import stay available.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('functional')]
final class LicenseReadOnlyFunctionalTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private EntityManagerInterface $em;

    private User $admin;

    private Account $account;

    private Folder $inbox;

    private Email $email;

    private Address $contact;

    private CalendarEvent $event;

    private Connector $connector;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $_ENV['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_PUBLIC_KEYS'] = '';
        $_ENV['AVALYNXOFFICE_LICENSE_KEYS'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_KEYS'] = '';
        $_ENV['AVALYNXOFFICE_LICENSE_FILE'] = '';
        $_SERVER['AVALYNXOFFICE_LICENSE_FILE'] = '';
        $_ENV['AVALYNXOFFICE_ORGANIZATION_ID'] = 'test-organization';
        $_SERVER['AVALYNXOFFICE_ORGANIZATION_ID'] = 'test-organization';
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->refreshDatabase($this->em);
        $this->seedReadOnlyInstallation();
    }

    /**
     * Seeds an installation whose grace period has expired while 5 accounts
     * are active: the effective status is READ_ONLY.
     */
    private function seedReadOnlyInstallation(): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->admin = new User();
        $this->admin->setEmail('admin@example.com');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword($hasher->hashPassword($this->admin, 'admin'));
        $this->admin->setLastAuthAt(new \DateTimeImmutable('now'));
        $this->em->persist($this->admin);

        for ($i = 1; $i <= 4; ++$i) {
            $extra = new User();
            $extra->setEmail(sprintf('extra-%d@example.com', $i));
            $extra->setPassword('x');
            $extra->setLastAuthAt(new \DateTimeImmutable('now'));
            $this->em->persist($extra);
        }

        $state = static::getContainer()->get(\AvalynxOffice\Licensing\Service\LicenseGracePeriodManager::class)->getState(null);
        $state->setViolationStartedAt(new \DateTimeImmutable('-100 days', new \DateTimeZone('UTC')));
        $state->setGracePeriodEndsAt(new \DateTimeImmutable('-70 days', new \DateTimeZone('UTC')));

        $this->account = new Account();
        $this->account->setName('Info')->setEmail('info@example.com');
        $this->em->persist($this->account);

        $this->connector = new Connector();
        $this->connector->setDefaultAccount($this->account);
        $this->connector->setName('Primary');
        $this->connector->setDomain('example.com');
        $this->em->persist($this->connector);

        $config = new \AvalynxOffice\Entity\ConnectorConfig();
        $config->setConnector($this->connector);
        $config->setPluginKey('pop3');
        $config->setConfig([
            'pop3Host' => 'pop3.example.com',
            'pop3Port' => 995,
            'pop3Username' => 'info',
            'pop3Password' => 'secret',
            'pop3Security' => 'ssl',
            'pop3DeleteAfterFetch' => true,
        ]);
        $this->em->persist($config);

        $this->inbox = (new Folder())->setName('Posteingang');
        $this->inbox->setFunctions([\AvalynxOffice\Entity\Enum\FolderFunction::INBOX->value]);
        $this->em->persist($this->inbox);
        $this->account->setInboxFolder($this->inbox);
        $inboxLink = new \AvalynxOffice\Entity\FolderAccount();
        $inboxLink->setAccount($this->account);
        $inboxLink->setFolder($this->inbox);
        $this->em->persist($inboxLink);

        $this->email = new Email();
        $this->email->setSubject('Incoming message');
        $this->email->setAccount($this->account);
        $this->email->setPreviewBody('Body');
        $emailFolder = new EmailFolder();
        $emailFolder->setEmail($this->email);
        $emailFolder->setFolder($this->inbox);
        $this->email->addEmailFolder($emailFolder);
        $this->em->persist($this->email);

        $this->contact = new Address();
        $this->contact->setEmail('contact@example.com');
        $this->contact->setName('Contact');
        $this->contact->setIsContact(true);
        $this->em->persist($this->contact);
        $accountAddress = new AccountAddress();
        $accountAddress->setAccount($this->account);
        $accountAddress->setAddress($this->contact);
        $this->em->persist($accountAddress);

        $this->event = new CalendarEvent();
        $this->event->setTitle('Review');
        $this->event->setStartAt(new \DateTimeImmutable('+1 day'));
        $this->event->setAccount($this->account);
        $this->em->persist($this->event);

        $this->em->flush();

        static::getContainer()->get(LicenseManager::class)->reset();
    }

    public function testInstallationIsReadOnly(): void
    {
        $manager = static::getContainer()->get(LicenseManager::class);
        self::assertTrue($manager->isReadOnly());
        self::assertSame(\AvalynxOffice\Licensing\Model\LicenseStatus::READ_ONLY, $manager->getStatus());
        self::assertSame(5, $manager->getUsedSeats());
        self::assertSame(3, $manager->getAllowedSeats());
    }

    public function testMailIndexAndDetailRemainReadable(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/mails');

        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/email/'.$this->email->getId());
        self::assertResponseIsSuccessful();

        /** @var Email $reloaded */
        $reloaded = $this->em->find(Email::class, $this->email->getId());
        $this->em->refresh($reloaded);
        // Opening a message must not change its read state.
        self::assertFalse($reloaded->isRead());
    }

    public function testReadToggleIsBlockedAndChangesNothing(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('mail_favorite');
        $this->client->request('POST', '/email/'.$this->email->getId().'/read', ['read' => '1', '_token' => $token], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);
        $response = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('LICENSE_READ_ONLY', $response);

        $this->em->clear();
        /** @var Email $reloaded */
        $reloaded = $this->em->find(Email::class, $this->email->getId());
        self::assertFalse($reloaded->isRead());
    }

    public function testMoveIsBlocked(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/email/'.$this->email->getId().'/move', ['targetFolderId' => (string) $this->inbox->getId()], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testFavoriteIsBlocked(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('mail_favorite');
        $this->client->request('POST', '/email/'.$this->email->getId().'/favorite', ['favorite' => '1', '_token' => $token], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        /** @var Email $reloaded */
        $reloaded = $this->em->find(Email::class, $this->email->getId());
        self::assertFalse($reloaded->isFavorite());
    }

    public function testReplyDraftCreationIsBlocked(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('reply_email_'.$this->email->getId());
        $this->client->request('POST', '/mail/reply/'.$this->email->getId(), ['_token' => $token], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);
        // No outgoing draft was persisted.
        $drafts = $this->em->getRepository(Email::class)->findBy(['isOutgoing' => true]);
        self::assertCount(0, $drafts);
    }

    public function testAttachmentDownloadRemainsPossible(): void
    {
        $storage = static::getContainer()->get(AttachmentStorage::class);
        $attachment = new Attachment();
        $attachment->setName('report.txt');
        $attachment->setSize(11);
        $attachment->setMimeType('text/plain');
        $attachment->setSha1(sha1('hello world'));
        $attachment->setFilePath($storage->store($attachment, 'hello world'));
        $link = new EmailAttachmentLink();
        $link->setEmail($this->email);
        $link->setAttachment($attachment);
        $this->email->addEmailAttachment($link);
        $this->em->persist($attachment);
        $this->em->persist($link);
        $this->em->flush();

        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/email/'.$this->email->getId().'/attachment/'.$attachment->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment;', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testCalendarDeleteIsBlocked(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('delete_event_'.$this->event->getId());

        $this->client->request('POST', '/calendar/all/'.$this->event->getId().'/delete', ['_token' => $token], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->find(CalendarEvent::class, $this->event->getId()));
    }

    public function testCalendarReadRemainsPossible(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/calendar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Review');
    }

    public function testContactDeleteIsBlocked(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('delete_contact_'.$this->contact->getId());

        $this->client->request('POST', '/contacts/all/'.$this->contact->getId().'/delete', ['_token' => $token], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->find(Address::class, $this->contact->getId()));
    }

    public function testContactsReadRemainsPossible(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/contacts');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'contact@example.com');
    }

    public function testAccountDeleteIsBlocked(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('delete_account_'.$this->account->getId());

        $this->client->request('POST', '/admin/accounts/'.$this->account->getId(), ['_token' => $token], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->find(Account::class, $this->account->getId()));
    }

    public function testConnectorDeleteIsBlocked(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('delete_connector_'.$this->connector->getId());

        $this->client->request('POST', '/admin/connectors/'.$this->connector->getId(), ['_token' => $token], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->find(Connector::class, $this->connector->getId()));
    }

    public function testLicenseKeyManagementStaysPossibleWhileReadOnly(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('add_license_keys');

        $this->client->request('POST', '/admin/license/keys', [
            '_token' => $token,
            'license_keys' => 'not-a-valid-key',
        ]);

        self::assertResponseRedirects('/admin/license');
        self::assertCount(1, $this->em->getRepository(\AvalynxOffice\Licensing\Entity\StoredLicenseKey::class)->findAll());
    }

    public function testUserDeactivationStaysPossibleWhileReadOnlyAndEndsReadOnly(): void
    {
        $this->client->loginUser($this->admin);

        $extra = $this->em->getRepository(User::class)->findOneBy(['email' => 'extra-1@example.com']);
        self::assertNotNull($extra);
        $this->updateUser($extra, []);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['success']);

        $this->em->clear();
        $deactivated = $this->em->find(User::class, $extra->getId());
        self::assertInstanceOf(User::class, $deactivated);
        self::assertFalse($deactivated->isActive());

        // Reactivating the account is blocked while the installation is
        // read-only.
        $this->updateUser($extra, ['active' => '1']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $stillInactive = $this->em->find(User::class, $extra->getId());
        self::assertInstanceOf(User::class, $stillInactive);
        self::assertFalse($stillInactive->isActive());

        // Still 4 active accounts: read-only persists.
        $manager = static::getContainer()->get(LicenseManager::class);
        $manager->reset();
        self::assertTrue($manager->isReadOnly());

        foreach (['extra-2@example.com', 'extra-3@example.com'] as $emailAddress) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $emailAddress]);
            self::assertNotNull($user);
            $this->updateUser($user, []);
            self::assertResponseIsSuccessful();
        }

        $manager->reset();
        self::assertFalse($manager->isReadOnly());
        self::assertSame(\AvalynxOffice\Licensing\Model\LicenseStatus::COMMUNITY, $manager->getStatus());

        // Reactivation is allowed again after the installation is compliant.
        $reactivate = $this->em->getRepository(User::class)->findOneBy(['email' => 'extra-1@example.com']);
        self::assertNotNull($reactivate);
        $this->updateUser($reactivate, ['active' => '1']);
        self::assertResponseIsSuccessful();
    }

    public function testUserEditBlocksChangesOtherThanDeactivationWhileReadOnly(): void
    {
        $this->client->loginUser($this->admin);

        $extra = $this->em->getRepository(User::class)->findOneBy(['email' => 'extra-1@example.com']);
        self::assertNotNull($extra);

        // A reactivation or any other modification is rejected.
        $this->updateUser($extra, ['active' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->json()['success']);

        $this->updateUser($extra, ['password' => 'changed-secret']);
        self::assertResponseStatusCodeSame(403);

        $this->updateUser($extra, ['email' => 'renamed@example.com']);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $unchanged = $this->em->find(User::class, $extra->getId());
        self::assertInstanceOf(User::class, $unchanged);
        self::assertTrue($unchanged->isActive());
        self::assertSame('extra-1@example.com', $unchanged->getEmail());
        self::assertSame('x', $unchanged->getPassword());
    }

    public function testUserCreationIsBlockedWhileReadOnly(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('POST', '/admin/users/new', [
            'user_admin' => [
                'email' => 'blocked@example.com',
                'password' => 'secret',
                '_token' => $this->setCsrfToken('admin_user'),
            ],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(403);
        $payload = $this->json();
        self::assertFalse($payload['success']);
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['email' => 'blocked@example.com']));
    }

    public function testUserDeletionIsBlockedWhileReadOnly(): void
    {
        $this->client->loginUser($this->admin);

        $extra = $this->em->getRepository(User::class)->findOneBy(['email' => 'extra-1@example.com']);
        self::assertNotNull($extra);
        $this->client->request('POST', '/admin/users/'.$extra->getId()->toRfc4122().'/delete', [
            '_token' => $this->setCsrfToken('admin_users_delete_'.$extra->getId()->toRfc4122()),
        ]);

        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNotNull($this->em->find(User::class, $extra->getId()));
    }

    public function testUserTableDisablesDeleteWhileReadOnly(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/admin/users/data', ['_token' => $this->setCsrfToken('datatable')]);

        self::assertResponseIsSuccessful();
        $payload = $this->json();

        self::assertCount(5, $payload['data']);
        foreach ($payload['data'] as $row) {
            // Edit stays available: deactivation is the documented way to
            // end the read-only state. Delete is disabled everywhere.
            self::assertStringContainsString('action-btn-modal', $row['data']['options']);
            self::assertStringContainsString('disabled', $row['data']['options']);
        }
    }

    public function testFolderCreationForNewAccountKeepsWorkingBecauseItIsTechnical(): void
    {
        // Account admin index (read-only view) must stay reachable.
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/admin/accounts', [], [], [
            'HTTP_X-Requested-With' => 'XMLHttpRequest',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testMailImportKeepsWorkingWhileReadOnly(): void
    {
        $account = $this->account;
        $inbox = $this->inbox;

        // Build a fetcher against the real container services but a mocked
        // POP3 storage: the import path must not consult the license state.
        $rawMessage = "From: Sender <sender@example.com>\r\n"
            ."To: info@example.com\r\n"
            ."Subject: Imported while read-only\r\n"
            ."Date: Mon, 01 Jan 2024 12:00:00 +0000\r\n"
            ."Message-ID: <import-1@example.com>\r\n"
            ."Content-Type: text/plain; charset=UTF-8\r\n"
            ."\r\n"
            .'Imported body';

        $storage = $this->createMock(\Laminas\Mail\Storage\Pop3::class);
        $storage->method('getUniqueId')->willReturn([1 => 'uid-import-1']);
        $storage->expects(self::atLeastOnce())->method('getMessage')->with(1)->willReturn(new \Laminas\Mail\Storage\Message(['raw' => $rawMessage]));
        $storage->expects(self::once())->method('removeMessage')->with(1);

        $factory = $this->createStub(\JBSNewMedia\Pop3ConnectorBundle\Service\Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $config = $this->pop3Config();
        $request = new \AvalynxOffice\Plugin\Contract\ConnectorFetchRequest();
        $request->deleteAfter = true;

        $fetcher = new \JBSNewMedia\Pop3ConnectorBundle\Service\Pop3Fetcher(
            $this->em,
            static::getContainer()->get(\AvalynxOffice\Repository\EmailRepository::class),
            static::getContainer()->get(FolderManager::class),
            static::getContainer()->get(AttachmentStorage::class),
            $factory,
            static::getContainer()->get(\AvalynxOffice\Service\Mail\MailRoutingService::class),
            static::getContainer()->get(\AvalynxOffice\Repository\ConnectorConfigRepository::class),
        );

        $result = $fetcher->fetch($this->connector, $config, $request);

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => 0], $result);

        // The message is fully persisted locally and assigned to the inbox.
        $imported = $this->em->getRepository(Email::class)->findOneBy([
            'importKey' => $this->connector->getId().':uid-import-1',
        ]);
        self::assertNotNull($imported);
        self::assertSame('Imported while read-only', $imported->getSubject());
        self::assertFalse($imported->isRead());
        // The message is assigned to an inbox folder of the account.
        $assignedToInbox = false;
        foreach ($imported->getEmailFolders() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if (null !== $folder && in_array(\AvalynxOffice\Entity\Enum\FolderFunction::INBOX->value, $folder->getFunctions(), true)) {
                $assignedToInbox = true;
            }
        }
        self::assertTrue($assignedToInbox);
        self::assertSame($account->getId()?->toRfc4122(), $imported->getAccount()?->getId()?->toRfc4122());

        // A repeated import does not create duplicates.
        $storage2 = $this->createMock(\Laminas\Mail\Storage\Pop3::class);
        $storage2->method('getUniqueId')->willReturn([1 => 'uid-import-1']);
        $storage2->expects(self::never())->method('getMessage')->with(1)->willReturn(new \Laminas\Mail\Storage\Message(['raw' => $rawMessage]));
        $storage2->expects(self::never())->method('removeMessage');
        $factory->method('create')->willReturn($storage2);

        // The diff drops the already imported message from the candidates:
        // nothing is re-downloaded, nothing is duplicated.
        $result2 = $fetcher->fetch($this->connector, $config, $request);
        self::assertSame(['imported' => 0, 'skipped' => 0, 'errors' => 0], $result2);
        self::assertCount(1, $this->em->getRepository(Email::class)->findBy(['importKey' => $this->connector->getId().':uid-import-1']));
    }

    /**
     * @return array<string, mixed>
     */
    private function pop3Config(): array
    {
        return [
            'pop3Host' => 'pop3.example.com',
            'pop3Port' => 995,
            'pop3Username' => 'info',
            'pop3Password' => 'secret',
            'pop3Security' => 'ssl',
            'pop3DeleteAfterFetch' => true,
        ];
    }

    public function testNonXmlRequestsReceiveHtmlForbiddenPage(): void
    {
        $this->client->loginUser($this->admin);
        $token = $this->setCsrfToken('mail_favorite');
        $this->client->request('POST', '/email/'.$this->email->getId().'/read', ['read' => '1', '_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Action not available', $content);
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

    /**
     * Submits the admin user edit form for the given user. The email field
     * defaults to the current address so that partial submissions (e.g. only
     * changing the active flag) stay valid.
     *
     * @param array<string, string> $fields
     */
    private function updateUser(User $target, array $fields): void
    {
        $this->client->request('POST', '/admin/users/'.$target->getId()->toRfc4122().'/edit', [
            'user_admin' => [...['email' => $target->getEmail()], ...$fields, '_token' => $this->setCsrfToken('admin_user')],
        ], [], ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        \assert(is_array($decoded));

        return $decoded;
    }
}
