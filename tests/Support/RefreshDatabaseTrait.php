<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Resets the database to a clean state between tests.
 *
 * On every platform the schema is only rebuilt when it no longer matches the
 * entity metadata: the first run stores a fingerprint of the generated DDL in
 * a dedicated table and every later run just truncates all tables. A rebuild
 * happens when a table is missing or the fingerprint is stale (entity changes
 * or a schema damaged by a previous run). This keeps every test on a clean
 * database with fresh sequences while cutting the per-test schema overhead.
 */
trait RefreshDatabaseTrait
{
    /**
     * Fingerprint table: single row that stores the hash of the schema DDL
     * the current code version generates. Not visible to the application.
     */
    private const string SCHEMA_HASH_TABLE = '_test_schema_hash';

    /**
     * Tables that hold installation state, not test data, and therefore
     * survive a refresh (same convention as DatabasePurger::SYSTEM_TABLES).
     *
     * @var list<non-empty-string>
     */
    private const array PRESERVED_TABLES = [
        'doctrine_migration_versions',
        'messenger_messages',
        self::SCHEMA_HASH_TABLE,
    ];

    /** Cached DDL fingerprint for the current process. */
    private static ?string $schemaHash = null;

    protected function refreshDatabase(EntityManagerInterface $entityManager): void
    {
        $connection = $entityManager->getConnection();
        $mariadb = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;

        // Session-scoped durability relaxation: the test database is wiped on
        // every run, so commits do not need to wait for a WAL flush. The
        // setting stays local to this connection and is never global. MariaDB
        // has no session-scoped equivalent - its test stack (see
        // docker-compose.mariadb.yml) relaxes the durability server-side.
        if (! $mariadb) {
            $connection->executeStatement('SET synchronous_commit = OFF');
        }

        $tables = self::getTableNames($connection, $mariadb);

        if (array_diff(self::getExpectedTableNames($entityManager), $tables) !== []
            || self::getStoredSchemaHash($connection, $tables) !== self::getSchemaHash($entityManager)
        ) {
            self::rebuildSchema($entityManager, $tables, $mariadb);

            return;
        }

        self::truncateAllTables($connection, $tables, $mariadb);
    }

    /**
     * Table names the test schema must contain: the entity tables, the
     * doctrine remember-me table and the schema fingerprint table.
     *
     * @return list<non-empty-string>
     */
    private static function getExpectedTableNames(EntityManagerInterface $entityManager): array
    {
        $names = [];

        foreach ($entityManager->getMetadataFactory()->getAllMetadata() as $metadata) {
            $names[] = $metadata->getTableName();
        }

        $names[] = 'rememberme_token';
        $names[] = self::SCHEMA_HASH_TABLE;

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private static function getTableNames(Connection $connection, bool $mariadb): array
    {
        if ($mariadb) {
            return array_map('strval', $connection->createSchemaManager()->listTableNames());
        }

        $names = $connection->fetchFirstColumn(
            "SELECT tablename FROM pg_tables WHERE schemaname = 'public'",
        );

        return array_map('strval', $names);
    }

    private static function getStoredSchemaHash(Connection $connection, array $tables): ?string
    {
        if (!in_array(self::SCHEMA_HASH_TABLE, $tables, true)) {
            return null;
        }

        $hash = $connection->fetchOne(
            'SELECT hash FROM ' . $connection->getDatabasePlatform()->quoteIdentifier(self::SCHEMA_HASH_TABLE) . ' WHERE id = 1',
        );

        return is_string($hash) ? $hash : null;
    }

    private static function getSchemaHash(EntityManagerInterface $entityManager): string
    {
        if (self::$schemaHash === null) {
            $statements = (new SchemaTool($entityManager))->getCreateSchemaSql(
                $entityManager->getMetadataFactory()->getAllMetadata(),
            );

            self::$schemaHash = md5(implode("\n", $statements));
        }

        return self::$schemaHash;
    }

    /**
     * @param list<string> $tables
     */
    private static function truncateAllTables(Connection $connection, array $tables, bool $mariadb): void
    {
        $targets = array_diff($tables, self::PRESERVED_TABLES);

        if ($targets === []) {
            return;
        }

        $quoted = array_map(
            static fn (string $table): string => $connection->getDatabasePlatform()->quoteIdentifier($table),
            array_values($targets),
        );

        if ($mariadb) {
            // MariaDB has no multi-table TRUNCATE; with the foreign key
            // checks disabled every truncate also resets the auto-increment
            // counters, which is the RESTART IDENTITY equivalent.
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($quoted as $table) {
                    $connection->executeStatement('TRUNCATE TABLE ' . $table);
                }
            } finally {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }

            return;
        }

        // RESTART IDENTITY restores the sequence state a fresh schema would
        // have; CASCADE resolves the foreign key dependencies between tables.
        $connection->executeStatement('TRUNCATE TABLE ' . implode(', ', $quoted) . ' RESTART IDENTITY CASCADE');
    }

    /**
     * @param list<string> $tables
     */
    private static function rebuildSchema(EntityManagerInterface $entityManager, array $tables, bool $mariadb): void
    {
        $connection = $entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        if (! $mariadb) {
            $connection->executeStatement('CREATE SCHEMA IF NOT EXISTS public');
            $connection->executeStatement('SET search_path TO public');

            foreach (array_diff($tables, self::PRESERVED_TABLES) as $table) {
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table) . ' CASCADE');
            }
        } else {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_diff($tables, self::PRESERVED_TABLES) as $table) {
                $connection->executeStatement('DROP TABLE IF EXISTS ' . $platform->quoteIdentifier($table));
            }
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }

        $tool = new SchemaTool($entityManager);
        $tool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        // The doctrine remember-me token table is not an entity and is
        // therefore not part of the schema tool metadata.
        $connection->executeStatement('DROP TABLE IF EXISTS rememberme_token');

        if ($mariadb) {
            $connection->executeStatement("CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed DATETIME NOT NULL, class VARCHAR(100) DEFAULT '' NOT NULL, username VARCHAR(200) NOT NULL, INDEX idx_rememberme_token_username (username), PRIMARY KEY(series)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } else {
            $connection->executeStatement("CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, class VARCHAR(100) DEFAULT '' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY(series))");
            $connection->executeStatement('CREATE INDEX idx_rememberme_token_username ON rememberme_token (username)');
        }

        $hashTable = $platform->quoteIdentifier(self::SCHEMA_HASH_TABLE);
        $connection->executeStatement('DROP TABLE IF EXISTS ' . $hashTable);
        $connection->executeStatement('CREATE TABLE ' . $hashTable . ' (id INTEGER NOT NULL PRIMARY KEY, hash VARCHAR(32) NOT NULL)');
        $connection->executeStatement('INSERT INTO ' . $hashTable . ' (id, hash) VALUES (1, ?)', [self::getSchemaHash($entityManager)]);
    }
}
