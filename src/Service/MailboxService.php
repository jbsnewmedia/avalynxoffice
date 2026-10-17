<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\Address;
use AvalynxOffice\Entity\Enum\FolderFunction;
use AvalynxOffice\Entity\Folder;
use AvalynxOffice\Entity\FolderMailbox;
use AvalynxOffice\Entity\Mailbox;
use AvalynxOffice\Repository\AddressRepository;
use AvalynxOffice\Service\Mail\FolderManager;
use Doctrine\ORM\EntityManagerInterface;

class MailboxService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FolderManager $folderManager,
        private readonly AddressRepository $addressRepository,
    ) {
    }

    /**
     * @param array<int, array{email: string, name: ?string}>                           $aliases
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $folders
     */
    public function save(Mailbox $mailbox, array $aliases = [], array $folders = [], bool $isNew = false): void
    {
        if ($isNew) {
            $this->entityManager->persist($mailbox);
            $this->entityManager->flush();
        }

        $this->syncAliases($mailbox, $aliases);
        $this->folderManager->ensureDefaultFolders($mailbox);
        $this->syncFolders($mailbox, $folders);

        $this->entityManager->flush();
    }

    public function delete(Mailbox $mailbox): void
    {
        $this->entityManager->remove($mailbox);
        $this->entityManager->flush();
    }

    public function formatAliases(Mailbox $mailbox): string
    {
        $aliases = [];
        foreach ($mailbox->getAddresses() as $address) {
            if ($address instanceof Address) {
                $aliases[] = [
                    'email' => $address->getEmail(),
                    'name' => $address->getName(),
                ];
            }
        }

        usort($aliases, static fn (array $left, array $right): int => [$left['email'], $left['name'] ?? ''] <=> [$right['email'], $right['name'] ?? '']);

        return $this->encodePayload($aliases);
    }

    public function formatFolders(Mailbox $mailbox): string
    {
        $folders = [];
        foreach ($mailbox->getFolderLinks() as $link) {
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
     * @return array<int, array{email: string, name: ?string}>
     */
    public function normalizeAliases(?string $input): array
    {
        $normalized = [];
        foreach ($this->decodePayload($input) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $emailRaw = $item['email'] ?? null;
            $nameRaw = $item['name'] ?? null;
            $email = is_scalar($emailRaw) ? strtolower(trim((string) $emailRaw)) : '';
            $name = is_scalar($nameRaw) ? trim((string) $nameRaw) : '';

            if ('' === $email) {
                continue;
            }

            $normalized[$email] = [
                'email' => $email,
                'name' => '' !== $name ? $name : null,
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
     * @param array<int, array{email: string, name: ?string}> $aliases
     *
     * @return array<int, string>
     */
    public function findInvalidAliases(array $aliases): array
    {
        $invalid = [];
        foreach ($aliases as $alias) {
            if (false === filter_var($alias['email'], FILTER_VALIDATE_EMAIL)) {
                $invalid[] = $alias['email'];
            }
        }

        return $invalid;
    }

    /**
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $folders
     *
     * @return array<int, string>
     */
    public function findNonRemovableFolders(Mailbox $mailbox, array $folders): array
    {
        $desiredFolders = $this->desiredFolderDefinitions($folders);
        $blocked = [];

        foreach ($mailbox->getFolderLinks() as $link) {
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

    /**
     * @param array<int, array{email: string, name: ?string}> $aliases
     */
    private function syncAliases(Mailbox $mailbox, array $aliases): void
    {
        $desiredAliases = [];
        $mailboxAddress = strtolower($mailbox->getEmail());
        foreach ($aliases as $alias) {
            $email = strtolower(trim($alias['email']));
            if ('' === $email || $email === $mailboxAddress) {
                continue;
            }
            $desiredAliases[$email] = [
                'email' => $email,
                'name' => $alias['name'],
            ];
        }

        foreach ($mailbox->getAddresses() as $address) {
            if (!$address instanceof Address) {
                continue;
            }

            $email = strtolower($address->getEmail());
            if (!isset($desiredAliases[$email])) {
                $mailbox->removeAddress($address);
                continue;
            }

            $address->setName($desiredAliases[$email]['name']);
            unset($desiredAliases[$email]);
        }

        foreach ($desiredAliases as $desiredAlias) {
            $address = $this->addressRepository->findOneBy(['email' => $desiredAlias['email']]);
            if (!$address instanceof Address) {
                $address = new Address()->setEmail($desiredAlias['email']);
                $this->entityManager->persist($address);
            }

            $address->setName($desiredAlias['name']);
            $mailbox->addAddress($address);
        }
    }

    /**
     * @param array<int, array{name: string, sortOrder: int, functions: array<string>}> $folders
     */
    private function syncFolders(Mailbox $mailbox, array $folders): void
    {
        $desiredFolders = $this->desiredFolderDefinitions($folders);
        $remainingLinks = $mailbox->getFolderLinks()->toArray();

        foreach ($desiredFolders as $folderData) {
            $matchedLink = $this->matchExistingLink($remainingLinks, $folderData);
            if (null === $matchedLink) {
                $this->folderManager->ensureFolder(
                    $mailbox,
                    $folderData['name'],
                    $folderData['sortOrder'],
                    $folderData['functions'],
                );
                continue;
            }

            $folder = $matchedLink->getFolder();
            if (!$folder instanceof Folder) {
                continue;
            }

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

            $mailbox->removeFolder($folder);
            $this->entityManager->remove($link);
        }

        $mailbox->setInboxFolder($this->folderManager->getFolderByFunction($mailbox, FolderFunction::MAILBOX));
        $this->entityManager->persist($mailbox);
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
     * @param list<array{email: string, name: ?string}|array{name: string, sortOrder: int, functions: array<string>}> $payload
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
     * @param array<int, FolderMailbox>                                     $remainingLinks
     * @param array{name: string, sortOrder: int, functions: array<string>} $folderData
     */
    private function matchExistingLink(array $remainingLinks, array $folderData): ?FolderMailbox
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
