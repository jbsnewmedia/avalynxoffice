<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Migration;

use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Brings a database to the current installation version while keeping all
 * data — used after a backup restore and standalone (container start,
 * `app:migrate`) when an existing installation is updated.
 *
 * The database carries its own doctrine_migration_versions rows. Two
 * paths exist:
 *
 * - The tracking is a clean subset of the current migration history: the
 *   pending migrations run directly (this also applies any data
 *   migration logic they contain).
 * - The tracking contains unknown versions (e.g. the baseline migration
 *   was rewritten since the installation was set up, see the baseline
 *   policy in AGENTS.md) or is missing entirely: no incremental path
 *   exists, so all current migrations are marked as executed and the
 *   schema is converged to the entity metadata (SchemaTool diff, see
 *   convergeSchema). Existing rows stay untouched — only the structure
 *   is adapted.
 *
 * Note: the schema-sync fallback cannot apply data backfills — only real
 * incremental migrations can. Write one whenever a schema change needs
 * data to be re-filled on existing databases.
 *
 * The fallback deliberately avoids `doctrine:schema:update`: since ORM 3
 * that command has no save-mode anymore (`--complete` is a no-op) and
 * drops every asset that is not part of the entity metadata — including
 * the migration tracking table itself.
 */
final readonly class DatabaseMigrator
{
    private const string TRACKING_TABLE = 'doctrine_migration_versions';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
        private readonly string $migrationsDir,
    ) {
    }

    /**
     * Runs the migration step.
     *
     * @throws \RuntimeException when a migration command fails
     */
    public function migrateToCurrent(?OutputInterface $output = null): MigrationResult
    {
        $output ??= new NullOutput();

        $executed = $this->executedVersions();

        // Clean path: the tracking matches the current migration history,
        // so the pending migrations can run directly.
        if ([] !== $executed && [] === array_diff(
            array_map(self::normalizeVersion(...), $executed),
            $this->availableVersions(),
        )) {
            $before = count($executed);
            $this->runSymfonyCommand('doctrine:migrations:migrate', ['--no-interaction'], $output);

            return new MigrationResult(
                MigrationResult::MODE_MIGRATIONS,
                max(0, count($this->executedVersions()) - $before),
            );
        }

        // Fallback: tracking missing, empty or containing unknown versions
        // (rewritten baseline / installation of another version).
        // Re-mark the current migration history and converge the schema.
        $this->resetTracking();
        $this->runSymfonyCommand('doctrine:migrations:sync-metadata-storage', ['--no-interaction'], $output);
        $this->runSymfonyCommand('doctrine:migrations:version', ['--add', '--all', '--no-interaction'], $output);
        $this->convergeSchema();

        return new MigrationResult(MigrationResult::MODE_SCHEMA_SYNC, 0);
    }

    /**
     * @return list<string> recorded migration versions (empty when the
     *                      tracking table does not exist yet)
     */
    private function executedVersions(): array
    {
        $connection = $this->entityManager->getConnection();
        if (!in_array(self::TRACKING_TABLE, $connection->createSchemaManager()->listTableNames(), true)) {
            return [];
        }

        $rows = $connection->executeQuery('SELECT version FROM '.self::TRACKING_TABLE)->fetchFirstColumn();

        return array_values(array_map(
            static fn (mixed $version): string => is_scalar($version) ? (string) $version : '',
            $rows,
        ));
    }

    /**
     * @return list<string> versions registered in the migrations directory
     *                      (see doctrine_migrations.yaml: one class per
     *                      file, named Version<version>.php)
     */
    private function availableVersions(): array
    {
        $files = glob($this->migrationsDir.'/Version*.php');
        if (false === $files) {
            return [];
        }

        $versions = [];
        foreach ($files as $file) {
            if (1 === preg_match('/Version(\d+)\.php$/', $file, $matches)) {
                $versions[] = $matches[1];
            }
        }

        return $versions;
    }

    /**
     * The migration storage records the namespaced class name (e.g.
     * "DoctrineMigrations\Version20261028153909", depending on the storage
     * format of the creating installation) — for the comparison with the
     * migrations directory only the trailing version number counts.
     */
    private static function normalizeVersion(string $version): string
    {
        if (1 === preg_match('/(\d+)$/', $version, $matches)) {
            return $matches[1];
        }

        return $version;
    }

    private function resetTracking(): void
    {
        $connection = $this->entityManager->getConnection();
        if (in_array(self::TRACKING_TABLE, $connection->createSchemaManager()->listTableNames(), true)) {
            $connection->executeStatement('DELETE FROM '.self::TRACKING_TABLE);
        }
    }

    /**
     * Converges the schema to the current entity metadata via a SchemaTool
     * diff, limited to entity tables: the schema assets filter is narrowed
     * to the mapped table names for the comparison, so system tables
     * (migration tracking, schema fingerprint, rememberme, messenger) can
     * never be touched — the diff cannot drop them. Existing rows keep
     * their values; only the structure is adapted.
     */
    private function convergeSchema(): void
    {
        $entityManager = $this->entityManager;
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $connection = $entityManager->getConnection();

        $entityTableNames = [];
        foreach ($metadata as $classMetadata) {
            $entityTableNames[] = strtolower($classMetadata->getTableName());
        }

        $config = $connection->getConfiguration();
        $previousFilter = $config->getSchemaAssetsFilter();
        $config->setSchemaAssetsFilter(
            static fn (string|AbstractAsset $asset): bool => in_array(
                strtolower($asset instanceof AbstractAsset ? $asset->getName() : $asset),
                $entityTableNames,
                true,
            ),
        );

        try {
            $statements = new SchemaTool($entityManager)->getUpdateSchemaSql($metadata);
        } finally {
            $config->setSchemaAssetsFilter($previousFilter);
        }

        foreach ($statements as $statement) {
            $connection->executeStatement($statement);
        }
    }

    /**
     * @param array<int, string> $arguments
     */
    private function runSymfonyCommand(string $name, array $arguments, OutputInterface $output): void
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);
        $command = $application->find($name);
        $input = new StringInput(implode(' ', $arguments));
        $input->setInteractive(false);

        if (Command::SUCCESS !== $command->run($input, $output)) {
            throw new \RuntimeException('The migration step "'.$name.'" failed.');
        }
    }
}
