<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Schema;

use InvalidArgumentException;
use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Errors\SchemaError;
use Kwasii\LaravelSqlParser\Errors\SqlParserException;
use Kwasii\LaravelSqlParser\Expressions\Anonymous;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\Dot;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Table;

/**
 * A schema based on a nested mapping (sqlglot schema.py MappingSchema), in one of the forms:
 *   1. [table => [column => type]]
 *   2. [db => [table => [column => type]]]
 *   3. [catalog => [db => [table => [column => type]]]]
 * Types are SQL type strings or DataType instances.
 *
 * `visible` optionally lists the visible columns of each table, nested like the schema
 * ([table => [col, ...]], ...); when it's empty, every column is visible.
 */
class MappingSchema extends Schema
{
    private const array TABLE_PARTS = ['this', 'db', 'catalog'];

    /** @var array<array-key, mixed> */
    public array $mapping;

    /** @var array<array-key, mixed> */
    public array $udfMapping;

    /** @var array<array-key, mixed> */
    public array $visible;

    public readonly bool $normalize;

    private readonly Dialect $schemaDialect;

    /** @var list<list<string>> table paths, innermost (table) name first */
    private array $mappingPaths;

    /** @var list<list<string>> UDF paths, innermost (function) name first */
    private array $udfPaths;

    /** @var list<string>|null */
    private ?array $supportedTableArgs = null;

    private int $depth = 0;

    /** @var array<string, DataType> */
    private array $typeMappingCache = [];

    /** @var array<string, array<array-key, mixed>|null> */
    private array $findCache = [];

    /**
     * @param  array<array-key, mixed>|null  $schema
     * @param  array<array-key, mixed>|null  $visible
     * @param  array<array-key, mixed>|null  $udfMapping
     */
    public function __construct(?array $schema = null, ?array $visible = null, string|Dialect|null $dialect = null, bool $normalize = true, ?array $udfMapping = null)
    {
        $this->visible = $visible ?? [];
        $this->normalize = $normalize;
        $this->schemaDialect = Dialect::get($dialect);
        $schema ??= [];
        $udfMapping ??= [];

        $this->mapping = $normalize ? $this->normalizeMapping($schema) : $schema;
        $this->udfMapping = $normalize ? $this->normalizeUdfs($udfMapping) : $udfMapping;
        $this->mappingPaths = array_map(array_reverse(...), self::flattenSchema($this->mapping, $this->depth()));
        $this->udfPaths = array_map(array_reverse(...), self::flattenSchema($this->udfMapping, self::dictDepth($this->udfMapping)));
    }

    public function dialect(): Dialect
    {
        return $this->schemaDialect;
    }

    public static function fromMappingSchema(MappingSchema $schema): self
    {
        return new self($schema->mapping, $schema->visible, $schema->schemaDialect, $schema->normalize, $schema->udfMapping);
    }

    /**
     * @param  array<array-key, mixed>|null  $schema
     */
    public function copy(?array $schema = null): self
    {
        return new self($schema ?? $this->mapping, $this->visible, $this->schemaDialect, $this->normalize, $this->udfMapping);
    }

    public function isEmpty(): bool
    {
        return $this->mapping === [];
    }

    public function depth(): int
    {
        if (! $this->isEmpty() && $this->depth === 0) {
            // The columns themselves are a mapping, but they aren't part of the table path
            $this->depth = self::dictDepth($this->mapping) - 1;
        }

        return $this->depth;
    }

    public function udfDepth(): int
    {
        return self::dictDepth($this->udfMapping);
    }

    public function supportedTableArgs(): array
    {
        if ($this->supportedTableArgs === null || ($this->supportedTableArgs === [] && $this->mapping !== [])) {
            $this->supportedTableArgs = [];

            if ($this->mapping !== []) {
                $depth = $this->depth();

                if ($depth >= 1 && $depth <= 3) {
                    $this->supportedTableArgs = array_slice(self::TABLE_PARTS, 0, $depth);
                } elseif ($depth !== 0) {
                    throw new SchemaError("Invalid mapping shape. Depth: {$depth}");
                }
            }
        }

        return $this->supportedTableArgs;
    }

    /**
     * The names of a table's parts, innermost (table) first.
     *
     * @return list<string>
     */
    public function tableParts(Table $table): array
    {
        return array_reverse(array_map(static fn (Expression $p): string => $p->name(), $table->parts()));
    }

    /**
     * The names of a UDF's parts, innermost (function) first; a.b.c(...) is Dot(Dot(a, b), Anonymous(c)).
     *
     * @return list<string>
     */
    public function udfParts(Anonymous $udf): array
    {
        $parent = $udf->parent;
        $parts = $parent instanceof Dot
            ? array_map(static fn (Expression $p): string => $p->name(), $parent->flatten())
            : [$udf->name()];

        return array_slice(array_reverse($parts), 0, $this->udfDepth());
    }

    /**
     * The column mapping of a table, or null when it's not in the schema.
     *
     * @return array<array-key, mixed>|null
     */
    public function find(Table $table, bool $raiseOnMissing = true, bool $ensureDataTypes = false): ?array
    {
        $parts = array_slice($this->tableParts($table), 0, count($this->supportedTableArgs()));
        $key = implode("\0", $parts)."\1".($ensureDataTypes ? '1' : '0');

        if (isset($this->findCache[$key])) {
            return $this->findCache[$key];
        }

        $resolved = $this->findInPaths($parts, $this->mappingPaths, $raiseOnMissing);
        $schema = $resolved === null ? null : $this->nestedGetParts($resolved, $this->mapping, $raiseOnMissing);

        if (! is_array($schema)) {
            $schema = null;
        }

        if ($ensureDataTypes && $schema !== null) {
            foreach ($schema as $column => $type) {
                if (is_string($type)) {
                    $schema[$column] = $this->toDataType($type);
                }
            }
        }

        return $this->findCache[$key] = $schema;
    }

    /**
     * The return type of a UDF, or null when it's not in the UDF mapping.
     */
    public function findUdf(Anonymous $udf, bool $raiseOnMissing = false): mixed
    {
        $resolved = $this->findInPaths($this->udfParts($udf), $this->udfPaths, $raiseOnMissing);

        return $resolved === null ? null : self::nestedGet($this->udfMapping, array_reverse($resolved), $raiseOnMissing);
    }

    public function addTable(Table|string $table, array|string|null $columnMapping = null, string|Dialect|null $dialect = null, ?bool $normalize = null, bool $matchDepth = true): void
    {
        $normalizedTable = $this->normalizeTable($table, $dialect, $normalize);

        if ($matchDepth && ! $this->isEmpty() && count($normalizedTable->parts()) !== $this->depth()) {
            throw new SchemaError("Table {$normalizedTable->sql($this->schemaDialect)} must match the schema's nesting level: {$this->depth()}.");
        }

        $normalizedColumns = [];

        foreach (self::ensureColumnMapping($columnMapping) as $key => $value) {
            $normalizedColumns[$this->normalizeName((string) $key, $dialect, normalize: $normalize)] = $value;
        }

        $schema = $this->find($normalizedTable, raiseOnMissing: false);

        if ($schema !== null && $schema !== [] && $normalizedColumns === []) {
            return;
        }

        $parts = $this->tableParts($normalizedTable);
        self::nestedSet($this->mapping, array_reverse($parts), $normalizedColumns);

        if (! in_array($parts, $this->mappingPaths, true)) {
            $this->mappingPaths[] = $parts;
        }

        $this->findCache = [];
        $this->supportedTableArgs = null;
    }

    public function columnNames(Table|string $table, bool $onlyVisible = false, string|Dialect|null $dialect = null, ?bool $normalize = null): array
    {
        $normalizedTable = $this->normalizeTable($table, $dialect, $normalize);
        $schema = $this->find($normalizedTable);

        if ($schema === null) {
            return [];
        }

        $columns = array_map('strval', array_keys($schema));

        if (! $onlyVisible || $this->visible === []) {
            return $columns;
        }

        $visible = $this->nestedGetParts($this->tableParts($normalizedTable), $this->visible, false);
        $visible = is_array($visible) ? array_map('strval', $visible) : [];

        return array_values(array_filter($columns, static fn (string $column): bool => in_array($column, $visible, true)));
    }

    public function getColumnType(Table|string $table, Column|string $column, string|Dialect|null $dialect = null, ?bool $normalize = null): DataType
    {
        $normalizedTable = $this->normalizeTable($table, $dialect, $normalize);
        $name = $this->normalizeName(is_string($column) ? $column : self::columnIdentifier($column), $dialect, normalize: $normalize);
        $tableSchema = $this->find($normalizedTable, raiseOnMissing: false);

        if ($tableSchema !== null && $tableSchema !== []) {
            $columnType = $tableSchema[$name] ?? null;

            if ($columnType instanceof DataType) {
                return $columnType;
            }

            if (is_string($columnType)) {
                return $this->toDataType($columnType, $dialect);
            }
        }

        return new DataType(['this' => DType::UNKNOWN]);
    }

    public function getUdfType(Anonymous|string $udf, string|Dialect|null $dialect = null, ?bool $normalize = null): DataType
    {
        $parts = $this->normalizeUdf($udf, $dialect, $normalize);
        $resolved = $this->findInPaths($parts, $this->udfPaths, false);

        if ($resolved !== null) {
            $type = self::nestedGet($this->udfMapping, array_reverse($resolved), false);

            if ($type instanceof DataType) {
                return $type;
            }

            if (is_string($type)) {
                return $this->toDataType($type, $dialect);
            }
        }

        return new DataType(['this' => DType::UNKNOWN]);
    }

    public function hasColumn(Table|string $table, Column|string $column, string|Dialect|null $dialect = null, ?bool $normalize = null): bool
    {
        $normalizedTable = $this->normalizeTable($table, $dialect, $normalize);
        $name = $this->normalizeName(is_string($column) ? $column : self::columnIdentifier($column), $dialect, normalize: $normalize);
        $tableSchema = $this->find($normalizedTable, raiseOnMissing: false);

        return $tableSchema !== null && array_key_exists($name, $tableSchema);
    }

    /**
     * Normalize a name (or identifier) the way the dialect resolves it (sqlglot normalize_name).
     */
    public static function normalizeNameIdentifier(Identifier|string $identifier, string|Dialect|null $dialect = null, bool $isTable = false, ?bool $normalize = true): Identifier
    {
        if (is_string($identifier)) {
            $identifier = self::parseIdentifier($identifier, $dialect);
        } elseif ($normalize === true) {
            $identifier = $identifier->copy();
        }

        if ($normalize !== true) {
            return $identifier;
        }

        // Used by normalize_identifier: BigQuery has special rules for tables
        $identifier->setMeta('is_table', $isTable);

        return Dialect::get($dialect)->normalizeIdentifier($identifier);
    }

    /**
     * Parse a name into an identifier, falling back to a plain identifier (sqlglot exp.parse_identifier).
     */
    public static function parseIdentifier(Identifier|string $name, string|Dialect|null $dialect = null): Identifier
    {
        if ($name instanceof Identifier) {
            return $name;
        }

        try {
            $identifier = Dialect::get($dialect)->parseInto(Identifier::class, $name);

            if ($identifier instanceof Identifier) {
                return $identifier;
            }
        } catch (SqlParserException) {
            // Not a valid identifier in the dialect, e.g. it contains spaces
        }

        return Builder::toIdentifier($name) ?? new Identifier(['this' => $name]);
    }

    /**
     * Normalize a column mapping given as an array, a "col: type, ..." string or a list of names.
     *
     * @param  array<array-key, mixed>|string|null  $mapping
     * @return array<array-key, mixed>
     */
    public static function ensureColumnMapping(array|string|null $mapping): array
    {
        if ($mapping === null) {
            return [];
        }

        if (is_string($mapping)) {
            $out = [];

            foreach (explode(',', $mapping) as $nameType) {
                $pieces = explode(':', trim($nameType));

                if (count($pieces) < 2) {
                    throw new InvalidArgumentException("Invalid mapping provided: {$mapping}");
                }

                $out[trim($pieces[0])] = trim($pieces[1]);
            }

            return $out;
        }

        if (array_is_list($mapping) && $mapping !== [] && is_string($mapping[0])) {
            $out = [];

            foreach ($mapping as $name) {
                $out[trim((string) $name)] = null;
            }

            return $out;
        }

        return $mapping;
    }

    /**
     * The key paths of the tables of a nested schema mapping.
     *
     * @param  array<array-key, mixed>  $schema
     * @param  list<string>  $keys
     * @return list<list<string>>
     */
    public static function flattenSchema(array $schema, ?int $depth = null, array $keys = []): array
    {
        $tables = [];
        $depth ??= self::dictDepth($schema) - 1;

        foreach ($schema as $k => $v) {
            $k = (string) $k;

            if ($depth === 1 || ! is_array($v)) {
                $tables[] = [...$keys, $k];
            } elseif ($depth >= 2) {
                array_push($tables, ...self::flattenSchema($v, $depth - 1, [...$keys, $k]));
            }
        }

        return $tables;
    }

    /**
     * The nesting depth of an array (sqlglot dict_depth): 0 for a non-array, 1 for an empty array.
     */
    public static function dictDepth(mixed $value): int
    {
        if (! is_array($value)) {
            return 0;
        }

        if ($value === []) {
            return 1;
        }

        return 1 + self::dictDepth($value[array_key_first($value)]);
    }

    /**
     * The value at a key path of a nested array, or null.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     * @param  list<string>|null  $names  names of the keys, for the error message
     */
    public static function nestedGet(array $data, array $keys, bool $raiseOnMissing = true, ?array $names = null): mixed
    {
        $result = $data;

        foreach ($keys as $i => $key) {
            $result = is_array($result) ? ($result[$key] ?? null) : null;

            if ($result === null) {
                if ($raiseOnMissing) {
                    $name = $names[$i] ?? $key;
                    $name = $name === 'this' ? 'table' : $name;

                    throw new InvalidArgumentException("Unknown {$name}: {$key}");
                }

                return null;
            }
        }

        return $result;
    }

    /**
     * Set a value at a key path of a nested array, in place.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keys
     */
    public static function nestedSet(array &$data, array $keys, mixed $value): void
    {
        if ($keys === []) {
            return;
        }

        $sub = &$data;

        foreach (array_slice($keys, 0, -1) as $key) {
            if (! isset($sub[$key]) || ! is_array($sub[$key])) {
                $sub[$key] = [];
            }

            $sub = &$sub[$key];
        }

        $sub[$keys[count($keys) - 1]] = $value;
    }

    /**
     * Resolve possibly partial path parts (innermost first) against the known paths (sqlglot's
     * trie lookup): a unique completion of a prefix is filled in, an ambiguous one is null (or raises).
     *
     * @param  list<string>  $parts
     * @param  list<list<string>>  $paths
     * @return list<string>|null
     */
    private function findInPaths(array $parts, array $paths, bool $raiseOnMissing): ?array
    {
        $count = count($parts);
        $possibilities = [];

        if ($count === 0) {
            return null;
        }

        foreach ($paths as $path) {
            if (array_slice($path, 0, $count) !== $parts) {
                continue;
            }

            if (count($path) === $count) {
                return $parts;
            }

            $possibilities[] = array_slice($path, $count);
        }

        if ($possibilities === []) {
            return null;
        }

        if (count($possibilities) === 1) {
            return [...$parts, ...$possibilities[0]];
        }

        if ($raiseOnMissing) {
            $joined = implode('.', $parts);
            $message = implode(', ', array_map(static fn (array $p): string => implode('.', $p), $possibilities));

            throw new SchemaError("Ambiguous mapping for {$joined}: {$message}.");
        }

        return null;
    }

    /**
     * nested_get over table parts given innermost first.
     *
     * @param  list<string>  $parts
     * @param  array<array-key, mixed>  $data
     */
    private function nestedGetParts(array $parts, array $data, bool $raiseOnMissing): mixed
    {
        $names = $this->supportedTableArgs();
        $count = min(count($names), count($parts));

        return self::nestedGet($data, array_slice(array_reverse($parts), 0, $count), $raiseOnMissing, $names);
    }

    /**
     * @param  array<array-key, mixed>  $schema
     * @return array<array-key, mixed>
     */
    private function normalizeMapping(array $schema): array
    {
        $normalized = [];
        $flattened = self::flattenSchema($schema);

        foreach ($flattened as $keys) {
            $columns = self::nestedGet($schema, $keys);
            $tableName = implode('.', array_slice($keys, 0, -1));
            $level = count($flattened[0]);

            if (! is_array($columns)) {
                throw new SchemaError("Table {$tableName} must match the schema's nesting level: {$level}.");
            }

            if ($columns === []) {
                throw new SchemaError("Table {$tableName} must have at least one column");
            }

            if (is_array($columns[array_key_first($columns)])) {
                $path = implode('.', [...$keys, ...self::flattenSchema($columns)[0]]);

                throw new SchemaError("Table {$path} must match the schema's nesting level: {$level}.");
            }

            $normalizedKeys = array_map(fn (string $key): string => $this->normalizeName($key, isTable: true), $keys);

            foreach ($columns as $columnName => $columnType) {
                self::nestedSet($normalized, [...$normalizedKeys, $this->normalizeName((string) $columnName)], $columnType);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $udfs
     * @return array<array-key, mixed>
     */
    private function normalizeUdfs(array $udfs): array
    {
        $normalized = [];

        foreach (self::flattenSchema($udfs, self::dictDepth($udfs)) as $keys) {
            $type = self::nestedGet($udfs, $keys);
            $normalizedKeys = array_map(fn (string $key): string => $this->normalizeName($key, isTable: true), $keys);
            self::nestedSet($normalized, $normalizedKeys, $type);
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeUdf(Anonymous|string $udf, string|Dialect|null $dialect = null, ?bool $normalize = null): array
    {
        $dialect ??= $this->schemaDialect;
        $normalize ??= $this->normalize;

        if (is_string($udf)) {
            $parsed = Builder::maybeParse($udf, dialect: $dialect);

            if ($parsed instanceof Anonymous) {
                $udf = $parsed;
            } elseif ($parsed instanceof Dot && $parsed->args['expression'] instanceof Anonymous) {
                $udf = $parsed->args['expression'];
            } else {
                throw new SchemaError("Unable to parse UDF from: '{$udf}'");
            }
        }

        $parts = $this->udfParts($udf);

        if ($normalize) {
            $parts = array_map(fn (string $part): string => $this->normalizeName($part, $dialect, isTable: true), $parts);
        }

        return $parts;
    }

    /**
     * The table, parsed if needed, with its identifiers normalized (a copy when normalizing).
     */
    public function normalizeTable(Table|string $table, string|Dialect|null $dialect = null, ?bool $normalize = null): Table
    {
        $dialect ??= $this->schemaDialect;
        $normalize ??= $this->normalize;

        $normalized = Builder::maybeParse($table, into: Table::class, dialect: $dialect, copy: $normalize);

        if (! $normalized instanceof Table) {
            throw new SchemaError('Unable to parse table: '.(is_string($table) ? $table : $table->sql()));
        }

        if ($normalize) {
            foreach ($normalized->parts() as $part) {
                if ($part instanceof Identifier) {
                    $part->replace(self::normalizeNameIdentifier($part, $dialect, isTable: true, normalize: true));
                }
            }
        }

        return $normalized;
    }

    private function normalizeName(Identifier|string $name, string|Dialect|null $dialect = null, bool $isTable = false, ?bool $normalize = null): string
    {
        $normalize ??= $this->normalize;
        $dialect ??= $this->schemaDialect;

        return self::normalizeNameIdentifier($name, $dialect, $isTable, $normalize)->name();
    }

    private static function columnIdentifier(Column $column): Identifier|string
    {
        $this_ = $column->args['this'] ?? null;

        return $this_ instanceof Identifier ? $this_ : $column->name();
    }

    /**
     * A type string as a DataType, with its identifiers normalized.
     */
    private function toDataType(string $schemaType, string|Dialect|null $dialect = null): DataType
    {
        if (! isset($this->typeMappingCache[$schemaType])) {
            $dialect = $dialect !== null ? Dialect::get($dialect) : $this->schemaDialect;

            try {
                $expression = DataType::fromStr($schemaType, $dialect, udt: $dialect->settings['SUPPORTS_USER_DEFINED_TYPES'] === true);
            } catch (SqlParserException $e) {
                throw new SchemaError("Failed to build type '{$schemaType}' in dialect {$dialect->name()}.", 0, $e);
            }

            $expression->transform(static fn (Expression $node): Expression => $dialect->normalizeIdentifier($node), copy: false);
            $this->typeMappingCache[$schemaType] = $expression;
        }

        return $this->typeMappingCache[$schemaType];
    }
}
