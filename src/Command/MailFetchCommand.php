<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Repository\MailboxRepository;
use AvalynxOffice\Service\Mail\Pop3Fetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:mail:fetch',
    description: 'Fetches mail via POP3 for the configured mailboxes and imports it into the database.'
)]
class MailFetchCommand extends Command
{
    public function __construct(
        private readonly MailboxRepository $mailboxRepo,
        private readonly Pop3Fetcher $fetcher,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('mailbox', null, InputOption::VALUE_REQUIRED, 'Mailbox-ID (UUID) oder E-Mail-Adresse. Wenn nicht gesetzt, werden alle Mailboxen mit ihren Connectoren abgerufen.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of messages to process per mailbox.', null)
            ->addOption('oldest', null, InputOption::VALUE_NONE, 'Process the oldest messages first (default: newest first).')
            ->addOption('delete-after', null, InputOption::VALUE_NONE, 'Delete the message from the server after a successful import (overrides the mailbox setting).')
            ->addOption('error-log', null, InputOption::VALUE_REQUIRED, 'Path for a detailed error log (with UIDL, subject and trace).');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $mbFilter = $input->getOption('mailbox');
        $mbFilter = is_string($mbFilter) && '' !== $mbFilter ? $mbFilter : null;
        $limitOpt = $input->getOption('limit');
        $limit = is_numeric($limitOpt) ? (int) $limitOpt : null;
        $oldest = (bool) $input->getOption('oldest');
        $deleteAfterOpt = $input->getOption('delete-after');
        $deleteAfter = $deleteAfterOpt ? true : null; // null = verwende Mailbox-Default
        $errorLogOpt = $input->getOption('error-log');
        $errorLogPath = is_string($errorLogOpt) && '' !== $errorLogOpt ? $errorLogOpt : null;
        if ($errorLogPath) {
            $dir = dirname($errorLogPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $output->writeln(sprintf('<info>Fehler-Log: %s</info>', $errorLogPath));
        }
        $mailboxes = [];
        if ($mbFilter) {
            $mb = null;
            try {
                $mb = $this->mailboxRepo->find($mbFilter);
            } catch (\Throwable) {
            }
            if (!$mb) {
                $mb = $this->mailboxRepo->findOneBy(['email' => $mbFilter]);
            }
            if (!$mb) {
                $output->writeln('<error>Mailbox not found: '.$mbFilter.'</error>');

                return Command::FAILURE;
            }
            $mailboxes = [$mb];
        } else {
            $mailboxes = $this->mailboxRepo->findAll();
        }
        if (!$mailboxes) {
            $output->writeln('<comment>Keine Mailboxen vorhanden.</comment>');

            return Command::SUCCESS;
        }
        $totalImported = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        $processedConnectors = 0;
        foreach ($mailboxes as $mb) {
            $connectors = $mb->getConnectors();
            if ($connectors->isEmpty()) {
                $output->writeln(sprintf('<comment>[%s]</comment> No connector configured.', $mb->getEmail()));
                continue;
            }
            foreach ($connectors as $connector) {
                ++$processedConnectors;
                $label = sprintf('%s / %s', $mb->getEmail(), $connector->getEmail());
                $output->writeln(sprintf('<info>[%s]</info> Abruf startet …', $label));
                $this->fetcher->setOutput($output);
                $this->fetcher->setErrorLogPath($errorLogPath);
                $result = $this->fetcher->fetchForConnector($connector, $limit, $deleteAfter, $oldest);
                $output->writeln(sprintf('   imported: %d, skipped: %d, errors: %d', $result['imported'], $result['skipped'], $result['errors']));
                $totalImported += $result['imported'];
                $totalSkipped += $result['skipped'];
                $totalErrors += $result['errors'];
            }
        }
        if (0 === $processedConnectors) {
            $output->writeln('<comment>Keine Connectoren vorhanden.</comment>');

            return Command::SUCCESS;
        }
        $output->writeln('—');
        $output->writeln(sprintf('<info>Done.</info> Total: imported=%d, skipped=%d, errors=%d', $totalImported, $totalSkipped, $totalErrors));

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
