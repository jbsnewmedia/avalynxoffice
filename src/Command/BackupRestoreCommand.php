<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Service\Backup\BackupRestoreService;
use AvalynxOffice\Service\Backup\BackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Restores a backup archive (database + attachments) from the command
 * line — intended for disaster recovery and for archives larger than the
 * web upload limit.
 *
 * The archive is given as file name below data/backups or as absolute
 * path. Encrypted archives need their password. Before the restore a
 * safety backup of the current state is created unless --no-safety-backup
 * is given.
 */
#[AsCommand(
    name: 'app:backup:restore',
    description: 'Restores a backup archive (overwrites database and attachments).',
)]
final class BackupRestoreCommand extends Command
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupRestoreService $restoreService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addArgument('archive', InputArgument::REQUIRED, 'Backup file name (below data/backups) or absolute path')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Password of an encrypted backup (preferred: shell env var)')
            ->addOption('no-safety-backup', null, InputOption::VALUE_NONE, 'Skip the automatic safety backup of the current state')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $archiveArg = $input->getArgument('archive');
        if (!is_string($archiveArg) || '' === $archiveArg) {
            $io->error('A backup file name or path is required.');

            return Command::FAILURE;
        }
        $archivePath = str_contains($archiveArg, '/')
            ? $archiveArg
            : $this->backups->getBackupDir().'/'.$archiveArg;
        if (!is_file($archivePath)) {
            $io->error('Backup archive not found: '.$archivePath);

            return Command::FAILURE;
        }

        $io->warning('This restores the archive and OVERWRITES all database and attachment data of this installation.');
        if (!$input->getOption('yes') && !$io->confirm('Restore now?', false)) {
            $io->note('Operation aborted.');

            return Command::SUCCESS;
        }

        $password = $input->getOption('password');
        $password = is_string($password) && '' !== $password ? $password : null;

        try {
            $result = $this->restoreService->restore($archivePath, $password, !$input->getOption('no-safety-backup'));
        } catch (\RuntimeException $exception) {
            $io->error('Restore failed: '.$exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Restore complete: %d tables, %d files%s%s',
            $result->tableCount,
            $result->fileCount,
            $result->includesFiles ? '' : ' (attachments not included in this backup)',
            null !== $result->safetyBackupFilename
                ? ' — safety backup of the previous state: '.$result->safetyBackupFilename
                : '',
        ));

        return Command::SUCCESS;
    }
}
