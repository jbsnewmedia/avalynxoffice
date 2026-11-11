<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Backup;

use AvalynxOffice\Service\Migration\MigrationResult;

/**
 * Statistics of a successful backup restore, shown to the admin as flash
 * message / CLI output.
 */
final readonly class RestoreResult
{
    /**
     * @param list<string> $tables
     */
    public function __construct(
        public int $tableCount,
        public array $tables,
        public int $fileCount,
        public bool $includesFiles,
        public ?string $safetyBackupFilename,
        public ?string $backupAppVersion = null,
        public ?MigrationResult $migration = null,
    ) {
    }
}
