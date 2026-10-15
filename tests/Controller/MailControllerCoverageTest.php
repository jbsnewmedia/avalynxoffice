<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Attachment;
use App\Entity\Email;
use App\Entity\EmailAttachmentLink;
use App\Entity\EmailFolder;
use App\Entity\Folder;
use App\Entity\Mailbox;
use App\Entity\User;
use App\Service\Mail\AttachmentStorage;
use App\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class MailControllerCoverageTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AttachmentStorage $attachmentStorage;
    private User $adminUser;
    private Mailbox $mailbox;
    private Folder $folder;
    private Email $email;
    private Attachment $attachment;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->attachmentStorage = static::getContainer()->get(AttachmentStorage::class);

        $this->refreshDatabase($this->entityManager);
        [$this->adminUser, $this->mailbox, $this->folder, $this->email, $this->attachment] = $this->seedData();
    }

    public function testIndexFolderEmailFavoriteAndMoreRoutesWorkForAuthorizedUser(): void
    {
        $this->loginAdmin();

        $mailboxId = (string) $this->mailbox->getId();
        $folderId = (string) $this->folder->getId();
        $emailId = (string) $this->email->getId();

        $this->client->request('GET', '/mails');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/mail/compose"]');

        $this->client->request('GET', sprintf('/mails/%s/%s/%s', $mailboxId, $folderId, $emailId));
        self::assertResponseIsSuccessful();

        $this->client->request('POST', sprintf('/email/%s/favorite', $emailId), ['favorite' => '1']);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"success":true,"favorite":true}', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', sprintf('/email/%s/favorite', $emailId), ['favorite' => '0']);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"success":true,"favorite":false}', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/folder/%s/more?mailboxId=%s&offset=0&limit=1', $folderId, $mailboxId));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/folder/%s/more?mailboxId=all&offset=0&limit=1', $folderId));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/email/%s', '00000000-0000-0000-0000-000000000000'));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', sprintf('/folder/%s/more', '00000000-0000-0000-0000-000000000000'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousUserIsDeniedAccessToMailDetailsAndFavoriteRoutes(): void
    {
        $this->loginGuestUser();

        $this->client->request('GET', sprintf('/email/%s', $this->email->getId()));
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/email/%s/favorite', $this->email->getId()), ['favorite' => '1']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGuestUserIsDeniedAccessToForeignMailboxFolder(): void
    {
        $this->loginGuestUser();

        $this->client->request('GET', sprintf('/mails/%s/%s', $this->mailbox->getId(), $this->folder->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testMailAttachmentDownloadStillWorksWithLoadedFixtures(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', sprintf('/email/%s/attachment/%s', $this->email->getId(), $this->attachment->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testIndexAndFolderRenderEmptyStateWithoutMailboxes(): void
    {
        $this->refreshDatabase($this->entityManager);

        $admin = (new User())
            ->setEmail('lonely-admin@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_ADMIN']);
        $this->entityManager->persist($admin);
        $this->entityManager->flush();
        $this->client->loginUser($admin);

        $this->client->request('GET', '/mails');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/mails/%s/%s', '00000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000002'));
        self::assertResponseIsSuccessful();
    }

    public function testRootRedirectsToMailIndex(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/mails');
    }

    public function testIndexSelectsFirstFolderWithUnreadEmails(): void
    {
        $this->loginAdmin();
        $this->entityManager->clear();

        $this->client->request('GET', '/mails');
        self::assertResponseIsSuccessful();
    }

    public function testFolderResetsActiveFolderWhenNotLinkedToMailbox(): void
    {
        $this->loginAdmin();

        $unlinkedFolder = (new Folder())
            ->setName('Loose Folder')
            ->setFunctions(['inbox']);
        $this->entityManager->persist($unlinkedFolder);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/mails/%s/%s', $this->mailbox->getId(), $unlinkedFolder->getId()));
        self::assertResponseIsSuccessful();
    }

    public function testEmailDetailDownloadEdgesFavoriteAndFolderMoreGuards(): void
    {
        $brokenAttachment = (new Attachment())
            ->setName('broken.pdf')
            ->setMimeType('application/pdf');
        $brokenAttachment->setSize(1);
        $brokenAttachment->setSha1(sha1('x'));
        $brokenAttachment->setFilePath('missing/does-not-exist.bin');
        $brokenLink = (new EmailAttachmentLink())
            ->setEmail($this->email)
            ->setAttachment($brokenAttachment);
        $this->email->addEmailAttachment($brokenLink);
        $this->entityManager->persist($brokenAttachment);
        $this->entityManager->persist($brokenLink);
        $this->entityManager->flush();

        $emailId = (string) $this->email->getId();
        $folderId = (string) $this->folder->getId();
        $mailboxId = (string) $this->mailbox->getId();
        $brokenAttachmentId = (string) $brokenAttachment->getId();
        $missingUuid = '00000000-0000-0000-0000-000000000000';

        $this->loginAdmin();

        $this->client->request('GET', sprintf('/email/%s', $emailId));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/email/%s/attachment/%s', $missingUuid, $missingUuid));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', sprintf('/email/%s/attachment/%s', $emailId, $missingUuid));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', sprintf('/email/%s/attachment/%s', $emailId, $brokenAttachmentId));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', sprintf('/email/%s/favorite', $missingUuid), ['favorite' => '1']);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('"success":false', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/folder/%s/more?mailboxId=%s&limit=0', $folderId, $mailboxId));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/folder/%s/more?mailboxId=%s&offset=-1', $folderId, $mailboxId));
        self::assertResponseIsSuccessful();
    }

    public function testGuestIsDeniedAccessToEmailWithoutMailbox(): void
    {
        $orphanEmail = (new Email())
            ->setSubject('Orphan')
            ->setPreviewBody('Vorschau')
            ->setReceivedAt(new \DateTimeImmutable('2026-01-02 10:00:00'));
        $this->entityManager->persist($orphanEmail);
        $this->entityManager->flush();

        $this->loginGuestUser();

        $this->client->request('GET', sprintf('/email/%s', $orphanEmail->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->adminUser);
    }

    private function loginGuestUser(): void
    {
        $guest = (new User())
            ->setEmail('guest@example.com')
            ->setPassword('secret');
        $this->entityManager->persist($guest);
        $this->entityManager->flush();

        $this->client->loginUser($guest);
    }

    /**
     * @return array{0: User, 1: Mailbox, 2: Folder, 3: Email, 4: Attachment}
     */
    private function seedData(): array
    {
        $admin = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_ADMIN']);

        $mailbox = (new Mailbox())
            ->setName('Support')
            ->setEmail('support@example.com');

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

        $attachment = (new Attachment())
            ->setName('angebot.pdf')
            ->setMimeType('application/pdf');
        $payload = '%PDF-1.4 test';
        $attachment->setSize(strlen($payload));
        $attachment->setSha1(sha1($payload));
        $attachment->setFilePath($this->attachmentStorage->store($attachment, $payload));

        $attachmentLink = (new EmailAttachmentLink())
            ->setEmail($email)
            ->setAttachment($attachment);
        $email->addEmailAttachment($attachmentLink);

        $admin->addMailbox($mailbox);

        $this->entityManager->persist($admin);
        $this->entityManager->persist($mailbox);
        $this->entityManager->persist($folder);
        $this->entityManager->persist($email);
        $this->entityManager->persist($emailFolder);
        $this->entityManager->persist($attachment);
        $this->entityManager->persist($attachmentLink);
        $this->entityManager->flush();

        return [$admin, $mailbox, $folder, $email, $attachment];
    }
}
