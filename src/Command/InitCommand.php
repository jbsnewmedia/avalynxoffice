<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Command;

use AvalynxOffice\Entity\User;
use AvalynxOffice\Licensing\Service\LicenseEnforcementInterface;
use AvalynxOffice\Service\DatabasePurger;
use AvalynxOffice\Service\UserService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:init',
    description: 'Initializes the project: runs migrations, creates the first user, and optionally generates test data.',
)]
class InitCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly KernelInterface $kernel,
        private readonly DatabasePurger $databasePurger,
        private readonly LicenseEnforcementInterface $licenseEnforcement,
        private readonly UserService $userService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
This command initializes the project in two steps:

 1. The database migrations are executed.
 2. You are asked whether to generate test data.
    - Yes: The fixtures are loaded (including the administrator admin@example.com / admin).
    - No:  If data already exists, you are asked whether to clear the database
           completely or everything except the users. Afterwards an administrator
           is created interactively, unless one already exists.

The command is intended for the initial setup and can be run repeatedly.
HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Project initialization');

        $io->section('Step 1/2: Database migrations');
        $migrationResult = $this->runMigrations($io, $output);
        if (Command::SUCCESS !== $migrationResult) {
            $io->error('The migrations could not be executed.');

            return Command::FAILURE;
        }
        $io->success('Migrations executed successfully.');

        $io->section('Step 2/2: Users & test data');
        $loadFixtures = $io->confirm(
            'Generate test data? This will purge the database and populate it with fixtures (including the administrator admin@example.com / admin).',
            false,
        );

        if ($loadFixtures) {
            return $this->loadTestData($io, $output);
        }

        return $this->setupWithoutTestData($io);
    }

    private function runMigrations(SymfonyStyle $io, OutputInterface $output): int
    {
        if ($this->needsMigrationSync()) {
            $io->note('The database schema already exists, but no migrations are recorded. The migration version table will be synchronized.');
            $metadataResult = $this->runSymfonyCommand(
                'doctrine:migrations:sync-metadata-storage',
                ['--no-interaction'],
                $output,
            );
            if (Command::SUCCESS !== $metadataResult) {
                $io->error('The migration metadata could not be synchronized.');

                return Command::FAILURE;
            }
            $versionResult = $this->runSymfonyCommand(
                'doctrine:migrations:version',
                ['--add', '--all', '--no-interaction'],
                $output,
            );
            if (Command::SUCCESS !== $versionResult) {
                $io->error('The migration versions could not be synchronized.');

                return Command::FAILURE;
            }
        }

        return $this->runSymfonyCommand(
            'doctrine:migrations:migrate',
            ['--no-interaction'],
            $output,
        );
    }

    /**
     * Detects the state in which the schema already exists (e.g. created via
     * schema:create or the SchemaTool), but no migration is recorded in the
     * version table. In that case migrate would try to recreate the tables and
     * fail with "table already exists".
     */
    protected function needsMigrationSync(): bool
    {
        $connection = $this->entityManager->getConnection();
        $tables = $connection->createSchemaManager()->listTableNames();
        $hasManagedTables = [] !== array_values(array_filter(
            $tables,
            static fn (string $table): bool => !in_array($table, ['doctrine_migration_versions', 'messenger_messages'], true),
        ));
        if (!$hasManagedTables) {
            return false;
        }
        if (!in_array('doctrine_migration_versions', $tables, true)) {
            return true;
        }

        $count = $connection->executeQuery('SELECT COUNT(*) FROM doctrine_migration_versions')->fetchOne();

        return false === $count || (is_numeric($count) && 0 === (int) $count);
    }

    private function loadTestData(SymfonyStyle $io, OutputInterface $output): int
    {
        $io->text('Loading test data ...');
        $fixturesResult = $this->runSymfonyCommand(
            'doctrine:fixtures:load',
            ['--no-interaction'],
            $output,
        );
        if (Command::SUCCESS !== $fixturesResult) {
            $io->error('The test data could not be loaded.');

            return Command::FAILURE;
        }
        $io->success('Test data generated successfully.');
        $io->definitionList(
            ['Login' => 'admin@example.com'],
            ['Password' => 'admin'],
        );

        return Command::SUCCESS;
    }

    private function setupWithoutTestData(SymfonyStyle $io): int
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $existingUser = $userRepository->findOneBy([]);

        if (null !== $existingUser) {
            $choice = $io->choice(
                'Existing data was found. How should the database be handled?',
                [
                    'keep' => 'Leave data unchanged',
                    'users' => 'Clear everything except users',
                    'all' => 'Clear everything (including users)',
                ],
                'keep',
            );

            if ('users' === $choice || 'all' === $choice) {
                try {
                    $this->databasePurger->purge('users' === $choice);
                } catch (\Throwable $e) {
                    $io->error('The database could not be cleared: '.$e->getMessage());

                    return Command::FAILURE;
                }
                $this->entityManager->clear();
                $io->success('users' === $choice
                    ? 'All data except users has been removed.'
                    : 'The database has been cleared completely.');
            }
        }

        return $this->createFirstUser($io);
    }

    private function createFirstUser(SymfonyStyle $io): int
    {
        $userRepository = $this->entityManager->getRepository(User::class);
        $existingUser = $userRepository->findOneBy([]);
        if (null !== $existingUser) {
            $io->note(sprintf(
                'A user already exists (%s). Creation skipped.',
                $existingUser->getEmail(),
            ));

            return Command::SUCCESS;
        }

        $emailRaw = $io->ask('Administrator email address');
        $email = is_string($emailRaw) ? trim($emailRaw) : '';
        if ('' === $email) {
            $io->error('A valid email address must be provided.');

            return Command::FAILURE;
        }

        $password = $io->askHidden('Administrator password');
        if (!is_string($password) || '' === trim($password)) {
            $io->error('The password must not be empty.');

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        // The first user of an installation without any administrator
        // automatically becomes the administrator.
        $this->userService->applyInitialRoles($user);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        // Seat accounting and the user insert must share one transaction so
        // that concurrent activations are serialized by the license state
        // row lock.
        $this->entityManager->wrapInTransaction(function () use ($user): void {
            $this->licenseEnforcement->assertUserSeatAvailable();
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        });

        $io->success(sprintf('Administrator %s created successfully.', $user->getEmail()));

        return Command::SUCCESS;
    }

    /**
     * @param array<int, string> $arguments
     */
    protected function runSymfonyCommand(string $name, array $arguments, OutputInterface $output): int
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $command = $application->find($name);
        $input = new StringInput(implode(' ', $arguments));
        $input->setInteractive(false);

        return $command->run($input, $output);
    }
}
