<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mail:clear',
    description: 'Leert die Tabellen Address, Attachment, Body, Email, EmailAddressLink, EmailAttachmentLink, EmailBodyLink, EmailFolder, Folder, FolderAccount',
)]
class MailClearCommand extends Command
{
    /**
     * @var array<string>
     */
    private array $tables = [
        'email_address_link',
        'email_attachment_link',
        'email_body_link',
        'email_folder',
        'account_folder',
        'address',
        'attachment',
        'body',
        'email',
        'folder',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->getOption('yes') && !$io->confirm('Are you sure you want to delete all mail data (emails, attachments, contacts, folders)?', false)) {
            $io->note('Operation aborted.');

            return Command::SUCCESS;
        }
        $connection = $this->entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();
        try {
            $platformName = strtolower(new \ReflectionClass($platform)->getShortName());
            if (str_contains($platformName, 'sqlite')) {
                $connection->executeStatement('PRAGMA foreign_keys = OFF');
            } elseif (str_contains($platformName, 'postgresql')) {
                $connection->executeStatement("SET session_replication_role = 'replica'");
            } else {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            }
            $connection->beginTransaction();
            if (str_contains($platformName, 'postgresql')) {
                $connection->executeStatement('UPDATE account SET inbox_folder_id = NULL');
                $tableList = implode(', ', $this->tables);
                $io->write('Leere alle Tabellen in PostgreSQL... ');
                try {
                    $connection->executeStatement(sprintf('TRUNCATE %s', $tableList));
                    $io->writeln('succeeded.');
                } catch (\Exception) {
                    $io->writeln('failed. Trying DELETE FROM...');
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                        $connection->beginTransaction();
                    }
                    foreach ($this->tables as $table) {
                        $connection->executeStatement('DELETE FROM '.$table);
                    }
                    $io->writeln('All tables cleared with DELETE.');
                }
            } else {
                try {
                    $connection->executeStatement('UPDATE account SET inbox_folder_id = NULL');
                } catch (\Exception) {
                }
                foreach ($this->tables as $table) {
                    $io->write(sprintf('Leere Tabelle <info>%s</info>... ', $table));
                    try {
                        $connection->executeStatement($platform->getTruncateTableSQL($table));
                    } catch (\Exception) {
                        $connection->executeStatement('DELETE FROM '.$table);
                    }
                    $io->writeln('succeeded.');
                }
            }
            $connection->commit();
            if (str_contains($platformName, 'sqlite')) {
                $connection->executeStatement('PRAGMA foreign_keys = ON');
            } elseif (str_contains($platformName, 'postgresql')) {
                $connection->executeStatement("SET session_replication_role = 'origin'");
            } else {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
            $io->success('All tables have been cleared successfully.');
        } catch (\Exception $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $platformName = strtolower(new \ReflectionClass($platform)->getShortName());
            if (str_contains($platformName, 'sqlite')) {
                $connection->executeStatement('PRAGMA foreign_keys = ON');
            } elseif (str_contains($platformName, 'postgresql')) {
                $connection->executeStatement("SET session_replication_role = 'origin'");
            } else {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }
            $io->error('Failed to clear the tables: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
