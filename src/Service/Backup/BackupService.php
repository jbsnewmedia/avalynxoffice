<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Backup;

use AvalynxOffice\Service\AppService;
use AvalynxOffice\Service\Mail\AttachmentStorage;

/**
 * Creates, lists and deletes AvalynxOffice backups below
 * data/backups (outside the web root, on the persistent volume).
 *
 * A backup is either a plain ZIP archive (".aobak") or the same bytes
 * wrapped in password encryption (".aobak.enc", see BackupCrypto).
 */
final readonly class BackupService
{
    private const string FILENAME_PATTERN = '/^avalynxoffice-(backup|pre-restore|uploaded|auto)-\d{4}-\d{2}-\d{2}_\d{6}(?:_\d+)?\.aobak(\.enc)?$/';

    public function __construct(
        private readonly string $projectDir,
        private readonly DatabaseDumper $dumper,
        private readonly BackupArchive $archive,
        private readonly BackupCrypto $crypto,
        private readonly AttachmentStorage $attachments,
        private readonly AppService $appService,
    ) {
    }

    /**
     * Directory the backups live in (data/backups below the project dir,
     * i.e. on the persistent volume, outside the web root).
     */
    public function getBackupDir(): string
    {
        return rtrim($this->projectDir, '/').'/data/backups';
    }

    /**
     * Base directory of the attachment files included when "include files"
     * is enabled.
     */
    public function getAttachmentsBaseDir(): string
    {
        return $this->attachments->getBaseDir();
    }

    /**
     * @return list<BackupFileInfo> newest first
     */
    public function listBackups(): array
    {
        $dir = $this->getBackupDir();
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (1 === preg_match(self::FILENAME_PATTERN, $entry)) {
                $files[] = $entry;
            }
        }

        $infos = [];
        foreach ($files as $filename) {
            $path = $dir.'/'.$filename;
            $encrypted = str_ends_with($filename, '.enc');
            $manifest = null;
            if (!$encrypted) {
                try {
                    $manifest = $this->archive->readManifest($path);
                } catch (\RuntimeException) {
                    $manifest = null;
                }
            }

            $mtime = filemtime($path) ?: 0;
            $createdAt = new \DateTimeImmutable('@'.$mtime)->setTimezone(new \DateTimeZone(date_default_timezone_get()));

            $infos[] = new BackupFileInfo(
                $filename,
                filesize($path) ?: 0,
                $encrypted,
                $createdAt,
                $manifest,
            );
        }

        usort(
            $infos,
            static fn (BackupFileInfo $a, BackupFileInfo $b): int => $b->createdAt <=> $a->createdAt
                ?: strcmp($b->filename, $a->filename),
        );

        return $infos;
    }

    /**
     * Creates a backup and returns its file name.
     *
     * @param string $namePrefix 'backup', 'pre-restore', 'uploaded' or 'auto'
     *
     * @throws \RuntimeException when the backup cannot be created
     */
    public function create(bool $includeFiles, ?string $password, string $namePrefix = 'backup'): string
    {
        $backupDir = $this->getBackupDir();
        if (!is_dir($backupDir) && !mkdir($backupDir, 0o770, true) && !is_dir($backupDir)) {
            throw new \RuntimeException('Backup directory cannot be created.');
        }

        $tempDir = sys_get_temp_dir().'/aobak-'.uniqid('', true);
        if (!mkdir($tempDir, 0o700, true) && !is_dir($tempDir)) {
            throw new \RuntimeException('Temporary backup directory cannot be created.');
        }

        try {
            return $this->build($backupDir, $tempDir, $includeFiles, $password, $namePrefix);
        } finally {
            $this->removeRecursively($tempDir);
        }
    }

    /**
     * Deletes a backup by file name.
     *
     * @throws \RuntimeException when the name is invalid or the file is missing
     */
    public function delete(string $filename): void
    {
        $path = $this->resolveFilename($filename);
        if (!unlink($path)) {
            throw new \RuntimeException('Backup "'.$filename.'" cannot be deleted.');
        }
    }

    /**
     * Resolves a validated backup file name to its absolute path.
     *
     * @throws \RuntimeException when the name is invalid or the file is missing
     */
    public function resolveFilename(string $filename): string
    {
        if (1 !== preg_match(self::FILENAME_PATTERN, $filename)) {
            throw new \RuntimeException('Invalid backup file name.');
        }

        $path = $this->getBackupDir().'/'.$filename;
        if (!is_file($path)) {
            throw new \RuntimeException('Backup "'.$filename.'" does not exist.');
        }

        return $path;
    }

    /**
     * Builds a file name for a stored backup with the given content type
     * (plain archive or encrypted wrapper). When the name already exists
     * (several backups within the same second), a counter suffix is
     * appended so nothing is silently overwritten.
     */
    public function buildFilename(string $namePrefix, bool $encrypted): string
    {
        $base = sprintf(
            'avalynxoffice-%s-%s',
            $namePrefix,
            date('Y-m-d_His'),
        );
        $extension = $encrypted ? '.aobak.enc' : '.aobak';

        $filename = $base.$extension;
        $counter = 2;
        while (is_file($this->getBackupDir().'/'.$filename)) {
            $filename = $base.'_'.$counter.$extension;
            ++$counter;
        }

        return $filename;
    }

    /**
     * @throws \RuntimeException
     */
    private function build(string $backupDir, string $tempDir, bool $includeFiles, ?string $password, string $namePrefix): string
    {
        $entries = [];
        $checksums = [];
        $rowCounts = [];
        $tables = [];
        $fileCount = 0;

        $schema = $this->dumper->dumpSchema();
        $schemaFile = $tempDir.'/'.BackupArchive::SCHEMA_ENTRY;
        $this->writeFile($schemaFile, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $entries[BackupArchive::SCHEMA_ENTRY] = $schemaFile;
        $checksums[BackupArchive::SCHEMA_ENTRY] = hash_file('sha256', $schemaFile) ?: '';

        foreach ($schema['tables'] as $tableSpec) {
            $tableSpecArray = BackupArchive::specArray($tableSpec);
            $tableName = BackupArchive::specString($tableSpecArray, 'name');
            $entry = BackupArchive::DATA_PREFIX.$tableName.'.jsonl';
            $dataFile = $tempDir.'/'.md5($entry).'.jsonl';
            $rowCounts[$tableName] = $this->dumper->dumpTableData($tableSpecArray, $dataFile);
            $tables[] = $tableName;
            $entries[$entry] = $dataFile;
            $checksums[$entry] = hash_file('sha256', $dataFile) ?: '';
        }

        if ($includeFiles) {
            foreach ($this->attachmentFiles() as $relativePath => $absolutePath) {
                $entry = BackupArchive::FILES_PREFIX.$relativePath;
                $entries[$entry] = $absolutePath;
                $checksums[$entry] = hash_file('sha256', $absolutePath) ?: '';
                ++$fileCount;
            }
        }

        $manifest = [
            'format' => BackupArchive::FORMAT_VERSION,
            'createdAt' => date('c'),
            'appVersion' => $this->appService->getVersion(),
            'database' => [
                'platform' => $this->dumper->platformName(),
                'version' => $this->dumper->databaseVersion() ?? '',
            ],
            'includesFiles' => $includeFiles,
            'tables' => $tables,
            'rowCounts' => $rowCounts,
            'fileCount' => $fileCount,
            'checksums' => $checksums,
        ];

        $tempZip = $backupDir.'/.'.uniqid('tmp-backup-', true).'.zip';
        $this->archive->writeZip($tempZip, $entries, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $filename = $this->buildFilename($namePrefix, null !== $password);
        $target = $backupDir.'/'.$filename;
        if (null !== $password) {
            $this->crypto->encryptFile($tempZip, $target, $password);
            unlink($tempZip);
        } elseif (!rename($tempZip, $target)) {
            unlink($tempZip);
            throw new \RuntimeException('Backup archive cannot be stored.');
        }

        return $filename;
    }

    /**
     * @return array<string, string> relative path → absolute path
     */
    private function attachmentFiles(): array
    {
        $baseDir = $this->attachments->getBaseDir();
        if (!is_dir($baseDir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = ltrim(substr($file->getPathname(), strlen($baseDir)), '/');
            if ('' !== $relative) {
                $files[$relative] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    private function writeFile(string $path, string $content): void
    {
        if (false === file_put_contents($path, $content)) {
            throw new \RuntimeException('Backup file "'.$path.'" cannot be written.');
        }
    }

    private function removeRecursively(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->removeRecursively($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
