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
 * Connectors become plugin based: creates connector_config (JSON settings
 * per connector and plugin key) and drops the built-in pop3/smtp columns
 * of the connector table.
 */
final class Version20261024160000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Creates connector_config for plugin based connectors and drops the built-in pop3/smtp connector columns.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE connector_config (id UUID NOT NULL, connector_id UUID NOT NULL, plugin_key VARCHAR(100) NOT NULL, config JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_connector_config ON connector_config (connector_id, plugin_key)');
        $this->addSql('CREATE INDEX IDX_A2B6C31BA76ED395 ON connector_config (connector_id)');
        $this->addSql('ALTER TABLE connector_config ADD CONSTRAINT FK_A2B6C31BA76ED395 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE connector DROP COLUMN pop3_host');
        $this->addSql('ALTER TABLE connector DROP COLUMN pop3_port');
        $this->addSql('ALTER TABLE connector DROP COLUMN pop3_username');
        $this->addSql('ALTER TABLE connector DROP COLUMN pop3_password');
        $this->addSql('ALTER TABLE connector DROP COLUMN pop3_security');
        $this->addSql('ALTER TABLE connector DROP COLUMN pop3_delete_after_fetch');
        $this->addSql('ALTER TABLE connector DROP COLUMN smtp_host');
        $this->addSql('ALTER TABLE connector DROP COLUMN smtp_port');
        $this->addSql('ALTER TABLE connector DROP COLUMN smtp_username');
        $this->addSql('ALTER TABLE connector DROP COLUMN smtp_password');
        $this->addSql('ALTER TABLE connector DROP COLUMN smtp_security');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector ADD COLUMN pop3_host VARCHAR(190) DEFAULT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN pop3_port INT DEFAULT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN pop3_username VARCHAR(190) DEFAULT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN pop3_password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN pop3_security VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN pop3_delete_after_fetch BOOLEAN DEFAULT TRUE NOT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN smtp_host VARCHAR(190) DEFAULT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN smtp_port INT DEFAULT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN smtp_username VARCHAR(190) DEFAULT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN smtp_password VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE connector ADD COLUMN smtp_security VARCHAR(20) DEFAULT NULL');
        $this->addSql('DROP TABLE connector_config');
    }
}
