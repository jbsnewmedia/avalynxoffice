<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\CalendarEvent;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAddress;
use AvalynxOffice\Entity\Connector;
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
        $this->connector->setPop3Host('pop3.example.com');
        $this->connector->setPop3Username('info');
        $this->connector->setPop3Password('secret');
        $this->connector->setSmtpHost('smtp.example.com');
        $this->em->persist($this->connector);

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
        $this->client->request('POST', '/email/'.$this->email->getId().'/read', ['read' => '1'], [], [
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
        $this->client->request('POST', '/email/'.$this->email->getId().'/favorite', ['favorite' => '1'], [], [
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
        $this->client->request('GET', '/mail/reply/'.$this->email->getId(), [], [], [
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
            'license_keys' => "not-a-valid-key",
        ]);

        self::assertResponseRedirects('/admin/license');
        self::assertCount(1, $this->em->getRepository(\AvalynxOffice\Licensing\Entity\StoredLicenseKey::class)->findAll());
    }

    public function testUserDeactivationStaysPossibleWhileReadOnlyAndEndsReadOnly(): void
    {
        $this->client->loginUser($this->admin);

        $extra = $this->em->getRepository(User::class)->findOneBy(['email' => 'extra-1@example.com']);
        self::assertNotNull($extra);
        $token = $this->setCsrfToken('toggle_user_active_'.$extra->getId());
        $this->client->request('POST', '/admin/users/'.$extra->getId().'/active', ['_token' => $token]);
        self::assertResponseRedirects('/admin/users');

        // Reactivating the account is blocked while the installation is
        // read-only.
        $reactivateToken = $this->setCsrfToken('toggle_user_active_'.$extra->getId());
        $this->client->request('POST', '/admin/users/'.$extra->getId().'/active', ['_token' => $reactivateToken]);
        self::assertResponseStatusCodeSame(403);

        $this->em->clear();
        $stillInactive = $this->em->find(User::class, $extra->getId());
        self::assertFalse($stillInactive->isActive());

        // Still 4 active accounts: read-only persists.
        $manager = static::getContainer()->get(LicenseManager::class);
        $manager->reset();
        self::assertTrue($manager->isReadOnly());

        foreach (['extra-2@example.com', 'extra-3@example.com'] as $emailAddress) {
            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $emailAddress]);
            self::assertNotNull($user);
            $token = $this->setCsrfToken('toggle_user_active_'.$user->getId());
            $this->client->request('POST', '/admin/users/'.$user->getId().'/active', ['_token' => $token]);
            self::assertResponseRedirects('/admin/users');
        }

        $manager->reset();
        self::assertFalse($manager->isReadOnly());
        self::assertSame(\AvalynxOffice\Licensing\Model\LicenseStatus::COMMUNITY, $manager->getStatus());

        // Reactivation is allowed again after the installation is compliant.
        $reactivate = $this->em->getRepository(User::class)->findOneBy(['email' => 'extra-1@example.com']);
        $token = $this->setCsrfToken('toggle_user_active_'.$reactivate->getId());
        $this->client->request('POST', '/admin/users/'.$reactivate->getId().'/active', ['_token' => $token]);
        self::assertResponseRedirects('/admin/users');
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
            ."Imported body";

        $storage = $this->createMock(\Laminas\Mail\Storage\Pop3::class);
        $storage->method('countMessages')->willReturn(1);
        $storage->method('getUniqueId')->with(1)->willReturn('uid-import-1');
        $storage->method('getMessage')->with(1)->willReturn(new \Laminas\Mail\Storage\Message(['raw' => $rawMessage]));
        $storage->expects(self::once())->method('removeMessage')->with(1);

        $factory = $this->createStub(\AvalynxOffice\Service\Mail\Pop3StorageFactory::class);
        $factory->method('create')->willReturn($storage);

        $connector = $this->connector;
        $connector->setPop3DeleteAfterFetch(true);

        $fetcher = new \AvalynxOffice\Service\Mail\Pop3Fetcher(
            $this->em,
            static::getContainer()->get(\AvalynxOffice\Repository\EmailRepository::class),
            static::getContainer()->get(FolderManager::class),
            static::getContainer()->get(AttachmentStorage::class),
            $factory,
            static::getContainer()->get(\AvalynxOffice\Service\Mail\MailRoutingService::class),
        );

        $result = $fetcher->fetchForConnector($connector);

        self::assertSame(['imported' => 1, 'skipped' => 0, 'errors' => 0], $result);

        // The message is fully persisted locally and assigned to the inbox.
        $imported = $this->em->getRepository(Email::class)->findOneBy([
            'importKey' => $connector->getId().':uid-import-1',
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
        $storage2->method('countMessages')->willReturn(1);
        $storage2->method('getUniqueId')->with(1)->willReturn('uid-import-1');
        $storage2->method('getMessage')->with(1)->willReturn(new \Laminas\Mail\Storage\Message(['raw' => $rawMessage]));
        $storage2->expects(self::never())->method('removeMessage');
        $factory->method('create')->willReturn($storage2);

        $result2 = $fetcher->fetchForConnector($connector);
        self::assertSame(['imported' => 0, 'skipped' => 1, 'errors' => 0], $result2);
        self::assertCount(1, $this->em->getRepository(Email::class)->findBy(['importKey' => $connector->getId().':uid-import-1']));
    }

    public function testNonXmlRequestsReceiveHtmlForbiddenPage(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('POST', '/email/'.$this->email->getId().'/read', ['read' => '1']);

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
}
