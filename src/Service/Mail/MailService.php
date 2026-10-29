<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Mail;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Repository\EmailRepository;
use AvalynxOffice\Repository\FolderRepository;

class MailService
{
    public function __construct(
        private readonly FolderRepository $folderRepo,
        private readonly EmailRepository $emailRepo,
    ) {
    }

    /**
     * Unread email count per folder. Folders without any unread email get 0.
     * Entries in $folders that are not Folder instances are ignored.
     *
     * @param array<Folder> $folders
     *
     * @return array<string, int> folder id => unread email count
     */
    public function countEmailsPerFolder(array $folders): array
    {
        $map = [];
        foreach ($folders as $folder) {
            if (!$folder instanceof Folder) {
                continue;
            }
            $map[(string) $folder->getId()] = 0;
        }

        foreach ($this->emailRepo->unreadCountsPerFolder($this->filterFolders($folders)) as $folderId => $count) {
            $map[$folderId] = $count;
        }

        return $map;
    }

    /**
     * @param array<Folder> $folders
     *
     * @return array<Folder>
     */
    private function filterFolders(array $folders): array
    {
        return array_values(array_filter($folders, fn (mixed $folder): bool => $folder instanceof Folder));
    }

    /**
     * @param array<Folder>      $folders
     * @param array<string, int> $counts
     *
     * @return array{folders: array<Folder>, counts: array<string, int>}
     */
    public function groupFoldersByName(array $folders, array $counts): array
    {
        $groupedFolders = [];
        $groupedCounts = [];
        foreach ($folders as $f) {
            $groupKey = $this->folderGroupKey($f);
            if (!isset($groupedFolders[$groupKey])) {
                $groupedFolders[$groupKey] = $f;
                $groupedCounts[(string) $f->getId()] = 0;
            }
            $groupedCounts[(string) $groupedFolders[$groupKey]->getId()] += $counts[(string) $f->getId()] ?? 0;
        }

        return [
            'folders' => array_values($groupedFolders),
            'counts' => $groupedCounts,
        ];
    }

    /**
     * @param array<Folder> $folders
     *
     * @return array<Folder>
     */
    public function foldersInSameGroup(array $folders, Folder $reference): array
    {
        $groupKey = $this->folderGroupKey($reference);

        return array_values(array_filter(
            $folders,
            fn (Folder $folder): bool => $this->folderGroupKey($folder) === $groupKey,
        ));
    }

    /**
     * @param array<Account> $accounts
     *
     * @return array<string, Folder>
     */
    public function getFirstFolderByAccount(array $accounts): array
    {
        $firstFolderByAccount = [];
        foreach ($accounts as $mb) {
            $ff = $this->folderRepo->findFirstFolderForAccount($mb);
            if ($ff) {
                $firstFolderByAccount[(string) $mb->getId()] = $ff;
            }
        }

        return $firstFolderByAccount;
    }

    private function folderGroupKey(Folder $folder): string
    {
        $functions = $folder->getFunctions();
        if ([] !== $functions) {
            sort($functions);

            return 'functions:'.implode('|', $functions);
        }

        return 'name:'.mb_strtolower($folder->getName());
    }
}
