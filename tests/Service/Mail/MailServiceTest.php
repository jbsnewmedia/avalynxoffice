<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Service\Mail\MailService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\TestCase;

final class MailServiceTest extends TestCase
{
    public function testCountEmailsPerFolderSkipsInvalidEntriesAndCountsOnlyUnreadEmails(): void
    {
        $unreadEmail = (new Email())->setIsRead(false);
        $readEmail = (new Email())->setIsRead(true);
        $folder = $this->createFolderWithEmailFolders('Inbox', [
            (new EmailFolder())->setEmail($unreadEmail),
            (new EmailFolder())->setEmail($readEmail),
            new EmailFolder(),
        ]);

        $service = new MailService($this->createStub(FolderRepository::class));
        $counts = $service->countEmailsPerFolder([$folder, new \stdClass()]);

        self::assertSame([((string) $folder->getId()) => 1], $counts);
    }

    public function testGroupFoldersByNameAndFunctionsMergesCounts(): void
    {
        $inboxA = (new Folder())
            ->setName('Inbox A')
            ->setFunctions(['inbox']);
        $inboxB = (new Folder())
            ->setName('Inbox B')
            ->setFunctions(['inbox']);
        $archive = (new Folder())
            ->setName('Archive');

        $service = new MailService($this->createStub(FolderRepository::class));
        $result = $service->groupFoldersByName(
            [$inboxA, $inboxB, $archive],
            [
                (string) $inboxA->getId() => 2,
                (string) $inboxB->getId() => 3,
                (string) $archive->getId() => 1,
            ],
        );

        self::assertSame([$inboxA, $archive], $result['folders']);
        self::assertSame(5, $result['counts'][(string) $inboxA->getId()]);
        self::assertSame(1, $result['counts'][(string) $archive->getId()]);
    }

    public function testFoldersInSameGroupMatchesByNormalizedGroupKey(): void
    {
        $archiveUpper = (new Folder())
            ->setName('Archive');
        $archiveLower = (new Folder())
            ->setName('archive');
        $drafts = (new Folder())
            ->setName('Drafts')
            ->setFunctions(['drafts']);

        $service = new MailService($this->createStub(FolderRepository::class));

        self::assertSame(
            [$archiveUpper, $archiveLower],
            $service->foldersInSameGroup([$archiveUpper, $archiveLower, $drafts], $archiveUpper),
        );
        self::assertSame([$drafts], $service->foldersInSameGroup([$archiveUpper, $archiveLower, $drafts], $drafts));
    }

    public function testGetFirstFolderByAccountReturnsOnlyResolvedFolders(): void
    {
        $accountA = (new Account())
            ->setName('A')
            ->setEmail('a@example.com');
        $accountB = (new Account())
            ->setName('B')
            ->setEmail('b@example.com');

        $folder = (new Folder())
            ->setName('Inbox');
        $accountA->addFolder($folder, -10);

        $repository = $this->createMock(FolderRepository::class);
        $repository->expects(self::exactly(2))
            ->method('findFirstFolderForAccount')
            ->willReturnCallback(static fn (Account $account): ?Folder => 'a@example.com' === $account->getEmail() ? $folder : null);

        $service = new MailService($repository);

        self::assertSame(
            [((string) $accountA->getId()) => $folder],
            $service->getFirstFolderByAccount([$accountA, $accountB]),
        );
    }

    public function testCollectEmailsGathersNonNullEmailsAndDeduplicates(): void
    {
        $sharedEmail = (new Email())->setSubject('Shared');
        $otherEmail = (new Email())->setSubject('Other');

        $folderA = $this->createFolderWithEmailFolders('A', [
            (new EmailFolder())->setEmail($sharedEmail),
            new EmailFolder(),
            (new EmailFolder())->setEmail($otherEmail),
        ]);
        $folderB = $this->createFolderWithEmailFolders('B', [
            (new EmailFolder())->setEmail($sharedEmail),
        ]);

        $service = new MailService($this->createStub(FolderRepository::class));
        $emails = $service->collectEmails([$folderA, $folderB]);

        self::assertSame([$sharedEmail, $otherEmail], $emails);
    }

    public function testCollectEmailsKeepsDuplicatesWhenDeduplicationDisabled(): void
    {
        $sharedEmail = (new Email())->setSubject('Shared');

        $folderA = $this->createFolderWithEmailFolders('A', [
            (new EmailFolder())->setEmail($sharedEmail),
        ]);
        $folderB = $this->createFolderWithEmailFolders('B', [
            (new EmailFolder())->setEmail($sharedEmail),
        ]);

        $service = new MailService($this->createStub(FolderRepository::class));

        self::assertSame(
            [$sharedEmail, $sharedEmail],
            $service->collectEmails([$folderA, $folderB], false),
        );
    }

    public function testSortByDateDescendingOrdersByBestAvailableDate(): void
    {
        $newest = (new Email())->setSentAt(new \DateTimeImmutable('2024-01-03 00:00:00'));
        $middle = (new Email())->setReceivedAt(new \DateTimeImmutable('2024-01-02 00:00:00'));
        $oldest = (new Email())->setSentAt(new \DateTimeImmutable('2024-01-01 00:00:00'));

        $service = new MailService($this->createStub(FolderRepository::class));

        self::assertSame(
            [$newest, $middle, $oldest],
            $service->sortByDateDescending([$oldest, $newest, $middle]),
        );
    }

    /**
     * @param list<EmailFolder> $emailFolders
     */
    private function createFolderWithEmailFolders(string $name, array $emailFolders): Folder
    {
        return (new class(new ArrayCollection($emailFolders)) extends Folder {
            public function __construct(
                private readonly Collection $testEmailFolders,
            ) {
                parent::__construct();
            }

            #[\Override]
            public function getEmailFolders(): Collection
            {
                return $this->testEmailFolders;
            }
        })->setName($name);
    }
}
