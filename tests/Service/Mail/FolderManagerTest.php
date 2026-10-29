<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Service\Mail\FolderManager;
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

        $this->assertSame(['mailbox', 'inbox', 'outbox', 'scheduled', 'drafts'], FolderManager::requiredSystemFunctions());
        $this->assertSame(FolderManager::FOLDER_OUTBOX, FolderManager::defaultFolderName('sent'));
        $this->assertSame(-80, FolderManager::defaultSortOrder('sent'));
        $this->assertTrue(FolderManager::isSystemFunction('drafts'));
        $this->assertTrue(FolderManager::isSystemFolder($folder));
        $this->assertSame(['mailbox', 'outbox'], FolderManager::normalizeFunctions(['mailbox', 'none', 'sent', 'invalid']));
    }

    public function testEnsureFolderCreatesAccountFolderAndSetsInboxFolder(): void
    {
        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByAccountOrderByAssignment')->willReturn([]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);
        $account = new Account();

        $folder = $folderManager->ensureFolder($account, FolderManager::FOLDER_MAILBOX, -100, ['mailbox']);

        $this->assertSame(FolderManager::FOLDER_MAILBOX, $folder->getName());
        $this->assertSame(['mailbox'], $folder->getFunctions());
        $this->assertSame($folder, $account->getInboxFolder());
        $this->assertCount(1, $account->getFolderLinks());
        $this->assertSame(-100, $account->getFolderLinks()->first()->getSortOrder());
    }

    public function testEnsureSystemFolderUpdatesExistingFolderAndLink(): void
    {
        $account = new Account();
        $folder = (new Folder())->setName(FolderManager::FOLDER_MAILBOX)->setFunctions([]);
        $account->addFolder($folder, 5);

        $folderRepo = $this->createMock(FolderRepository::class);
        $folderRepo->expects($this->atLeastOnce())->method('findByAccountOrderByAssignment')->with($account)->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureSystemFolder($account, 'mailbox');

        $this->assertSame($folder, $result);
        $this->assertTrue($folder->hasFunction('mailbox'));
        $this->assertSame($folder, $account->getInboxFolder());
        $this->assertSame(-100, $account->getFolderLinks()->first()->getSortOrder());
        $this->assertSame($folder, $folderManager->getFolderByFunction($account, 'mailbox'));
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

    public function testSyncEmailSystemFoldersMovesIncomingMailToAccountAndInbox(): void
    {
        $account = new Account();
        $email = (new Email())
            ->setAccount($account)
            ->setReceivedAt(new \DateTimeImmutable());
        $draftsFolder = (new Folder())->setName(FolderManager::FOLDER_DRAFTS)->setFunctions(['drafts']);
        $accountFolder = (new Folder())->setName(FolderManager::FOLDER_MAILBOX)->setFunctions(['mailbox']);
        $inboxFolder = (new Folder())->setName(FolderManager::FOLDER_INBOX)->setFunctions(['inbox']);
        $account->addFolder($draftsFolder, -70);
        $account->addFolder($accountFolder, -100);
        $account->addFolder($inboxFolder, -90);
        $this->folderManager->assignEmailToFolder($email, $draftsFolder);

        $folderRepo = $this->createMock(FolderRepository::class);
        $folderRepo->expects($this->atLeastOnce())->method('findByAccountOrderByAssignment')->with($account)->willReturn([$accountFolder, $inboxFolder, $draftsFolder]);
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
        $account = new Account();
        $folder = (new Folder())->setName('Altname')->setFunctions(['mailbox']);
        $account->addFolder($folder, 5);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByAccountOrderByAssignment')->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureFolder($account, FolderManager::FOLDER_MAILBOX, -100, ['mailbox', 'inbox']);

        $this->assertSame($folder, $result);
        $this->assertSame(FolderManager::FOLDER_MAILBOX, $folder->getName());
        $this->assertSame(['mailbox', 'inbox'], $folder->getFunctions());
        $this->assertSame($folder, $account->getInboxFolder());
        $this->assertSame(-100, $account->getFolderLinks()->first()->getSortOrder());
    }

    public function testEnsureFolderReturnsMatchingFolderWithoutChangesWhenAlreadyConsistent(): void
    {
        $account = new Account();
        $folder = (new Folder())->setName('Posteingang Extra')->setFunctions(['inbox']);
        $account->addFolder($folder, -90);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByAccountOrderByAssignment')->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureFolder($account, 'Posteingang Extra', -90, ['inbox']);

        $this->assertSame($folder, $result);
        $this->assertSame(['inbox'], $folder->getFunctions());
        $this->assertNull($account->getInboxFolder());
    }

    public function testSyncEmailSystemFoldersMovesSentOutgoingMailToOutbox(): void
    {
        $account = new Account();
        $email = (new Email())
            ->setAccount($account)
            ->setIsOutgoing(true)
            ->setIsSent(true);
        $inboxFolder = (new Folder())->setName(FolderManager::FOLDER_INBOX)->setFunctions(['inbox']);
        $outboxFolder = (new Folder())->setName(FolderManager::FOLDER_OUTBOX)->setFunctions(['outbox']);
        $account->addFolder($inboxFolder, -90);
        $account->addFolder($outboxFolder, -80);
        $this->folderManager->assignEmailToFolder($email, $inboxFolder);

        $folderRepo = $this->createMock(FolderRepository::class);
        $folderRepo->expects($this->atLeastOnce())->method('findByAccountOrderByAssignment')->with($account)->willReturn([$inboxFolder, $outboxFolder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->syncEmailSystemFolders($email);

        $assignedNames = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $assignedNames[] = $emailFolder->getFolder()?->getName();
        }

        $this->assertSame([FolderManager::FOLDER_MAILBOX, FolderManager::FOLDER_OUTBOX], $assignedNames);
    }

    public function testSyncEmailSystemFoldersMovesUnsentOutgoingMailToScheduled(): void
    {
        $account = new Account();
        $email = (new Email())
            ->setAccount($account)
            ->setIsOutgoing(true)
            ->setIsSent(false)
            ->setIsDraft(false);
        $outboxFolder = (new Folder())->setName(FolderManager::FOLDER_OUTBOX)->setFunctions(['outbox']);
        $account->addFolder($outboxFolder, -80);
        $this->folderManager->assignEmailToFolder($email, $outboxFolder);

        $folderRepo = $this->createMock(FolderRepository::class);
        $folderRepo->expects($this->atLeastOnce())->method('findByAccountOrderByAssignment')->with($account)->willReturn([$outboxFolder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->syncEmailSystemFolders($email);

        $assignedNames = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $assignedNames[] = $emailFolder->getFolder()?->getName();
        }

        $this->assertSame([FolderManager::FOLDER_MAILBOX, FolderManager::FOLDER_SCHEDULED], $assignedNames);
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

    public function testSyncEmailSystemFoldersReturnsWhenEmailHasNoAccount(): void
    {
        $email = new Email();

        $this->folderManager->syncEmailSystemFolders($email);

        $this->assertCount(0, $email->getEmailFolders());
    }

    public function testSyncEmailSystemFoldersKeepsNonSystemFolderWhileAssigningTargetFolder(): void
    {
        $account = new Account();
        $email = (new Email())
            ->setAccount($account)
            ->setIsOutgoing(true)
            ->setIsSent(true);
        $customFolder = (new Folder())->setName('Custom')->setFunctions([]);
        $outboxFolder = (new Folder())->setName(FolderManager::FOLDER_OUTBOX)->setFunctions(['outbox']);
        $account->addFolder($outboxFolder, -80);
        $this->folderManager->assignEmailToFolder($email, $customFolder);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByAccountOrderByAssignment')->willReturn([$outboxFolder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->syncEmailSystemFolders($email);

        $assignedNames = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $assignedNames[] = $emailFolder->getFolder()?->getName();
        }

        $this->assertSame(['Custom', FolderManager::FOLDER_MAILBOX, FolderManager::FOLDER_OUTBOX], $assignedNames);
    }

    public function testSyncEmailSystemFoldersKeepsExistingDraftSystemFolderForDraftEmail(): void
    {
        $account = new Account();
        $email = (new Email())
            ->setAccount($account)
            ->setIsDraft(true);
        $draftsFolder = (new Folder())->setName(FolderManager::FOLDER_DRAFTS)->setFunctions(['drafts']);
        $account->addFolder($draftsFolder, -70);
        $this->folderManager->assignEmailToFolder($email, $draftsFolder);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByAccountOrderByAssignment')->willReturn([$draftsFolder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->syncEmailSystemFolders($email);

        $assignedNames = [];
        foreach ($email->getEmailFolders() as $emailFolder) {
            $assignedNames[] = $emailFolder->getFolder()?->getName();
        }

        $this->assertSame([FolderManager::FOLDER_DRAFTS, FolderManager::FOLDER_MAILBOX], $assignedNames);
    }

    public function testEnsureSystemFolderReturnsExistingUnlinkedFolderWithoutCreatingAccountLink(): void
    {
        $account = new Account();
        $folder = (new Folder())->setName(FolderManager::FOLDER_INBOX)->setFunctions(['inbox']);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByAccountOrderByAssignment')->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureSystemFolder($account, 'inbox');

        $this->assertSame($folder, $result);
        $this->assertCount(0, $account->getFolderLinks());
    }

    public function testEnsureSystemFolderUpdatesExistingLinkedSystemFolderSortOrder(): void
    {
        $account = new Account();
        $folder = (new Folder())->setName(FolderManager::FOLDER_INBOX)->setFunctions(['inbox']);
        $account->addFolder($folder, 10);

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByAccountOrderByAssignment')->willReturn([$folder]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $result = $folderManager->ensureSystemFolder($account, 'inbox');

        $this->assertSame($folder, $result);
        $this->assertSame(-90, $account->getFolderLinks()->first()->getSortOrder());
    }

    public function testEnsureDefaultFoldersCreatesAllRequiredSystemFolders(): void
    {
        $account = new Account();

        $folderRepo = $this->createStub(FolderRepository::class);
        $folderRepo->method('findByAccountOrderByAssignment')->willReturn([]);
        $folderManager = new FolderManager($this->createStub(EntityManagerInterface::class), $folderRepo);

        $folderManager->ensureDefaultFolders($account);

        $this->assertCount(5, $account->getFolderLinks());
        $this->assertSame(FolderManager::FOLDER_MAILBOX, $account->getInboxFolder()?->getName());
    }
}
