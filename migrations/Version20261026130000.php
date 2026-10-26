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
 * Adds per-user display preferences for dates and times: the time zone the
 * times are rendered in and the format variant used by the user_datetime
 * twig filter.
 */
final class Version20261026130000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Adds timezone and time format display preferences to app_user.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD timezone VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD time_format VARCHAR(16) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP timezone');
        $this->addSql('ALTER TABLE app_user DROP time_format');
    }
}
