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
 * Creates app_user_login_session: the registry of concurrent logins per
 * user (device, ip, geolocation, remember-me state) which powers the
 * profile session overview and allows ending sessions (kick).
 */
final class Version20261024150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Creates app_user_login_session for the concurrent-login registry (session overview and kick).';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user_login_session (id UUID NOT NULL, user_id UUID NOT NULL, session_token VARCHAR(64) NOT NULL, remember_me BOOLEAN DEFAULT FALSE NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent TEXT DEFAULT NULL, browser VARCHAR(60) DEFAULT NULL, os VARCHAR(60) DEFAULT NULL, geo_city VARCHAR(100) DEFAULT NULL, geo_country VARCHAR(100) DEFAULT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6E5B7A1AE0D71F9B ON app_user_login_session (session_token)');
        $this->addSql('CREATE INDEX IDX_6E5B7A1AA76ED395 ON app_user_login_session (user_id)');
        $this->addSql('ALTER TABLE app_user_login_session ADD CONSTRAINT FK_6E5B7A1AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_user_login_session');
    }
}
