<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the calendar_event table for the calendar module.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE calendar_event (id UUID NOT NULL, mailbox_id UUID DEFAULT NULL, title VARCHAR(190) NOT NULL, location VARCHAR(190) DEFAULT NULL, description TEXT DEFAULT NULL, start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_all_day BOOLEAN NOT NULL DEFAULT FALSE, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_A6B8D1C39D86650F ON calendar_event (mailbox_id)');
        $this->addSql('COMMENT ON COLUMN calendar_event.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN calendar_event.mailbox_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN calendar_event.start_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN calendar_event.end_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN calendar_event.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN calendar_event.updated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE calendar_event');
    }
}
