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
 * Adds connector.fetch_from: only data from this date onwards is fetched
 * (mails by received date, calendar entries by start date).
 */
final class Version20261026150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Adds connector.fetch_from for the "fetch from" date.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector ADD fetch_from DATE DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector DROP fetch_from');
    }
}
