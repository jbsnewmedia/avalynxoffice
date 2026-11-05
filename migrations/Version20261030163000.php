<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
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
        foreach ($this->statements(
            [
                'CREATE TABLE app_user_app_password (id UUID NOT NULL, label VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))',
                'CREATE INDEX IDX_1CCBF7E5A76ED395 ON app_user_app_password (user_id)',
                'ALTER TABLE app_user_app_password ADD CONSTRAINT FK_1CCBF7E5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE',
            ],
            [
                'CREATE TABLE app_user_app_password (id CHAR(36) NOT NULL, label VARCHAR(100) NOT NULL, password_hash VARCHAR(255) NOT NULL, last_used_at DATETIME DEFAULT NULL, revoked_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id CHAR(36) NOT NULL, INDEX IDX_1CCBF7E5A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                'ALTER TABLE app_user_app_password ADD CONSTRAINT FK_1CCBF7E5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE',
            ],
        ) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->statements(
            [
                'ALTER TABLE app_user_app_password DROP CONSTRAINT FK_1CCBF7E5A76ED395',
                'DROP TABLE app_user_app_password',
            ],
            [
                'ALTER TABLE app_user_app_password DROP FOREIGN KEY FK_1CCBF7E5A76ED395',
                'DROP TABLE app_user_app_password',
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
