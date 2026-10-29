<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Mail;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\EmailFolder;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderAccount;
use AvalynxOffice\Repository\FolderRepository;
use Doctrine\ORM\EntityManagerInterface;

class FolderManager
{
    public const FOLDER_MAILBOX = 'Postfach';
    public const FOLDER_INBOX = 'Posteingang';
    public const FOLDER_OUTBOX = 'Postausgang';
    public const FOLDER_SCHEDULED = 'Geplant';
    public const FOLDER_DRAFTS = 'Entwürfe';
    public const FOLDER_SPAM = 'Spam';
    public const FOLDER_TRASH = 'Papierkorb';

    /**
     * @return array<string, array{name: string, sortOrder: int}>
     */
    public static function systemFolderDefinitions(): array
    {
        return [
            FolderFunction::MAILBOX->value => ['name' => self::FOLDER_MAILBOX, 'sortOrder' => -100],
            FolderFunction::INBOX->value => ['name' => self::FOLDER_INBOX, 'sortOrder' => -90],
            FolderFunction::OUTBOX->value => ['name' => self::FOLDER_OUTBOX, 'sortOrder' => -80],
            FolderFunction::SCHEDULED->value => ['name' => self::FOLDER_SCHEDULED, 'sortOrder' => -70],
            FolderFunction::DRAFTS->value => ['name' => self::FOLDER_DRAFTS, 'sortOrder' => -60],
            FolderFunction::TRASH->value => ['name' => self::FOLDER_TRASH, 'sortOrder' => -50],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function requiredSystemFunctions(): array
    {
        return array_keys(self::systemFolderDefinitions());
    }

    public static function defaultFolderName(FolderFunction|string $function): string
    {
        $normalizedFunction = self::normalizeFunctionValue($function);

        return self::systemFolderDefinitions()[$normalizedFunction]['name'] ?? self::FOLDER_MAILBOX;
    }

    public static function defaultSortOrder(FolderFunction|string $function): int
    {
        $normalizedFunction = self::normalizeFunctionValue($function);

        return self::systemFolderDefinitions()[$normalizedFunction]['sortOrder'] ?? 0;
    }

    public static function isSystemFunction(FolderFunction|string $function): bool
    {
        return in_array(self::normalizeFunctionValue($function), self::requiredSystemFunctions(), true);
    }

    public static function isSystemFolder(Folder $folder): bool
    {
        return [] !== array_intersect($folder->getFunctions(), self::requiredSystemFunctions());
    }

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FolderRepository $folderRepo,
    ) {
    }

    /**
     * @param array<string|FolderFunction> $functions
     */
    public function ensureFolder(Account $account, string $folderName, int $sortOrder = 0, array $functions = []): Folder
    {
        $normalizedFunctions = self::normalizeFunctions($functions);
        $folders = $this->folderRepo->findByAccountOrderByAssignment($account);

        if ([] !== $normalizedFunctions) {
            foreach ($folders as $folder) {
                if ([] === array_intersect($normalizedFunctions, $folder->getFunctions())) {
                    continue;
                }

                $changed = false;
                if ($folder->getName() !== $folderName && '' !== $folderName) {
                    $folder->setName($folderName);
                    $changed = true;
                }
                if ($folder->getFunctions() !== $normalizedFunctions) {
                    $folder->setFunctions($normalizedFunctions);
                    $changed = true;
                }
                if ($this->syncAccountFolderLink($account, $folder, $sortOrder)) {
                    $changed = true;
                }
                if (in_array(FolderFunction::MAILBOX->value, $normalizedFunctions, true) && $account->getInboxFolder() !== $folder) {
                    $account->setInboxFolder($folder);
                    $this->em->persist($account);
                    $changed = true;
                }
                if ($changed) {
                    $this->em->persist($folder);
                    $this->em->flush();
                }

                return $folder;
            }
        }

        foreach ($folders as $f) {
            if ($f->getName() === $folderName) {
                $changed = false;
                if ([] !== $normalizedFunctions && $f->getFunctions() !== $normalizedFunctions) {
                    $f->setFunctions($normalizedFunctions);
                    $this->em->persist($f);
                    $changed = true;
                }
                if ($this->syncAccountFolderLink($account, $f, $sortOrder)) {
                    $changed = true;
                }
                if (in_array(FolderFunction::MAILBOX->value, $normalizedFunctions, true) && $account->getInboxFolder() !== $f) {
                    $account->setInboxFolder($f);
                    $this->em->persist($account);
                    $changed = true;
                }
                if ($changed) {
                    $this->em->flush();
                }

                return $f;
            }
        }
        $folder = new Folder();
        $folder->setName($folderName);
        $folder->setFunctions($normalizedFunctions);
        $this->em->persist($folder);
        $this->em->flush();
        $link = new FolderAccount();
        $link->setAccount($account);
        $link->setFolder($folder);
        $link->setSortOrder($sortOrder);
        $this->em->persist($link);
        $account->getFolderLinks()->add($link);
        if (in_array(FolderFunction::MAILBOX->value, $normalizedFunctions, true)) {
            $account->setInboxFolder($folder);
            $this->em->persist($account);
        }
        $this->em->flush();

        return $folder;
    }

    public function ensureSystemFolder(Account $account, FolderFunction|string $function): Folder
    {
        $normalizedFunction = self::normalizeFunctionValue($function);
        $existing = $this->getFolderByFunction($account, $normalizedFunction);
        if ($existing instanceof Folder) {
            $changed = false;
            if ($this->syncAccountFolderLink($account, $existing, self::defaultSortOrder($normalizedFunction))) {
                $changed = true;
            }
            if (FolderFunction::MAILBOX->value === $normalizedFunction && $account->getInboxFolder() !== $existing) {
                $account->setInboxFolder($existing);
                $this->em->persist($account);
                $changed = true;
            }
            if ($changed) {
                $this->em->flush();
            }

            return $existing;
        }

        return $this->ensureFolder(
            $account,
            self::defaultFolderName($normalizedFunction),
            self::defaultSortOrder($normalizedFunction),
            [$normalizedFunction],
        );
    }

    public function getFolderByFunction(Account $account, FolderFunction|string $function): ?Folder
    {
        $normalizedFunction = self::normalizeFunctionValue($function);
        foreach ($this->folderRepo->findByAccountOrderByAssignment($account) as $folder) {
            if ($folder->hasFunction($normalizedFunction)) {
                return $folder;
            }
        }

        return null;
    }

    public function assignEmailToFolder(Email $email, Folder $folder): void
    {
        foreach ($email->getEmailFolders() as $ef) {
            if ($ef->getFolder() === $folder) {
                return;
            }
        }
        $ef = new EmailFolder();
        $ef->setEmail($email);
        $ef->setFolder($folder);
        $this->em->persist($ef);
        $email->addEmailFolder($ef);
    }

    public function moveEmailToFolder(Email $email, Folder $targetFolder, ?Folder $sourceFolder = null): void
    {
        $found = false;
        foreach ($email->getEmailFolders() as $ef) {
            if ($sourceFolder && $ef->getFolder() === $sourceFolder) {
                $ef->setFolder($targetFolder);
                $found = true;
            }
        }
        if (!$found) {
            $this->assignEmailToFolder($email, $targetFolder);
        }
    }

    public function syncEmailSystemFolders(Email $email): void
    {
        $account = $email->getAccount();
        if (!$account instanceof Account) {
            return;
        }

        $targetFunctions = $this->resolveTargetFunctions($email);
        foreach ($email->getEmailFolders()->toArray() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if (!$folder instanceof Folder || !self::isSystemFolder($folder)) {
                continue;
            }

            if ([] !== array_intersect($folder->getFunctions(), $targetFunctions)) {
                continue;
            }

            $email->removeEmailFolder($emailFolder);
            $this->em->remove($emailFolder);
        }

        foreach ($targetFunctions as $function) {
            $folder = $this->ensureSystemFolder($account, $function);
            $this->assignEmailToFolder($email, $folder);
        }
    }

    public function ensureDefaultFolders(Account $account): void
    {
        foreach (self::requiredSystemFunctions() as $function) {
            $this->ensureSystemFolder($account, $function);
        }
    }

    /**
     * @param array<string|FolderFunction> $functions
     *
     * @return array<string>
     */
    public static function normalizeFunctions(array $functions): array
    {
        $normalized = [];
        foreach ($functions as $function) {
            $value = self::normalizeFunctionValue($function);
            if ('' === $value || !self::isSystemFunction($value)) {
                continue;
            }
            $normalized[] = $value;
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeFunctionValue(FolderFunction|string $function): string
    {
        $value = strtolower(trim($function instanceof FolderFunction ? $function->value : $function));

        return 'sent' === $value ? FolderFunction::OUTBOX->value : $value;
    }

    private function syncAccountFolderLink(Account $account, Folder $folder, int $sortOrder): bool
    {
        foreach ($account->getFolderLinks() as $link) {
            if ($link->getFolder() !== $folder) {
                continue;
            }

            if ($link->getSortOrder() === $sortOrder) {
                return false;
            }

            $link->setSortOrder($sortOrder);
            $this->em->persist($link);

            return true;
        }

        return false;
    }

    /**
     * The mailbox function is part of every target set: Postfach always
     * contains ALL mails, the second function marks the special folder
     * (Posteingang / Postausgang / Geplant / Entwürfe / Papierkorb) the mail
     * belongs in.
     *
     * @return array<string>
     */
    private function resolveTargetFunctions(Email $email): array
    {
        // Deleted mails stay in the trash across syncs; only moving them out
        // of the trash (or a manual move) changes this.
        foreach ($email->getEmailFolders() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if ($folder instanceof Folder && $folder->hasFunction(FolderFunction::TRASH->value)) {
                return [FolderFunction::MAILBOX->value, FolderFunction::TRASH->value];
            }
        }

        if (!$email->isOutgoing() && $email->getReceivedAt() instanceof \DateTimeImmutable) {
            return [FolderFunction::MAILBOX->value, FolderFunction::INBOX->value];
        }

        if ($email->isDraft() || (!$email->isOutgoing() && null === $email->getReceivedAt())) {
            return [FolderFunction::MAILBOX->value, FolderFunction::DRAFTS->value];
        }

        if (!$email->isSent()) {
            // Outgoing, ready to send but not processed yet, or scheduled for
            // a future send.
            return [FolderFunction::MAILBOX->value, FolderFunction::SCHEDULED->value];
        }

        return [FolderFunction::MAILBOX->value, FolderFunction::OUTBOX->value];
    }
}
