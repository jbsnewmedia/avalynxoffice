<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Service\Backup\BackupSchedulerService;
use AvalynxOffice\Service\Backup\BackupScheduleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs the automatic backup once — the shell-cron variant for hosts
 * without a long-running loop (e.g. All-Inkl KAS script cronjobs):
 *
 *   php /path/to/avalynxoffice/bin/console app:backup:run
 *
 * Without --force the scheduler only acts when the configured interval
 * has elapsed, so the cronjob can run more often than backups are
 * actually created. A flock lock prevents overlapping runs (Docker loop
 * and cron URL share the same scheduler).
 */
#[AsCommand(
    name: 'app:backup:run',
    description: 'Creates an automatic backup when due (shell cronjob variant).',
)]
final class BackupRunCommand extends Command
{
    public function __construct(
        private readonly BackupSchedulerService $scheduler,
        private readonly BackupScheduleService $schedule,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Run even when the configured interval has not elapsed yet')
            ->addOption('ignore-enabled', null, InputOption::VALUE_NONE, 'With --force: run even when automatic backups are disabled')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $result = $this->scheduler->maybeRun(force: $force, ignoreEnabled: (bool) $input->getOption('ignore-enabled'));

        if ($result['ran'] && is_string($result['filename'])) {
            $io->success('Automatisches Backup erstellt: '.$this->schedule->lastResult());

            return Command::SUCCESS;
        }

        $reason = is_string($result['reason']) ? $this->shortReason($result['reason']) : 'unbekannt';
        $io->comment('Kein Backup erstellt ('.$reason.').');

        return Command::SUCCESS;
    }

    private function shortReason(string $translationKey): string
    {
        return match ($translationKey) {
            'admin.backup.auto_reason_disabled' => 'automatische Backups sind deaktiviert',
            'admin.backup.auto_reason_not_due' => 'Intervall noch nicht abgelaufen',
            'admin.backup.auto_reason_locked' => 'läuft bereits in einem anderen Prozess',
            'admin.backup.auto_reason_error' => 'Fehler beim Erstellen (siehe Log / Admin-Backup-Seite)',
            default => $translationKey,
        };
    }
}
