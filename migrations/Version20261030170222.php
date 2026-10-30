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
 * Double opt-in for recovery addresses: verification state and token hash.
 */
final class Version20261030170222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add verified_at, verification_token_hash and verification_expires_at to app_recovery_address';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_recovery_address ADD verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE app_recovery_address ADD verification_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_recovery_address ADD verification_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_recovery_address DROP verified_at');
        $this->addSql('ALTER TABLE app_recovery_address DROP verification_token_hash');
        $this->addSql('ALTER TABLE app_recovery_address DROP verification_expires_at');
    }
}
