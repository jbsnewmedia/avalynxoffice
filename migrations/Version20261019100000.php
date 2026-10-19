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
 * Creates the administrator-assisted password reset flow: a per-user request
 * row with the hashed token and release number, its status machine and the
 * audit columns (who started it, who released it, from which IP).
 */
final class Version20261019100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Creates app_password_reset_request for admin-assisted password resets.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_password_reset_request (id UUID NOT NULL, user_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, release_number_hash VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, release_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, attempts INT NOT NULL, created_by_admin UUID DEFAULT NULL, released_by_admin UUID DEFAULT NULL, created_ip VARCHAR(45) DEFAULT NULL, released_ip VARCHAR(45) DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A1B2C3D4A76ED395 ON app_password_reset_request (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_password_reset_token_hash ON app_password_reset_request (token_hash)');
        $this->addSql('ALTER TABLE app_password_reset_request ADD CONSTRAINT FK_A1B2C3D4A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_password_reset_request');
    }
}
