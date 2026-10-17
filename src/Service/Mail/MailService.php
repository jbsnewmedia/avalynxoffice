<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Mail;

use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Repository\FolderRepository;

class MailService
{
    public function __construct(
        private readonly FolderRepository $folderRepo,
    ) {
    }

    /**
     * @param array<Folder> $folders
     *
     * @return array<string, int>
     */
    public function countEmailsPerFolder(array $folders): array
    {
        $map = [];
        foreach ($folders as $folder) {
            if (!$folder instanceof Folder) {
                continue;
            }
            $count = 0;
            foreach ($folder->getEmailFolders() as $ef) {
                $email = $ef->getEmail();
                if ($email && !$email->isRead()) {
                    ++$count;
                }
            }
            $map[(string) $folder->getId()] = $count;
        }

        return $map;
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
     * @param array<Mailbox> $mailboxes
     *
     * @return array<string, Folder>
     */
    public function getFirstFolderByMailbox(array $mailboxes): array
    {
        $firstFolderByMailbox = [];
        foreach ($mailboxes as $mb) {
            $ff = $this->folderRepo->findFirstFolderForMailbox($mb);
            if ($ff) {
                $firstFolderByMailbox[(string) $mb->getId()] = $ff;
            }
        }

        return $firstFolderByMailbox;
    }

    /**
     * @param array<Folder> $folders
     *
     * @return array<Email>
     */
    public function collectEmails(array $folders, bool $deduplicate = true): array
    {
        $emails = [];
        foreach ($folders as $folder) {
            foreach ($folder->getEmailFolders() as $emailFolder) {
                $email = $emailFolder->getEmail();
                if ($email instanceof Email) {
                    $emails[] = $email;
                }
            }
        }

        if ($deduplicate) {
            $emails = array_unique($emails, SORT_REGULAR);
        }

        return array_values($emails);
    }

    /**
     * @param array<Email> $emails
     *
     * @return array<Email>
     */
    public function sortByDateDescending(array $emails): array
    {
        usort($emails, static function (Email $a, Email $b): int {
            $dateB = $b->getSentAt()?->getTimestamp() ?? $b->getReceivedAt()?->getTimestamp() ?? $b->getCreatedAt()?->getTimestamp() ?? 0;
            $dateA = $a->getSentAt()?->getTimestamp() ?? $a->getReceivedAt()?->getTimestamp() ?? $a->getCreatedAt()?->getTimestamp() ?? 0;

            return $dateB <=> $dateA;
        });

        return $emails;
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
