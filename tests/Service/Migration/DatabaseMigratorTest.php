<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Migration;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Service\Migration\DatabaseMigrator;
use AvalynxOffice\Service\Migration\MigrationResult;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class DatabaseMigratorTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->refreshDatabase($em);
    }

    /**
     * An installation updated from an older version (no backup involved):
     * its migration history is unknown and its schema predates a newer
     * table — the migration must converge the schema and keep every row.
     */
    public function testMigrationKeepsDataAndConvergesDivergingInstallations(): void
    {
        $migrator = static::getContainer()->get(DatabaseMigrator::class);
        \assert($migrator instanceof DatabaseMigrator);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $connection = $em->getConnection();

        $admin = new User();
        $admin->setEmail('admin@example.com');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword(password_hash('admin', PASSWORD_BCRYPT));
        $em->persist($admin);
        $em->flush();

        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS doctrine_migration_versions (version VARCHAR(255) NOT NULL, PRIMARY KEY(version))',
        );
        $connection->executeStatement('DELETE FROM doctrine_migration_versions');
        $connection->executeStatement("INSERT INTO doctrine_migration_versions (version) VALUES ('Version20260901000000')");
        $connection->executeStatement('DROP TABLE IF EXISTS app_recovery_address');

        $result = $migrator->migrateToCurrent();

        // The data survived the migration.
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user'));
        self::assertNotNull($em->getRepository(User::class)->findOneByEmail('admin@example.com'));

        // The schema was converged to the current metadata.
        self::assertSame(MigrationResult::MODE_SCHEMA_SYNC, $result->mode);
        self::assertContains('app_recovery_address', $connection->createSchemaManager()->listTableNames());

        // The tracking was replaced by the current history.
        $versions = array_map('strval', $connection->executeQuery('SELECT version FROM doctrine_migration_versions')->fetchFirstColumn());
        self::assertNotContains('Version20260901000000', $versions);
        self::assertGreaterThanOrEqual(4, count($versions));

        // A second run is a no-op on the now clean path — and keeps the data.
        $second = $migrator->migrateToCurrent();
        self::assertSame(MigrationResult::MODE_MIGRATIONS, $second->mode);
        self::assertSame(0, $second->migrationCount);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user'));
    }
}
