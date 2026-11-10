<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Service\Backup\BackupSchedulerService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Long-running scheduler loop for automatic backups: wakes up every few
 * seconds and hands over to the scheduler, which only acts when the
 * admin-configured interval has elapsed. Settings are re-read on every
 * pass, so changes on the admin backup page apply without a restart.
 *
 * The production entrypoint starts this loop in the background next to
 * the web server (disable with AVALYNXOFFICE_BACKUP_DAEMON=off). The
 * All-Inkl style alternative is the cron URL / app:backup:run.
 */
#[AsCommand(
    name: 'app:backup:schedule',
    description: 'Scheduler loop: creates automatic backups when the configured interval has elapsed.',
)]
final class BackupScheduleCommand extends Command
{
    public function __construct(
        private readonly BackupSchedulerService $scheduler,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between the wake-up checks.', '60')
            ->addOption('max-runs', null, InputOption::VALUE_REQUIRED, 'Stop after this number of checks (0 = run forever).', '0')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $intervalOption = $input->getOption('interval');
        $interval = max(5, is_numeric($intervalOption) ? (int) $intervalOption : 60);
        $maxRunsOption = $input->getOption('max-runs');
        $maxRuns = max(0, is_numeric($maxRunsOption) ? (int) $maxRunsOption : 0);

        $io->success(sprintf(
            'Auto-Backup-Scheduler gestartet (Prüfabstand: %ds, Einstellungen werden pro Durchlauf neu gelesen).',
            $interval,
        ));

        $runs = 0;
        while (0 === $maxRuns || $runs < $maxRuns) {
            ++$runs;
            $result = $this->scheduler->maybeRun();

            if ($result['ran'] && is_string($result['filename'])) {
                $io->success(sprintf('Automatisches Backup erstellt: %s', $result['filename']));
            }

            if (0 === $maxRuns || $runs < $maxRuns) {
                sleep($interval);
            }
        }

        $io->note('Auto-Backup-Scheduler beendet.');

        return Command::SUCCESS;
    }
}
