<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add licensing tables (license_state, license_key), user activation flag and mail license hold flag.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE license_state (id INT NOT NULL, organization_id VARCHAR(64) DEFAULT NULL, installation_id VARCHAR(64) DEFAULT NULL, violation_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, grace_period_ends_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, last_known_status VARCHAR(20) DEFAULT NULL, last_checked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN license_state.violation_started_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN license_state.grace_period_ends_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN license_state.last_checked_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE license_key (id UUID NOT NULL, key_hash VARCHAR(64) NOT NULL, license_key TEXT NOT NULL, added_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_license_key_hash ON license_key (key_hash)');
        $this->addSql('COMMENT ON COLUMN license_key.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN license_key.added_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE app_user ADD active BOOLEAN NOT NULL DEFAULT true');
        $this->addSql('ALTER TABLE email ADD license_hold BOOLEAN NOT NULL DEFAULT false');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE license_state');
        $this->addSql('DROP TABLE license_key');
        $this->addSql('ALTER TABLE app_user DROP active');
        $this->addSql('ALTER TABLE email DROP license_hold');
    }
}
