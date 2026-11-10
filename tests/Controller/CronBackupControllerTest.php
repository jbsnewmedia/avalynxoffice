<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Controller;

use AvalynxOffice\Service\Backup\BackupScheduleService;
use AvalynxOffice\Service\Backup\BackupService;
use AvalynxOffice\Tests\Support\RefreshDatabaseTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[Group('integration')]
#[Group('functional')]
final class CronBackupControllerTest extends WebTestCase
{
    use RefreshDatabaseTrait;

    private KernelBrowser $client;

    private string $backupDir;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->refreshDatabase($em);

        $projectDir = (string) static::getContainer()->getParameter('kernel.project_dir');
        $this->backupDir = rtrim($projectDir, '/').'/data/backups';
        $this->clearBackupFiles();
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

    private function clearBackupFiles(): void
    {
        if (!is_dir($this->backupDir)) {
            return;
        }

        foreach (scandir($this->backupDir) ?: [] as $entry) {
            if (is_string($entry) && $entry !== '.' && $entry !== '..') {
                @unlink($this->backupDir.'/'.$entry);
            }
        }
    }

    private function scheduleService(): BackupScheduleService
    {
        $service = static::getContainer()->get(BackupScheduleService::class);
        \assert($service instanceof BackupScheduleService);

        return $service;
    }

    private function backupService(): BackupService
    {
        $service = static::getContainer()->get(BackupService::class);
        \assert($service instanceof BackupService);

        return $service;
    }

    private function callCron(string $token, array $query = []): array
    {
        $this->client->request('GET', '/cron/backup', array_merge(['token' => $token], $query));

        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        \assert(is_array($data));

        return $data;
    }

    public function testCronRequiresValidToken(): void
    {
        $token = (string) $this->scheduleService()->cronToken();

        $missing = $this->callCron('');
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($missing['success']);
        self::assertSame('invalid token', $missing['message']);

        $wrong = $this->callCron(str_repeat('0', 48));
        self::assertResponseStatusCodeSame(401);
        self::assertFalse($wrong['success']);
        self::assertSame('invalid token', $wrong['message']);

        $valid = $this->callCron($token);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($valid['success']);
    }

    public function testCronDoesNothingWhenDisabled(): void
    {
        $token = (string) $this->scheduleService()->cronToken();

        $result = $this->callCron($token);
        self::assertTrue($result['success']);
        self::assertFalse($result['ran']);
        self::assertSame('admin.backup.auto_reason_disabled', $result['reason']);

        // force=1 must not override the disabled toggle via the public URL.
        $forced = $this->callCron($token, ['force' => '1']);
        self::assertFalse($forced['ran']);
        self::assertCount(0, $this->backupService()->listBackups());
    }

    public function testCronRunsWhenDueAndSkipsUntilIntervalElapsed(): void
    {
        $schedule = $this->scheduleService();
        $schedule->save(['enabled' => true]);
        $token = (string) $schedule->cronToken();

        $result = $this->callCron($token);
        self::assertTrue($result['ran']);
        self::assertIsString($result['filename']);
        self::assertStringContainsString('avalynxoffice-auto-', $result['filename']);

        // Second call: interval not elapsed yet.
        $again = $this->callCron($token);
        self::assertFalse($again['ran']);
        self::assertSame('admin.backup.auto_reason_not_due', $again['reason']);

        // force=1 overrides the due check.
        $forced = $this->callCron($token, ['force' => '1']);
        self::assertTrue($forced['ran']);
        self::assertIsString($forced['filename']);
        self::assertNotSame($result['filename'], $forced['filename']);
    }

    public function testCronRateLimitsBruteForceAttempts(): void
    {
        $token = (string) $this->scheduleService()->cronToken();

        // The in-memory limiter storage lives per kernel: keep one kernel
        // for the whole burst, otherwise every request starts with a
        // freshly reset window.
        $this->client->disableReboot();

        // Burn the 20 allowed sliding-window attempts with wrong tokens.
        for ($i = 0; $i < 20; ++$i) {
            $this->client->request('GET', '/cron/backup', ['token' => str_repeat((string) ($i % 10), 48)]);
            self::assertResponseStatusCodeSame(401);
        }

        // Attempt 21 is rate limited — even with the correct token (the
        // limiter runs before the token check).
        $this->client->request('GET', '/cron/backup', ['token' => $token]);
        self::assertResponseStatusCodeSame(429);
    }
}
