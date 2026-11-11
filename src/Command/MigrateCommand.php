<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Service\Migration\DatabaseMigrator;
use AvalynxOffice\Service\Migration\MigrationResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Brings the database of this installation to the current version while
 * keeping all data. Runs at every container start (see the entrypoint)
 * and can be run manually after an image update.
 *
 * Installations whose migration tracking matches the current history get
 * the pending migrations applied directly. Diverging or missing tracking
 * (installations from before a rewritten baseline migration) is re-marked
 * and the schema is converged to the current entity metadata — no backup
 * is needed for that.
 */
#[AsCommand(
    name: 'app:migrate',
    description: 'Brings the database to the current installation version, keeping all data.',
)]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly DatabaseMigrator $migrator,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
This command migrates the database of the installation to the current
version without losing any data:

  * If the recorded migration history matches the current migrations,
    only the pending migrations are executed.
  * If the history is unknown or incomplete (for example installations
    created before the baseline migration was rewritten), all current
    migrations are marked as executed and the schema is converged to the
    current entity metadata. Existing rows keep their values.

The command runs automatically at every container start.
HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Database migration');

        try {
            $result = $this->migrator->migrateToCurrent($output);
        } catch (\RuntimeException $exception) {
            $io->error('The migration failed: '.$exception->getMessage());

            return Command::FAILURE;
        }

        if (MigrationResult::MODE_SCHEMA_SYNC === $result->mode) {
            $io->success('The database is at the current version (schema converged — the recorded migration history was unknown or incomplete; all data was kept).');
        } else {
            $io->success(sprintf('The database is at the current version (%d migration(s) applied).', $result->migrationCount));
        }

        return Command::SUCCESS;
    }
}
