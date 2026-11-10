<?php

declare(strict_types=1);

/*
 * Copyright (C) 2026 JBS New Media GmbH <https://jbs-newmedia.com>
 * Licensed under the AvalynxOffice Public License (APL) version 1.0.
 */

namespace AvalynxOffice\Service\Backup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Name\UnqualifiedName;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Schema\UniqueConstraint;

/**
 * Platform independent database dump and restore.
 *
 * The schema is introspected via Doctrine and serialized to a portable
 * JSON structure; table data is written as JSONL (one JSON object per
 * row). Because neither format embeds SQL dialect, a backup taken on
 * PostgreSQL can be restored into MariaDB and vice versa — the CREATE
 * statements are regenerated from the schema description for whichever
 * platform the restore runs on.
 *
 * Column values are normalized by the introspected Doctrine type:
 * datetimes become canonical strings, booleans become JSON booleans and
 * binary columns are base64 marked ("b:..."). On insert every value is
 * bound through its column type so both engines receive native values.
 */
final readonly class DatabaseDumper
{
    private const int INSERT_BATCH = 250;

    /** @var list<string> */
    private const array DATETIME_TYPES = [
        'datetime', 'datetime_immutable', 'vardatetime', 'vardatetime_immutable',
        'datetimetz', 'datetimetz_immutable', 'datetime_utc',
        'date', 'date_immutable', 'time', 'time_immutable',
    ];

    /** @var list<string> */
    private const array DATE_TYPES = ['date', 'date_immutable'];

    /** @var list<string> */
    private const array TIME_TYPES = ['time', 'time_immutable'];

    /** @var list<string> */
    private const array BINARY_TYPES = ['blob', 'binary'];

    /** Doctrine type names bound as plain strings (values pass through raw). */
    private const array STRING_BINDING_OVERRIDES = ['json', 'jsonb', 'uuid', 'guid', 'simple_array'];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Serializes the full schema of the current database.
     *
     * @return array{format: int, tables: list<array<string, mixed>>}
     */
    public function dumpSchema(): array
    {
        /** @var list<array<string, mixed>> $tables */
        $tables = [];
        foreach ($this->connection->createSchemaManager()->introspectSchema()->getTables() as $table) {
            $tables[] = $this->serializeTable($table);
        }

        return ['format' => BackupArchive::FORMAT_VERSION, 'tables' => $tables];
    }

    /**
     * Writes all rows of one table as JSONL into $targetFile and returns
     * the row count.
     *
     * @param array<string, mixed> $tableSpec the serialized table definition
     *
     * @throws \RuntimeException when a value cannot be represented safely
     */
    public function dumpTableData(array $tableSpec, string $targetFile): int
    {
        $table = BackupArchive::specString($tableSpec, 'name');
        $typesByColumn = $this->columnTypes($tableSpec);
        $statement = $this->connection->executeQuery(
            'SELECT * FROM '.$this->connection->quoteIdentifier($table),
        );

        $handle = fopen($targetFile, 'wb');
        if (false === $handle) {
            throw new \RuntimeException('Backup data file "'.$targetFile.'" cannot be written.');
        }

        try {
            $rows = 0;
            foreach ($statement->iterateAssociative() as $row) {
                $encoded = [];
                foreach ($row as $column => $value) {
                    if (!is_string($column)) {
                        continue;
                    }

                    $type = $typesByColumn[$column] ?? 'string';
                    $encoded[$column] = $this->normalizeForJson($type, $value, $table, $column);
                }

                $line = json_encode($encoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                fwrite($handle, $line."\n");
                ++$rows;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * Drops every table of the current database. Foreign key checks must
     * already be disabled by the caller (see BackupRestoreService).
     *
     * PostgreSQL checks FK dependencies during DDL regardless of the
     * replication role, so its drops run with CASCADE — safe here because
     * every dropped table is recreated from the backup schema afterwards.
     */
    public function dropAllTables(): void
    {
        $postgresql = $this->isPostgresql();
        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            $this->connection->executeStatement(
                $postgresql
                    ? 'DROP TABLE '.$this->connection->quoteIdentifier($table).' CASCADE'
                    : $this->connection->getDatabasePlatform()->getDropTableSQL($table),
            );
        }
    }

    /**
     * Recreates all tables from a serialized schema description, generated
     * for the platform the restore currently runs on.
     *
     * Foreign key constraints are added in a second pass (ALTER TABLE) once
     * every table exists — CREATE TABLE validates referenced tables
     * regardless of the replication role, and the serialized order may
     * reference not-yet-created tables.
     *
     * @param array<string, mixed> $schema
     */
    public function createTables(array $schema): void
    {
        $tables = $schema['tables'] ?? [];
        if (!is_array($tables)) {
            throw new \RuntimeException('Backup schema has no table list.');
        }

        /** @var list<array{table: string, constraint: ForeignKeyConstraint}> $foreignKeys */
        $foreignKeys = [];
        foreach ($tables as $tableSpec) {
            if (!is_array($tableSpec)) {
                throw new \RuntimeException('Backup schema contains a malformed table entry.');
            }

            $foreignKeys = [...$foreignKeys, ...$this->createTable(BackupArchive::specArray($tableSpec))];
        }

        $platform = $this->connection->getDatabasePlatform();
        foreach ($foreignKeys as $foreignKey) {
            $this->connection->executeStatement(
                $platform->getCreateForeignKeySQL($foreignKey['constraint'], $foreignKey['table']),
            );
        }
    }

    /**
     * Restores the rows of one table in chunked transactions and returns
     * the row count.
     *
     * @param array<string, mixed>               $tableSpec
     * @param iterable<array<string|int, mixed>> $rows      raw JSON-decoded rows
     */
    public function insertRows(array $tableSpec, iterable $rows): int
    {
        $table = BackupArchive::specString($tableSpec, 'name');
        $typesByColumn = $this->columnTypes($tableSpec);

        $inserted = 0;
        $batch = 0;
        $this->connection->beginTransaction();

        try {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException('Backup data of table "'.$table.'" contains a malformed row.');
                }

                $data = [];
                $types = [];
                foreach ($row as $column => $value) {
                    if (!is_string($column)) {
                        continue;
                    }

                    $type = $typesByColumn[$column] ?? 'string';
                    $data[$column] = $this->denormalizeForInsert($type, $value);
                    $types[$column] = $this->bindingType($type);
                }

                $this->connection->insert($table, $data, $types);
                ++$inserted;
                ++$batch;

                if ($batch >= self::INSERT_BATCH) {
                    $this->connection->commit();
                    $this->connection->beginTransaction();
                    $batch = 0;
                }
            }

            $this->connection->commit();
        } catch (\Throwable $throwable) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $throwable;
        }

        return $inserted;
    }

    /**
     * Bumps PostgreSQL identity/serial sequences past the restored data so
     * later inserts cannot collide with restored primary keys. MariaDB and
     * MySQL adjust their counters automatically on explicit-id inserts.
     */
    public function fixSequences(): void
    {
        if (!$this->isPostgresql()) {
            return;
        }

        foreach ($this->connection->createSchemaManager()->introspectSchema()->getTables() as $table) {
            $tableName = $table->getObjectName()->toString();
            foreach ($table->getColumns() as $column) {
                if (!$column->getAutoincrement()) {
                    continue;
                }

                $columnName = $column->getObjectName()->toString();
                $sequence = $this->connection->fetchOne(
                    'SELECT pg_get_serial_sequence(?, ?)',
                    [$tableName, $columnName],
                );
                if (!is_string($sequence) || '' === $sequence) {
                    continue;
                }

                $this->connection->executeStatement(sprintf(
                    'SELECT setval(%s, (SELECT COALESCE(MAX(%s), 0) + 1 FROM %s), false)',
                    $this->connection->quote($sequence),
                    $this->connection->quoteIdentifier($columnName),
                    $this->connection->quoteIdentifier($tableName),
                ));
            }
        }
    }

    /**
     * Whether the current platform is PostgreSQL.
     */
    public function isPostgresql(): bool
    {
        return $this->isPlatform('postgresql');
    }

    /**
     * Executes a raw statement (foreign key toggles around the restore).
     */
    public function executeStatement(string $sql): void
    {
        $this->connection->executeStatement($sql);
    }

    /**
     * Human readable platform name for the manifest.
     */
    public function platformName(): string
    {
        $platform = $this->connection->getDatabasePlatform();

        return strtolower(new \ReflectionClass($platform)->getShortName());
    }

    /**
     * Server version string of the current connection (diagnostics in the
     * manifest), null when it cannot be determined.
     */
    public function databaseVersion(): ?string
    {
        try {
            $version = $this->connection->fetchOne('SELECT version()');

            return is_string($version) ? $version : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $tableSpec
     *
     * @return array<string, string> column name → Doctrine type name
     */
    private function columnTypes(array $tableSpec): array
    {
        $types = [];
        $columns = is_array($tableSpec['columns'] ?? null) ? $tableSpec['columns'] : [];
        foreach ($columns as $column) {
            if (is_array($column) && isset($column['name'], $column['type'])
                && is_string($column['name']) && is_string($column['type'])) {
                $types[$column['name']] = $column['type'];
            }
        }

        return $types;
    }

    /**
     * Creates one table without its foreign key constraints and returns
     * them for the deferred second pass (see createTables).
     *
     * @param array<string, mixed> $tableSpec
     *
     * @return list<array{table: string, constraint: ForeignKeyConstraint}>
     */
    private function createTable(array $tableSpec): array
    {
        $name = BackupArchive::specString($tableSpec, 'name');
        if ('' === $name) {
            throw new \RuntimeException('Backup schema contains a table without a name.');
        }

        $editor = Table::editor()->setUnquotedName($name);

        $columns = $tableSpec['columns'] ?? [];
        if (!is_array($columns)) {
            throw new \RuntimeException('Backup schema of table "'.$name.'" has no column list.');
        }

        foreach ($columns as $column) {
            if (!is_array($column)) {
                throw new \RuntimeException('Backup schema of table "'.$name.'" contains a malformed column.');
            }

            $editor->addColumn($this->buildColumn(BackupArchive::specArray($column)));
        }

        $primaryKeyColumns = BackupArchive::specStringList(
            BackupArchive::specArray($tableSpec['primaryKey'] ?? []),
            'columns',
        );
        if ([] !== $primaryKeyColumns) {
            $constraint = PrimaryKeyConstraint::editor()
                ->setUnquotedColumnNames(...$this->nonEmptyStrings($primaryKeyColumns))
                ->create();
            $editor->addPrimaryKeyConstraint($constraint);
        }

        $indexes = $tableSpec['indexes'] ?? [];
        if (is_array($indexes)) {
            foreach ($indexes as $index) {
                if (!is_array($index)) {
                    throw new \RuntimeException('Backup schema of table "'.$name.'" contains a malformed index.');
                }

                $indexSpec = BackupArchive::specArray($index);
                $indexColumns = BackupArchive::specStringList($indexSpec, 'columns');
                if ([] === $indexColumns) {
                    throw new \RuntimeException('Backup schema of table "'.$name.'" contains an index without columns.');
                }

                $editor->addIndex(new Index(
                    BackupArchive::specString($indexSpec, 'name'),
                    $indexColumns,
                    BackupArchive::specBool($indexSpec, 'unique'),
                    false,
                    BackupArchive::specStringList($indexSpec, 'flags'),
                ));
            }
        }

        $uniqueConstraints = $tableSpec['uniqueConstraints'] ?? [];
        if (is_array($uniqueConstraints)) {
            foreach ($uniqueConstraints as $constraint) {
                if (!is_array($constraint)) {
                    throw new \RuntimeException('Backup schema of table "'.$name.'" contains a malformed unique constraint.');
                }

                $constraintSpec = BackupArchive::specArray($constraint);
                $constraintColumns = BackupArchive::specStringList($constraintSpec, 'columns');
                if ([] === $constraintColumns) {
                    throw new \RuntimeException('Backup schema of table "'.$name.'" contains a unique constraint without columns.');
                }

                $editor->addUniqueConstraint(new UniqueConstraint(
                    BackupArchive::specString($constraintSpec, 'name'),
                    $constraintColumns,
                ));
            }
        }

        $foreignKeys = $tableSpec['foreignKeys'] ?? [];
        $deferredForeignKeys = [];
        if (is_array($foreignKeys)) {
            foreach ($foreignKeys as $foreignKey) {
                if (!is_array($foreignKey)) {
                    throw new \RuntimeException('Backup schema of table "'.$name.'" contains a malformed foreign key.');
                }

                $fkSpec = BackupArchive::specArray($foreignKey);
                $localColumns = BackupArchive::specStringList($fkSpec, 'local');
                $foreignColumns = BackupArchive::specStringList($fkSpec, 'foreign');
                $foreignTable = BackupArchive::specString($fkSpec, 'foreignTable');
                if ([] === $localColumns || [] === $foreignColumns || '' === $foreignTable) {
                    throw new \RuntimeException('Backup schema of table "'.$name.'" contains a malformed foreign key definition.');
                }

                $options = [];
                foreach (['onUpdate', 'onDelete'] as $key) {
                    $action = BackupArchive::specString($fkSpec, $key);
                    if ('' !== $action) {
                        $options[$key] = $action;
                    }
                }

                $constraint = new ForeignKeyConstraint(
                    $localColumns,
                    $foreignTable,
                    $foreignColumns,
                    BackupArchive::specString($fkSpec, 'name'),
                    $options,
                );
                // NOT added to the editor: PostgreSQL's getCreateTableSQL
                // emits FK ALTERs immediately, which fails when the
                // referenced table does not exist yet (see createTables).
                $deferredForeignKeys[] = [
                    'table' => $this->connection->quoteIdentifier($name),
                    'constraint' => $constraint,
                ];
            }
        }

        $tableOptions = $tableSpec['options'] ?? [];
        if (is_array($tableOptions)) {
            /** @var array<string, mixed> $options */
            $options = array_filter($tableOptions, static fn (mixed $value): bool => is_scalar($value) || null === $value);
            if ([] !== $options) {
                $editor->setOptions($options);
            }
        }

        $table = $editor->create();
        foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
            $this->connection->executeStatement($sql);
        }

        return $deferredForeignKeys;
    }

    /**
     * @param list<string> $values
     *
     * @return list<non-empty-string>
     */
    private function nonEmptyStrings(array $values): array
    {
        $result = [];
        foreach ($values as $value) {
            if ('' !== $value) {
                $result[] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $column
     */
    private function buildColumn(array $column): Column
    {
        $columnName = BackupArchive::specString($column, 'name');
        $columnType = BackupArchive::specString($column, 'type');
        if ('' === $columnName || '' === $columnType) {
            throw new \RuntimeException('Backup schema contains a column without a name or type.');
        }

        $editor = Column::editor()
            ->setUnquotedName($columnName)
            ->setTypeName($columnType)
            ->setNotNull(BackupArchive::specBool($column, 'notnull', true))
            ->setFixed(BackupArchive::specBool($column, 'fixed'))
            ->setAutoincrement(BackupArchive::specBool($column, 'autoincrement'))
            ->setScale(BackupArchive::specInt($column, 'scale', 0) ?? 0)
            ->setComment(BackupArchive::specString($column, 'comment'));

        $length = BackupArchive::specInt($column, 'length');
        if (null !== $length) {
            $editor->setLength($length);
        }

        $precision = BackupArchive::specInt($column, 'precision');
        if (null !== $precision) {
            $editor->setPrecision($precision);
        }

        $default = $column['default'] ?? null;
        if (null !== $default) {
            $editor->setDefaultValue($default);
        }

        $charset = BackupArchive::specString($column, 'charset');
        if ('' !== $charset) {
            $editor->setCharset($charset);
        }

        $collation = BackupArchive::specString($column, 'collation');
        if ('' !== $collation) {
            $editor->setCollation($collation);
        }

        return $editor->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTable(Table $table): array
    {
        /** @var list<array<string, mixed>> $columns */
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[] = [
                'name' => $column->getObjectName()->toString(),
                'type' => $column->getTypeName(),
                'notnull' => $column->getNotnull(),
                'default' => $column->getDefault(),
                'length' => $column->getLength(),
                'precision' => $column->getPrecision(),
                'scale' => $column->getScale(),
                'fixed' => $column->getFixed(),
                'autoincrement' => $column->getAutoincrement(),
                'comment' => $column->getComment(),
                'charset' => $column->getCharset(),
                'collation' => $column->getCollation(),
            ];
        }

        $primaryKey = $table->getPrimaryKeyConstraint();
        $indexes = [];
        foreach ($table->getIndexes() as $index) {
            if ($index->isPrimary()) {
                continue;
            }

            $indexes[] = [
                'name' => $index->getObjectName()->toString(),
                'columns' => $index->getUnquotedColumns(),
                'unique' => $index->isUnique(),
                'flags' => $index->getFlags(),
            ];
        }

        $uniqueConstraints = [];
        foreach ($table->getUniqueConstraints() as $constraint) {
            $constraintName = $constraint->getObjectName();
            $uniqueConstraints[] = [
                'name' => $constraintName?->toString() ?? '',
                'columns' => $constraint->getUnquotedColumns(),
                'flags' => $constraint->getFlags(),
            ];
        }

        $foreignKeys = [];
        foreach ($table->getForeignKeys() as $foreignKey) {
            $foreignKeyName = $foreignKey->getObjectName();
            $foreignKeys[] = [
                'name' => $foreignKeyName?->toString() ?? '',
                'local' => $foreignKey->getUnquotedLocalColumns(),
                'foreignTable' => $foreignKey->getUnqualifiedForeignTableName(),
                'foreign' => $foreignKey->getUnquotedForeignColumns(),
                'onUpdate' => $this->referentialAction($foreignKey->getOnUpdateAction()),
                'onDelete' => $this->referentialAction($foreignKey->getOnDeleteAction()),
            ];
        }

        $options = [];
        foreach ($table->getOptions() as $key => $value) {
            if (is_string($key) && (is_scalar($value) || null === $value)) {
                $options[$key] = $value;
            }
        }

        return [
            'name' => $table->getName(),
            'columns' => $columns,
            'primaryKey' => [
                'columns' => null === $primaryKey
                    ? []
                    : array_map(
                        static fn (UnqualifiedName $name): string => $name->toString(),
                        $primaryKey->getColumnNames(),
                    ),
            ],
            'indexes' => $indexes,
            'uniqueConstraints' => $uniqueConstraints,
            'foreignKeys' => $foreignKeys,
            'options' => $options,
        ];
    }

    private function referentialAction(ReferentialAction $action): ?string
    {
        return ReferentialAction::NO_ACTION === $action ? null : $action->value;
    }

    private function normalizeForJson(string $type, mixed $value, string $table, string $column): mixed
    {
        if (null === $value) {
            return null;
        }

        if (in_array($type, self::DATETIME_TYPES, true)) {
            return $this->normalizeDateTime($type, $value, $table, $column);
        }

        if ('boolean' === $type) {
            return $this->normalizeBoolean($value, $table, $column);
        }

        if (in_array($type, self::BINARY_TYPES, true) || is_resource($value)) {
            $binary = is_resource($value) ? stream_get_contents($value) : $value;
            if (!is_string($binary)) {
                throw new \RuntimeException('Binary value of "'.$table.'.'.$column.'" cannot be read.');
            }

            return 'b:'.base64_encode($binary);
        }

        if (is_string($value) && !preg_match('//u', $value)) {
            throw new \RuntimeException(sprintf('Value of "%s.%s" is not valid UTF-8 and cannot be stored in the backup.', $table, $column));
        }

        return $value;
    }

    private function normalizeDateTime(string $type, mixed $value, string $table, string $column): string
    {
        if (is_string($value)) {
            try {
                $value = new \DateTimeImmutable($value);
            } catch (\Exception) {
                throw new \RuntimeException(sprintf('Datetime value of "%s.%s" cannot be parsed: "%s".', $table, $column, $value));
            }
        }

        if (!$value instanceof \DateTimeInterface) {
            throw new \RuntimeException(sprintf('Datetime value of "%s.%s" has an unexpected type.', $table, $column));
        }

        if (in_array($type, self::DATE_TYPES, true)) {
            return $value->format('Y-m-d');
        }

        if (in_array($type, self::TIME_TYPES, true)) {
            return $this->withOptionalFraction($value, 'H:i:s');
        }

        return $this->withOptionalFraction($value, 'Y-m-d H:i:s');
    }

    private function withOptionalFraction(\DateTimeInterface $value, string $baseFormat): string
    {
        $base = $value->format($baseFormat);
        $microseconds = (int) $value->format('u');

        return 0 === $microseconds ? $base : $base.'.'.$value->format('v');
    }

    private function normalizeBoolean(mixed $value, string $table, string $column): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return 0 !== $value;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                '1', 't', 'true', 'y', 'yes' => true,
                '0', 'f', 'false', 'n', 'no' => false,
                default => throw new \RuntimeException(sprintf('Boolean value of "%s.%s" cannot be parsed: "%s".', $table, $column, $value)),
            };
        }

        throw new \RuntimeException(sprintf('Boolean value of "%s.%s" has an unexpected type.', $table, $column));
    }

    private function denormalizeForInsert(string $type, mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        if (in_array($type, self::DATETIME_TYPES, true)) {
            if (!is_string($value)) {
                throw new \RuntimeException('Backup contains a non-string datetime value.');
            }

            return $this->parseDateTime($type, $value);
        }

        if (in_array($type, self::BINARY_TYPES, true) && is_string($value) && str_starts_with($value, 'b:')) {
            $decoded = base64_decode(substr($value, 2), true);
            if (false === $decoded) {
                throw new \RuntimeException('Backup contains a malformed base64 binary value.');
            }

            return $decoded;
        }

        return $value;
    }

    private function parseDateTime(string $type, string $value): \DateTime
    {
        if (in_array($type, self::DATE_TYPES, true)) {
            $parsed = \DateTime::createFromFormat('!Y-m-d', $value);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        if (1 === preg_match('/^(\d{2}:\d{2}:\d{2})(?:\.(\d{1,9}))?$/', $value, $matches)) {
            $fraction = isset($matches[2]) ? '.'.$matches[2] : '';

            return new \DateTime('2000-01-01 '.$matches[1].$fraction);
        }

        try {
            return new \DateTime($value);
        } catch (\Exception) {
            throw new \RuntimeException('Backup contains an unparsable datetime value: "'.$value.'".');
        }
    }

    private function bindingType(string $type): string
    {
        if (in_array($type, self::STRING_BINDING_OVERRIDES, true)) {
            return 'string';
        }

        if (in_array($type, self::BINARY_TYPES, true)) {
            return $type;
        }

        return in_array($type, self::DATETIME_TYPES, true) || 'boolean' === $type ? $type : 'string';
    }

    private function isPlatform(string $needle): bool
    {
        $platform = $this->connection->getDatabasePlatform();

        return str_contains(strtolower(new \ReflectionClass($platform)->getShortName()), $needle);
    }
}
