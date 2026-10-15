<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add contact fields to the address table for the EAS contacts interface.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address ADD is_contact BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE address ADD firstname VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE address ADD lastname VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE address ADD company VARCHAR(190) DEFAULT NULL');
        $this->addSql('ALTER TABLE address ADD business_phone VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE address ADD mobile_phone VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE address ADD home_phone VARCHAR(60) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address DROP is_contact');
        $this->addSql('ALTER TABLE address DROP firstname');
        $this->addSql('ALTER TABLE address DROP lastname');
        $this->addSql('ALTER TABLE address DROP company');
        $this->addSql('ALTER TABLE address DROP business_phone');
        $this->addSql('ALTER TABLE address DROP mobile_phone');
        $this->addSql('ALTER TABLE address DROP home_phone');
    }
}
