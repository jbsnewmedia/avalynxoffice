<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Entity\SyncRun;
use AvalynxOffice\Service\Sync\SyncService;
use AvalynxOffice\Service\Sync\SyncSettingsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running sync service: syncs a batch of mails, contacts and calendar
 * entries, then pauses for the configured interval. Intended to run as a
 * dedicated container process next to the web server. The batch sizes and
 * pauses are configured in the admin system status page and re-read on
 * every pass, so changes apply without a restart.
 */
#[AsCommand(
    name: 'app:sync:daemon',
    description: 'Sync service loop: syncs mails, contacts and calendar entries, then pauses.',
)]
class SyncDaemonCommand extends Command
{
    public function __construct(
        private readonly SyncService $syncService,
        private readonly SyncSettingsService $syncSettings,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('max-runs', null, InputOption::VALUE_REQUIRED, 'Stop after this number of passes (0 = run forever).', '0')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxRunsOption = $input->getOption('max-runs');
        $maxRuns = max(0, is_numeric($maxRunsOption) ? (int) $maxRunsOption : 0);

        $settings = $this->syncSettings->all();
        $output->writeln(sprintf(
            '<info>Sync-Dienst gestartet (Intervall: %ds, Backfill-Intervall: %ds, Mails: %d, Kontakte: %d, Termine: %d pro Lauf).</info>',
            $settings['intervalSeconds'],
            $settings['busyIntervalSeconds'],
            $settings['mailsLimit'],
            $settings['contactsLimit'],
            $settings['calendarLimit'],
        ));

        $runs = 0;
        while (0 === $maxRuns || $runs < $maxRuns) {
            // Re-read the settings on every pass: admin changes apply
            // without restarting the service.
            $settings = $this->syncSettings->all();

            $run = $this->syncService->run(
                [
                    'mails' => $settings['mailsLimit'],
                    'contacts' => $settings['contactsLimit'],
                    'calendar' => $settings['calendarLimit'],
                    'oldestFirst' => true,
                ],
                SyncRun::TRIGGER_DAEMON,
            );
            ++$runs;

            $imported = $run->getMailsImported() + $run->getContactsCount() + $run->getCalendarCount();

            $output->writeln('['.new \DateTimeImmutable()->format('Y-m-d H:i:s').'] Durchlauf #'.$runs.': '.$run->getStatus()
                .' — Mails: '.$run->getMailsImported().' importiert, '.$run->getMailsSkipped().' übersprungen, '.$run->getMailsErrors().' Fehler.'
                .' — Pause: '.($imported > 0 ? $settings['busyIntervalSeconds'] : $settings['intervalSeconds']).'s.');

            if (0 !== $maxRuns && $runs >= $maxRuns) {
                break;
            }

            // Entries are flowing: keep the backfill running with the short
            // busy pause. The system is drained: wait for the idle interval.
            $pause = $imported > 0 ? $settings['busyIntervalSeconds'] : $settings['intervalSeconds'];
            if ($pause > 0) {
                sleep($pause);
            }
        }

        $output->writeln('<info>Sync-Dienst beendet.</info>');

        return Command::SUCCESS;
    }
}
