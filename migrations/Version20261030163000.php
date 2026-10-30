<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Device/app passwords for Exchange ActiveSync clients.
 */
final class Version20261030163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_user_app_password (per-device app passwords for EAS)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_user_app_password (id UUID NOT NULL, label VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_1CCBF7E5A76ED395 ON app_user_app_password (user_id)');
        $this->addSql('ALTER TABLE app_user_app_password ADD CONSTRAINT FK_1CCBF7E5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user_app_password DROP CONSTRAINT FK_1CCBF7E5A76ED395');
        $this->addSql('DROP TABLE app_user_app_password');
    }
}
