<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Support;

use AvalynxOffice\Entity\AppSetting;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
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
        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // synchronous_commit is PostgreSQL-specific; the MariaDB test
            // stack (docker-compose.mariadb.yml) relaxes durability
            // server-side instead of on the session.
            self::markTestSkipped('synchronous_commit is PostgreSQL-specific.');
        }

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
        self::assertTrue($this->tableExists('app_setting'));
    }

    public function testRefreshRebuildsTheSchemaWhenATableIsMissing(): void
    {
        $this->refreshDatabase($this->entityManager);

        if ($this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            $this->connection->executeStatement('DROP TABLE app_setting');
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        } else {
            $this->connection->executeStatement('DROP TABLE app_setting');
        }

        $this->refreshDatabase($this->entityManager);

        self::assertTrue($this->tableExists('app_setting'));
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
            self::getTableNames($this->connection, $this->isMariaDb()),
        ));
        self::assertSame(
            0,
            $this->connection->fetchOne("SELECT count(*) FROM app_setting WHERE setting_key = 'stale-fingerprint-test-key'"),
        );
    }

    public function testRefreshPreservesInstallationStateTables(): void
    {
        $this->refreshDatabase($this->entityManager);

        $tableExisted = $this->tableExists('doctrine_migration_versions');

        $this->connection->executeStatement('CREATE TABLE IF NOT EXISTS doctrine_migration_versions (version VARCHAR(255) NOT NULL, PRIMARY KEY (version))');
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

    private function isMariaDb(): bool
    {
        return $this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform;
    }

    private function tableExists(string $table): bool
    {
        return in_array($table, $this->connection->createSchemaManager()->listTableNames(), true);
    }
}
