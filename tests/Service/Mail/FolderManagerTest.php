<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Email;
use App\Entity\Folder;
use App\Entity\Mailbox;
use App\Repository\FolderRepository;
use App\Service\Mail\FolderManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class FolderManagerTest extends TestCase
{
    private EntityManagerInterface $em;
    private FolderRepository $folderRepo;
    private FolderManager $folderManager;

    protected function setUp(): void
    {
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->folderRepo = $this->createStub(FolderRepository::class);
        $this->folderManager = new FolderManager($this->em, $this->folderRepo);
    }

    public function testAssignEmailToFolder(): void
    {
        $email = new Email();
        $folder = new Folder();

        $this->folderManager->assignEmailToFolder($email, $folder);

        $this->assertCount(1, $email->getEmailFolders());
        $this->assertSame($folder, $email->getEmailFolders()[0]->getFolder());
    }

    public function testAssignEmailToFolderDuplicate(): void
    {
        $email = new Email();
        $folder = new Folder();

        $this->folderManager->assignEmailToFolder($email, $folder);
        $this->folderManager->assignEmailToFolder($email, $folder);

        $this->assertCount(1, $email->getEmailFolders());
    }

    public function testStaticHelpersExposeSystemFolderMetadata(): void
    {
        $folder = (new Folder())->setFunctions(['mailbox']);

        $this->assertSame(['mailbox', 'inbox', 'outbox', 'drafts'], FolderManager::requiredSystemFunctions());
        $this->assertSame(FolderManager::FOLDER_OUTBOX, FolderManager::defaultFolderName('sent'));
        $this->assertSame(-80, FolderManager::defaultSortOrder('sent'));
        $this->assertTrue(FolderManager::isSystemFunction('drafts'));
        $this->assertTrue(FolderManager::isSystemFolder($folder));
        $this->assertSame(['mailbox', 'outbox'], FolderManager::normalizeFunctions(['mailbox', 'none', 'sent', 'invalid']));
    }

    public function testEnsureFolderCreatesMailboxFolderAndSetsInboxFolder(): void
    {
        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByMailboxOrderByAssignment')->willReturn([]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);
        $mailbox = new Mailbox();

        $folder = $folderManager->ensureFolder($mailbox, FolderManager::FOLDER_MAILBOX, -100, ['mailbox']);

        $this->assertSame(FolderManager::FOLDER_MAILBOX, $folder->getName());
        $this->assertSame(['mailbox'], $folder->getFunctions());
        $this->assertSame($folder, $mailbox->getInboxFolder());
        $this->assertCount(1, $mailbox->getFolderLinks());
        $this->assertSame(-100, $mailbox->getFolderLinks()->first()->getSortOrder());
    }

    public function testEnsureSystemFolderUpdatesExistingFolderAndLink(): void
    {
        $mailbox = new Mailbox();
        $folder = (new Folder())->setName(FolderManager::FOLDER_MAILBOX)->setFunctions([]);
        $mailbox->addFolder($folder, 5);

        $folderRepo = $this->createMock(FolderRepository::class);
        $folderRepo->expects($this->atLeastOnce())->method('findByMailboxOrderByAssignment')->with($mailbox)->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureSystemFolder($mailbox, 'mailbox');

        $this->assertSame($folder, $result);
        $this->assertTrue($folder->hasFunction('mailbox'));
        $this->assertSame($folder, $mailbox->getInboxFolder());
        $this->assertSame(-100, $mailbox->getFolderLinks()->first()->getSortOrder());
        $this->assertSame($folder, $folderManager->getFolderByFunction($mailbox, 'mailbox'));
    }

    public function testMoveEmailToFolderReusesExistingFolderLink(): void
    {
        $email = new Email();
        $source = (new Folder())->setName('Source');
        $target = (new Folder())->setName('Target');
        $this->folderManager->assignEmailToFolder($email, $source);

        $this->folderManager->moveEmailToFolder($email, $target, $source);

        $this->assertCount(1, $email->getEmailFolders());
        $this->assertSame($target, $email->getEmailFolders()->first()->getFolder());
    }

    public function testSyncEmailSystemFoldersMovesIncomingMailToMailboxAndInbox(): void
    {
        $mailbox = new Mailbox();
        $email = (new Email())
            ->setMailbox($mailbox)
            ->setReceivedAt(new \DateTimeImmutable());
        $draftsFolder = (new Folder())->setName(FolderManager::FOLDER_DRAFTS)->setFunctions(['drafts']);
        $mailboxFolder = (new Folder())->setName(FolderManager::FOLDER_MAILBOX)->setFunctions(['mailbox']);
        $inboxFolder = (new Folder())->setName(FolderManager::FOLDER_INBOX)->setFunctions(['inbox']);
        $mailbox->addFolder($draftsFolder, -70);
        $mailbox->addFolder($mailboxFolder, -100);
        $mailbox->addFolder($inboxFolder, -90);
        $this->folderManager->assignEmailToFolder($email, $draftsFolder);

        $folderRepo = $this->createMock(FolderRepository::class);
        $folderRepo->expects($this->atLeastOnce())->method('findByMailboxOrderByAssignment')->with($mailbox)->willReturn([$mailboxFolder, $inboxFolder, $draftsFolder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->syncEmailSystemFolders($email);

        $assignedNames = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $assignedNames[] = $emailFolder->getFolder()?->getName();
        }

        $this->assertSame([FolderManager::FOLDER_MAILBOX, FolderManager::FOLDER_INBOX], $assignedNames);
    }

    public function testEnsureFolderUpdatesMatchingFunctionFolderAndLink(): void
    {
        $mailbox = new Mailbox();
        $folder = (new Folder())->setName('Altname')->setFunctions(['mailbox']);
        $mailbox->addFolder($folder, 5);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByMailboxOrderByAssignment')->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureFolder($mailbox, FolderManager::FOLDER_MAILBOX, -100, ['mailbox', 'inbox']);

        $this->assertSame($folder, $result);
        $this->assertSame(FolderManager::FOLDER_MAILBOX, $folder->getName());
        $this->assertSame(['mailbox', 'inbox'], $folder->getFunctions());
        $this->assertSame($folder, $mailbox->getInboxFolder());
        $this->assertSame(-100, $mailbox->getFolderLinks()->first()->getSortOrder());
    }

    public function testEnsureFolderReturnsMatchingFolderWithoutChangesWhenAlreadyConsistent(): void
    {
        $mailbox = new Mailbox();
        $folder = (new Folder())->setName('Posteingang Extra')->setFunctions(['inbox']);
        $mailbox->addFolder($folder, -90);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByMailboxOrderByAssignment')->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureFolder($mailbox, 'Posteingang Extra', -90, ['inbox']);

        $this->assertSame($folder, $result);
        $this->assertSame(['inbox'], $folder->getFunctions());
        $this->assertNull($mailbox->getInboxFolder());
    }

    public function testSyncEmailSystemFoldersMovesSentOutgoingMailToOutbox(): void
    {
        $mailbox = new Mailbox();
        $email = (new Email())
            ->setMailbox($mailbox)
            ->setIsOutgoing(true)
            ->setIsSent(true);
        $inboxFolder = (new Folder())->setName(FolderManager::FOLDER_INBOX)->setFunctions(['inbox']);
        $outboxFolder = (new Folder())->setName(FolderManager::FOLDER_OUTBOX)->setFunctions(['outbox']);
        $mailbox->addFolder($inboxFolder, -90);
        $mailbox->addFolder($outboxFolder, -80);
        $this->folderManager->assignEmailToFolder($email, $inboxFolder);

        $folderRepo = $this->createMock(FolderRepository::class);
        $folderRepo->expects($this->atLeastOnce())->method('findByMailboxOrderByAssignment')->with($mailbox)->willReturn([$inboxFolder, $outboxFolder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->syncEmailSystemFolders($email);

        $assignedNames = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $assignedNames[] = $emailFolder->getFolder()?->getName();
        }

        $this->assertSame([FolderManager::FOLDER_OUTBOX], $assignedNames);
    }

    public function testMoveEmailToFolderAssignsTargetWhenNoSourceProvided(): void
    {
        $email = new Email();
        $existing = (new Folder())->setName('Existing');
        $target = (new Folder())->setName('Target');
        $this->folderManager->assignEmailToFolder($email, $existing);

        $this->folderManager->moveEmailToFolder($email, $target);

        $names = [];
        foreach ($email->getEmailFolders() as $ef) {
            $names[] = $ef->getFolder()?->getName();
        }

        $this->assertSame(['Existing', 'Target'], $names);
    }

    public function testSyncEmailSystemFoldersReturnsWhenEmailHasNoMailbox(): void
    {
        $email = new Email();

        $this->folderManager->syncEmailSystemFolders($email);

        $this->assertCount(0, $email->getEmailFolders());
    }

    public function testSyncEmailSystemFoldersKeepsNonSystemFolderWhileAssigningTargetFolder(): void
    {
        $mailbox = new Mailbox();
        $email = (new Email())
            ->setMailbox($mailbox)
            ->setIsOutgoing(true)
            ->setIsSent(true);
        $customFolder = (new Folder())->setName('Custom')->setFunctions([]);
        $outboxFolder = (new Folder())->setName(FolderManager::FOLDER_OUTBOX)->setFunctions(['outbox']);
        $mailbox->addFolder($outboxFolder, -80);
        $this->folderManager->assignEmailToFolder($email, $customFolder);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByMailboxOrderByAssignment')->willReturn([$outboxFolder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->syncEmailSystemFolders($email);

        $assignedNames = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $assignedNames[] = $emailFolder->getFolder()?->getName();
        }

        $this->assertSame(['Custom', FolderManager::FOLDER_OUTBOX], $assignedNames);
    }

    public function testSyncEmailSystemFoldersKeepsExistingDraftSystemFolderForDraftEmail(): void
    {
        $mailbox = new Mailbox();
        $email = (new Email())
            ->setMailbox($mailbox)
            ->setIsDraft(true);
        $draftsFolder = (new Folder())->setName(FolderManager::FOLDER_DRAFTS)->setFunctions(['drafts']);
        $mailbox->addFolder($draftsFolder, -70);
        $this->folderManager->assignEmailToFolder($email, $draftsFolder);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByMailboxOrderByAssignment')->willReturn([$draftsFolder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->syncEmailSystemFolders($email);

        $this->assertCount(1, $email->getEmailFolders());
        $this->assertSame($draftsFolder, $email->getEmailFolders()->first()->getFolder());
    }

    public function testEnsureSystemFolderReturnsExistingUnlinkedFolderWithoutCreatingMailboxLink(): void
    {
        $mailbox = new Mailbox();
        $folder = (new Folder())->setName(FolderManager::FOLDER_INBOX)->setFunctions(['inbox']);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByMailboxOrderByAssignment')->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureSystemFolder($mailbox, 'inbox');

        $this->assertSame($folder, $result);
        $this->assertCount(0, $mailbox->getFolderLinks());
    }

    public function testEnsureSystemFolderUpdatesExistingLinkedSystemFolderSortOrder(): void
    {
        $mailbox = new Mailbox();
        $folder = (new Folder())->setName(FolderManager::FOLDER_INBOX)->setFunctions(['inbox']);
        $mailbox->addFolder($folder, 10);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByMailboxOrderByAssignment')->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureSystemFolder($mailbox, 'inbox');

        $this->assertSame($folder, $result);
        $this->assertSame(-90, $mailbox->getFolderLinks()->first()->getSortOrder());
    }

    public function testEnsureDefaultFoldersCreatesAllRequiredSystemFolders(): void
    {
        $mailbox = new Mailbox();

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByMailboxOrderByAssignment')->willReturn([]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->ensureDefaultFolders($mailbox);

        $this->assertCount(4, $mailbox->getFolderLinks());
        $this->assertSame(FolderManager::FOLDER_MAILBOX, $mailbox->getInboxFolder()?->getName());
    }
}
