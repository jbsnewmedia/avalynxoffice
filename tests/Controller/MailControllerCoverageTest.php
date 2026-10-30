<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Attachment;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailAttachmentLink;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
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
    private Account $account;
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
        [$this->adminUser, $this->account, $this->folder, $this->email, $this->attachment] = $this->seedData();
    }

    public function testIndexFolderEmailFavoriteAndMoreRoutesWorkForAuthorizedUser(): void
    {
        $this->loginAdmin();

        $accountId = (string) $this->account->getId();
        $folderId = (string) $this->folder->getId();
        $emailId = (string) $this->email->getId();

        $this->client->request('GET', '/mails');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/mail/compose"]');

        $crawler = $this->client->request('GET', sprintf('/mails/%s/%s/%s', $accountId, $folderId, $emailId));
        self::assertResponseIsSuccessful();

        // The favorite toggle sends the CSRF token rendered on the mail list.
        $favoriteToken = $crawler->filter('.email-list-container')->attr('data-mail-token');
        self::assertNotEmpty($favoriteToken);

        $this->client->request('POST', sprintf('/email/%s/favorite', $emailId), ['favorite' => '1', '_token' => $favoriteToken]);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"success":true,"favorite":true}', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', sprintf('/email/%s/favorite', $emailId), ['favorite' => '0', '_token' => $favoriteToken]);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"success":true,"favorite":false}', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/folder/%s/more?accountId=%s&offset=0&limit=1', $folderId, $accountId));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/folder/%s/more?accountId=all&offset=0&limit=1', $folderId));
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

        $this->client->request('POST', sprintf('/email/%s/favorite', $this->email->getId()), ['favorite' => '1', '_token' => $this->setCsrfToken('mail_favorite')]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testFavoriteRejectsMissingOrInvalidCsrfToken(): void
    {
        $this->loginAdmin();
        $emailId = (string) $this->email->getId();

        $this->client->request('POST', sprintf('/email/%s/favorite', $emailId), ['favorite' => '1']);
        self::assertResponseStatusCodeSame(403);
        self::assertStringContainsString('"success":false', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', sprintf('/email/%s/favorite', $emailId), ['favorite' => '1', '_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Email::class, $emailId);
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isFavorite());

        // The token may alternatively be sent as X-CSRF-Token header.
        $this->client->request('POST', sprintf('/email/%s/favorite', $emailId), ['favorite' => '1'], [], ['HTTP_X_CSRF_TOKEN' => $this->setCsrfToken('mail_favorite')]);
        self::assertResponseIsSuccessful();
        self::assertJsonStringEqualsJsonString('{"success":true,"favorite":true}', (string) $this->client->getResponse()->getContent());
    }

    public function testGuestUserIsDeniedAccessToForeignAccountFolder(): void
    {
        $this->loginGuestUser();

        $this->client->request('GET', sprintf('/mails/%s/%s', $this->account->getId(), $this->folder->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testMailAttachmentDownloadStillWorksWithLoadedFixtures(): void
    {
        $this->loginAdmin();

        $this->client->request('GET', sprintf('/email/%s/attachment/%s', $this->email->getId(), $this->attachment->getId()));
        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testIndexAndFolderRenderEmptyStateWithoutAccounts(): void
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

    public function testIndexRendersAllMailsView(): void
    {
        $this->loginAdmin();
        $this->entityManager->clear();

        $this->client->request('GET', '/mails');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a[href="/mails/all/all"]');
        self::assertSelectorExists(sprintf('.email-item[data-email="%s"]', $this->email->getId()));
        self::assertSelectorTextContains('a[href="/mails/all/all"] .badge', '1');
    }

    public function testAllMailsViewsAndPaginationSupportAllScope(): void
    {
        $this->loginAdmin();
        $this->entityManager->clear();
        $accountId = (string) $this->account->getId();
        $emailId = (string) $this->email->getId();

        $this->client->request('GET', '/mails/all/all');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('.email-item[data-email="%s"]', $emailId));
        self::assertSelectorTextContains('a[href="/mails/all/all"] .badge', '1');

        // An unknown folder id is neither "all" nor a resolvable folder: the
        // listing renders with a zero total.
        $this->client->request('GET', '/mails/all/00000000-0000-7000-8000-000000000000');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/mails/%s/all', $accountId));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/mails/all/all/%s', $emailId));
        self::assertResponseIsSuccessful();
        self::assertSelectorExists(sprintf('.email-item.email-active[data-email="%s"]', $emailId));

        $this->client->request('GET', '/folder/all/more?accountId=all&offset=0&limit=10');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/folder/all/more?accountId=%s&offset=0&limit=10', $accountId));
        self::assertResponseIsSuccessful();
    }

    public function testAllMailsMoreDeniesForeignAccountScope(): void
    {
        $this->loginGuestUser();

        $this->client->request('GET', sprintf('/folder/all/more?accountId=%s&offset=0&limit=10', $this->account->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    public function testFolderResetsActiveFolderWhenNotLinkedToAccount(): void
    {
        $this->loginAdmin();

        $unlinkedFolder = (new Folder())
            ->setName('Loose Folder')
            ->setFunctions(['inbox']);
        $this->entityManager->persist($unlinkedFolder);
        $this->entityManager->flush();

        $this->client->request('GET', sprintf('/mails/%s/%s', $this->account->getId(), $unlinkedFolder->getId()));
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
        $accountId = (string) $this->account->getId();
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

        $this->client->request('POST', sprintf('/email/%s/favorite', $missingUuid), ['favorite' => '1', '_token' => $this->setCsrfToken('mail_favorite')]);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString('"success":false', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', sprintf('/folder/%s/more?accountId=%s&limit=0', $folderId, $accountId));
        self::assertResponseIsSuccessful();

        $this->client->request('GET', sprintf('/folder/%s/more?accountId=%s&offset=-1', $folderId, $accountId));
        self::assertResponseIsSuccessful();
    }

    public function testGuestIsDeniedAccessToEmailWithoutAccount(): void
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
     * @return array{0: User, 1: Account, 2: Folder, 3: Email, 4: Attachment}
     */
    private function seedData(): array
    {
        $admin = (new User())
            ->setEmail('admin@example.com')
            ->setPassword('secret')
            ->setRoles(['ROLE_ADMIN']);

        $account = (new Account())
            ->setName('Support')
            ->setEmail('support@example.com');

        $folder = (new Folder())
            ->setName('Posteingang')
            ->setFunctions(['inbox']);
        $account->addFolder($folder);

        $email = (new Email())
            ->setAccount($account)
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

        $admin->addAccount($account);

        $this->entityManager->persist($admin);
        $this->entityManager->persist($account);
        $this->entityManager->persist($folder);
        $this->entityManager->persist($email);
        $this->entityManager->persist($emailFolder);
        $this->entityManager->persist($attachment);
        $this->entityManager->persist($attachmentLink);
        $this->entityManager->flush();

        return [$admin, $account, $folder, $email, $attachment];
    }
}
