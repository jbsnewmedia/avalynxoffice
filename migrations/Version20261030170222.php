<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Double opt-in for recovery addresses: verification state and token hash.
 */
final class Version20261030170222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add verified_at, verification_token_hash and verification_expires_at to app_recovery_address';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->statements(
            [
                'ALTER TABLE app_recovery_address ADD verified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
                'ALTER TABLE app_recovery_address ADD verification_token_hash VARCHAR(64) DEFAULT NULL',
                'ALTER TABLE app_recovery_address ADD verification_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL',
            ],
            [
                'ALTER TABLE app_recovery_address ADD verified_at DATETIME DEFAULT NULL',
                'ALTER TABLE app_recovery_address ADD verification_token_hash VARCHAR(64) DEFAULT NULL',
                'ALTER TABLE app_recovery_address ADD verification_expires_at DATETIME DEFAULT NULL',
            ],
        ) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->statements(
            [
                'ALTER TABLE app_recovery_address DROP verified_at',
                'ALTER TABLE app_recovery_address DROP verification_token_hash',
                'ALTER TABLE app_recovery_address DROP verification_expires_at',
            ],
            [
                'ALTER TABLE app_recovery_address DROP verified_at',
                'ALTER TABLE app_recovery_address DROP verification_token_hash',
                'ALTER TABLE app_recovery_address DROP verification_expires_at',
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
