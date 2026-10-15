<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align calendar_event recurrence column names with the Doctrine naming scheme.';
    }

    public function up(Schema $schema): void
    {
        // Idempotent: Version20261005210000 creates the columns on fresh
        // databases with the final names already, so each rename only runs
        // when the legacy name still exists (databases migrated with the
        // original migration versions).
        $this->addSql(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_dayofweek')
        AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_day_of_week') THEN
        ALTER TABLE calendar_event RENAME COLUMN recurrence_dayofweek TO recurrence_day_of_week;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_dayofmonth')
        AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_day_of_month') THEN
        ALTER TABLE calendar_event RENAME COLUMN recurrence_dayofmonth TO recurrence_day_of_month;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_weekofmonth')
        AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_week_of_month') THEN
        ALTER TABLE calendar_event RENAME COLUMN recurrence_weekofmonth TO recurrence_week_of_month;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_monthofyear')
        AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_month_of_year') THEN
        ALTER TABLE calendar_event RENAME COLUMN recurrence_monthofyear TO recurrence_month_of_year;
    END IF;
END
$$
SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_day_of_week')
        AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_dayofweek') THEN
        ALTER TABLE calendar_event RENAME COLUMN recurrence_day_of_week TO recurrence_dayofweek;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_day_of_month')
        AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_dayofmonth') THEN
        ALTER TABLE calendar_event RENAME COLUMN recurrence_day_of_month TO recurrence_dayofmonth;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_week_of_month')
        AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_weekofmonth') THEN
        ALTER TABLE calendar_event RENAME COLUMN recurrence_week_of_month TO recurrence_weekofmonth;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_month_of_year')
        AND NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'calendar_event' AND column_name = 'recurrence_monthofyear') THEN
        ALTER TABLE calendar_event RENAME COLUMN recurrence_month_of_year TO recurrence_monthofyear;
    END IF;
END
$$
SQL);
    }
}
