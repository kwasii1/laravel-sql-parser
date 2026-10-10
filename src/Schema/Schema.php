<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Schema;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;

/**
 * A database schema: the columns (and their types) of tables (sqlglot schema.py Schema).
 */
abstract class Schema
{
    /**
     * The dialect of the schema, when it needs dialect-specific behavior.
     */
    public function dialect(): ?Dialect
    {
        return null;
    }

    /**
     * Register or update a table.
     *
     * @param  array<array-key, mixed>|string|null  $columnMapping  column => type, "col: type, ..." or a list of column names
     */
    abstract public function addTable(Table|string $table, array|string|null $columnMapping = null, string|Dialect|null $dialect = null, ?bool $normalize = null, bool $matchDepth = true): void;

    /**
     * The column names of a table.
     *
     * @return list<string>
     */
    abstract public function columnNames(Table|string $table, bool $onlyVisible = false, string|Dialect|null $dialect = null, ?bool $normalize = null): array;

    /**
     * The type of a column of a table (UNKNOWN when it isn't known).
     */
    abstract public function getColumnType(Table|string $table, Column|string $column, string|Dialect|null $dialect = null, ?bool $normalize = null): DataType;

    /**
     * Whether the column appears in the table's schema.
     */
    public function hasColumn(Table|string $table, Column|string $column, string|Dialect|null $dialect = null, ?bool $normalize = null): bool
    {
        $name = is_string($column) ? $column : $column->name();

        return in_array($name, $this->columnNames($table, dialect: $dialect, normalize: $normalize), true);
    }

    /**
     * The return type of a user-defined function (UNKNOWN when it isn't known).
     */
    public function getUdfType(Anonymous|string $udf, string|Dialect|null $dialect = null, ?bool $normalize = null): DataType
    {
        return new DataType(['this' => DType::UNKNOWN]);
    }

    /**
     * The table arguments this schema supports, e.g. ['this', 'db', 'catalog'].
     *
     * @return list<string>
     */
    abstract public function supportedTableArgs(): array;

    /**
     * Whether the schema is empty.
     */
    public function isEmpty(): bool
    {
        return true;
    }

    /**
     * A Schema as is, or a MappingSchema for a nested array of table => column => type (sqlglot ensure_schema).
     *
     * @param  array<array-key, mixed>|Schema|null  $schema
     */
    public static function ensure(array|Schema|null $schema, string|Dialect|null $dialect = null): Schema
    {
        if ($schema instanceof Schema) {
            return $schema;
        }

        return new MappingSchema($schema, dialect: $dialect);
    }
}
