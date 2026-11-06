<?php

declare(strict_types=1);

namespace AvalynxOffice\Tests\Db\Type;

use AvalynxOffice\Db\Type\UuidType;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * The portable UUID type stores RFC-9562 strings on every platform:
 * PostgreSQL keeps its native uuid column, MariaDB/MySQL get CHAR(36).
 */
final class UuidTypeTest extends TestCase
{
    private const string UUID = '018f6a1b-7c15-7abc-9def-3f2a1b4c5d6e';

    public function testGetSQLDeclarationUsesChar36OnMysqlAndGuidOnOthers(): void
    {
        $type = new UuidType();

        $mysql = $type->getSQLDeclaration([], new MariaDBPlatform());
        self::assertSame((new MariaDBPlatform())->getStringTypeDeclarationSQL(['length' => 36, 'fixed' => true]), $mysql);

        $postgres = $type->getSQLDeclaration([], new PostgreSQLPlatform());
        self::assertSame((new PostgreSQLPlatform())->getGuidTypeDeclarationSQL([]), $postgres);
    }

    public function testConvertToPHPValuePassesNullAndUuidInstancesThrough(): void
    {
        $type = new UuidType();
        $platform = new PostgreSQLPlatform();

        self::assertNull($type->convertToPHPValue(null, $platform));

        $uuid = new UuidV7();
        self::assertSame($uuid, $type->convertToPHPValue($uuid, $platform));
    }

    public function testConvertToPHPValueParsesUuidStrings(): void
    {
        $type = new UuidType();
        $platform = new PostgreSQLPlatform();

        $parsed = $type->convertToPHPValue(self::UUID, $platform);

        self::assertInstanceOf(Uuid::class, $parsed);
        self::assertSame(self::UUID, $parsed->toRfc4122());
    }

    public function testConvertToPHPValueRejectsInvalidValues(): void
    {
        $type = new UuidType();
        $platform = new PostgreSQLPlatform();

        try {
            $type->convertToPHPValue('not-a-uuid', $platform);
            self::fail('An invalid string must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Invalid UUID value "not-a-uuid".', $exception->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $type->convertToPHPValue(['not', 'scalar'], $platform);
    }

    public function testConvertToDatabaseValueHandlesNullEmptyAndInstances(): void
    {
        $type = new UuidType();
        $platform = new PostgreSQLPlatform();

        self::assertNull($type->convertToDatabaseValue(null, $platform));
        self::assertNull($type->convertToDatabaseValue('', $platform));
        self::assertNull($type->convertToDatabaseValue(['not', 'scalar'], $platform));

        $uuid = new UuidV7();
        self::assertSame($uuid->toRfc4122(), $type->convertToDatabaseValue($uuid, $platform));
        self::assertSame(self::UUID, $type->convertToDatabaseValue(self::UUID, $platform));
    }

    public function testConvertToDatabaseValueRejectsInvalidStrings(): void
    {
        $type = new UuidType();
        $platform = new PostgreSQLPlatform();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid UUID value "nope".');
        $type->convertToDatabaseValue('nope', $platform);
    }

    public function testGetMappedDatabaseTypesRegistersUuid(): void
    {
        $type = new UuidType();

        self::assertSame(['uuid'], $type->getMappedDatabaseTypes(new PostgreSQLPlatform()));
    }
}
