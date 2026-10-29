<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace JBSNewMedia\MailActionBundle\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Repository\EmailFolderRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class EmailActionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EmailFolderRepository $emailFolderRepository,
    ) {
    }

    public function markAsRead(Email $email, bool $isRead = true): void
    {
        $email->setIsRead($isRead);
        $this->entityManager->flush();
    }

    public function moveEmail(Email $email, Folder $targetFolder): void
    {
        $emailFolders = $this->emailFolderRepository->findBy(['email' => $email]);
        foreach ($emailFolders as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if ($folder instanceof Folder && $folder->hasFunction(FolderFunction::MAILBOX->value)) {
                // Postfach always contains every mail, so it survives a move.
                continue;
            }

            $this->entityManager->remove($emailFolder);
        }
        $this->entityManager->flush();
        $hasTarget = array_any($this->emailFolderRepository->findBy(['email' => $email]), fn ($emailFolder) => $emailFolder->getFolder()?->getId() === $targetFolder->getId());

        if (!$hasTarget) {
            $newEmailFolder = new EmailFolder();
            $newEmailFolder->setEmail($email);
            $newEmailFolder->setFolder($targetFolder);
            $this->entityManager->persist($newEmailFolder);
        }

        $this->ensureMailboxAssignment($email);
        $this->entityManager->flush();
    }

    /**
     * Postfach contains ALL mails: when a mail ends up without its mailbox
     * assignment after a move, restore it from the account's mailbox folder.
     */
    private function ensureMailboxAssignment(Email $email): void
    {
        foreach ($email->getEmailFolders() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if ($folder instanceof Folder && $folder->hasFunction(FolderFunction::MAILBOX->value)) {
                return;
            }
        }

        $account = $email->getAccount();
        if (!$account instanceof Account) {
            return;
        }

        foreach ($account->getFolderLinks() as $link) {
            $folder = $link->getFolder();
            if ($folder instanceof Folder && $folder->hasFunction(FolderFunction::MAILBOX->value)) {
                $mailboxLink = new EmailFolder();
                $mailboxLink->setEmail($email);
                $mailboxLink->setFolder($folder);
                $this->entityManager->persist($mailboxLink);

                return;
            }
        }
    }
}
