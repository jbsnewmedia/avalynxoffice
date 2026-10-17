<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261017110232 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE address (id UUID NOT NULL, email VARCHAR(190) NOT NULL, name VARCHAR(190) DEFAULT NULL, is_contact BOOLEAN DEFAULT false NOT NULL, firstname VARCHAR(100) DEFAULT NULL, lastname VARCHAR(100) DEFAULT NULL, company VARCHAR(190) DEFAULT NULL, business_phone VARCHAR(60) DEFAULT NULL, mobile_phone VARCHAR(60) DEFAULT NULL, home_phone VARCHAR(60) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, active BOOLEAN DEFAULT true NOT NULL, last_auth_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)');
        $this->addSql('CREATE TABLE attachment (id UUID NOT NULL, name VARCHAR(255) NOT NULL, size INT NOT NULL, mime_type VARCHAR(190) NOT NULL, sha1 VARCHAR(40) NOT NULL, file_path VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE body (id UUID NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE calendar_event (id UUID NOT NULL, title VARCHAR(190) NOT NULL, location VARCHAR(190) DEFAULT NULL, description TEXT DEFAULT NULL, start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_all_day BOOLEAN DEFAULT false NOT NULL, timezone VARCHAR(64) DEFAULT NULL, recurrence_type SMALLINT DEFAULT NULL, recurrence_interval INT DEFAULT NULL, recurrence_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, recurrence_day_of_week SMALLINT DEFAULT NULL, recurrence_day_of_month SMALLINT DEFAULT NULL, recurrence_week_of_month SMALLINT DEFAULT NULL, recurrence_month_of_year SMALLINT DEFAULT NULL, uid VARCHAR(190) DEFAULT NULL, dtstamp TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, busy_status SMALLINT DEFAULT NULL, sensitivity SMALLINT DEFAULT NULL, reminder SMALLINT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mailbox_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_calendar_event_uid ON calendar_event (uid)');
        $this->addSql('CREATE INDEX IDX_57FA09C966EC35CC ON calendar_event (mailbox_id)');
        $this->addSql('CREATE TABLE email (id UUID NOT NULL, content_hash VARCHAR(64) DEFAULT NULL, subject VARCHAR(255) DEFAULT NULL, preview_body TEXT DEFAULT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_outgoing BOOLEAN NOT NULL, is_read BOOLEAN NOT NULL, is_favorite BOOLEAN DEFAULT false NOT NULL, is_important BOOLEAN DEFAULT false NOT NULL, is_draft BOOLEAN DEFAULT false NOT NULL, is_sending BOOLEAN DEFAULT false NOT NULL, is_sent BOOLEAN DEFAULT false NOT NULL, license_hold BOOLEAN DEFAULT false NOT NULL, has_encoding_issues BOOLEAN DEFAULT false NOT NULL, scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, message_id VARCHAR(255) DEFAULT NULL, import_key VARCHAR(190) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mailbox_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E7927C741CDA8F7D ON email (content_hash)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E7927C741EFD0B29 ON email (import_key)');
        $this->addSql('CREATE INDEX IDX_E7927C7466EC35CC ON email (mailbox_id)');
        $this->addSql('CREATE TABLE email_address_link (id UUID NOT NULL, type VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, email_id UUID NOT NULL, address_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1EF3AF49A832C1C9 ON email_address_link (email_id)');
        $this->addSql('CREATE INDEX IDX_1EF3AF49F5B7AF75 ON email_address_link (address_id)');
        $this->addSql('CREATE TABLE email_attachment_link (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, email_id UUID NOT NULL, attachment_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_2CBF08C6A832C1C9 ON email_attachment_link (email_id)');
        $this->addSql('CREATE INDEX IDX_2CBF08C6464E68B ON email_attachment_link (attachment_id)');
        $this->addSql('CREATE TABLE email_body_link (id UUID NOT NULL, content_type VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, email_id UUID NOT NULL, body_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_6C23BAB8A832C1C9 ON email_body_link (email_id)');
        $this->addSql('CREATE INDEX IDX_6C23BAB89B621D84 ON email_body_link (body_id)');
        $this->addSql('CREATE TABLE email_folder (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, email_id UUID NOT NULL, folder_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_9138DA3DA832C1C9 ON email_folder (email_id)');
        $this->addSql('CREATE INDEX IDX_9138DA3D162CB942 ON email_folder (folder_id)');
        $this->addSql('CREATE TABLE folder (id UUID NOT NULL, name VARCHAR(190) NOT NULL, functions JSON DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE license_key (id UUID NOT NULL, key_hash VARCHAR(64) NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, license_key TEXT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_C54B257BFB971 ON license_key (key_hash)');
        $this->addSql('CREATE TABLE license_state (id INT NOT NULL, organization_id VARCHAR(64) DEFAULT NULL, installation_id VARCHAR(64) DEFAULT NULL, violation_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, grace_period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_known_status VARCHAR(20) DEFAULT NULL, last_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE mailbox (id UUID NOT NULL, name VARCHAR(190) NOT NULL, email VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, inbox_folder_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_A69FE20B6C6BD431 ON mailbox (inbox_folder_id)');
        $this->addSql('CREATE TABLE mailbox_address (created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mailbox_id UUID NOT NULL, address_id UUID NOT NULL, PRIMARY KEY (mailbox_id, address_id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mailbox_address ON mailbox_address (mailbox_id, address_id)');
        $this->addSql('CREATE INDEX IDX_A304EB3466EC35CC ON mailbox_address (mailbox_id)');
        $this->addSql('CREATE INDEX IDX_A304EB34F5B7AF75 ON mailbox_address (address_id)');
        $this->addSql('CREATE TABLE mailbox_connector (id UUID NOT NULL, name VARCHAR(190) NOT NULL, email VARCHAR(190) NOT NULL, pop3_host VARCHAR(190) DEFAULT NULL, pop3_port INT DEFAULT NULL, pop3_username VARCHAR(190) DEFAULT NULL, pop3_password VARCHAR(255) DEFAULT NULL, pop3_security VARCHAR(20) DEFAULT NULL, pop3_delete_after_fetch BOOLEAN DEFAULT true NOT NULL, smtp_host VARCHAR(190) DEFAULT NULL, smtp_port INT DEFAULT NULL, smtp_username VARCHAR(190) DEFAULT NULL, smtp_password VARCHAR(255) DEFAULT NULL, smtp_security VARCHAR(20) DEFAULT NULL, is_primary BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mailbox_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mailbox_connector_email ON mailbox_connector (mailbox_id, email)');
        $this->addSql('CREATE INDEX IDX_67EBF6E066EC35CC ON mailbox_connector (mailbox_id)');
        $this->addSql('CREATE TABLE mailbox_folder (sort_order INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mailbox_id UUID NOT NULL, folder_id UUID NOT NULL, PRIMARY KEY (mailbox_id, folder_id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_mailbox_folder ON mailbox_folder (mailbox_id, folder_id)');
        $this->addSql('CREATE INDEX IDX_774CF62566EC35CC ON mailbox_folder (mailbox_id)');
        $this->addSql('CREATE INDEX IDX_774CF625162CB942 ON mailbox_folder (folder_id)');
        $this->addSql('CREATE TABLE user_mailbox (created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, mailbox_id UUID NOT NULL, PRIMARY KEY (user_id, mailbox_id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_mailbox ON user_mailbox (user_id, mailbox_id)');
        $this->addSql('CREATE INDEX IDX_FE92FC01A76ED395 ON user_mailbox (user_id)');
        $this->addSql('CREATE INDEX IDX_FE92FC0166EC35CC ON user_mailbox (mailbox_id)');
        $this->addSql('ALTER TABLE calendar_event ADD CONSTRAINT FK_57FA09C966EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email ADD CONSTRAINT FK_E7927C7466EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_address_link ADD CONSTRAINT FK_1EF3AF49A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_address_link ADD CONSTRAINT FK_1EF3AF49F5B7AF75 FOREIGN KEY (address_id) REFERENCES address (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_attachment_link ADD CONSTRAINT FK_2CBF08C6A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_attachment_link ADD CONSTRAINT FK_2CBF08C6464E68B FOREIGN KEY (attachment_id) REFERENCES attachment (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_body_link ADD CONSTRAINT FK_6C23BAB8A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_body_link ADD CONSTRAINT FK_6C23BAB89B621D84 FOREIGN KEY (body_id) REFERENCES body (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_folder ADD CONSTRAINT FK_9138DA3DA832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE email_folder ADD CONSTRAINT FK_9138DA3D162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox ADD CONSTRAINT FK_A69FE20B6C6BD431 FOREIGN KEY (inbox_folder_id) REFERENCES folder (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_address ADD CONSTRAINT FK_A304EB3466EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_address ADD CONSTRAINT FK_A304EB34F5B7AF75 FOREIGN KEY (address_id) REFERENCES address (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_connector ADD CONSTRAINT FK_67EBF6E066EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_folder ADD CONSTRAINT FK_774CF62566EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE mailbox_folder ADD CONSTRAINT FK_774CF625162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_mailbox ADD CONSTRAINT FK_FE92FC01A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_mailbox ADD CONSTRAINT FK_FE92FC0166EC35CC FOREIGN KEY (mailbox_id) REFERENCES mailbox (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE calendar_event DROP CONSTRAINT FK_57FA09C966EC35CC');
        $this->addSql('ALTER TABLE email DROP CONSTRAINT FK_E7927C7466EC35CC');
        $this->addSql('ALTER TABLE email_address_link DROP CONSTRAINT FK_1EF3AF49A832C1C9');
        $this->addSql('ALTER TABLE email_address_link DROP CONSTRAINT FK_1EF3AF49F5B7AF75');
        $this->addSql('ALTER TABLE email_attachment_link DROP CONSTRAINT FK_2CBF08C6A832C1C9');
        $this->addSql('ALTER TABLE email_attachment_link DROP CONSTRAINT FK_2CBF08C6464E68B');
        $this->addSql('ALTER TABLE email_body_link DROP CONSTRAINT FK_6C23BAB8A832C1C9');
        $this->addSql('ALTER TABLE email_body_link DROP CONSTRAINT FK_6C23BAB89B621D84');
        $this->addSql('ALTER TABLE email_folder DROP CONSTRAINT FK_9138DA3DA832C1C9');
        $this->addSql('ALTER TABLE email_folder DROP CONSTRAINT FK_9138DA3D162CB942');
        $this->addSql('ALTER TABLE mailbox DROP CONSTRAINT FK_A69FE20B6C6BD431');
        $this->addSql('ALTER TABLE mailbox_address DROP CONSTRAINT FK_A304EB3466EC35CC');
        $this->addSql('ALTER TABLE mailbox_address DROP CONSTRAINT FK_A304EB34F5B7AF75');
        $this->addSql('ALTER TABLE mailbox_connector DROP CONSTRAINT FK_67EBF6E066EC35CC');
        $this->addSql('ALTER TABLE mailbox_folder DROP CONSTRAINT FK_774CF62566EC35CC');
        $this->addSql('ALTER TABLE mailbox_folder DROP CONSTRAINT FK_774CF625162CB942');
        $this->addSql('ALTER TABLE user_mailbox DROP CONSTRAINT FK_FE92FC01A76ED395');
        $this->addSql('ALTER TABLE user_mailbox DROP CONSTRAINT FK_FE92FC0166EC35CC');
        $this->addSql('DROP TABLE address');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE attachment');
        $this->addSql('DROP TABLE body');
        $this->addSql('DROP TABLE calendar_event');
        $this->addSql('DROP TABLE email');
        $this->addSql('DROP TABLE email_address_link');
        $this->addSql('DROP TABLE email_attachment_link');
        $this->addSql('DROP TABLE email_body_link');
        $this->addSql('DROP TABLE email_folder');
        $this->addSql('DROP TABLE folder');
        $this->addSql('DROP TABLE license_key');
        $this->addSql('DROP TABLE license_state');
        $this->addSql('DROP TABLE mailbox');
        $this->addSql('DROP TABLE mailbox_address');
        $this->addSql('DROP TABLE mailbox_connector');
        $this->addSql('DROP TABLE mailbox_folder');
        $this->addSql('DROP TABLE user_mailbox');
    }
}
