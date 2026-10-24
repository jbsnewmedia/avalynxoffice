<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Repository;

use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderAccount;
use AvalynxOffice\Entity\Account;
use AvalynxOffice\Repository\FolderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

final class FolderRepositoryTest extends TestCase
{
    public function testFindByAccountOrderByAssignmentSortsAndDeduplicatesFolders(): void
    {
        $repository = $this->createRepository();
        $account = new Account();
        $zeta = (new Folder())->setName('Zeta');
        $alpha = (new Folder())->setName('Alpha');
        $account->addFolder($zeta, 10);
        $account->addFolder($alpha, 10);
        $account->addFolder($zeta, 5);

        $folders = $repository->findByAccountOrderByAssignment($account);

        self::assertSame([$zeta, $alpha], $folders);
    }

    public function testFindByAccountOrderByAssignmentSortsEqualPrioritiesByName(): void
    {
        $repository = $this->createRepository();
        $account = new Account();
        $beta = (new Folder())->setName('Beta');
        $alpha = (new Folder())->setName('Alpha');
        $account->addFolder($beta, 10);
        $account->addFolder($alpha, 10);

        self::assertSame([$alpha, $beta], $repository->findByAccountOrderByAssignment($account));
    }

    public function testFindByAccountOrderByAssignmentSkipsDuplicateFolderLinks(): void
    {
        $repository = $this->createRepository();
        $account = new Account();
        $folder = (new Folder())->setName('Alpha');

        $firstLink = (new FolderAccount())
            ->setAccount($account)
            ->setFolder($folder)
            ->setSortOrder(1);
        $secondLink = (new FolderAccount())
            ->setAccount($account)
            ->setFolder($folder)
            ->setSortOrder(2);

        $linksProperty = new \ReflectionProperty(Account::class, 'folderLinks');
        $linksProperty->setValue($account, new ArrayCollection([$firstLink, $secondLink]));

        self::assertSame([$folder], $repository->findByAccountOrderByAssignment($account));
    }

    public function testFindFirstFolderForAccountReturnsBestSortedFolder(): void
    {
        $repository = $this->createRepository();
        $account = new Account();
        $beta = (new Folder())->setName('Beta');
        $alpha = (new Folder())->setName('Alpha');
        $account->addFolder($beta, 2);
        $account->addFolder($alpha, 2);

        self::assertSame($alpha, $repository->findFirstFolderForAccount($account));
        self::assertNull($repository->findFirstFolderForAccount(new Account()));
    }

    public function testFolderRepositoryIgnoresLinksWithoutFolders(): void
    {
        $repository = $this->createRepository();
        $account = new Account();
        $folder = (new Folder())->setName('Alpha');
        $account->addFolder($folder, 1);

        $linksProperty = new \ReflectionProperty(Account::class, 'folderLinks');
        $linksProperty->setValue($account, new ArrayCollection([
            new class extends FolderAccount {
                #[\Override]
                public function getFolder(): ?Folder
                {
                    return null;
                }
            },
            ...$account->getFolderLinks()->toArray(),
        ]));

        self::assertSame([$folder], $repository->findByAccountOrderByAssignment($account));
        self::assertSame($folder, $repository->findFirstFolderForAccount($account));
    }

    public function testFindFoldersLinkedToAnyAccountUsesExpectedQueryBuilderChain(): void
    {
        $expected = [(new Folder())->setName('Inbox')];
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('getResult')->willReturn($expected);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->expects(self::once())->method('innerJoin')->with('f.accountLinks', 'fm')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('addSelect')->with('fm')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('orderBy')->with('f.name', 'ASC')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('getQuery')->willReturn($query);

        $repository = $this->getMockBuilder(FolderRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQueryBuilder'])
            ->getMock();
        $repository->expects(self::once())->method('createQueryBuilder')->with('f')->willReturn($queryBuilder);

        self::assertSame($expected, $repository->findFoldersLinkedToAnyAccount());
    }

    private function createRepository(): FolderRepository
    {
        /** @var FolderRepository $repository */
        $repository = (new \ReflectionClass(FolderRepository::class))->newInstanceWithoutConstructor();

        return $repository;
    }

    public function testFindFoldersLinkedToAccountsReturnsEmptyArrayWithoutAccounts(): void
    {
        self::assertSame([], $this->createRepository()->findFoldersLinkedToAccounts([]));
    }
}
