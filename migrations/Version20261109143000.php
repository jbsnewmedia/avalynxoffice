<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Full Exchange ActiveSync field support for contacts and calendar:
 * every SyncContact/SyncAppointment field with a storage counterpart
 * gets a column; recurring series gain an exception child table.
 */
final class Version20261109143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add EAS contact/calendar columns and calendar_event_exception';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->statements(
            [
                'ALTER TABLE address ADD COLUMN middlename VARCHAR(100) DEFAULT NULL, ADD COLUMN nickname VARCHAR(100) DEFAULT NULL, ADD COLUMN name_prefix VARCHAR(50) DEFAULT NULL, ADD COLUMN name_suffix VARCHAR(50) DEFAULT NULL, ADD COLUMN department VARCHAR(100) DEFAULT NULL, ADD COLUMN job_title VARCHAR(190) DEFAULT NULL, ADD COLUMN office_location VARCHAR(190) DEFAULT NULL, ADD COLUMN assistant_name VARCHAR(100) DEFAULT NULL, ADD COLUMN manager_name VARCHAR(100) DEFAULT NULL, ADD COLUMN spouse_name VARCHAR(100) DEFAULT NULL, ADD COLUMN website VARCHAR(190) DEFAULT NULL, ADD COLUMN business_phone2 VARCHAR(60) DEFAULT NULL, ADD COLUMN business_fax_number VARCHAR(60) DEFAULT NULL, ADD COLUMN home_phone2 VARCHAR(60) DEFAULT NULL, ADD COLUMN home_fax_number VARCHAR(60) DEFAULT NULL, ADD COLUMN car_phone VARCHAR(60) DEFAULT NULL, ADD COLUMN pager_number VARCHAR(60) DEFAULT NULL, ADD COLUMN radio_number VARCHAR(60) DEFAULT NULL, ADD COLUMN assistant_phone VARCHAR(60) DEFAULT NULL, ADD COLUMN company_phone VARCHAR(60) DEFAULT NULL, ADD COLUMN mms VARCHAR(60) DEFAULT NULL, ADD COLUMN email2 VARCHAR(190) DEFAULT NULL, ADD COLUMN email3 VARCHAR(190) DEFAULT NULL, ADD COLUMN im_address VARCHAR(190) DEFAULT NULL, ADD COLUMN im_address2 VARCHAR(190) DEFAULT NULL, ADD COLUMN im_address3 VARCHAR(190) DEFAULT NULL, ADD COLUMN customer_id VARCHAR(190) DEFAULT NULL, ADD COLUMN government_id VARCHAR(190) DEFAULT NULL, ADD COLUMN account_name VARCHAR(190) DEFAULT NULL, ADD COLUMN yomi_company VARCHAR(100) DEFAULT NULL, ADD COLUMN yomi_firstname VARCHAR(100) DEFAULT NULL, ADD COLUMN yomi_lastname VARCHAR(100) DEFAULT NULL, ADD COLUMN birthday DATE DEFAULT NULL, ADD COLUMN anniversary DATE DEFAULT NULL, ADD COLUMN business_street VARCHAR(190) DEFAULT NULL, ADD COLUMN business_city VARCHAR(100) DEFAULT NULL, ADD COLUMN business_state VARCHAR(100) DEFAULT NULL, ADD COLUMN business_postal_code VARCHAR(20) DEFAULT NULL, ADD COLUMN business_country VARCHAR(100) DEFAULT NULL, ADD COLUMN home_street VARCHAR(190) DEFAULT NULL, ADD COLUMN home_city VARCHAR(100) DEFAULT NULL, ADD COLUMN home_state VARCHAR(100) DEFAULT NULL, ADD COLUMN home_postal_code VARCHAR(20) DEFAULT NULL, ADD COLUMN home_country VARCHAR(100) DEFAULT NULL, ADD COLUMN other_street VARCHAR(190) DEFAULT NULL, ADD COLUMN other_city VARCHAR(100) DEFAULT NULL, ADD COLUMN other_state VARCHAR(100) DEFAULT NULL, ADD COLUMN other_postal_code VARCHAR(20) DEFAULT NULL, ADD COLUMN other_country VARCHAR(100) DEFAULT NULL, ADD COLUMN note TEXT DEFAULT NULL, ADD COLUMN children JSON DEFAULT NULL, ADD COLUMN categories JSON DEFAULT NULL, ADD COLUMN picture TEXT DEFAULT NULL',
                    'ALTER TABLE calendar_event ADD COLUMN organizer_name VARCHAR(190) DEFAULT NULL, ADD COLUMN organizer_email VARCHAR(190) DEFAULT NULL, ADD COLUMN attendees JSON DEFAULT NULL, ADD COLUMN meeting_status SMALLINT DEFAULT NULL, ADD COLUMN response_type SMALLINT DEFAULT NULL, ADD COLUMN response_requested BOOLEAN DEFAULT NULL, ADD COLUMN disallow_new_time_proposal BOOLEAN DEFAULT NULL, ADD COLUMN online_meeting_conf_link VARCHAR(255) DEFAULT NULL, ADD COLUMN online_meeting_external_link VARCHAR(255) DEFAULT NULL, ADD COLUMN categories JSON DEFAULT NULL, ADD COLUMN recurrence_occurrences INTEGER DEFAULT NULL, ADD COLUMN recurrence_calendar_type SMALLINT DEFAULT NULL, ADD COLUMN recurrence_first_day_of_week SMALLINT DEFAULT NULL',
                    'CREATE TABLE calendar_event_exception (id UUID NOT NULL, event_id UUID NOT NULL, exception_start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, is_deleted BOOLEAN DEFAULT FALSE NOT NULL, title VARCHAR(190) DEFAULT NULL, start_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_all_day BOOLEAN DEFAULT FALSE NOT NULL, location VARCHAR(190) DEFAULT NULL, description TEXT DEFAULT NULL, busy_status SMALLINT DEFAULT NULL, reminder SMALLINT DEFAULT NULL, PRIMARY KEY (id))',
                    'CREATE INDEX idx_calendar_event_exception_event ON calendar_event_exception (event_id)',
                    'ALTER TABLE calendar_event_exception ADD CONSTRAINT FK_calendar_event_exception_event FOREIGN KEY (event_id) REFERENCES calendar_event (id) ON DELETE CASCADE NOT DEFERRABLE',
                ],
            [
                'ALTER TABLE address ADD COLUMN middlename VARCHAR(100) DEFAULT NULL, ADD COLUMN nickname VARCHAR(100) DEFAULT NULL, ADD COLUMN name_prefix VARCHAR(50) DEFAULT NULL, ADD COLUMN name_suffix VARCHAR(50) DEFAULT NULL, ADD COLUMN department VARCHAR(100) DEFAULT NULL, ADD COLUMN job_title VARCHAR(190) DEFAULT NULL, ADD COLUMN office_location VARCHAR(190) DEFAULT NULL, ADD COLUMN assistant_name VARCHAR(100) DEFAULT NULL, ADD COLUMN manager_name VARCHAR(100) DEFAULT NULL, ADD COLUMN spouse_name VARCHAR(100) DEFAULT NULL, ADD COLUMN website VARCHAR(190) DEFAULT NULL, ADD COLUMN business_phone2 VARCHAR(60) DEFAULT NULL, ADD COLUMN business_fax_number VARCHAR(60) DEFAULT NULL, ADD COLUMN home_phone2 VARCHAR(60) DEFAULT NULL, ADD COLUMN home_fax_number VARCHAR(60) DEFAULT NULL, ADD COLUMN car_phone VARCHAR(60) DEFAULT NULL, ADD COLUMN pager_number VARCHAR(60) DEFAULT NULL, ADD COLUMN radio_number VARCHAR(60) DEFAULT NULL, ADD COLUMN assistant_phone VARCHAR(60) DEFAULT NULL, ADD COLUMN company_phone VARCHAR(60) DEFAULT NULL, ADD COLUMN mms VARCHAR(60) DEFAULT NULL, ADD COLUMN email2 VARCHAR(190) DEFAULT NULL, ADD COLUMN email3 VARCHAR(190) DEFAULT NULL, ADD COLUMN im_address VARCHAR(190) DEFAULT NULL, ADD COLUMN im_address2 VARCHAR(190) DEFAULT NULL, ADD COLUMN im_address3 VARCHAR(190) DEFAULT NULL, ADD COLUMN customer_id VARCHAR(190) DEFAULT NULL, ADD COLUMN government_id VARCHAR(190) DEFAULT NULL, ADD COLUMN account_name VARCHAR(190) DEFAULT NULL, ADD COLUMN yomi_company VARCHAR(100) DEFAULT NULL, ADD COLUMN yomi_firstname VARCHAR(100) DEFAULT NULL, ADD COLUMN yomi_lastname VARCHAR(100) DEFAULT NULL, ADD COLUMN birthday DATE DEFAULT NULL, ADD COLUMN anniversary DATE DEFAULT NULL, ADD COLUMN business_street VARCHAR(190) DEFAULT NULL, ADD COLUMN business_city VARCHAR(100) DEFAULT NULL, ADD COLUMN business_state VARCHAR(100) DEFAULT NULL, ADD COLUMN business_postal_code VARCHAR(20) DEFAULT NULL, ADD COLUMN business_country VARCHAR(100) DEFAULT NULL, ADD COLUMN home_street VARCHAR(190) DEFAULT NULL, ADD COLUMN home_city VARCHAR(100) DEFAULT NULL, ADD COLUMN home_state VARCHAR(100) DEFAULT NULL, ADD COLUMN home_postal_code VARCHAR(20) DEFAULT NULL, ADD COLUMN home_country VARCHAR(100) DEFAULT NULL, ADD COLUMN other_street VARCHAR(190) DEFAULT NULL, ADD COLUMN other_city VARCHAR(100) DEFAULT NULL, ADD COLUMN other_state VARCHAR(100) DEFAULT NULL, ADD COLUMN other_postal_code VARCHAR(20) DEFAULT NULL, ADD COLUMN other_country VARCHAR(100) DEFAULT NULL, ADD COLUMN note LONGTEXT DEFAULT NULL, ADD COLUMN children JSON DEFAULT NULL, ADD COLUMN categories JSON DEFAULT NULL, ADD COLUMN picture LONGTEXT DEFAULT NULL',
                'ALTER TABLE calendar_event ADD COLUMN organizer_name VARCHAR(190) DEFAULT NULL, ADD COLUMN organizer_email VARCHAR(190) DEFAULT NULL, ADD COLUMN attendees JSON DEFAULT NULL, ADD COLUMN meeting_status SMALLINT DEFAULT NULL, ADD COLUMN response_type SMALLINT DEFAULT NULL, ADD COLUMN response_requested TINYINT(1) DEFAULT NULL, ADD COLUMN disallow_new_time_proposal TINYINT(1) DEFAULT NULL, ADD COLUMN online_meeting_conf_link VARCHAR(255) DEFAULT NULL, ADD COLUMN online_meeting_external_link VARCHAR(255) DEFAULT NULL, ADD COLUMN categories JSON DEFAULT NULL, ADD COLUMN recurrence_occurrences INTEGER DEFAULT NULL, ADD COLUMN recurrence_calendar_type SMALLINT DEFAULT NULL, ADD COLUMN recurrence_first_day_of_week SMALLINT DEFAULT NULL',
                'CREATE TABLE calendar_event_exception (id CHAR(36) NOT NULL, event_id CHAR(36) NOT NULL, exception_start_at DATETIME NOT NULL, is_deleted TINYINT(1) DEFAULT 0 NOT NULL, title VARCHAR(190) DEFAULT NULL, start_at DATETIME DEFAULT NULL, end_at DATETIME DEFAULT NULL, is_all_day TINYINT(1) DEFAULT 0 NOT NULL, location VARCHAR(190) DEFAULT NULL, description LONGTEXT DEFAULT NULL, busy_status SMALLINT DEFAULT NULL, reminder SMALLINT DEFAULT NULL, INDEX idx_calendar_event_exception_event (event_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                'ALTER TABLE calendar_event_exception ADD CONSTRAINT FK_calendar_event_exception_event FOREIGN KEY (event_id) REFERENCES calendar_event (id) ON DELETE CASCADE',
            ],
        ) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->statements(
            [
                'ALTER TABLE calendar_event_exception DROP CONSTRAINT FK_calendar_event_exception_event',
                'DROP TABLE calendar_event_exception',
                'ALTER TABLE calendar_event DROP COLUMN organizer_name, DROP COLUMN organizer_email, DROP COLUMN attendees, DROP COLUMN meeting_status, DROP COLUMN response_type, DROP COLUMN response_requested, DROP COLUMN disallow_new_time_proposal, DROP COLUMN online_meeting_conf_link, DROP COLUMN online_meeting_external_link, DROP COLUMN categories, DROP COLUMN recurrence_occurrences, DROP COLUMN recurrence_calendar_type, DROP COLUMN recurrence_first_day_of_week',
                'ALTER TABLE address DROP COLUMN middlename, DROP COLUMN nickname, DROP COLUMN name_prefix, DROP COLUMN name_suffix, DROP COLUMN department, DROP COLUMN job_title, DROP COLUMN office_location, DROP COLUMN assistant_name, DROP COLUMN manager_name, DROP COLUMN spouse_name, DROP COLUMN website, DROP COLUMN business_phone2, DROP COLUMN business_fax_number, DROP COLUMN home_phone2, DROP COLUMN home_fax_number, DROP COLUMN car_phone, DROP COLUMN pager_number, DROP COLUMN radio_number, DROP COLUMN assistant_phone, DROP COLUMN company_phone, DROP COLUMN mms, DROP COLUMN email2, DROP COLUMN email3, DROP COLUMN im_address, DROP COLUMN im_address2, DROP COLUMN im_address3, DROP COLUMN customer_id, DROP COLUMN government_id, DROP COLUMN account_name, DROP COLUMN yomi_company, DROP COLUMN yomi_firstname, DROP COLUMN yomi_lastname, DROP COLUMN birthday, DROP COLUMN anniversary, DROP COLUMN business_street, DROP COLUMN business_city, DROP COLUMN business_state, DROP COLUMN business_postal_code, DROP COLUMN business_country, DROP COLUMN home_street, DROP COLUMN home_city, DROP COLUMN home_state, DROP COLUMN home_postal_code, DROP COLUMN home_country, DROP COLUMN other_street, DROP COLUMN other_city, DROP COLUMN other_state, DROP COLUMN other_postal_code, DROP COLUMN other_country, DROP COLUMN note, DROP COLUMN children, DROP COLUMN categories, DROP COLUMN picture',
            ],
            [
                'ALTER TABLE calendar_event_exception DROP FOREIGN KEY FK_calendar_event_exception_event',
                'DROP TABLE calendar_event_exception',
                'ALTER TABLE calendar_event DROP COLUMN organizer_name, DROP COLUMN organizer_email, DROP COLUMN attendees, DROP COLUMN meeting_status, DROP COLUMN response_type, DROP COLUMN response_requested, DROP COLUMN disallow_new_time_proposal, DROP COLUMN online_meeting_conf_link, DROP COLUMN online_meeting_external_link, DROP COLUMN categories, DROP COLUMN recurrence_occurrences, DROP COLUMN recurrence_calendar_type, DROP COLUMN recurrence_first_day_of_week',
                'ALTER TABLE address DROP COLUMN middlename, DROP COLUMN nickname, DROP COLUMN name_prefix, DROP COLUMN name_suffix, DROP COLUMN department, DROP COLUMN job_title, DROP COLUMN office_location, DROP COLUMN assistant_name, DROP COLUMN manager_name, DROP COLUMN spouse_name, DROP COLUMN website, DROP COLUMN business_phone2, DROP COLUMN business_fax_number, DROP COLUMN home_phone2, DROP COLUMN home_fax_number, DROP COLUMN car_phone, DROP COLUMN pager_number, DROP COLUMN radio_number, DROP COLUMN assistant_phone, DROP COLUMN company_phone, DROP COLUMN mms, DROP COLUMN email2, DROP COLUMN email3, DROP COLUMN im_address, DROP COLUMN im_address2, DROP COLUMN im_address3, DROP COLUMN customer_id, DROP COLUMN government_id, DROP COLUMN account_name, DROP COLUMN yomi_company, DROP COLUMN yomi_firstname, DROP COLUMN yomi_lastname, DROP COLUMN birthday, DROP COLUMN anniversary, DROP COLUMN business_street, DROP COLUMN business_city, DROP COLUMN business_state, DROP COLUMN business_postal_code, DROP COLUMN business_country, DROP COLUMN home_street, DROP COLUMN home_city, DROP COLUMN home_state, DROP COLUMN home_postal_code, DROP COLUMN home_country, DROP COLUMN other_street, DROP COLUMN other_city, DROP COLUMN other_state, DROP COLUMN other_postal_code, DROP COLUMN other_country, DROP COLUMN note, DROP COLUMN children, DROP COLUMN categories, DROP COLUMN picture',
            ],
        ) as $sql) {
            $this->addSql($sql);
        }
    }

    /**
     * @param list<string> $postgresql
     * @param list<string> $mariadb
     * @return list<string>
     */
    private function statements(array $postgresql, array $mariadb): array
    {
        return $this->platform instanceof AbstractMySQLPlatform ? $mariadb : $postgresql;
    }
}
