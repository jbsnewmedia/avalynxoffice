<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\DatabasePurger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;

final class DatabasePurgerTest extends TestCase
{
    public function testPurgeKeepsUsersAndSystemTablesWhenRequested(): void
    {
        $connection = $this->createConnection('postgresql', [
            'app_user',
            'mailbox',
            'email',
            'doctrine_migration_versions',
            'messenger_messages',
        ]);

        $statements = [];
        $this->captureStatements($connection, $statements);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');

        $purger = new DatabasePurger($this->createEntityManager($connection, $this->createMetadata('app_user')));

        self::assertSame(['mailbox', 'email'], $purger->purge(true));
        self::assertContains('TRUNCATE mailbox, email', $statements);
        self::assertContains("SET session_replication_role = 'replica'", $statements);
        self::assertContains("SET session_replication_role = 'origin'", $statements);
    }

    public function testPurgeClearsAllManagedTablesWithoutKeepingUsers(): void
    {
        $connection = $this->createConnection('postgresql', [
            'app_user',
            'mailbox',
            'email',
            'messenger_messages',
        ]);

        $statements = [];
        $this->captureStatements($connection, $statements);

        $purger = new DatabasePurger($this->createEntityManager($connection, $this->createMetadata('app_user')));

        self::assertSame(['app_user', 'mailbox', 'email'], $purger->purge(false));
        self::assertContains('TRUNCATE app_user, mailbox, email', $statements);
    }

    public function testPurgeFallsBackToDeleteOnPostgresql(): void
    {
        $connection = $this->createConnection('postgresql', ['a', 'b']);

        $statements = [];
        $connection->expects(self::any())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;
                if (str_starts_with($sql, 'TRUNCATE ')) {
                    throw new \RuntimeException('truncate denied');
                }

                return 1;
            });

        $purger = new DatabasePurger($this->createEntityManager($connection, $this->createMetadata('app_user')));
        $purger->purge(false);

        self::assertContains('DELETE FROM a', $statements);
        self::assertContains('DELETE FROM b', $statements);
    }

    public function testPurgeDeletesEachTableOnSqlite(): void
    {
        $connection = $this->createConnection('sqlite', ['t1', 't2']);

        $statements = [];
        $this->captureStatements($connection, $statements);

        $purger = new DatabasePurger($this->createEntityManager($connection, $this->createMetadata('app_user')));
        $purger->purge(false);

        self::assertContains('PRAGMA foreign_keys = OFF', $statements);
        self::assertContains('DELETE FROM t1', $statements);
        self::assertContains('DELETE FROM t2', $statements);
        self::assertContains('PRAGMA foreign_keys = ON', $statements);
    }

    public function testPurgeTruncatesEachTableOnMysql(): void
    {
        $connection = $this->createConnection('mysql', ['t1', 't2']);

        $statements = [];
        $this->captureStatements($connection, $statements);

        $purger = new DatabasePurger($this->createEntityManager($connection, $this->createMetadata('app_user')));
        $purger->purge(false);

        self::assertContains('SET FOREIGN_KEY_CHECKS = 0', $statements);
        self::assertContains('TRUNCATE t1', $statements);
        self::assertContains('TRUNCATE t2', $statements);
        self::assertContains('SET FOREIGN_KEY_CHECKS = 1', $statements);
    }

    public function testPurgeFallsBackToDeleteOnMysql(): void
    {
        $connection = $this->createConnection('mysql', ['t1']);

        $statements = [];
        $connection->expects(self::any())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;
                if ('TRUNCATE t1' === $sql) {
                    throw new \RuntimeException('truncate denied');
                }

                return 1;
            });

        $purger = new DatabasePurger($this->createEntityManager($connection, $this->createMetadata('app_user')));
        $purger->purge(false);

        self::assertContains('DELETE FROM t1', $statements);
    }

    public function testPurgeRollsBackReenablesForeignKeysAndRethrowsOnFailure(): void
    {
        $connection = $this->createConnection('mysql', ['t1']);

        $statements = [];
        $connection->expects(self::any())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;
                if ('TRUNCATE t1' === $sql || 'DELETE FROM t1' === $sql) {
                    throw new \RuntimeException('cannot clear');
                }

                return 1;
            });

        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('commit');

        $purger = new DatabasePurger($this->createEntityManager($connection, $this->createMetadata('app_user')));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot clear');
        try {
            $purger->purge(false);
        } finally {
            self::assertContains('SET FOREIGN_KEY_CHECKS = 0', $statements);
            self::assertContains('SET FOREIGN_KEY_CHECKS = 1', $statements);
        }
    }

    public function testPurgeReturnsEmptyListAndSkipsTruncateWhenNoManagedTables(): void
    {
        $connection = $this->createConnection('postgresql', []);

        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');

        $purger = new DatabasePurger($this->createEntityManager($connection, $this->createMetadata('app_user')));

        self::assertSame([], $purger->purge(false));
    }

    /**
     * @param list<string> $statements
     */
    private function captureStatements(Connection $connection, array &$statements): void
    {
        $connection->expects(self::any())
            ->method('executeStatement')
            ->willReturnCallback(static function (string $sql) use (&$statements): int {
                $statements[] = $sql;

                return 1;
            });
    }

    private function createConnection(string $platformName, array $tableNames): Connection
    {
        $platform = match ($platformName) {
            'sqlite' => new SQLitePlatform(),
            'postgresql' => new PostgreSQLPlatform(),
            'mysql' => new MySQLPlatform(),
            default => throw new \InvalidArgumentException(sprintf('Unsupported platform "%s".', $platformName)),
        };

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects(self::once())->method('listTableNames')->willReturn($tableNames);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('createSchemaManager')->willReturn($schemaManager);
        $connection->expects(self::any())->method('getDatabasePlatform')->willReturn($platform);

        return $connection;
    }

    private function createEntityManager(Connection $connection, ClassMetadata $metadata): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        return $entityManager;
    }

    /**
     * @return ClassMetadata<User>
     */
    private function createMetadata(string $tableName): ClassMetadata
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getTableName')->willReturn($tableName);

        return $metadata;
    }
}
