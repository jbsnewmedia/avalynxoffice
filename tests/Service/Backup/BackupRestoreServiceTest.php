<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Backup;

use AvalynxOffice\Service\Backup\BackupRestoreService;
use AvalynxOffice\Service\Backup\BackupService;
use AvalynxOffice\Service\Migration\MigrationResult;
use AvalynxOffice\Service\DatabasePurger;
use AvalynxOffice\Service\Mail\AttachmentStorage;
use AvalynxOffice\Service\AppService;
use AvalynxOffice\Entity\User;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class BackupRestoreServiceTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private string $backupDir;

    private string $probeFile;

    private string $probeContent;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->refreshDatabase($em);

        $service = static::getContainer()->get(AttachmentStorage::class);
        \assert($service instanceof AttachmentStorage);
        $baseDir = $service->getBaseDir();

        $this->probeFile = $baseDir.'/backup-roundtrip/probe.bin';
        $this->probeContent = random_bytes(1024);
        if (!is_dir(dirname($this->probeFile))) {
            mkdir(dirname($this->probeFile), 0o770, true);
        }
        file_put_contents($this->probeFile, $this->probeContent);

        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $this->backupDir = rtrim($projectDir, '/').'/data/backups';
        $this->clearBackupFiles();
    }

    protected function tearDown(): void
    {
        $this->clearBackupFiles();
        @unlink($this->probeFile);
        @rmdir(dirname($this->probeFile));
        parent::tearDown();
    }

    private function clearBackupFiles(): void
    {
        if (!is_dir($this->backupDir)) {
            return;
        }

        foreach (scandir($this->backupDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->backupDir.'/'.$entry);
            }
        }
    }

    public function testFileRoundtripRestoresDeletedAttachmentFiles(): void
    {
        $backups = static::getContainer()->get(BackupService::class);
        \assert($backups instanceof BackupService);
        $restore = static::getContainer()->get(BackupRestoreService::class);
        \assert($restore instanceof BackupRestoreService);

        $filename = $backups->create(true, null);
        unlink($this->probeFile);
        self::assertFileDoesNotExist($this->probeFile);

        $result = $restore->restore($this->backupDir.'/'.$filename, null, false);

        self::assertGreaterThanOrEqual(1, $result->fileCount);
        self::assertTrue($result->includesFiles);
        self::assertFileExists($this->probeFile);
        self::assertSame($this->probeContent, (string) file_get_contents($this->probeFile));
    }

    public function testDatabaseRoundtripRecoversFromWipedData(): void
    {
        $backups = static::getContainer()->get(BackupService::class);
        \assert($backups instanceof BackupService);
        $restore = static::getContainer()->get(BackupRestoreService::class);
        \assert($restore instanceof BackupRestoreService);
        $purger = static::getContainer()->get(DatabasePurger::class);
        \assert($purger instanceof DatabasePurger);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $connection = $em->getConnection();

        $admin = new User();
        $admin->setEmail('admin@example.com');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword(password_hash('admin', PASSWORD_BCRYPT));
        $em->persist($admin);
        $em->flush();

        $filename = $backups->create(false, 'another-secret');

        $purger->purge();
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user'));

        $result = $restore->restore($this->backupDir.'/'.$filename, 'another-secret');

        self::assertGreaterThan(0, $result->tableCount);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user'));
    }

    public function testRestoreMigratesToCurrentVersionByDefault(): void
    {
        $backups = static::getContainer()->get(BackupService::class);
        \assert($backups instanceof BackupService);
        $restore = static::getContainer()->get(BackupRestoreService::class);
        \assert($restore instanceof BackupRestoreService);
        $appService = static::getContainer()->get(AppService::class);
        \assert($appService instanceof AppService);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);

        $admin = new User();
        $admin->setEmail('admin@example.com');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword(password_hash('admin', PASSWORD_BCRYPT));
        $em->persist($admin);
        $em->flush();

        $filename = $backups->create(false, null);
        $result = $restore->restore($this->backupDir.'/'.$filename, null, false);

        self::assertNotNull($result->migration);
        self::assertContains(
            $result->migration->mode,
            [MigrationResult::MODE_MIGRATIONS, MigrationResult::MODE_SCHEMA_SYNC],
        );
        self::assertSame($appService->getVersion(), $result->backupAppVersion);
        self::assertGreaterThan(0, $result->tableCount);

        // After the migration step the tracking is a clean subset of the
        // current migration history (or converged via schema sync).
        $connection = $em->getConnection();
        self::assertContains(
            'doctrine_migration_versions',
            $connection->createSchemaManager()->listTableNames(),
            'The migration tracking table must survive the migration step.',
        );
        self::assertNotSame(
            [],
            $connection->executeQuery('SELECT version FROM doctrine_migration_versions')->fetchFirstColumn(),
        );

        // The restored data survived the migration step.
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user'));
    }

    public function testRestoreWithCleanTrackingRunsPendingMigrations(): void
    {
        $backups = static::getContainer()->get(BackupService::class);
        \assert($backups instanceof BackupService);
        $restore = static::getContainer()->get(BackupRestoreService::class);
        \assert($restore instanceof BackupRestoreService);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $connection = $em->getConnection();
        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');

        // A tracking state matching the current migration history (the
        // storage records the namespaced class name) must take the clean
        // migration path — e.g. a backup of an installation whose schema
        // is only a few migrations behind.
        $available = [];
        foreach (glob($projectDir.'/migrations/Version*.php') ?: [] as $file) {
            if (1 === preg_match('/Version(\d+)\.php$/', (string) $file, $matches)) {
                $available[] = 'DoctrineMigrations\\Version'.$matches[1];
            }
        }
        self::assertNotSame([], $available);

        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS doctrine_migration_versions (version VARCHAR(255) NOT NULL, PRIMARY KEY(version))',
        );
        $connection->executeStatement('DELETE FROM doctrine_migration_versions');
        foreach ($available as $version) {
            $connection->executeStatement(
                'INSERT INTO doctrine_migration_versions (version) VALUES (?)',
                [$version],
            );
        }

        $admin = new User();
        $admin->setEmail('admin@example.com');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword(password_hash('admin', PASSWORD_BCRYPT));
        $em->persist($admin);
        $em->flush();

        $filename = $backups->create(false, null);
        $result = $restore->restore($this->backupDir.'/'.$filename, null, false);

        self::assertNotNull($result->migration);
        self::assertSame(MigrationResult::MODE_MIGRATIONS, $result->migration->mode);
        self::assertSame(0, $result->migration->migrationCount);
        self::assertGreaterThan(0, $result->tableCount);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user'));
    }

    public function testRestoreWithoutMigrationSkipsMigrationStep(): void
    {
        $backups = static::getContainer()->get(BackupService::class);
        \assert($backups instanceof BackupService);
        $restore = static::getContainer()->get(BackupRestoreService::class);
        \assert($restore instanceof BackupRestoreService);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $connection = $em->getConnection();

        $admin = new User();
        $admin->setEmail('admin@example.com');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setPassword(password_hash('admin', PASSWORD_BCRYPT));
        $em->persist($admin);
        $em->flush();

        $filename = $backups->create(false, null);
        $result = $restore->restore($this->backupDir.'/'.$filename, null, false, false);

        self::assertNull($result->migration);
        self::assertGreaterThan(0, $result->tableCount);
        // The restored tracking matches the backup's own state; the schema
        // is usable, the migration to the current version stays pending.
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM app_user'));
    }
}
