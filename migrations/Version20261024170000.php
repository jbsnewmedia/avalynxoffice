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
 * Creates the rememberme_token table (doctrine remember-me token provider):
 * remember-me tokens become server-side revocable, so kicked sessions are
 * not restored by the remember-me cookie auto-login.
 */
final class Version20261024170000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Creates rememberme_token for the doctrine remember-me token provider.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, class VARCHAR(100) DEFAULT '' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY(series))");
        $this->addSql('CREATE INDEX idx_rememberme_token_username ON rememberme_token (username)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE rememberme_token');
    }
}
