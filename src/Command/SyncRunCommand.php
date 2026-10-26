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
 * Runs a single sync pass (mails, contacts, calendar) and records it for
 * the system status in the admin area. Without options the configured
 * sync service settings are used.
 */
#[AsCommand(
    name: 'app:sync:run',
    description: 'Runs a single sync pass and records it for the system status.',
)]
class SyncRunCommand extends Command
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
            ->addOption('mails', null, InputOption::VALUE_OPTIONAL, 'Maximum number of mails per connector (default: configured value).', null)
            ->addOption('contacts', null, InputOption::VALUE_OPTIONAL, 'Maximum number of contacts per connector (default: configured value).', null)
            ->addOption('calendar', null, InputOption::VALUE_OPTIONAL, 'Maximum number of calendar entries per connector (default: configured value).', null)
            ->addOption('newest-first', null, InputOption::VALUE_NONE, 'Process the newest messages first (default: oldest first).')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $settings = $this->syncSettings->all();

        $mails = $this->optionOrNull($input, 'mails') ?? $settings['mailsLimit'];
        $contacts = $this->optionOrNull($input, 'contacts') ?? $settings['contactsLimit'];
        $calendar = $this->optionOrNull($input, 'calendar') ?? $settings['calendarLimit'];

        $output->writeln('<info>Sync startet …</info>');
        $run = $this->syncService->run(
            ['mails' => $mails, 'contacts' => $contacts, 'calendar' => $calendar, 'oldestFirst' => !$input->getOption('newest-first')],
            SyncRun::TRIGGER_MANUAL,
        );

        $output->writeln(sprintf(
            'Mails: %d importiert, %d übersprungen, %d Fehler.',
            $run->getMailsImported(),
            $run->getMailsSkipped(),
            $run->getMailsErrors(),
        ));
        $output->writeln('Kontakte: '.$run->getContactsCount().', Termine: '.$run->getCalendarCount().'.');

        if (SyncRun::STATUS_ERROR === $run->getStatus()) {
            $output->writeln('<error>Sync fehlgeschlagen: '.($run->getMessage() ?? 'unbekannter Fehler').'</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Sync abgeschlossen.</info>');

        return Command::SUCCESS;
    }

    private function optionOrNull(InputInterface $input, string $name): ?int
    {
        $value = $input->getOption($name);
        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
