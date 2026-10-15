<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add timezone and recurrence fields to calendar_event for the EAS calendar sync.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event ADD timezone VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD recurrence_type SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD recurrence_interval INT DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD recurrence_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD recurrence_day_of_week SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD recurrence_day_of_month SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD recurrence_week_of_month SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE calendar_event ADD recurrence_month_of_year SMALLINT DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN calendar_event.recurrence_until IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE calendar_event DROP timezone');
        $this->addSql('ALTER TABLE calendar_event DROP recurrence_type');
        $this->addSql('ALTER TABLE calendar_event DROP recurrence_interval');
        $this->addSql('ALTER TABLE calendar_event DROP recurrence_until');
        $this->addSql('ALTER TABLE calendar_event DROP recurrence_day_of_week');
        $this->addSql('ALTER TABLE calendar_event DROP recurrence_day_of_month');
        $this->addSql('ALTER TABLE calendar_event DROP recurrence_week_of_month');
        $this->addSql('ALTER TABLE calendar_event DROP recurrence_month_of_year');
    }
}
