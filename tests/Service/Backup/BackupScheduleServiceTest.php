<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Service\Backup;

use AvalynxOffice\Service\Backup\BackupScheduleService;
use AvalynxOffice\Service\Backup\BackupSchedulerService;
use AvalynxOffice\Service\Backup\BackupService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
final class BackupScheduleServiceTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private string $backupDir;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->refreshDatabase($em);

        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $this->backupDir = rtrim($projectDir, '/').'/data/backups';
        if (is_dir($this->backupDir)) {
            foreach (scandir($this->backupDir) ?: [] as $entry) {
                if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                    @unlink($this->backupDir.'/'.$entry);
                }
            }
        }
    }

    protected function tearDown(): void
    {
        if (is_dir($this->backupDir)) {
            foreach (scandir($this->backupDir) ?: [] as $entry) {
                if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                    @unlink($this->backupDir.'/'.$entry);
                }
            }
        }

        parent::tearDown();
    }

    private function scheduleService(): BackupScheduleService
    {
        $service = static::getContainer()->get(BackupScheduleService::class);
        \assert($service instanceof BackupScheduleService);

        return $service;
    }

    private function schedulerService(): BackupSchedulerService
    {
        $service = static::getContainer()->get(BackupSchedulerService::class);
        \assert($service instanceof BackupSchedulerService);

        return $service;
    }

    private function backupService(): BackupService
    {
        $service = static::getContainer()->get(BackupService::class);
        \assert($service instanceof BackupService);

        return $service;
    }

    public function testSettingsDefaultsAndClamping(): void
    {
        $schedule = $this->scheduleService();

        $defaults = $schedule->all();
        self::assertFalse($defaults['enabled']);
        self::assertSame(BackupScheduleService::DEFAULT_INTERVAL_HOURS, $defaults['intervalHours']);
        self::assertTrue($defaults['includeFiles']);
        self::assertSame(BackupScheduleService::DEFAULT_RETENTION, $defaults['retention']);

        $schedule->save([
            'enabled' => true,
            'intervalHours' => 9999,
            'includeFiles' => false,
            'retention' => 0,
        ]);

        $stored = $schedule->all();
        self::assertTrue($stored['enabled']);
        self::assertSame(BackupScheduleService::MAX_INTERVAL_HOURS, $stored['intervalHours']);
        self::assertFalse($stored['includeFiles']);
        self::assertSame(BackupScheduleService::MIN_RETENTION, $stored['retention']);
    }

    public function testIsDueAndNextRun(): void
    {
        $schedule = $this->scheduleService();

        // Disabled: never due, no next run.
        self::assertFalse($schedule->isDue());
        self::assertNull($schedule->nextRunAt());

        // Enabled without a previous run: due immediately.
        $schedule->save(['enabled' => true]);
        self::assertTrue($schedule->isDue());
        self::assertNotNull($schedule->nextRunAt());

        // Recent run: not due, next run = last run + interval.
        $recent = new \DateTimeImmutable('-30 minutes');
        $schedule->save(['intervalHours' => 1]);
        $schedule->recordResult('ok: test.aobak', $recent);
        self::assertFalse($schedule->isDue());
        self::assertSame(
            $recent->modify('+1 hour')->getTimestamp(),
            $schedule->nextRunAt()?->getTimestamp(),
        );

        // Interval elapsed: due again.
        $schedule->recordResult('ok: test.aobak', new \DateTimeImmutable('-2 hours'));
        self::assertTrue($schedule->isDue());
    }

    public function testCronTokenIsStableAndRegenerable(): void
    {
        $schedule = $this->scheduleService();

        $token = $schedule->cronToken();
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', (string) $token);
        self::assertSame($token, $schedule->cronToken());

        $regenerated = $schedule->regenerateCronToken();
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', $regenerated);
        self::assertNotSame($token, $regenerated);
        self::assertSame($regenerated, $schedule->cronToken());
    }

    public function testMaybeRunCreatesAutoBackupAndRecordsResult(): void
    {
        $schedule = $this->scheduleService();
        $schedule->save(['enabled' => true]);

        $result = $this->schedulerService()->maybeRun();

        self::assertTrue($result['ran']);
        self::assertIsString($result['filename']);
        self::assertStringContainsString('avalynxoffice-auto-', $result['filename']);
        self::assertStringStartsWith('ok:', (string) $schedule->lastResult());

        // Interval elapsed? No — just ran: a second pass does nothing.
        $second = $this->schedulerService()->maybeRun();
        self::assertFalse($second['ran']);
        self::assertSame('admin.backup.auto_reason_not_due', $second['reason']);
    }

    public function testMaybeRunSkipsWhenDisabled(): void
    {
        $result = $this->schedulerService()->maybeRun();

        self::assertFalse($result['ran']);
        self::assertSame('admin.backup.auto_reason_disabled', $result['reason']);
        self::assertCount(0, $this->backupService()->listBackups());
    }

    public function testMaybeRunLockPreventsParallelRuns(): void
    {
        $schedule = $this->scheduleService();
        $schedule->save(['enabled' => true]);

        // Hold the scheduler lock like a parallel trigger would.
        $lockHandle = fopen($this->backupDir.'/.scheduler.lock', 'c');
        \assert(is_resource($lockHandle));
        self::assertTrue(flock($lockHandle, LOCK_EX | LOCK_NB));

        try {
            $result = $this->schedulerService()->maybeRun(force: true);
            self::assertFalse($result['ran']);
            self::assertSame('admin.backup.auto_reason_locked', $result['reason']);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function testRetentionPrunesOnlyAutoBackups(): void
    {
        $schedule = $this->scheduleService();
        $schedule->save(['enabled' => true, 'retention' => 2]);

        $service = $this->backupService();
        $manual = $service->create(false, null);
        $autoOne = $service->create(false, null, 'auto');
        $autoTwo = $service->create(false, null, 'auto');
        $autoThree = $service->create(false, null, 'auto');

        $deleted = $this->schedulerService()->pruneRetention(2);

        self::assertSame(1, $deleted);
        $names = array_map(
            static fn ($info): string => $info->filename,
            $service->listBackups(),
        );
        self::assertCount(3, $names);
        self::assertContains($manual, $names);
        self::assertContains($autoTwo, $names);
        self::assertContains($autoThree, $names);
        self::assertNotContains($autoOne, $names);
    }
}
