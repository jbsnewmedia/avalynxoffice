<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service;

use AvalynxOffice\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Clears database tables dynamically via the schema manager.
 *
 * Im Gegensatz zum MailClearCommand (statische Liste der Mail-Tabellen) werden hier
 * every table is taken into account. System tables (migrations, messenger) are
 * always preserved; the user table can optionally be kept.
 */
class DatabasePurger
{
    /** @var array<int, string> */
    private const array SYSTEM_TABLES = ['doctrine_migration_versions', 'messenger_messages'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return list<string> the cleared tables
     */
    public function purge(bool $keepUsers = false): array
    {
        $connection = $this->entityManager->getConnection();
        $tables = $this->resolveTables($connection, $keepUsers);
        $platform = $connection->getDatabasePlatform();
        $platformName = $this->platformName($platform);

        $this->disableForeignKeys($connection, $platformName);
        try {
            $connection->beginTransaction();
            if (str_contains($platformName, 'postgresql')) {
                $this->truncatePostgresql($connection, $tables);
            } else {
                foreach ($tables as $table) {
                    $this->truncateTable($connection, $platform, $table);
                }
            }
            $connection->commit();
        } catch (\Throwable $throwable) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->enableForeignKeys($connection, $platformName);
            throw $throwable;
        }
        $this->enableForeignKeys($connection, $platformName);

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function resolveTables(Connection $connection, bool $keepUsers): array
    {
        $tables = $connection->createSchemaManager()->listTableNames();
        $preserve = self::SYSTEM_TABLES;
        if ($keepUsers) {
            $preserve[] = $this->entityManager->getClassMetadata(User::class)->getTableName();
        }

        return array_values(array_filter(
            $tables,
            static fn (string $table): bool => !in_array($table, $preserve, true),
        ));
    }

    private function platformName(AbstractPlatform $platform): string
    {
        return strtolower(new \ReflectionClass($platform)->getShortName());
    }

    private function disableForeignKeys(Connection $connection, string $platformName): void
    {
        if (str_contains($platformName, 'sqlite')) {
            $connection->executeStatement('PRAGMA foreign_keys = OFF');
        } elseif (str_contains($platformName, 'postgresql')) {
            $connection->executeStatement("SET session_replication_role = 'replica'");
        } else {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }
    }

    private function enableForeignKeys(Connection $connection, string $platformName): void
    {
        if (str_contains($platformName, 'sqlite')) {
            $connection->executeStatement('PRAGMA foreign_keys = ON');
        } elseif (str_contains($platformName, 'postgresql')) {
            $connection->executeStatement("SET session_replication_role = 'origin'");
        } else {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @param array<int, string> $tables
     */
    private function truncatePostgresql(Connection $connection, array $tables): void
    {
        if ([] === $tables) {
            return;
        }
        try {
            $connection->executeStatement('TRUNCATE '.implode(', ', $tables));
        } catch (\Throwable) {
            foreach ($tables as $table) {
                $connection->executeStatement('DELETE FROM '.$table);
            }
        }
    }

    private function truncateTable(Connection $connection, AbstractPlatform $platform, string $table): void
    {
        try {
            $connection->executeStatement($platform->getTruncateTableSQL($table));
        } catch (\Throwable) {
            $connection->executeStatement('DELETE FROM '.$table);
        }
    }
}
