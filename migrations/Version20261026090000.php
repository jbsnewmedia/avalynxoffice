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
 * Drops the unique index on connector.domain: one domain may be served by
 * several connectors (e.g. one per mailbox/server access). Uniqueness is
 * established on the alias level — the pair (connector, localPart) is
 * globally unique.
 */
final class Version20261026090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Drops uniq_connector_domain: several connectors per domain are allowed.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_connector_domain');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX uniq_connector_domain ON connector (domain)');
    }
}
