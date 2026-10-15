<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260710090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add has_encoding_issues flag to email table for tracking mails with sanitized headers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email ADD has_encoding_issues BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email DROP has_encoding_issues');
    }
}