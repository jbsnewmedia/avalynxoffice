<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Backup;

/**
 * One entry of the server side backup list.
 */
final readonly class BackupFileInfo
{
    /**
     * @param array<string, mixed>|null $manifest plain archives only (null when encrypted or unreadable)
     */
    public function __construct(
        public string $filename,
        public int $sizeBytes,
        public bool $encrypted,
        public \DateTimeImmutable $createdAt,
        public ?array $manifest,
    ) {
    }

    /**
     * Human readable size, e.g. "1.2 MiB".
     */
    public function sizeFormatted(): string
    {
        $bytes = max(0, $this->sizeBytes);
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        foreach (['KiB', 'MiB', 'GiB'] as $unit) {
            $bytes /= 1024;
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }
        }

        return round($bytes, 1).' TiB';
    }
}
