<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add EAS appointment fields (uid, dtstamp, busystatus, sensitivity, reminder) to calendar_event.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event ADD uid VARCHAR(190) DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD dtstamp TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD busy_status SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD sensitivity SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD reminder SMALLINT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_calendar_event_uid ON calendar_event (uid)');
        $this->addSql('COMMENT ON COLUMN calendar_event.dtstamp IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_calendar_event_uid');
        $this->addSql('ALTER TABLE calendar_event DROP uid');
        $this->addSql('ALTER TABLE calendar_event DROP dtstamp');
        $this->addSql('ALTER TABLE calendar_event DROP busy_status');
        $this->addSql('ALTER TABLE calendar_event DROP sensitivity');
        $this->addSql('ALTER TABLE calendar_event DROP reminder');
    }
}
