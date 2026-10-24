<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Repository;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Folder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Folder>
 */
class FolderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Folder::class);
    }

    /**
     * @return array<Folder>
     */
    public function findFoldersLinkedToAnyAccount(): array
    {
        /** @var array<Folder> $result */
        $result = $this->createQueryBuilder('f')
            ->innerJoin('f.accountLinks', 'fm')
            ->addSelect('fm')
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @param array<Account> $accounts
     *
     * @return array<Folder>
     */
    public function findFoldersLinkedToAccounts(array $accounts): array
    {
        if ([] === $accounts) {
            return [];
        }

        /** @var array<Folder> $result */
        $result = $this->createQueryBuilder('f')
            ->innerJoin('f.accountLinks', 'fm')
            ->where('fm.account IN (:accounts)')
            ->setParameter('accounts', $accounts)
            ->addSelect('fm')
            ->orderBy('f.name', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * @return array<Folder>
     */
    public function findByAccountOrderByAssignment(Account $account): array
    {
        $items = [];
        foreach ($account->getFolderLinks() as $link) {
            $folder = $link->getFolder();
            if (!$folder) {
                continue;
            }
            $items[] = [
                'folder' => $folder,
                'sort' => method_exists($link, 'getSortOrder') ? $link->getSortOrder() : 0,
                'name' => $folder->getName(),
            ];
        }
        usort($items, static function (array $a, array $b): int {
            $cmp = $a['sort'] <=> $b['sort'];
            if (0 !== $cmp) {
                return $cmp;
            }

            return $a['name'] <=> $b['name'];
        });
        $folders = [];
        $seen = [];
        foreach ($items as $it) {
            $id = (string) $it['folder']->getId();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $folders[] = $it['folder'];
        }

        return $folders;
    }

    public function findFirstFolderForAccount(Account $account): ?Folder
    {
        $first = null;
        $bestKey = null; // tuple [sort, name]
        foreach ($account->getFolderLinks() as $link) {
            $folder = $link->getFolder();
            if (!$folder) {
                continue;
            }
            $key = [method_exists($link, 'getSortOrder') ? $link->getSortOrder() : 0, $folder->getName()];
            if (null === $bestKey || ($key[0] <=> $bestKey[0]) < 0 || (($key[0] === $bestKey[0]) && ($key[1] <=> $bestKey[1]) < 0)) {
                $bestKey = $key;
                $first = $folder;
            }
        }

        return $first;
    }
}
