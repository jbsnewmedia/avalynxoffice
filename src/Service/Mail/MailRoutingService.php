<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Mail;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Email;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderAccount;
use AvalynxOffice\Repository\AccountAliasRepository;
use AvalynxOffice\Repository\ConnectorRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Routes fetched messages to accounts and re-routes the existing message
 * stock when an alias moves to another account: aliases act like filters
 * that are applied to the current stock as well.
 */
class MailRoutingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AccountAliasRepository $aliasRepository,
        private readonly ConnectorRepository $connectorRepository,
        private readonly FolderManager $folderManager,
    ) {
    }

    /**
     * Resolves the account a fetched message belongs to. Exact alias matches
     * win (recipients are evaluated in header order), otherwise the default
     * account of the connector receives the message. Returns null when the
     * message cannot be routed.
     *
     * @param list<string> $recipientAddresses all To/Cc addresses in header order
     *
     * @return array{account: Account, alias: AccountAlias|null}|null
     */
    public function resolveTarget(Connector $connector, array $recipientAddresses): ?array
    {
        foreach ($recipientAddresses as $recipient) {
            $localPart = self::localPartOf($recipient);
            $domain = self::domainOf($recipient);
            if (null === $localPart || null === $domain || $domain !== $connector->getDomain()) {
                continue;
            }

            $alias = $this->aliasRepository->findOneBy([
                'connector' => $connector,
                'localPart' => $localPart,
            ]);
            $account = $alias?->getAccount();
            if ($account instanceof Account) {
                return ['account' => $account, 'alias' => $alias];
            }
        }

        $defaultAccount = $connector->getDefaultAccount();
        if ($defaultAccount instanceof Account) {
            return ['account' => $defaultAccount, 'alias' => null];
        }

        return null;
    }

    /**
     * Moves every message that was delivered through the given alias
     * (connector + local part) to another account and returns the number
     * of moved messages.
     */
    public function rerouteLocalPart(Connector $connector, string $localPart, Account $target): int
    {
        $moved = 0;
        $emails = $this->entityManager->getRepository(Email::class)->findBy([
            'connector' => $connector,
            'matchedLocalPart' => strtolower(trim($localPart)),
        ]);
        foreach ($emails as $email) {
            if ($this->moveEmailToAccount($email, $target)) {
                ++$moved;
            }
        }
        $this->entityManager->flush();

        return $moved;
    }

    /**
     * Moves every message that was delivered to the former default account
     * of the connector (no alias match) to the new default account.
     */
    public function rerouteDefaultAccount(Connector $connector, ?Account $formerDefault, Account $target): int
    {
        if (!$formerDefault instanceof Account || $formerDefault === $target) {
            return 0;
        }

        $moved = 0;
        $emails = $this->entityManager->getRepository(Email::class)->findBy([
            'connector' => $connector,
            'matchedLocalPart' => null,
            'account' => $formerDefault,
        ]);
        foreach ($emails as $email) {
            if ($this->moveEmailToAccount($email, $target)) {
                ++$moved;
            }
        }
        $this->entityManager->flush();

        return $moved;
    }

    /**
     * Finds the connector responsible for a domain (used for sending).
     */
    public function connectorForDomain(string $domain): ?Connector
    {
        return $this->connectorRepository->findOneBy(['domain' => strtolower(trim($domain))]);
    }

    private function moveEmailToAccount(Email $email, Account $target): bool
    {
        $formerAccount = $email->getAccount();
        if ($formerAccount === $target) {
            return false;
        }

        // Reload the message state so stale folder collections cannot hide
        // existing assignments.
        $this->entityManager->refresh($email);
        foreach ($email->getEmailFolders()->toArray() as $emailFolder) {
            $folder = $emailFolder->getFolder();
            if (!$folder instanceof Folder) {
                continue;
            }
            $belongsToFormerAccount = null !== $formerAccount && null !== $this->entityManager
                ->getRepository(FolderAccount::class)
                ->findOneBy(['account' => $formerAccount, 'folder' => $folder]);
            if ($belongsToFormerAccount) {
                $email->removeEmailFolder($emailFolder);
                $this->entityManager->remove($emailFolder);
            }
        }

        $email->setAccount($target);
        $this->folderManager->syncEmailSystemFolders($email);
        $this->entityManager->persist($email);

        return true;
    }

    public static function localPartOf(string $email): ?string
    {
        $atPosition = strrpos($email, '@');
        if (false === $atPosition || 0 === $atPosition || $atPosition === strlen($email) - 1) {
            return null;
        }

        $localPart = strtolower(substr($email, 0, $atPosition));

        return '' !== $localPart ? $localPart : null;
    }

    public static function domainOf(string $email): ?string
    {
        $atPosition = strrpos($email, '@');
        if (false === $atPosition || $atPosition === strlen($email) - 1) {
            return null;
        }

        $domain = strtolower(substr($email, $atPosition + 1));

        return '' !== $domain ? $domain : null;
    }
}
