<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Backup;

use Psr\Log\LoggerInterface;

/**
 * Runs automatic backups when they are due, keeps the configured number
 * of newest auto backups (retention) and records the outcome for the
 * admin UI.
 *
 * All triggers (Docker loop, All-Inkl cron URL, shell cron) funnel into
 * {@see maybeRun()}. A flock lock below data/backups prevents parallel
 * runs when several triggers race.
 */
final class BackupSchedulerService
{
    /** @var resource|null */
    private $lockHandle;

    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupScheduleService $schedule,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Runs an auto backup when it is due.
     *
     * @param bool $force         run even when not due (admin trigger)
     * @param bool $ignoreEnabled ignore the enabled toggle (CLI --force)
     *
     * @return array{ran: bool, filename: string|null, reason: string|null} reason is
     *                                                                      null on success, otherwise a translation key describing why
     *                                                                      nothing ran ("disabled", "not_due", "locked")
     */
    public function maybeRun(bool $force = false, bool $ignoreEnabled = false): array
    {
        if (!$force && !$this->schedule->isDue()) {
            return ['ran' => false, 'filename' => null, 'reason' => $this->schedule->all()['enabled'] ? 'admin.backup.auto_reason_not_due' : 'admin.backup.auto_reason_disabled'];
        }

        if ($force && !$ignoreEnabled && !$this->schedule->all()['enabled']) {
            return ['ran' => false, 'filename' => null, 'reason' => 'admin.backup.auto_reason_disabled'];
        }

        if (!$this->acquireLock()) {
            return ['ran' => false, 'filename' => null, 'reason' => 'admin.backup.auto_reason_locked'];
        }

        try {
            $settings = $this->schedule->all();
            $filename = $this->backups->create($settings['includeFiles'], null, 'auto');
            $this->pruneRetention($settings['retention']);

            $this->schedule->recordResult('ok: '.$filename);
            $this->logger->info('Automatic backup created: {filename}', ['filename' => $filename]);

            return ['ran' => true, 'filename' => $filename, 'reason' => null];
        } catch (\Throwable $throwable) {
            $message = substr($throwable->getMessage(), 0, 200);
            $this->schedule->recordResult('error: '.$message);
            $this->logger->error('Automatic backup failed: {message}', ['message' => $throwable->getMessage()]);

            return ['ran' => false, 'filename' => null, 'reason' => 'admin.backup.auto_reason_error'];
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Deletes the oldest auto backups beyond the retention count. Only
     * "avalynxoffice-auto-*" archives are pruned — manual, uploaded and
     * safety backups are never touched.
     */
    public function pruneRetention(int $retention): int
    {
        $autoBackups = array_values(array_filter(
            $this->backups->listBackups(),
            static fn (BackupFileInfo $info): bool => str_contains($info->filename, 'avalynxoffice-auto-'),
        ));

        $deleted = 0;
        if (\count($autoBackups) > $retention) {
            // listBackups() is sorted newest first.
            foreach (\array_slice($autoBackups, $retention) as $info) {
                try {
                    $this->backups->delete($info->filename);
                    ++$deleted;
                } catch (\RuntimeException) {
                    // Unreadable/leftover file: skip, never break the
                    // scheduler over cleanup.
                }
            }
        }

        return $deleted;
    }

    /**
     * flock on a lock file below data/backups; auto-releases when the
     * process dies. Returns false when another run holds the lock.
     */
    private function acquireLock(): bool
    {
        $handle = @fopen($this->backups->getBackupDir().'/.scheduler.lock', 'c');
        if (false === $handle) {
            return false;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        // The handle must stay open for the whole run — closing (or
        // garbage collecting) it releases the lock.
        $this->lockHandle = $handle;
        fwrite($handle, (string) getmypid());

        return true;
    }

    private function releaseLock(): void
    {
        if (null === $this->lockHandle) {
            return;
        }

        // The lock file itself stays behind (a plain dotfile) — deleting a
        // locked file would open a race window for the next acquirer.
        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }
}
