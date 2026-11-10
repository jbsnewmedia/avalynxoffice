<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Backup;

use AvalynxOffice\Service\Backup\BackupRestoreService;
use AvalynxOffice\Service\Backup\BackupService;
use AvalynxOffice\Service\DatabasePurger;
use AvalynxOffice\Service\Mail\AttachmentStorage;
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
}
