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
            $connection->close();
            $connection->executeStatement('SET search_path TO public');

            $tool = new SchemaTool($entityManager);
            $tool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

            return;
        }

        $tool = new SchemaTool($entityManager);
        $classes = $entityManager->getMetadataFactory()->getAllMetadata();

        $tool->dropSchema($classes);
        $tool->createSchema($classes);
    }
}
