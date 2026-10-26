<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the sync_run table: one row per executed pass of the sync
 * service, shown as the system status in the admin area.
 */
final class Version20261026120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Creates sync_run for the sync service status.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sync_run (id UUID NOT NULL, status VARCHAR(16) NOT NULL, "trigger" VARCHAR(16) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mails_imported INT NOT NULL DEFAULT 0, mails_skipped INT NOT NULL DEFAULT 0, mails_errors INT NOT NULL DEFAULT 0, contacts_count INT NOT NULL DEFAULT 0, calendar_count INT NOT NULL DEFAULT 0, message TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_sync_run_started_at ON sync_run (started_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sync_run');
    }
}
