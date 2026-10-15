<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Folder;
use App\Entity\FolderMailbox;
use App\Entity\Mailbox;
use App\Repository\FolderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class FolderRepositoryTest extends TestCase
{
    public function testFindByMailboxOrderByAssignmentSortsAndDeduplicatesFolders(): void
    {
        $repository = $this->createRepository();
        $mailbox = new Mailbox();
        $zeta = (new Folder())->setName('Zeta');
        $alpha = (new Folder())->setName('Alpha');
        $mailbox->addFolder($zeta, 10);
        $mailbox->addFolder($alpha, 10);
        $mailbox->addFolder($zeta, 5);

        $folders = $repository->findByMailboxOrderByAssignment($mailbox);

        self::assertSame([$zeta, $alpha], $folders);
    }

    public function testFindByMailboxOrderByAssignmentSortsEqualPrioritiesByName(): void
    {
        $repository = $this->createRepository();
        $mailbox = new Mailbox();
        $beta = (new Folder())->setName('Beta');
        $alpha = (new Folder())->setName('Alpha');
        $mailbox->addFolder($beta, 10);
        $mailbox->addFolder($alpha, 10);

        self::assertSame([$alpha, $beta], $repository->findByMailboxOrderByAssignment($mailbox));
    }

    public function testFindByMailboxOrderByAssignmentSkipsDuplicateFolderLinks(): void
    {
        $repository = $this->createRepository();
        $mailbox = new Mailbox();
        $folder = (new Folder())->setName('Alpha');

        $firstLink = (new FolderMailbox())
            ->setMailbox($mailbox)
            ->setFolder($folder)
            ->setSortOrder(1);
        $secondLink = (new FolderMailbox())
            ->setMailbox($mailbox)
            ->setFolder($folder)
            ->setSortOrder(2);

        $linksProperty = new \ReflectionProperty(Mailbox::class, 'folderLinks');
        $linksProperty->setValue($mailbox, new ArrayCollection([$firstLink, $secondLink]));

        self::assertSame([$folder], $repository->findByMailboxOrderByAssignment($mailbox));
    }

    public function testFindFirstFolderForMailboxReturnsBestSortedFolder(): void
    {
        $repository = $this->createRepository();
        $mailbox = new Mailbox();
        $beta = (new Folder())->setName('Beta');
        $alpha = (new Folder())->setName('Alpha');
        $mailbox->addFolder($beta, 2);
        $mailbox->addFolder($alpha, 2);

        self::assertSame($alpha, $repository->findFirstFolderForMailbox($mailbox));
        self::assertNull($repository->findFirstFolderForMailbox(new Mailbox()));
    }

    public function testFolderRepositoryIgnoresLinksWithoutFolders(): void
    {
        $repository = $this->createRepository();
        $mailbox = new Mailbox();
        $folder = (new Folder())->setName('Alpha');
        $mailbox->addFolder($folder, 1);

        $linksProperty = new \ReflectionProperty(Mailbox::class, 'folderLinks');
        $linksProperty->setValue($mailbox, new ArrayCollection([
            new class extends FolderMailbox {
                #[\Override]
                public function getFolder(): ?Folder
                {
                    return null;
                }
            },
            ...$mailbox->getFolderLinks()->toArray(),
        ]));

        self::assertSame([$folder], $repository->findByMailboxOrderByAssignment($mailbox));
        self::assertSame($folder, $repository->findFirstFolderForMailbox($mailbox));
    }

    public function testFindFoldersLinkedToAnyMailboxUsesExpectedQueryBuilderChain(): void
    {
        $expected = [(new Folder())->setName('Inbox')];
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('getResult')->willReturn($expected);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects(self::once())->method('innerJoin')->with('f.mailboxLinks', 'fm')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('addSelect')->with('fm')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('orderBy')->with('f.name', 'ASC')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('getQuery')->willReturn($query);

        $repository = $this->getMockBuilder(FolderRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();
        $repository->expects(self::once())->method('createQueryBuilder')->with('f')->willReturn($queryBuilder);

        self::assertSame($expected, $repository->findFoldersLinkedToAnyMailbox());
    }

    private function createRepository(): FolderRepository
    {
        /** @var FolderRepository $repository */
        $repository = (new \ReflectionClass(FolderRepository::class))->newInstanceWithoutConstructor();

        return $repository;
    }

    public function testFindFoldersLinkedToMailboxesReturnsEmptyArrayWithoutMailboxes(): void
    {
        self::assertSame([], $this->createRepository()->findFoldersLinkedToMailboxes([]));
    }
}
