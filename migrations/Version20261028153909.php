<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Complete fresh database baseline: builds the whole AvalynxOffice schema
 * (accounts, connectors, mails, contacts, calendar, users, sessions, sync
 * runs, license tables) plus the rememberme_token table of the doctrine
 * remember-me token provider with its username lookup index.
 */
final class Version20261028153909 extends AbstractMigration
{
    /**
     * PostgreSQL statements (the primary development platform).
     *
     * @var list<string>
     */
    private const array POSTGRESQL_UP = [
        'CREATE TABLE account (id UUID NOT NULL, name VARCHAR(190) NOT NULL, email VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, inbox_folder_id UUID DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE INDEX IDX_7D3656A46C6BD431 ON account (inbox_folder_id)',
        'CREATE TABLE account_address (created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, account_id UUID NOT NULL, address_id UUID NOT NULL, PRIMARY KEY (account_id, address_id))',
        'CREATE UNIQUE INDEX uniq_account_address ON account_address (account_id, address_id)',
        'CREATE INDEX IDX_C4657BC99B6B5FBA ON account_address (account_id)',
        'CREATE INDEX IDX_C4657BC9F5B7AF75 ON account_address (address_id)',
        'CREATE TABLE account_alias (id UUID NOT NULL, local_part VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, account_id UUID NOT NULL, connector_id UUID NOT NULL, PRIMARY KEY (id))',
        'CREATE UNIQUE INDEX uniq_account_alias_address ON account_alias (connector_id, local_part)',
        'CREATE INDEX IDX_D7E3B2E59B6B5FBA ON account_alias (account_id)',
        'CREATE INDEX IDX_D7E3B2E54D085745 ON account_alias (connector_id)',
        'CREATE TABLE account_folder (sort_order INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, account_id UUID NOT NULL, folder_id UUID NOT NULL, PRIMARY KEY (account_id, folder_id))',
        'CREATE UNIQUE INDEX uniq_account_folder ON account_folder (account_id, folder_id)',
        'CREATE INDEX IDX_CB96C7BE9B6B5FBA ON account_folder (account_id)',
        'CREATE INDEX IDX_CB96C7BE162CB942 ON account_folder (folder_id)',
        'CREATE TABLE address (id UUID NOT NULL, email VARCHAR(190) NOT NULL, name VARCHAR(190) DEFAULT NULL, is_contact BOOLEAN DEFAULT false NOT NULL, firstname VARCHAR(100) DEFAULT NULL, lastname VARCHAR(100) DEFAULT NULL, company VARCHAR(190) DEFAULT NULL, business_phone VARCHAR(60) DEFAULT NULL, mobile_phone VARCHAR(60) DEFAULT NULL, home_phone VARCHAR(60) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE TABLE app_password_reset_request (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, release_number_hash VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, release_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, created_by_admin UUID DEFAULT NULL, released_by_admin UUID DEFAULT NULL, created_ip VARCHAR(45) DEFAULT NULL, released_ip VARCHAR(45) DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))',
        'CREATE UNIQUE INDEX UNIQ_E8CA1AC0B3BC57DA ON app_password_reset_request (token_hash)',
        'CREATE INDEX IDX_E8CA1AC0A76ED395 ON app_password_reset_request (user_id)',
        'CREATE TABLE app_recovery_address (id UUID NOT NULL, email VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))',
        'CREATE UNIQUE INDEX uniq_recovery_address_user_email ON app_recovery_address (user_id, email)',
        'CREATE INDEX IDX_D0A90DAFA76ED395 ON app_recovery_address (user_id)',
        'CREATE TABLE app_setting (id UUID NOT NULL, setting_key VARCHAR(190) NOT NULL, setting_value TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE UNIQUE INDEX UNIQ_722938D55FA1E697 ON app_setting (setting_key)',
        'CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, active BOOLEAN DEFAULT true NOT NULL, last_auth_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL, time_format VARCHAR(16) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE UNIQUE INDEX UNIQ_88BDF3E9E7927C74 ON app_user (email)',
        'CREATE TABLE app_user_login_session (id UUID NOT NULL, session_token VARCHAR(64) NOT NULL, remember_me BOOLEAN DEFAULT false NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent TEXT DEFAULT NULL, browser VARCHAR(60) DEFAULT NULL, os VARCHAR(60) DEFAULT NULL, geo_city VARCHAR(100) DEFAULT NULL, geo_country VARCHAR(100) DEFAULT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))',
        'CREATE UNIQUE INDEX UNIQ_4EF6AD81844A19ED ON app_user_login_session (session_token)',
        'CREATE INDEX IDX_4EF6AD81A76ED395 ON app_user_login_session (user_id)',
        'CREATE TABLE attachment (id UUID NOT NULL, name VARCHAR(255) NOT NULL, content_id VARCHAR(255) DEFAULT NULL, size INT NOT NULL, mime_type VARCHAR(190) NOT NULL, sha1 VARCHAR(40) NOT NULL, file_path VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE TABLE body (id UUID NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE TABLE calendar_event (id UUID NOT NULL, title VARCHAR(190) NOT NULL, location VARCHAR(190) DEFAULT NULL, description TEXT DEFAULT NULL, start_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_all_day BOOLEAN DEFAULT false NOT NULL, timezone VARCHAR(64) DEFAULT NULL, recurrence_type SMALLINT DEFAULT NULL, recurrence_interval INT DEFAULT NULL, recurrence_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, recurrence_day_of_week SMALLINT DEFAULT NULL, recurrence_day_of_month SMALLINT DEFAULT NULL, recurrence_week_of_month SMALLINT DEFAULT NULL, recurrence_month_of_year SMALLINT DEFAULT NULL, uid VARCHAR(190) DEFAULT NULL, dtstamp TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, busy_status SMALLINT DEFAULT NULL, sensitivity SMALLINT DEFAULT NULL, reminder SMALLINT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, account_id UUID DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE INDEX idx_calendar_event_uid ON calendar_event (uid)',
        'CREATE INDEX IDX_57FA09C99B6B5FBA ON calendar_event (account_id)',
        'CREATE TABLE connector (id UUID NOT NULL, name VARCHAR(190) NOT NULL, domain VARCHAR(190) NOT NULL, plugin_key VARCHAR(32) DEFAULT NULL, fetch_from DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, default_account_id UUID DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE INDEX IDX_148C456ED3480BD9 ON connector (default_account_id)',
        'CREATE TABLE connector_config (id UUID NOT NULL, plugin_key VARCHAR(100) NOT NULL, config JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, connector_id UUID NOT NULL, PRIMARY KEY (id))',
        'CREATE UNIQUE INDEX uniq_connector_config ON connector_config (connector_id, plugin_key)',
        'CREATE INDEX IDX_2C8F9C34D085745 ON connector_config (connector_id)',
        'CREATE TABLE email (id UUID NOT NULL, content_hash VARCHAR(64) DEFAULT NULL, subject VARCHAR(255) DEFAULT NULL, preview_body TEXT DEFAULT NULL, received_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_outgoing BOOLEAN NOT NULL, is_read BOOLEAN NOT NULL, is_favorite BOOLEAN DEFAULT false NOT NULL, is_important BOOLEAN DEFAULT false NOT NULL, is_draft BOOLEAN DEFAULT false NOT NULL, is_sending BOOLEAN DEFAULT false NOT NULL, is_sent BOOLEAN DEFAULT false NOT NULL, license_hold BOOLEAN DEFAULT false NOT NULL, has_encoding_issues BOOLEAN DEFAULT false NOT NULL, scheduled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, matched_local_part VARCHAR(190) DEFAULT NULL, message_id VARCHAR(255) DEFAULT NULL, import_key VARCHAR(190) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, account_id UUID DEFAULT NULL, connector_id UUID DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE UNIQUE INDEX UNIQ_E7927C741CDA8F7D ON email (content_hash)',
        'CREATE UNIQUE INDEX UNIQ_E7927C741EFD0B29 ON email (import_key)',
        'CREATE INDEX IDX_E7927C749B6B5FBA ON email (account_id)',
        'CREATE INDEX IDX_E7927C744D085745 ON email (connector_id)',
        'CREATE TABLE email_address_link (id UUID NOT NULL, type VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, email_id UUID NOT NULL, address_id UUID NOT NULL, PRIMARY KEY (id))',
        'CREATE INDEX IDX_1EF3AF49A832C1C9 ON email_address_link (email_id)',
        'CREATE INDEX IDX_1EF3AF49F5B7AF75 ON email_address_link (address_id)',
        'CREATE TABLE email_attachment_link (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, email_id UUID NOT NULL, attachment_id UUID NOT NULL, PRIMARY KEY (id))',
        'CREATE INDEX IDX_2CBF08C6A832C1C9 ON email_attachment_link (email_id)',
        'CREATE INDEX IDX_2CBF08C6464E68B ON email_attachment_link (attachment_id)',
        'CREATE TABLE email_body_link (id UUID NOT NULL, content_type VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, email_id UUID NOT NULL, body_id UUID NOT NULL, PRIMARY KEY (id))',
        'CREATE INDEX IDX_6C23BAB8A832C1C9 ON email_body_link (email_id)',
        'CREATE INDEX IDX_6C23BAB89B621D84 ON email_body_link (body_id)',
        'CREATE TABLE email_folder (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, email_id UUID NOT NULL, folder_id UUID NOT NULL, PRIMARY KEY (id))',
        'CREATE INDEX IDX_9138DA3DA832C1C9 ON email_folder (email_id)',
        'CREATE INDEX IDX_9138DA3D162CB942 ON email_folder (folder_id)',
        'CREATE TABLE folder (id UUID NOT NULL, name VARCHAR(190) NOT NULL, functions JSON DEFAULT \'[]\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE TABLE license_key (id UUID NOT NULL, key_hash VARCHAR(64) NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, license_key TEXT NOT NULL, PRIMARY KEY (id))',
        'CREATE UNIQUE INDEX UNIQ_C54B257BFB971 ON license_key (key_hash)',
        'CREATE TABLE license_state (id INT NOT NULL, organization_id VARCHAR(64) DEFAULT NULL, installation_id VARCHAR(64) DEFAULT NULL, violation_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, grace_period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_known_status VARCHAR(20) DEFAULT NULL, last_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE TABLE sync_run (id UUID NOT NULL, status VARCHAR(16) NOT NULL, trigger VARCHAR(16) NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, finished_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, mails_imported INT DEFAULT 0 NOT NULL, mails_skipped INT DEFAULT 0 NOT NULL, mails_errors INT DEFAULT 0 NOT NULL, contacts_count INT DEFAULT 0 NOT NULL, calendar_count INT DEFAULT 0 NOT NULL, message TEXT DEFAULT NULL, PRIMARY KEY (id))',
        'CREATE TABLE user_account (created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, account_id UUID NOT NULL, PRIMARY KEY (user_id, account_id))',
        'CREATE UNIQUE INDEX uniq_user_account ON user_account (user_id, account_id)',
        'CREATE INDEX IDX_253B48AEA76ED395 ON user_account (user_id)',
        'CREATE INDEX IDX_253B48AE9B6B5FBA ON user_account (account_id)',
        'CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, class VARCHAR(100) DEFAULT \'\' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY (series))',
        'CREATE INDEX idx_rememberme_token_username ON rememberme_token (username)',
        'ALTER TABLE account ADD CONSTRAINT FK_7D3656A46C6BD431 FOREIGN KEY (inbox_folder_id) REFERENCES folder (id) NOT DEFERRABLE',
        'ALTER TABLE account_address ADD CONSTRAINT FK_C4657BC99B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE account_address ADD CONSTRAINT FK_C4657BC9F5B7AF75 FOREIGN KEY (address_id) REFERENCES address (id) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE account_alias ADD CONSTRAINT FK_D7E3B2E59B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE account_alias ADD CONSTRAINT FK_D7E3B2E54D085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE account_folder ADD CONSTRAINT FK_CB96C7BE9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE',
        'ALTER TABLE account_folder ADD CONSTRAINT FK_CB96C7BE162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) NOT DEFERRABLE',
        'ALTER TABLE app_password_reset_request ADD CONSTRAINT FK_E8CA1AC0A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE app_recovery_address ADD CONSTRAINT FK_D0A90DAFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE app_user_login_session ADD CONSTRAINT FK_4EF6AD81A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE calendar_event ADD CONSTRAINT FK_57FA09C99B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE',
        'ALTER TABLE connector ADD CONSTRAINT FK_148C456ED3480BD9 FOREIGN KEY (default_account_id) REFERENCES account (id) ON DELETE SET NULL NOT DEFERRABLE',
        'ALTER TABLE connector_config ADD CONSTRAINT FK_2C8F9C34D085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE CASCADE NOT DEFERRABLE',
        'ALTER TABLE email ADD CONSTRAINT FK_E7927C749B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE',
        'ALTER TABLE email ADD CONSTRAINT FK_E7927C744D085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE SET NULL NOT DEFERRABLE',
        'ALTER TABLE email_address_link ADD CONSTRAINT FK_1EF3AF49A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) NOT DEFERRABLE',
        'ALTER TABLE email_address_link ADD CONSTRAINT FK_1EF3AF49F5B7AF75 FOREIGN KEY (address_id) REFERENCES address (id) NOT DEFERRABLE',
        'ALTER TABLE email_attachment_link ADD CONSTRAINT FK_2CBF08C6A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) NOT DEFERRABLE',
        'ALTER TABLE email_attachment_link ADD CONSTRAINT FK_2CBF08C6464E68B FOREIGN KEY (attachment_id) REFERENCES attachment (id) NOT DEFERRABLE',
        'ALTER TABLE email_body_link ADD CONSTRAINT FK_6C23BAB8A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) NOT DEFERRABLE',
        'ALTER TABLE email_body_link ADD CONSTRAINT FK_6C23BAB89B621D84 FOREIGN KEY (body_id) REFERENCES body (id) NOT DEFERRABLE',
        'ALTER TABLE email_folder ADD CONSTRAINT FK_9138DA3DA832C1C9 FOREIGN KEY (email_id) REFERENCES email (id) NOT DEFERRABLE',
        'ALTER TABLE email_folder ADD CONSTRAINT FK_9138DA3D162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id) NOT DEFERRABLE',
        'ALTER TABLE user_account ADD CONSTRAINT FK_253B48AEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) NOT DEFERRABLE',
        'ALTER TABLE user_account ADD CONSTRAINT FK_253B48AE9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) NOT DEFERRABLE',
    ];

    /**
     * @var list<string>
     */
    private const array POSTGRESQL_DOWN = [
        'ALTER TABLE account DROP CONSTRAINT FK_7D3656A46C6BD431',
        'ALTER TABLE account_address DROP CONSTRAINT FK_C4657BC99B6B5FBA',
        'ALTER TABLE account_address DROP CONSTRAINT FK_C4657BC9F5B7AF75',
        'ALTER TABLE account_alias DROP CONSTRAINT FK_D7E3B2E59B6B5FBA',
        'ALTER TABLE account_alias DROP CONSTRAINT FK_D7E3B2E54D085745',
        'ALTER TABLE account_folder DROP CONSTRAINT FK_CB96C7BE9B6B5FBA',
        'ALTER TABLE account_folder DROP CONSTRAINT FK_CB96C7BE162CB942',
        'ALTER TABLE app_password_reset_request DROP CONSTRAINT FK_E8CA1AC0A76ED395',
        'ALTER TABLE app_recovery_address DROP CONSTRAINT FK_D0A90DAFA76ED395',
        'ALTER TABLE app_user_login_session DROP CONSTRAINT FK_4EF6AD81A76ED395',
        'ALTER TABLE calendar_event DROP CONSTRAINT FK_57FA09C99B6B5FBA',
        'ALTER TABLE connector DROP CONSTRAINT FK_148C456ED3480BD9',
        'ALTER TABLE connector_config DROP CONSTRAINT FK_2C8F9C34D085745',
        'ALTER TABLE email DROP CONSTRAINT FK_E7927C749B6B5FBA',
        'ALTER TABLE email DROP CONSTRAINT FK_E7927C744D085745',
        'ALTER TABLE email_address_link DROP CONSTRAINT FK_1EF3AF49A832C1C9',
        'ALTER TABLE email_address_link DROP CONSTRAINT FK_1EF3AF49F5B7AF75',
        'ALTER TABLE email_attachment_link DROP CONSTRAINT FK_2CBF08C6A832C1C9',
        'ALTER TABLE email_attachment_link DROP CONSTRAINT FK_2CBF08C6464E68B',
        'ALTER TABLE email_body_link DROP CONSTRAINT FK_6C23BAB8A832C1C9',
        'ALTER TABLE email_body_link DROP CONSTRAINT FK_6C23BAB89B621D84',
        'ALTER TABLE email_folder DROP CONSTRAINT FK_9138DA3DA832C1C9',
        'ALTER TABLE email_folder DROP CONSTRAINT FK_9138DA3D162CB942',
        'ALTER TABLE user_account DROP CONSTRAINT FK_253B48AEA76ED395',
        'ALTER TABLE user_account DROP CONSTRAINT FK_253B48AE9B6B5FBA',
        'DROP TABLE account',
        'DROP TABLE account_address',
        'DROP TABLE account_alias',
        'DROP TABLE account_folder',
        'DROP TABLE address',
        'DROP TABLE app_password_reset_request',
        'DROP TABLE app_recovery_address',
        'DROP TABLE app_setting',
        'DROP TABLE app_user',
        'DROP TABLE app_user_login_session',
        'DROP TABLE attachment',
        'DROP TABLE body',
        'DROP TABLE calendar_event',
        'DROP TABLE connector',
        'DROP TABLE connector_config',
        'DROP TABLE email',
        'DROP TABLE email_address_link',
        'DROP TABLE email_attachment_link',
        'DROP TABLE email_body_link',
        'DROP TABLE email_folder',
        'DROP TABLE folder',
        'DROP TABLE license_key',
        'DROP TABLE license_state',
        'DROP TABLE sync_run',
        'DROP TABLE user_account',
        'DROP INDEX idx_rememberme_token_username',
        'DROP TABLE rememberme_token',
    ];

    /**
     * MariaDB statements (shared-hosting variant, e.g. All-Inkl).
     *
     * Generated from the ORM metadata via SchemaTool on MariaDB 10.6 and
     * curated: UUIDs stay CHAR(36) RFC-9562 strings (the portable "uuid"
     * type), booleans become TINYINT, timestamps become DATETIME. Tables
     * are explicitly utf8mb4 with utf8mb4_unicode_ci so they are
     * independent of the server defaults (shared-hosting servers often
     * default to latin1). String comparisons therefore stay
     * case-insensitive on MariaDB, unlike the case-sensitive PostgreSQL
     * defaults.
     *
     * @var list<string>
     */
    private const array MARIADB_UP = [
        'CREATE TABLE account (id CHAR(36) NOT NULL, name VARCHAR(190) NOT NULL, email VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, inbox_folder_id CHAR(36) DEFAULT NULL, INDEX IDX_7D3656A46C6BD431 (inbox_folder_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE account_address (created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, account_id CHAR(36) NOT NULL, address_id CHAR(36) NOT NULL, UNIQUE INDEX uniq_account_address (account_id, address_id), INDEX IDX_C4657BC99B6B5FBA (account_id), INDEX IDX_C4657BC9F5B7AF75 (address_id), PRIMARY KEY (account_id, address_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE account_alias (id CHAR(36) NOT NULL, local_part VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, account_id CHAR(36) NOT NULL, connector_id CHAR(36) NOT NULL, UNIQUE INDEX uniq_account_alias_address (connector_id, local_part), INDEX IDX_D7E3B2E59B6B5FBA (account_id), INDEX IDX_D7E3B2E54D085745 (connector_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE address (id CHAR(36) NOT NULL, email VARCHAR(190) NOT NULL, name VARCHAR(190) DEFAULT NULL, is_contact TINYINT DEFAULT 0 NOT NULL, firstname VARCHAR(100) DEFAULT NULL, lastname VARCHAR(100) DEFAULT NULL, company VARCHAR(190) DEFAULT NULL, business_phone VARCHAR(60) DEFAULT NULL, mobile_phone VARCHAR(60) DEFAULT NULL, home_phone VARCHAR(60) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE app_setting (id CHAR(36) NOT NULL, setting_key VARCHAR(190) NOT NULL, setting_value LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_722938D55FA1E697 (setting_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE attachment (id CHAR(36) NOT NULL, name VARCHAR(255) NOT NULL, content_id VARCHAR(255) DEFAULT NULL, size INT NOT NULL, mime_type VARCHAR(190) NOT NULL, sha1 VARCHAR(40) NOT NULL, file_path VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE body (id CHAR(36) NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE calendar_event (id CHAR(36) NOT NULL, title VARCHAR(190) NOT NULL, location VARCHAR(190) DEFAULT NULL, description LONGTEXT DEFAULT NULL, start_at DATETIME NOT NULL, end_at DATETIME DEFAULT NULL, is_all_day TINYINT DEFAULT 0 NOT NULL, timezone VARCHAR(64) DEFAULT NULL, recurrence_type SMALLINT DEFAULT NULL, recurrence_interval INT DEFAULT NULL, recurrence_until DATETIME DEFAULT NULL, recurrence_day_of_week SMALLINT DEFAULT NULL, recurrence_day_of_month SMALLINT DEFAULT NULL, recurrence_week_of_month SMALLINT DEFAULT NULL, recurrence_month_of_year SMALLINT DEFAULT NULL, uid VARCHAR(190) DEFAULT NULL, dtstamp DATETIME DEFAULT NULL, busy_status SMALLINT DEFAULT NULL, sensitivity SMALLINT DEFAULT NULL, reminder SMALLINT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, account_id CHAR(36) DEFAULT NULL, INDEX idx_calendar_event_uid (uid), INDEX IDX_57FA09C99B6B5FBA (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE connector (id CHAR(36) NOT NULL, name VARCHAR(190) NOT NULL, domain VARCHAR(190) NOT NULL, plugin_key VARCHAR(32) DEFAULT NULL, fetch_from DATE DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, default_account_id CHAR(36) DEFAULT NULL, INDEX IDX_148C456ED3480BD9 (default_account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE connector_config (id CHAR(36) NOT NULL, plugin_key VARCHAR(100) NOT NULL, config JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, connector_id CHAR(36) NOT NULL, UNIQUE INDEX uniq_connector_config (connector_id, plugin_key), INDEX IDX_2C8F9C34D085745 (connector_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE email (id CHAR(36) NOT NULL, content_hash VARCHAR(64) DEFAULT NULL, subject VARCHAR(255) DEFAULT NULL, preview_body LONGTEXT DEFAULT NULL, received_at DATETIME DEFAULT NULL, sent_at DATETIME DEFAULT NULL, is_outgoing TINYINT NOT NULL, is_read TINYINT NOT NULL, is_favorite TINYINT DEFAULT 0 NOT NULL, is_important TINYINT DEFAULT 0 NOT NULL, is_draft TINYINT DEFAULT 0 NOT NULL, is_sending TINYINT DEFAULT 0 NOT NULL, is_sent TINYINT DEFAULT 0 NOT NULL, license_hold TINYINT DEFAULT 0 NOT NULL, has_encoding_issues TINYINT DEFAULT 0 NOT NULL, scheduled_at DATETIME DEFAULT NULL, matched_local_part VARCHAR(190) DEFAULT NULL, message_id VARCHAR(255) DEFAULT NULL, import_key VARCHAR(190) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, account_id CHAR(36) DEFAULT NULL, connector_id CHAR(36) DEFAULT NULL, UNIQUE INDEX UNIQ_E7927C741CDA8F7D (content_hash), UNIQUE INDEX UNIQ_E7927C741EFD0B29 (import_key), INDEX IDX_E7927C749B6B5FBA (account_id), INDEX IDX_E7927C744D085745 (connector_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE email_address_link (id CHAR(36) NOT NULL, type VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, email_id CHAR(36) NOT NULL, address_id CHAR(36) NOT NULL, INDEX IDX_1EF3AF49A832C1C9 (email_id), INDEX IDX_1EF3AF49F5B7AF75 (address_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE email_attachment_link (id CHAR(36) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, email_id CHAR(36) NOT NULL, attachment_id CHAR(36) NOT NULL, INDEX IDX_2CBF08C6A832C1C9 (email_id), INDEX IDX_2CBF08C6464E68B (attachment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE email_body_link (id CHAR(36) NOT NULL, content_type VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, email_id CHAR(36) NOT NULL, body_id CHAR(36) NOT NULL, INDEX IDX_6C23BAB8A832C1C9 (email_id), INDEX IDX_6C23BAB89B621D84 (body_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE email_folder (id CHAR(36) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, email_id CHAR(36) NOT NULL, folder_id CHAR(36) NOT NULL, INDEX IDX_9138DA3DA832C1C9 (email_id), INDEX IDX_9138DA3D162CB942 (folder_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE folder (id CHAR(36) NOT NULL, name VARCHAR(190) NOT NULL, functions JSON DEFAULT \'[]\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE account_folder (sort_order INT DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, account_id CHAR(36) NOT NULL, folder_id CHAR(36) NOT NULL, UNIQUE INDEX uniq_account_folder (account_id, folder_id), INDEX IDX_CB96C7BE9B6B5FBA (account_id), INDEX IDX_CB96C7BE162CB942 (folder_id), PRIMARY KEY (account_id, folder_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE app_password_reset_request (id CHAR(36) NOT NULL, token_hash VARCHAR(64) NOT NULL, release_number_hash VARCHAR(64) NOT NULL, status VARCHAR(20) NOT NULL, expires_at DATETIME NOT NULL, release_expires_at DATETIME DEFAULT NULL, attempts INT DEFAULT 0 NOT NULL, created_by_admin CHAR(36) DEFAULT NULL, released_by_admin CHAR(36) DEFAULT NULL, created_ip VARCHAR(45) DEFAULT NULL, released_ip VARCHAR(45) DEFAULT NULL, completed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id CHAR(36) NOT NULL, UNIQUE INDEX UNIQ_E8CA1AC0B3BC57DA (token_hash), INDEX IDX_E8CA1AC0A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE app_recovery_address (id CHAR(36) NOT NULL, email VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id CHAR(36) NOT NULL, UNIQUE INDEX uniq_recovery_address_user_email (user_id, email), INDEX IDX_D0A90DAFA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE sync_run (id CHAR(36) NOT NULL, status VARCHAR(16) NOT NULL, `trigger` VARCHAR(16) NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, mails_imported INT DEFAULT 0 NOT NULL, mails_skipped INT DEFAULT 0 NOT NULL, mails_errors INT DEFAULT 0 NOT NULL, contacts_count INT DEFAULT 0 NOT NULL, calendar_count INT DEFAULT 0 NOT NULL, message LONGTEXT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE app_user (id CHAR(36) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, active TINYINT DEFAULT 1 NOT NULL, last_auth_at DATETIME DEFAULT NULL, timezone VARCHAR(64) DEFAULT NULL, time_format VARCHAR(16) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, UNIQUE INDEX UNIQ_88BDF3E9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE user_account (created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id CHAR(36) NOT NULL, account_id CHAR(36) NOT NULL, UNIQUE INDEX uniq_user_account (user_id, account_id), INDEX IDX_253B48AEA76ED395 (user_id), INDEX IDX_253B48AE9B6B5FBA (account_id), PRIMARY KEY (user_id, account_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE app_user_login_session (id CHAR(36) NOT NULL, session_token VARCHAR(64) NOT NULL, remember_me TINYINT DEFAULT 0 NOT NULL, ip_address VARCHAR(45) DEFAULT NULL, user_agent LONGTEXT DEFAULT NULL, browser VARCHAR(60) DEFAULT NULL, os VARCHAR(60) DEFAULT NULL, geo_city VARCHAR(100) DEFAULT NULL, geo_country VARCHAR(100) DEFAULT NULL, last_seen_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id CHAR(36) NOT NULL, UNIQUE INDEX UNIQ_4EF6AD81844A19ED (session_token), INDEX IDX_4EF6AD81A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE license_state (id INT NOT NULL, organization_id VARCHAR(64) DEFAULT NULL, installation_id VARCHAR(64) DEFAULT NULL, violation_started_at DATETIME DEFAULT NULL, grace_period_ends_at DATETIME DEFAULT NULL, last_known_status VARCHAR(20) DEFAULT NULL, last_checked_at DATETIME DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE license_key (id CHAR(36) NOT NULL, key_hash VARCHAR(64) NOT NULL, added_at DATETIME NOT NULL, license_key LONGTEXT NOT NULL, UNIQUE INDEX UNIQ_C54B257BFB971 (key_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed DATETIME NOT NULL, class VARCHAR(100) DEFAULT \'\' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY (series)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        'ALTER TABLE account ADD CONSTRAINT FK_7D3656A46C6BD431 FOREIGN KEY (inbox_folder_id) REFERENCES folder (id)',
        'ALTER TABLE account_address ADD CONSTRAINT FK_C4657BC99B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE',
        'ALTER TABLE account_address ADD CONSTRAINT FK_C4657BC9F5B7AF75 FOREIGN KEY (address_id) REFERENCES address (id) ON DELETE CASCADE',
        'ALTER TABLE account_alias ADD CONSTRAINT FK_D7E3B2E59B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE',
        'ALTER TABLE account_alias ADD CONSTRAINT FK_D7E3B2E54D085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE CASCADE',
        'ALTER TABLE calendar_event ADD CONSTRAINT FK_57FA09C99B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id)',
        'ALTER TABLE connector ADD CONSTRAINT FK_148C456ED3480BD9 FOREIGN KEY (default_account_id) REFERENCES account (id) ON DELETE SET NULL',
        'ALTER TABLE connector_config ADD CONSTRAINT FK_2C8F9C34D085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE CASCADE',
        'ALTER TABLE email ADD CONSTRAINT FK_E7927C749B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id)',
        'ALTER TABLE email ADD CONSTRAINT FK_E7927C744D085745 FOREIGN KEY (connector_id) REFERENCES connector (id) ON DELETE SET NULL',
        'ALTER TABLE email_address_link ADD CONSTRAINT FK_1EF3AF49A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id)',
        'ALTER TABLE email_address_link ADD CONSTRAINT FK_1EF3AF49F5B7AF75 FOREIGN KEY (address_id) REFERENCES address (id)',
        'ALTER TABLE email_attachment_link ADD CONSTRAINT FK_2CBF08C6A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id)',
        'ALTER TABLE email_attachment_link ADD CONSTRAINT FK_2CBF08C6464E68B FOREIGN KEY (attachment_id) REFERENCES attachment (id)',
        'ALTER TABLE email_body_link ADD CONSTRAINT FK_6C23BAB8A832C1C9 FOREIGN KEY (email_id) REFERENCES email (id)',
        'ALTER TABLE email_body_link ADD CONSTRAINT FK_6C23BAB89B621D84 FOREIGN KEY (body_id) REFERENCES body (id)',
        'ALTER TABLE email_folder ADD CONSTRAINT FK_9138DA3DA832C1C9 FOREIGN KEY (email_id) REFERENCES email (id)',
        'ALTER TABLE email_folder ADD CONSTRAINT FK_9138DA3D162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id)',
        'ALTER TABLE account_folder ADD CONSTRAINT FK_CB96C7BE9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id)',
        'ALTER TABLE account_folder ADD CONSTRAINT FK_CB96C7BE162CB942 FOREIGN KEY (folder_id) REFERENCES folder (id)',
        'ALTER TABLE app_password_reset_request ADD CONSTRAINT FK_E8CA1AC0A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
        'ALTER TABLE app_recovery_address ADD CONSTRAINT FK_D0A90DAFA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
        'ALTER TABLE user_account ADD CONSTRAINT FK_253B48AEA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id)',
        'ALTER TABLE user_account ADD CONSTRAINT FK_253B48AE9B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id)',
        'ALTER TABLE app_user_login_session ADD CONSTRAINT FK_4EF6AD81A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
    ];

    /**
     * @var list<string>
     */
    private const array MARIADB_DOWN = [
        'SET FOREIGN_KEY_CHECKS = 0',
        'DROP TABLE account',
        'DROP TABLE account_address',
        'DROP TABLE account_alias',
        'DROP TABLE account_folder',
        'DROP TABLE address',
        'DROP TABLE app_password_reset_request',
        'DROP TABLE app_recovery_address',
        'DROP TABLE app_setting',
        'DROP TABLE app_user',
        'DROP TABLE app_user_login_session',
        'DROP TABLE attachment',
        'DROP TABLE body',
        'DROP TABLE calendar_event',
        'DROP TABLE connector',
        'DROP TABLE connector_config',
        'DROP TABLE email',
        'DROP TABLE email_address_link',
        'DROP TABLE email_attachment_link',
        'DROP TABLE email_body_link',
        'DROP TABLE email_folder',
        'DROP TABLE folder',
        'DROP TABLE license_key',
        'DROP TABLE license_state',
        'DROP TABLE sync_run',
        'DROP TABLE user_account',
        'DROP TABLE rememberme_token',
        'SET FOREIGN_KEY_CHECKS = 1',
    ];

    #[\Override]
    public function getDescription(): string
    {
        return 'Complete fresh database baseline with rememberme_token.';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        foreach ($this->statements(self::POSTGRESQL_UP, self::MARIADB_UP) as $sql) {
            $this->addSql($sql);
        }
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        foreach ($this->statements(self::POSTGRESQL_DOWN, self::MARIADB_DOWN) as $sql) {
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
