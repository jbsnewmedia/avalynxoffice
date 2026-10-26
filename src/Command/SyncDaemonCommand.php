<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Entity\SyncRun;
use AvalynxOffice\Service\Sync\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running sync service: syncs a batch of mails, contacts and calendar
 * entries, then pauses for the configured interval. Intended to run as a
 * dedicated container process next to the web server.
 */
#[AsCommand(
    name: 'app:sync:daemon',
    description: 'Sync service loop: syncs mails, contacts and calendar entries, then pauses.',
)]
class SyncDaemonCommand extends Command
{
    public function __construct(
        private readonly SyncService $syncService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Pause between passes in seconds.', '300')
            ->addOption('mails', null, InputOption::VALUE_REQUIRED, 'Maximum number of mails per connector and pass.', '100')
            ->addOption('contacts', null, InputOption::VALUE_REQUIRED, 'Maximum number of contacts per connector and pass.', '100')
            ->addOption('calendar', null, InputOption::VALUE_REQUIRED, 'Maximum number of calendar entries per connector and pass.', '100')
            ->addOption('max-runs', null, InputOption::VALUE_REQUIRED, 'Stop after this number of passes (0 = run forever).', '0')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $intervalOption = $input->getOption('interval');
        $interval = max(0, is_numeric($intervalOption) ? (int) $intervalOption : 300);
        $mailsOption = $input->getOption('mails');
        $mails = is_numeric($mailsOption) ? (int) $mailsOption : 100;
        $contactsOption = $input->getOption('contacts');
        $contacts = is_numeric($contactsOption) ? (int) $contactsOption : 100;
        $calendarOption = $input->getOption('calendar');
        $calendar = is_numeric($calendarOption) ? (int) $calendarOption : 100;
        $maxRunsOption = $input->getOption('max-runs');
        $maxRuns = max(0, is_numeric($maxRunsOption) ? (int) $maxRunsOption : 0);

        $output->writeln(sprintf('<info>Sync-Dienst gestartet (Intervall: %ds, Mails pro Lauf: %d).</info>', $interval, $mails));

        $runs = 0;
        while (0 === $maxRuns || $runs < $maxRuns) {
            $run = $this->syncService->run(
                ['mails' => $mails, 'contacts' => $contacts, 'calendar' => $calendar, 'oldestFirst' => true],
                SyncRun::TRIGGER_DAEMON,
            );
            ++$runs;

            $output->writeln('['.new \DateTimeImmutable()->format('Y-m-d H:i:s').'] Durchlauf #'.$runs.': '.$run->getStatus()
                .' — Mails: '.$run->getMailsImported().' importiert, '.$run->getMailsSkipped().' übersprungen, '.$run->getMailsErrors().' Fehler.');

            if (0 !== $maxRuns && $runs >= $maxRuns) {
                break;
            }

            if ($interval > 0) {
                sleep($interval);
            }
        }

        $output->writeln('<info>Sync-Dienst beendet.</info>');

        return Command::SUCCESS;
    }
}
