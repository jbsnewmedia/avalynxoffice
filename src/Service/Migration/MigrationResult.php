<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Migration;

/**
 * Statistics of a database migration run, shown to the admin as flash
 * message / CLI output.
 */
final readonly class MigrationResult
{
    /**
     * The migration tracking matched the current migration history: the
     * pending migrations were applied directly.
     */
    public const string MODE_MIGRATIONS = 'migrations';

    /**
     * The tracking was unknown or incomplete (e.g. rewritten baseline):
     * all current migrations were re-marked and the schema was converged
     * to the entity metadata via a schema diff.
     */
    public const string MODE_SCHEMA_SYNC = 'schema-sync';

    public function __construct(
        public string $mode,
        public int $migrationCount,
    ) {
    }
}
