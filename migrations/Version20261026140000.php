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
 * Adds connector.plugin_key: the connector plugin chosen for the connector
 * (e.g. "pop3" or "exchange"). Null keeps the legacy behaviour of using
 * every configured plugin.
 */
final class Version20261026140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Adds connector.plugin_key for the chosen connector plugin.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector ADD plugin_key VARCHAR(32) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector DROP plugin_key');
    }
}
