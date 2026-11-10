<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Backup;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Restores a backup archive: validates it, takes a forced safety backup of
 * the current state, replaces the whole database (drop + recreate from the
 * serialized schema + insert rows) and replaces the attachment files.
 *
 * Restore runs with foreign key checks disabled (replica mode on
 * PostgreSQL). Table data inserts run in chunked transactions; the DDL
 * steps cannot be transactional on MariaDB/MySQL — on a mid-restore
 * failure the forced safety backup is the way back.
 */
final readonly class BackupRestoreService
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupArchive $archive,
        private readonly DatabaseDumper $dumper,
    ) {
    }

    /**
     * Restores the archive at the given path (plain or encrypted).
     *
     * @param string      $archivePath      absolute path of the archive
     * @param string|null $password         required for encrypted archives
     * @param bool        $withSafetyBackup create a "pre-restore" backup first
     *
     * @throws \RuntimeException when validation, decryption or the restore fails
     */
    public function restore(string $archivePath, ?string $password, bool $withSafetyBackup = true): RestoreResult
    {
        $tempZip = null;
        try {
            if ($this->archive->isEncrypted($archivePath)) {
                if (null === $password || '' === $password) {
                    throw new \RuntimeException('This backup is encrypted and needs its password.');
                }

                $tempZip = tempnam(sys_get_temp_dir(), 'aobak-').'.zip';
                $this->archive->decryptTo($archivePath, $tempZip, $password);
                $zipPath = $tempZip;
            } else {
                $zipPath = $archivePath;
            }

            $manifest = $this->archive->readManifest($zipPath);
            $schema = $this->archive->readSchema($zipPath);

            $safetyFilename = null;
            if ($withSafetyBackup) {
                $safetyFilename = $this->backups->create(
                    BackupArchive::specBool($manifest, 'includesFiles', true),
                    null,
                    'pre-restore',
                );
            }

            $tables = $this->restoreDatabase($zipPath, $schema);
            $files = BackupArchive::specBool($manifest, 'includesFiles')
                ? $this->restoreFiles($zipPath)
                : 0;

            return new RestoreResult(
                count($tables),
                $tables,
                $files,
                BackupArchive::specBool($manifest, 'includesFiles'),
                $safetyFilename,
            );
        } finally {
            if (is_string($tempZip) && is_file($tempZip)) {
                @unlink($tempZip);
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> restored table names
     */
    private function restoreDatabase(string $zipPath, array $schema): array
    {
        $this->disableForeignKeys();
        try {
            $this->dumper->dropAllTables();
            $this->dumper->createTables($schema);

            $restored = [];
            $tables = is_array($schema['tables'] ?? null) ? $schema['tables'] : [];
            foreach ($tables as $tableSpec) {
                if (!is_array($tableSpec)) {
                    throw new \RuntimeException('Backup schema contains a malformed table entry.');
                }

                $tableName = BackupArchive::specString(BackupArchive::specArray($tableSpec), 'name');
                $entry = BackupArchive::DATA_PREFIX.$tableName.'.jsonl';
                $rows = $this->readRows($zipPath, $entry);
                $this->dumper->insertRows(BackupArchive::specArray($tableSpec), $rows);
                $restored[] = $tableName;
            }

            $this->dumper->fixSequences();

            return $restored;
        } finally {
            $this->enableForeignKeys();
        }
    }

    /**
     * Empties the attachment base directory and extracts the files/ entries
     * of the archive into it.
     *
     * @throws \RuntimeException
     */
    private function restoreFiles(string $zipPath): int
    {
        $baseDir = $this->backups->getAttachmentsBaseDir();
        if (!is_dir($baseDir) && !mkdir($baseDir, 0o770, true) && !is_dir($baseDir)) {
            throw new \RuntimeException('Attachment directory cannot be created.');
        }

        $entries = [];
        foreach (scandir($baseDir, \SCANDIR_SORT_NONE) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $entries[] = $baseDir.'/'.$entry;
            }
        }

        if ([] !== $entries) {
            new Filesystem()->remove($entries);
        }

        return $this->archive->extractFiles($zipPath, $baseDir);
    }

    /**
     * @return \Generator<array<string|int, mixed>>
     */
    private function readRows(string $zipPath, string $entry): \Generator
    {
        $stream = $this->archive->openDataEntry($zipPath, $entry);

        try {
            while (($line = fgets($stream)) !== false) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }

                try {
                    $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new \RuntimeException('Backup data entry "'.$entry.'" contains malformed JSON.');
                }

                if (!is_array($row)) {
                    throw new \RuntimeException('Backup data entry "'.$entry.'" contains a malformed row.');
                }

                yield $row;
            }
        } finally {
            fclose($stream);
        }
    }

    private function disableForeignKeys(): void
    {
        if ($this->dumper->isPostgresql()) {
            $this->dumper->executeStatement("SET session_replication_role = 'replica'");
        } else {
            $this->dumper->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        }
    }

    private function enableForeignKeys(): void
    {
        if ($this->dumper->isPostgresql()) {
            $this->dumper->executeStatement("SET session_replication_role = 'origin'");
        } else {
            $this->dumper->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
