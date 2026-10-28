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
 * Adds attachment.content_id: the Content-ID of inline images so the mail
 * view can resolve "cid:" references in the HTML body to the stored
 * attachment.
 */
final class Version20261028100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Adds attachment.content_id for inline image references.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attachment ADD content_id VARCHAR(255) DEFAULT NULL');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attachment DROP content_id');
    }
}
