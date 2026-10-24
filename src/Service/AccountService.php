<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\Account;
use AvalynxOffice\Entity\AccountAlias;
use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderAccount;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Repository\AccountAliasRepository;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Service\Mail\FolderManager;
use AvalynxOffice\Service\Mail\MailRoutingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

class AccountService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FolderManager $folderManager,
        private readonly ConnectorRepository $connectorRepository,
        private readonly AccountAliasRepository $aliasRepository,
        private readonly MailRoutingService $mailRoutingService,
    ) {
    }

    /**
     * @param array<int, array{connectorId: string, localPart: string}>                 $aliases
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $folders
     * @param list<User>|null                                                           $users   null keeps the current user assignments untouched
     */
    public function save(Account $account, array $aliases = [], array $folders = [], ?array $users = null, bool $isNew = false): void
    {
        if ($isNew) {
            $this->entityManager->persist($account);
            $this->entityManager->flush();
        }

        if (null !== $users) {
            $this->syncUsers($account, $users);
        }

        $reroutes = $this->syncAliases($account, $aliases);
        $this->folderManager->ensureDefaultFolders($account);
        $this->syncFolders($account, $folders);

        $this->entityManager->flush();

        foreach ($reroutes as $reroute) {
            $this->mailRoutingService->rerouteLocalPart($reroute['connector'], $reroute['localPart'], $account);
        }
    }

    public function delete(Account $account): void
    {
        $this->entityManager->remove($account);
        $this->entityManager->flush();
    }

    public function formatAliases(Account $account): string
    {
        $aliases = [];
        foreach ($account->getAliasLinks() as $alias) {
            if (!$alias instanceof AccountAlias) {
                continue;
            }
            $aliases[] = [
                'connectorId' => null !== $alias->getConnector()?->getId() ? (string) $alias->getConnector()->getId() : '',
                'localPart' => $alias->getLocalPart(),
            ];
        }

        usort($aliases, static fn (array $left, array $right): int => [$left['connectorId'], $left['localPart']] <=> [$right['connectorId'], $right['localPart']]);

        return $this->encodePayload($aliases);
    }

    public function formatFolders(Account $account): string
    {
        $folders = [];
        foreach ($account->getFolderLinks() as $link) {
            $folder = $link->getFolder();
            if (!$folder instanceof Folder) {
                continue;
            }
            $folders[] = [
                'functions' => $folder->getFunctions(),
                'name' => $folder->getName(),
                'sortOrder' => $link->getSortOrder(),
            ];
        }

        usort($folders, self::compareFoldersBySortOrderAndName(...));

        return $this->encodePayload($folders);
    }

    /**
     * @return array<int, array{connectorId: string, localPart: string}>
     */
    public function normalizeAliases(?string $input): array
    {
        $normalized = [];
        foreach ($this->decodePayload($input) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $connectorIdRaw = $item['connectorId'] ?? null;
            $localPartRaw = $item['localPart'] ?? null;
            $connectorId = is_scalar($connectorIdRaw) ? trim((string) $connectorIdRaw) : '';
            $localPart = is_scalar($localPartRaw) ? strtolower(trim((string) $localPartRaw)) : '';

            if ('' === $connectorId || '' === $localPart) {
                continue;
            }

            $normalized[$connectorId.'|'.$localPart] = [
                'connectorId' => $connectorId,
                'localPart' => $localPart,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @return array<int, array{name: string, sortOrder: int, functions: array<string>}>
     */
    public function normalizeFolders(?string $input): array
    {
        $normalized = [];
        foreach ($this->decodePayload($input) as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $nameRaw = $item['name'] ?? null;
            $sortOrderRaw = $item['sortOrder'] ?? null;
            $functionsRaw = $item['functions'] ?? [];
            $name = is_scalar($nameRaw) ? trim((string) $nameRaw) : '';
            if ('' === $name) {
                continue;
            }

            $normalized[strtolower($name)] = [
                'functions' => $this->normalizeFolderFunctions($functionsRaw),
                'name' => $name,
                'sortOrder' => $this->normalizeSortOrder($sortOrderRaw, $index),
            ];
        }

        return array_values($normalized);
    }

    /**
     * Returns every alias whose local part is not a usable address local
     * part (empty, whitespace or the @ sign) or whose connector does not
     * exist.
     *
     * @param array<int, array{connectorId: string, localPart: string}> $aliases
     *
     * @return array<int, string>
     */
    public function findInvalidAliases(array $aliases): array
    {
        $invalid = [];
        foreach ($aliases as $alias) {
            $connector = $this->findConnector($alias['connectorId']);
            if (!$connector instanceof Connector || '' === $alias['localPart'] || str_contains($alias['localPart'], '@') || 1 === preg_match('/\s/u', $alias['localPart'])) {
                $invalid[] = '' !== $alias['localPart'] ? $alias['localPart'] : '(leer)';
            }
        }

        return $invalid;
    }

    /**
     * Returns aliases that conflict with another alias for the same
     * connector address — either within the payload itself or with an
     * alias that already exists on a different account. Every address has
     * exactly one target account.
     *
     * @param array<int, array{connectorId: string, localPart: string}> $aliases
     *
     * @return array<int, string>
     */
    public function findConflictingAliases(Account $account, array $aliases): array
    {
        $conflicts = [];
        $seen = [];
        foreach ($aliases as $alias) {
            $key = $alias['connectorId'].'|'.$alias['localPart'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $connector = $this->findConnector($alias['connectorId']);
            if (!$connector instanceof Connector) {
                continue;
            }

            $existing = $this->aliasRepository->findOneBy([
                'connector' => $connector,
                'localPart' => $alias['localPart'],
            ]);
            if ($existing instanceof AccountAlias && $existing->getAccount() !== $account) {
                $conflicts[] = $alias['localPart'].'@'.$connector->getDomain();
            }
        }

        return $conflicts;
    }

    /**
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $folders
     *
     * @return array<int, string>
     */
    public function findNonRemovableFolders(Account $account, array $folders): array
    {
        $desiredFolders = $this->desiredFolderDefinitions($folders);
        $blocked = [];

        foreach ($account->getFolderLinks() as $link) {
            $folder = $link->getFolder();
            if (!$folder instanceof Folder) {
                continue;
            }

            if ($this->matchesDesiredFolder($folder, $desiredFolders)) {
                continue;
            }

            if ($folder->getEmailFolders()->count() > 0) {
                $blocked[] = $folder->getName();
            }
        }

        return $blocked;
    }

    /**
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $folders
     *
     * @return array<int, string>
     */
    public function findDuplicateFolderFunctions(array $folders): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($folders as $folder) {
            foreach ($folder['functions'] as $function) {
                if (FolderFunction::NONE->value === $function) {
                    continue;
                }
                if (isset($seen[$function])) {
                    $duplicates[] = FolderFunction::from($function)->translationKey();
                    continue;
                }

                $seen[$function] = true;
            }
        }

        return array_values(array_unique($duplicates));
    }

    /**
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $folders
     *
     * @return array<int, string>
     */
    public function findMissingSystemFolderFunctions(array $folders): array
    {
        $seen = [];
        foreach ($folders as $folder) {
            foreach ($folder['functions'] as $function) {
                if (FolderFunction::NONE->value === $function) {
                    continue;
                }
                $seen[$function] = true;
            }
        }

        $missing = [];
        foreach (FolderManager::requiredSystemFunctions() as $function) {
            if (!isset($seen[$function])) {
                $missing[] = FolderFunction::from($function)->translationKey();
            }
        }

        return $missing;
    }

    private function findConnector(string $identifier): ?Connector
    {
        if (!Uuid::isValid($identifier)) {
            return null;
        }

        try {
            return $this->connectorRepository->find($identifier);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<User> $users
     */
    private function syncUsers(Account $account, array $users): void
    {
        $desired = [];
        foreach ($users as $user) {
            if ($user instanceof User) {
                $desired[$user->getId()?->toBinary() ?? ''] = $user;
            }
        }

        foreach ($account->getUserLinks()->toArray() as $link) {
            $user = $link->getUser();
            if (!$user instanceof User) {
                continue;
            }
            $key = $user->getId()?->toBinary() ?? '';
            if (!isset($desired[$key])) {
                $account->removeUser($user);
                $this->entityManager->remove($link);
                continue;
            }
            unset($desired[$key]);
        }

        foreach ($desired as $user) {
            $account->addUser($user);
        }
    }

    /**
     * Syncs the alias assignments of the account. Aliases that were owned
     * by another account are moved to this account (every address has
     * exactly one target account); the moved message stock is re-routed
     * afterwards.
     *
     * @param array<int, array{connectorId: string, localPart: string}> $aliases
     *
     * @return list<array{connector: Connector, localPart: string}> alias moves that need a re-route
     */
    private function syncAliases(Account $account, array $aliases): array
    {
        $desired = [];
        foreach ($aliases as $alias) {
            $connector = $this->findConnector($alias['connectorId']);
            if (!$connector instanceof Connector || '' === $alias['localPart']) {
                continue;
            }
            $desired[$connector->getId()?->toBinary().'|'.$alias['localPart']] = [
                'connector' => $connector,
                'localPart' => $alias['localPart'],
            ];
        }

        foreach ($account->getAliasLinks()->toArray() as $existingAlias) {
            if (!$existingAlias instanceof AccountAlias) {
                continue;
            }
            $key = $existingAlias->getConnector()?->getId()?->toBinary().'|'.$existingAlias->getLocalPart();
            if (isset($desired[$key])) {
                unset($desired[$key]);
                continue;
            }
            $account->removeAlias($existingAlias);
            $this->entityManager->remove($existingAlias);
        }

        $reroutes = [];
        foreach ($desired as $desiredAlias) {
            $connector = $desiredAlias['connector'];
            $localPart = $desiredAlias['localPart'];
            $existing = $this->aliasRepository->findOneBy([
                'connector' => $connector,
                'localPart' => $localPart,
            ]);
            if ($existing instanceof AccountAlias) {
                if ($existing->getAccount() === $account) {
                    continue;
                }
                $formerAccount = $existing->getAccount();
                $formerAccount?->removeAlias($existing);
                $existing->setAccount($account);
                $account->addAlias($existing);
                $this->entityManager->persist($existing);
                $reroutes[] = ['connector' => $connector, 'localPart' => $localPart];
                continue;
            }

            $alias = new AccountAlias();
            $alias->setAccount($account);
            $alias->setConnector($connector);
            $alias->setLocalPart($localPart);
            $this->entityManager->persist($alias);
            $account->addAlias($alias);
        }

        return $reroutes;
    }

    /**
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $folders
     */
    private function syncFolders(Account $account, array $folders): void
    {
        $desiredFolders = $this->desiredFolderDefinitions($folders);
        $remainingLinks = $account->getFolderLinks()->toArray();

        foreach ($desiredFolders as $folderData) {
            $matchedLink = $this->matchExistingLink($remainingLinks, $folderData);
            if (null === $matchedLink) {
                $this->folderManager->ensureFolder(
                    $account,
                    $folderData['name'],
                    $folderData['sortOrder'],
                    $folderData['functions'],
                );
                continue;
            }

            $folder = $matchedLink->getFolder();
            assert($folder instanceof Folder);

            $folder->setName($folderData['name']);
            $folder->setFunctions($folderData['functions']);
            $matchedLink->setSortOrder($folderData['sortOrder']);
            $this->entityManager->persist($folder);
            $this->entityManager->persist($matchedLink);

            unset($remainingLinks[array_search($matchedLink, $remainingLinks, true)]);
        }

        foreach ($remainingLinks as $link) {
            $folder = $link->getFolder();
            if (!$folder instanceof Folder) {
                continue;
            }

            $account->removeFolder($folder);
            $this->entityManager->remove($link);
        }

        $account->setInboxFolder($this->folderManager->getFolderByFunction($account, FolderFunction::MAILBOX));
        $this->entityManager->persist($account);
    }

    /**
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $folders
     *
     * @return array<int, array{name: string, sortOrder: int, functions: array<string>}>
     */
    private function desiredFolderDefinitions(array $folders): array
    {
        $desiredFolders = [];

        foreach ($folders as $folder) {
            $name = trim($folder['name']);
            if ('' === $name) {
                continue;
            }

            $desiredFolders[] = [
                'functions' => $folder['functions'],
                'name' => $name,
                'sortOrder' => $folder['sortOrder'],
            ];
        }

        foreach (FolderManager::requiredSystemFunctions() as $function) {
            $found = false;
            foreach ($desiredFolders as $index => $folder) {
                if (!in_array($function, $folder['functions'], true)) {
                    continue;
                }

                $desiredFolders[$index]['functions'] = FolderManager::normalizeFunctions($folder['functions']);
                $found = true;
                break;
            }

            if ($found) {
                continue;
            }

            $desiredFolders[] = [
                'functions' => [$function],
                'name' => FolderManager::defaultFolderName($function),
                'sortOrder' => FolderManager::defaultSortOrder($function),
            ];
        }

        usort($desiredFolders, self::compareFoldersBySortOrderAndName(...));

        return $desiredFolders;
    }

    /**
     * @param array{sortOrder: int, name: string, functions: array<string>} $left
     * @param array{sortOrder: int, name: string, functions: array<string>} $right
     */
    private static function compareFoldersBySortOrderAndName(array $left, array $right): int
    {
        return [$left['sortOrder'], $left['name']] <=> [$right['sortOrder'], $right['name']];
    }

    /**
     * @return array<int, mixed>
     */
    private function decodePayload(?string $input): array
    {
        if (null === $input || '' === trim($input)) {
            return [];
        }

        $decoded = json_decode($input, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param list<array{connectorId: string, localPart: string}|array{name: string, sortOrder: int, functions: array<string>}> $payload
     */
    private function encodePayload(array $payload): string
    {
        $encoded = json_encode($payload);

        return false !== $encoded ? $encoded : '[]';
    }

    private function normalizeSortOrder(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && '' !== trim($value) && false !== filter_var($value, FILTER_VALIDATE_INT)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @return array<string>
     */
    private function normalizeFolderFunctions(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $functions = array_values(array_filter($value, is_string(...)));

        if (in_array(FolderFunction::NONE->value, $functions, true)) {
            return [];
        }

        return FolderManager::normalizeFunctions($functions);
    }

    /**
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $desiredFolders
     */
    private function matchesDesiredFolder(Folder $folder, array $desiredFolders): bool
    {
        foreach ($desiredFolders as $desiredFolder) {
            if ($folder->getName() === $desiredFolder['name']) {
                return true;
            }

            if ([] !== array_intersect($folder->getFunctions(), $desiredFolder['functions'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, FolderAccount>                                     $remainingLinks
     * @param array{name: string, sortOrder: int, functions: array<string>} $folderData
     */
    private function matchExistingLink(array $remainingLinks, array $folderData): ?FolderAccount
    {
        foreach ($remainingLinks as $link) {
            $folder = $link->getFolder();
            if (!$folder instanceof Folder) {
                continue;
            }

            if ($folder->getName() === $folderData['name']) {
                return $link;
            }

            if ([] !== array_intersect($folder->getFunctions(), $folderData['functions'])) {
                return $link;
            }
        }

        return null;
    }
}
