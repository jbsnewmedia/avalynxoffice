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
 * Account/connector restructuring: "Mailbox" becomes "Account", connectors
 * become standalone (one POP3/SMTP connector per domain with an optional
 * default account instead of a per-account connector row), and aliases move
 * into their own account_alias table that routes every address of a
 * connector to exactly one account. Emails store the responsible connector
 * and matched alias local part so alias moves can re-route the existing
 * stock.
 */
final class Version20261024120000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Renames mailbox to account, makes connectors standalone (with domain + default account), introduces account_alias and extends email with connector routing.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS mailbox_connector');

        $this->addSql('ALTER TABLE mailbox RENAME TO account');
        $this->addSql('ALTER TABLE user_mailbox RENAME TO user_account');
        $this->addSql('ALTER TABLE user_account RENAME COLUMN mailbox_id TO account_id');
        $this->addSql('ALTER INDEX uniq_user_mailbox RENAME TO uniq_user_account');
        $this->addSql('ALTER TABLE mailbox_folder RENAME TO account_folder');
        $this->addSql('ALTER TABLE account_folder RENAME COLUMN mailbox_id TO account_id');
        $this->addSql('ALTER INDEX uniq_mailbox_folder RENAME TO uniq_account_folder');
        $this->addSql('ALTER TABLE mailbox_address RENAME TO account_address');
        $this->addSql('ALTER TABLE account_address RENAME COLUMN mailbox_id TO account_id');
        $this->addSql('ALTER INDEX uniq_mailbox_address RENAME TO uniq_account_address');
        $this->addSql('ALTER TABLE calendar_event RENAME COLUMN mailbox_id TO account_id');
        $this->addSql('ALTER TABLE email RENAME COLUMN mailbox_id TO account_id');

        $this->addSql('CREATE TABLE connector (id UUID NOT NULL, default_account_id UUID DEFAULT NULL, name VARCHAR(190) NOT NULL, domain VARCHAR(190) NOT NULL, pop3_host VARCHAR(190) DEFAULT NULL, pop3_port INT DEFAULT NULL, pop3_username VARCHAR(190) DEFAULT NULL, pop3_password VARCHAR(255) DEFAULT NULL, pop3_security VARCHAR(20) DEFAULT NULL, pop3_delete_after_fetch BOOLEAN DEFAULT TRUE NOT NULL, smtp_host VARCHAR(190) DEFAULT NULL, smtp_port INT DEFAULT NULL, smtp_username VARCHAR(190) DEFAULT NULL, smtp_password VARCHAR(255) DEFAULT NULL, smtp_security VARCHAR(20) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_connector_domain ON connector (domain)');
        $this->addSql('CREATE INDEX IDX_148C456ED3480BD9 ON connector (default_account_id)');
        $this->addSql('ALTER TABLE connector ADD CONSTRAINT FK_148C456ED3480BD9 FOREIGN KEY (default_account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE account_alias (id UUID NOT NULL, account_id UUID NOT NULL, connector_id UUID NOT NULL, local_part VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_account_alias_address ON account_alias (connector_id, local_part)');
        $this->addSql('CREATE INDEX IDX_D7E3B2E59B6B5FBA ON account_alias (account_id)');
        $this->addSql('CREATE INDEX IDX_D7E3B2E54D085745 ON account_alias (connector_id)');
        $this->addSql('ALTER TABLE account_alias ADD CONSTRAINT FK_D7E3B2E59B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE account_alias ADD CONSTRAINT FK_D7E3B2E54D085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('ALTER TABLE email ADD connector_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE email ADD matched_local_part VARCHAR(190) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_E7927C744D085745 ON email (connector_id)');
        $this->addSql('ALTER TABLE email ADD CONSTRAINT FK_E7927C744D085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_E7927C744D085745');
        $this->addSql('ALTER TABLE email DROP CONSTRAINT FK_E7927C744D085745');
        $this->addSql('ALTER TABLE email DROP connector_id');
        $this->addSql('ALTER TABLE email DROP matched_local_part');

        $this->addSql('DROP TABLE account_alias');
        $this->addSql('DROP TABLE connector');

        $this->addSql('ALTER TABLE email RENAME COLUMN account_id TO mailbox_id');
        $this->addSql('ALTER TABLE calendar_event RENAME COLUMN account_id TO mailbox_id');
        $this->addSql('ALTER INDEX uniq_account_address RENAME TO uniq_mailbox_address');
        $this->addSql('ALTER TABLE account_address RENAME COLUMN account_id TO mailbox_id');
        $this->addSql('ALTER TABLE account_address RENAME TO mailbox_address');
        $this->addSql('ALTER INDEX uniq_account_folder RENAME TO uniq_mailbox_folder');
        $this->addSql('ALTER TABLE account_folder RENAME COLUMN account_id TO mailbox_id');
        $this->addSql('ALTER TABLE account_folder RENAME TO mailbox_folder');
        $this->addSql('ALTER INDEX uniq_user_account RENAME TO uniq_user_mailbox');
        $this->addSql('ALTER TABLE user_account RENAME COLUMN account_id TO mailbox_id');
        $this->addSql('ALTER TABLE user_account RENAME TO user_mailbox');
        $this->addSql('ALTER TABLE account RENAME TO mailbox');

        $this->addSql('CREATE TABLE mailbox_connector (id UUID NOT NULL, mailbox_id UUID NOT NULL, name VARCHAR(190) NOT NULL, email VARCHAR(190) NOT NULL, pop3_host VARCHAR(190) DEFAULT NULL, pop3_port INT DEFAULT NULL, pop3_username VARCHAR(190) DEFAULT NULL, pop3_password VARCHAR(255) DEFAULT NULL, pop3_security VARCHAR(20) DEFAULT NULL, pop3_delete_after_fetch BOOLEAN DEFAULT TRUE NOT NULL, smtp_host VARCHAR(190) DEFAULT NULL, smtp_port INT DEFAULT NULL, smtp_username VARCHAR(190) DEFAULT NULL, smtp_password VARCHAR(255) DEFAULT NULL, smtp_security VARCHAR(20) DEFAULT NULL, is_primary BOOLEAN DEFAULT FALSE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mailbox_connector_email ON mailbox_connector (mailbox_id, email)');
        $this->addSql('CREATE INDEX IDX_B9C8D7E6A76ED395 ON mailbox_connector (mailbox_id)');
        $this->addSql('ALTER TABLE mailbox_connector ADD CONSTRAINT FK_B9C8D7E6A76ED395 FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
