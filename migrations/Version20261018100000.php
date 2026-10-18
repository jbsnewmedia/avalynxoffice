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
 * Adds the recovery-address table (account recovery, max 2 per user).
 */
final class Version20261018100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Creates app_recovery_address for recovery-only email addresses.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_recovery_address (id UUID NOT NULL, user_id UUID NOT NULL, email VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_886A5C28A76ED395 ON app_recovery_address (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_recovery_address_user_email ON app_recovery_address (user_id, email)');
        $this->addSql('ALTER TABLE app_recovery_address ADD CONSTRAINT FK_886A5C28A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_recovery_address');
    }
}
