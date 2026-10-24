<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Entity\Connector;
use AvalynxOffice\Repository\ConnectorRepository;
use AvalynxOffice\Service\Mail\Pop3Fetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:mail:fetch',
    description: 'Fetches mail via POP3 for all connectors and routes each message to its account.'
)]
class MailFetchCommand extends Command
{
    public function __construct(
        private readonly ConnectorRepository $connectorRepo,
        private readonly Pop3Fetcher $fetcher,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('connector', null, InputOption::VALUE_REQUIRED, 'Connector-ID (UUID) oder Domain. Wenn nicht gesetzt, werden alle Connectoren abgerufen.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of messages to process per connector.', null)
            ->addOption('oldest', null, InputOption::VALUE_NONE, 'Process the oldest messages first (default: newest first).')
            ->addOption('delete-after', null, InputOption::VALUE_NONE, 'Delete the message from the server after a successful import (overrides the connector setting).')
            ->addOption('error-log', null, InputOption::VALUE_REQUIRED, 'Path for a detailed error log (with UIDL, subject and trace).');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connectorFilter = $input->getOption('connector');
        $connectorFilter = is_string($connectorFilter) && '' !== $connectorFilter ? $connectorFilter : null;
        $limitOpt = $input->getOption('limit');
        $limit = is_numeric($limitOpt) ? (int) $limitOpt : null;
        $oldest = (bool) $input->getOption('oldest');
        $deleteAfterOpt = $input->getOption('delete-after');
        $deleteAfter = $deleteAfterOpt ? true : null; // null = use connector default
        $errorLogOpt = $input->getOption('error-log');
        $errorLogPath = is_string($errorLogOpt) && '' !== $errorLogOpt ? $errorLogOpt : null;
        if ($errorLogPath) {
            $dir = dirname($errorLogPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $output->writeln(sprintf('<info>Fehler-Log: %s</info>', $errorLogPath));
        }
        $connectors = [];
        if ($connectorFilter) {
            $connector = null;
            try {
                $connector = $this->connectorRepo->find($connectorFilter);
            } catch (\Throwable) {
            }
            if (!$connector instanceof Connector) {
                $connector = $this->connectorRepo->findOneBy(['domain' => strtolower($connectorFilter)]);
            }
            if (!$connector instanceof Connector) {
                $output->writeln('<error>Connector not found: '.$connectorFilter.'</error>');

                return Command::FAILURE;
            }
            $connectors = [$connector];
        } else {
            $connectors = $this->connectorRepo->findBy([], ['name' => 'ASC']);
        }
        if (!$connectors) {
            $output->writeln('<comment>Keine Connectoren vorhanden.</comment>');

            return Command::SUCCESS;
        }
        $totalImported = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        foreach ($connectors as $connector) {
            $label = sprintf('%s (%s)', $connector->getName(), $connector->getDomain());
            $output->writeln(sprintf('<info>[%s]</info> Abruf startet …', $label));
            $this->fetcher->setOutput($output);
            $this->fetcher->setErrorLogPath($errorLogPath);
            $result = $this->fetcher->fetchForConnector($connector, $limit, $deleteAfter, $oldest);
            $output->writeln(sprintf('   imported: %d, skipped: %d, errors: %d', $result['imported'], $result['skipped'], $result['errors']));
            $totalImported += $result['imported'];
            $totalSkipped += $result['skipped'];
            $totalErrors += $result['errors'];
        }
        $output->writeln('—');
        $output->writeln(sprintf('<info>Done.</info> Total: imported=%d, skipped=%d, errors=%d', $totalImported, $totalSkipped, $totalErrors));

        return $totalErrors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
