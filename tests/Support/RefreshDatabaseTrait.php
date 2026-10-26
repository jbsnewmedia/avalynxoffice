<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Support;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

trait RefreshDatabaseTrait
{
    protected function refreshDatabase(EntityManagerInterface $entityManager): void
    {
        $connection = $entityManager->getConnection();

        if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            $connection->executeStatement('DROP SCHEMA public CASCADE');
            $connection->executeStatement('CREATE SCHEMA public');
            $connection->executeStatement('SET search_path TO public');

            $tool = new SchemaTool($entityManager);
            $tool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
            // The doctrine remember-me token table is not an entity and is
            // therefore not part of the schema tool metadata.
            $connection->executeStatement('DROP TABLE IF EXISTS rememberme_token');
            $connection->executeStatement("CREATE TABLE rememberme_token (series VARCHAR(88) NOT NULL, value VARCHAR(88) NOT NULL, lastUsed TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, class VARCHAR(100) DEFAULT '' NOT NULL, username VARCHAR(200) NOT NULL, PRIMARY KEY(series))");
            $connection->executeStatement('CREATE INDEX idx_rememberme_token_username ON rememberme_token (username)');

            return;
        }

        $tool = new SchemaTool($entityManager);
        $classes = $entityManager->getMetadataFactory()->getAllMetadata();

        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
