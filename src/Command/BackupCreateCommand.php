<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Service\Backup\BackupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a backup of the whole installation (database + attachments)
 * from the command line — intended for cron jobs and server-level
 * automation that cannot use the admin UI.
 */
#[AsCommand(
    name: 'app:backup:create',
    description: 'Creates a backup of database and attachments (optionally encrypted).',
)]
final class BackupCreateCommand extends Command
{
    public function __construct(
        private readonly BackupService $backups,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('no-files', null, InputOption::VALUE_NONE, 'Skip the attachment files (database only)')
            ->addOption('encrypt', null, InputOption::VALUE_NONE, 'Encrypt the archive with a password (Argon2id + XChaCha20-Poly1305)')
            ->addOption('password', 'p', InputOption::VALUE_REQUIRED, 'Encryption password (preferred: shell env var, so it stays off the command line)')
        ;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $password = $input->getOption('password');
        $encrypt = (bool) $input->getOption('encrypt');
        if ($encrypt && (!is_string($password) || '' === $password)) {
            $password = $io->askHidden('Encryption password (at least 8 characters)');
            if (!is_string($password) || mb_strlen($password) < 8) {
                $io->error('A password of at least 8 characters is required for encrypted backups.');

                return Command::FAILURE;
            }
        }

        if (!$encrypt) {
            $password = null;
        }

        try {
            $filename = $this->backups->create(!$input->getOption('no-files'), $password);
        } catch (\RuntimeException $exception) {
            $io->error('Backup failed: '.$exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Backup created: '.$this->backups->getBackupDir().'/'.$filename);

        return Command::SUCCESS;
    }
}
