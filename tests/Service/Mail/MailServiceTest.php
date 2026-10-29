<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Tests\Service\Mail;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Repository\FolderRepository;
use AvalynxOffice\Service\Mail\MailService;
use PHPUnit\Framework\TestCase;

final class MailServiceTest extends TestCase
{
    public function testCountEmailsPerFolderMergesRepositoryCountsAndSkipsInvalidEntries(): void
    {
        $folderA = (new Folder())->setName('A');
        $folderB = (new Folder())->setName('B');
        $passedFolders = null;

        $emailRepo = $this->createMock(EmailRepository::class);
        $emailRepo->expects(self::once())
            ->method('unreadCountsPerFolder')
            ->willReturnCallback(static function (array $folders) use (&$passedFolders, $folderA, $folderB): array {
                $passedFolders = $folders;

                return [
                    (string) $folderB->getId() => 4,
                    (string) $folderA->getId() => 1,
                ];
            });

        $service = new MailService($this->createStub(FolderRepository::class), $emailRepo);
        $counts = $service->countEmailsPerFolder([$folderA, new \stdClass(), $folderB]);

        self::assertSame([$folderA, $folderB], $passedFolders);
        self::assertSame([
            (string) $folderA->getId() => 1,
            (string) $folderB->getId() => 4,
        ], $counts);
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

        $service = new MailService($this->createStub(FolderRepository::class), $this->createStub(EmailRepository::class));
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

        $service = new MailService($this->createStub(FolderRepository::class), $this->createStub(EmailRepository::class));

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

        $service = new MailService($repository, $this->createStub(EmailRepository::class));

        self::assertSame(
            [((string) $accountA->getId()) => $folder],
            $service->getFirstFolderByAccount([$accountA, $accountB]),
        );
    }
}
