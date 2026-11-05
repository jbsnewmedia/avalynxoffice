<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Db\Type;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Symfony\Component\Uid\Uuid;

/**
 * Portable UUID type: RFC-9562 strings on every platform.
 *
 * The Symfony bridge type stores BINARY(16) on MariaDB/MySQL, which breaks
 * every query that binds a UUID without an explicit DBAL type (the value is
 * stringified via __toString() and never matches the binary column).
 * PostgreSQL keeps its native uuid column, MariaDB/MySQL get CHAR(36) -
 * both store the same RFC-9562 strings, so DQL queries, native queries and
 * the EAS plugin (which shares this database) behave identically on both
 * platforms.
 *
 * Registered as the "uuid" type in config/packages/doctrine.yaml; the
 * doctrine-bridge compiler pass therefore keeps its hands off.
 */
final class UuidType extends Type
{
    #[\Override]
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        if ($platform instanceof AbstractMySQLPlatform) {
            return $platform->getStringTypeDeclarationSQL([
                'length' => 36,
                'fixed' => true,
            ]);
        }

        return $platform->getGuidTypeDeclarationSQL($column);
    }

    #[\Override]
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Uuid
    {
        if (null === $value || $value instanceof Uuid) {
            return $value;
        }

        $valueString = is_scalar($value) ? (string) $value : '';

        try {
            return Uuid::fromString($valueString);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(sprintf('Invalid UUID value "%s".', $valueString), 0, $exception);
        }
    }

    #[\Override]
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }

        $valueString = is_scalar($value) ? (string) $value : null;

        if (null === $valueString || '' === $valueString) {
            return null;
        }

        try {
            return Uuid::fromString($valueString)->toRfc4122();
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException(sprintf('Invalid UUID value "%s".', $valueString), 0, $exception);
        }
    }

    #[\Override]
    public function getMappedDatabaseTypes(AbstractPlatform $platform): array
    {
        return ['uuid'];
    }
}
