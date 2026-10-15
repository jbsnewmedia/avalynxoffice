<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mail:clear',
    description: 'Leert die Tabellen Address, Attachment, Body, Email, EmailAddressLink, EmailAttachmentLink, EmailBodyLink, EmailFolder, Folder, FolderMailbox',
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
        'mailbox_folder',
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
        $this->addOption('yes', 'y', InputOption::VALUE_NONE, 'Bestätigung überspringen');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->getOption('yes') && !$io->confirm('Sind Sie sicher, dass Sie alle Mail-Daten (Emails, Anhänge, Kontakte, Ordner) löschen wollen?', false)) {
            $io->note('Vorgang abgebrochen.');

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
                $connection->executeStatement('UPDATE mailbox SET inbox_folder_id = NULL');
                $tableList = implode(', ', $this->tables);
                $io->write('Leere alle Tabellen in PostgreSQL... ');
                try {
                    $connection->executeStatement(sprintf('TRUNCATE %s', $tableList));
                    $io->writeln('erfolgreich.');
                } catch (\Exception) {
                    $io->writeln('fehlgeschlagen. Versuche DELETE FROM...');
                    if ($connection->isTransactionActive()) {
                        $connection->rollBack();
                        $connection->beginTransaction();
                    }
                    foreach ($this->tables as $table) {
                        $connection->executeStatement('DELETE FROM '.$table);
                    }
                    $io->writeln('Alle Tabellen mit DELETE gelöscht.');
                }
            } else {
                try {
                    $connection->executeStatement('UPDATE mailbox SET inbox_folder_id = NULL');
                } catch (\Exception) {
                }
                foreach ($this->tables as $table) {
                    $io->write(sprintf('Leere Tabelle <info>%s</info>... ', $table));
                    try {
                        $connection->executeStatement($platform->getTruncateTableSQL($table));
                    } catch (\Exception) {
                        $connection->executeStatement('DELETE FROM '.$table);
                    }
                    $io->writeln('erfolgreich.');
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
            $io->success('Alle Tabellen wurden erfolgreich geleert.');
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
            $io->error('Fehler beim Leeren der Tabellen: '.$e->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
