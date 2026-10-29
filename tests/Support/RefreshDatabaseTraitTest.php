<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Support;

use AvalynxOffice\Entity\AppSetting;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Locks in the behavior of the truncate-based database reset: every test
 * starts with empty tables and intact sequences, the schema is only rebuilt
 * when it no longer matches the entities, and the durability relaxation is
 * applied to the test connection.
 */
#[Group('integration')]
final class RefreshDatabaseTraitTest extends KernelTestCase
{
    use RefreshDatabaseTrait;

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
    }

    public function testRefreshRelaxesCommitDurabilityOnTheTestConnection(): void
    {
        $this->refreshDatabase($this->entityManager);

        self::assertSame('off', (string) $this->connection->fetchOne('SHOW synchronous_commit'));
    }

    public function testRefreshWipesAllDataWhileKeepingTheSchema(): void
    {
        $this->refreshDatabase($this->entityManager);

        $this->entityManager->persist(new AppSetting('refresh-db-test-key', 'test-value'));
        $this->entityManager->flush();

        self::assertSame(
            1,
            $this->connection->fetchOne("SELECT count(*) FROM app_setting WHERE setting_key = 'refresh-db-test-key'"),
        );

        $this->refreshDatabase($this->entityManager);

        self::assertSame(
            0,
            $this->connection->fetchOne("SELECT count(*) FROM app_setting WHERE setting_key = 'refresh-db-test-key'"),
        );
        self::assertSame(
            'app_setting',
            (string) $this->connection->fetchOne("SELECT to_regclass('public.app_setting')"),
        );
    }

    public function testRefreshRebuildsTheSchemaWhenATableIsMissing(): void
    {
        $this->refreshDatabase($this->entityManager);
        $this->connection->executeStatement('DROP TABLE app_setting');

        $this->refreshDatabase($this->entityManager);

        self::assertSame(
            'app_setting',
            (string) $this->connection->fetchOne("SELECT to_regclass('public.app_setting')"),
        );
        self::assertSame(0, $this->connection->fetchOne('SELECT count(*) FROM app_setting'));
    }

    public function testRefreshRebuildsTheSchemaWhenTheFingerprintIsStale(): void
    {
        $this->refreshDatabase($this->entityManager);

        $this->connection->executeStatement("UPDATE _test_schema_hash SET hash = 'stale'");
        $this->entityManager->persist(new AppSetting('stale-fingerprint-test-key', 'test-value'));
        $this->entityManager->flush();

        $this->refreshDatabase($this->entityManager);

        self::assertSame(self::getSchemaHash($this->entityManager), self::getStoredSchemaHash(
            $this->connection,
            self::getPublicTableNames($this->connection),
        ));
        self::assertSame(
            0,
            $this->connection->fetchOne("SELECT count(*) FROM app_setting WHERE setting_key = 'stale-fingerprint-test-key'"),
        );
    }

    public function testRefreshPreservesInstallationStateTables(): void
    {
        $this->refreshDatabase($this->entityManager);

        $tableExisted = $this->connection->fetchOne("SELECT to_regclass('public.doctrine_migration_versions')") !== null;

        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS doctrine_migration_versions (version VARCHAR(255) NOT NULL, executed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, execution_time INTEGER DEFAULT NULL, PRIMARY KEY (version))');
        $this->connection->executeStatement("INSERT INTO doctrine_migration_versions (version) VALUES ('refresh-db-test-version')");

        $this->refreshDatabase($this->entityManager);

        self::assertSame(
            1,
            $this->connection->fetchOne("SELECT count(*) FROM doctrine_migration_versions WHERE version = 'refresh-db-test-version'"),
        );

        if ($tableExisted) {
            $this->connection->executeStatement("DELETE FROM doctrine_migration_versions WHERE version = 'refresh-db-test-version'");
        } else {
            $this->connection->executeStatement('DROP TABLE doctrine_migration_versions');
        }
    }
}
