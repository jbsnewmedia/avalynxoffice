<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Backup;

/**
 * Reads and writes AvalynxOffice backup archives.
 *
 * A plain archive is a ZIP container with these entries:
 *   manifest.json   metadata (format version, timestamps, checksums)
 *   schema.json     serialized database schema (see DatabaseDumper)
 *   data/<t>.jsonl  one JSON object per row for every table
 *   files/<rel>     attachment files (optional)
 *
 * Encrypted archives are the same bytes wrapped by BackupCrypto, visible
 * through the "AOBAKEN1" magic instead of a ZIP header.
 */
final readonly class BackupArchive
{
    public const int FORMAT_VERSION = 1;
    public const string MANIFEST_ENTRY = 'manifest.json';
    public const string SCHEMA_ENTRY = 'schema.json';
    public const string DATA_PREFIX = 'data/';
    public const string FILES_PREFIX = 'files/';

    public function __construct(
        private readonly BackupCrypto $crypto,
    ) {
    }

    /**
     * Writes a plain ZIP archive: every entry is copied from the given
     * source file, the manifest (small) is added inline as the last entry.
     *
     * @param array<string, string> $entries entry name → absolute source path
     *
     * @throws \RuntimeException when the archive cannot be written
     */
    public function writeZip(string $target, array $entries, string $manifestJson): void
    {
        $zip = new \ZipArchive();
        $opened = $zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if (true !== $opened) {
            throw new \RuntimeException('Backup archive cannot be created (code '.$opened.').');
        }

        foreach ($entries as $entry => $source) {
            if (!$zip->addFile($source, $entry)) {
                throw new \RuntimeException('Backup entry "'.$entry.'" cannot be added to the archive.');
            }
        }

        if (!$zip->addFromString(self::MANIFEST_ENTRY, $manifestJson)) {
            throw new \RuntimeException('Backup manifest cannot be added to the archive.');
        }

        if (!$zip->close()) {
            throw new \RuntimeException('Backup archive cannot be finalized.');
        }
    }

    /**
     * Reads and validates the manifest of a (plain) archive.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the archive is not a backup or the
     *                           manifest is missing or incompatible
     */
    public function readManifest(string $zipPath): array
    {
        $manifest = $this->readJsonEntry($zipPath, self::MANIFEST_ENTRY);
        if (($manifest['format'] ?? null) !== self::FORMAT_VERSION) {
            throw new \RuntimeException('Backup manifest is missing or uses an unsupported format version.');
        }

        return $manifest;
    }

    /**
     * Reads the serialized schema of a (plain) archive.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the schema entry is missing or malformed
     */
    public function readSchema(string $zipPath): array
    {
        $schema = $this->readJsonEntry($zipPath, self::SCHEMA_ENTRY);
        if (!isset($schema['tables']) || !is_array($schema['tables'])) {
            throw new \RuntimeException('Backup schema is missing or malformed.');
        }

        return $schema;
    }

    /**
     * Whether the archive at the given path is encrypted.
     */
    public function isEncrypted(string $path): bool
    {
        return $this->crypto->isEncryptedFile($path);
    }

    /**
     * Decrypts an encrypted archive into a plain archive file.
     *
     * @throws \RuntimeException when decryption fails (wrong password or
     *                           corrupted archive)
     */
    public function decryptTo(string $source, string $target, string $password): void
    {
        $this->crypto->decryptFile($source, $target, $password);
    }

    /**
     * Extracts the files/ entries of a (plain) archive into $targetDir,
     * recreating the segmented attachment tree. Path traversal entries are
     * rejected instead of silently skipped — a corrupted archive must not
     * restore partially.
     *
     * @throws \RuntimeException on zip errors or unsafe entry names
     */
    public function extractFiles(string $zipPath, string $targetDir): int
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new \RuntimeException('Backup archive cannot be opened.');
        }

        try {
            $restored = 0;
            foreach ($this->fileEntries($zip) as $entry) {
                $relative = substr($entry, strlen(self::FILES_PREFIX));
                $this->assertSafeRelativePath($relative);

                $target = $targetDir.'/'.$relative;
                $directory = dirname($target);
                if (!is_dir($directory) && !mkdir($directory, 0o770, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Backup file directory "'.$directory.'" cannot be created.');
                }

                $stream = $zip->getStream($entry);
                if (false === $stream) {
                    throw new \RuntimeException('Backup file "'.$entry.'" cannot be read from the archive.');
                }

                try {
                    $out = @fopen($target, 'wb');
                    if (false === $out) {
                        throw new \RuntimeException('Backup file "'.$target.'" cannot be written.');
                    }

                    try {
                        stream_copy_to_stream($stream, $out);
                    } finally {
                        fclose($out);
                    }
                } finally {
                    fclose($stream);
                }

                ++$restored;
            }

            return $restored;
        } finally {
            $zip->close();
        }
    }

    /**
     * Opens a JSONL row stream for one data entry of a (plain) archive.
     * The caller must fclose the returned handle.
     *
     * @return resource
     *
     * @throws \RuntimeException when the entry does not exist
     */
    public function openDataEntry(string $zipPath, string $entryName)
    {
        $stream = @fopen('zip://'.$zipPath.'#'.$entryName, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Backup data entry "'.$entryName.'" cannot be read from the archive.');
        }

        return $stream;
    }

    /**
     * @return list<string>
     */
    private function fileEntries(\ZipArchive $zip): array
    {
        $entries = [];
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $name = $zip->getNameIndex($index);
            if (is_string($name) && str_starts_with($name, self::FILES_PREFIX) && self::FILES_PREFIX !== $name) {
                $entries[] = $name;
            }
        }

        return $entries;
    }

    /**
     * @throws \RuntimeException
     */
    private function assertSafeRelativePath(string $relative): void
    {
        if ('' === $relative || str_starts_with($relative, '/')
            || str_contains($relative, '\\') || str_contains($relative, '..')
            || 1 === preg_match('/[[:cntrl:]]/', $relative)) {
            throw new \RuntimeException('Backup archive contains an unsafe file path: "'.$relative.'".');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readJsonEntry(string $zipPath, string $entry): array
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($zipPath)) {
            throw new \RuntimeException('Backup archive cannot be opened (not a backup archive?).');
        }

        try {
            $raw = $zip->getFromName($entry);
            if (!is_string($raw)) {
                throw new \RuntimeException('Backup archive does not contain "'.$entry.'".');
            }
        } finally {
            $zip->close();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Backup archive entry "'.$entry.'" is not valid JSON.');
        }

        return self::specArray($decoded);
    }

    /**
     * Narrows a JSON-decoded value to an array with string keys — the
     * canonical in-memory form of every manifest/schema/table spec.
     *
     * @return array<string, mixed>
     */
    public static function specArray(mixed $value): array
    {
        $result = [];
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $result[$key] = $item;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function specString(array $spec, string $key, string $fallback = ''): string
    {
        $value = $spec[$key] ?? $fallback;

        return is_string($value) && '' !== $value ? $value : $fallback;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function specInt(array $spec, string $key, ?int $fallback = null): ?int
    {
        $value = $spec[$key] ?? $fallback;

        return is_int($value) ? $value : $fallback;
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function specBool(array $spec, string $key, bool $fallback = false): bool
    {
        $value = $spec[$key] ?? $fallback;

        return is_bool($value) ? $value : $fallback;
    }

    /**
     * Extracts a list of string values ("columns", "flags", ...) from a
     * spec; non-string entries are skipped.
     *
     * @param array<string, mixed> $spec
     *
     * @return list<string>
     */
    public static function specStringList(array $spec, string $key): array
    {
        $result = [];
        $value = $spec[$key] ?? [];
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item)) {
                    $result[] = $item;
                }
            }
        }

        return $result;
    }
}
