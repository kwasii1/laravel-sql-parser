<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use Closure;
use InvalidArgumentException;
use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Errors\ParseError;
use LaravelSqlParser\LaravelSqlParser\Errors\TokenError;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alter;
use LaravelSqlParser\LaravelSqlParser\Expressions\AlterRename;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Condition;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Delete;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Except;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Insert;
use LaravelSqlParser\LaravelSqlParser\Expressions\Intersect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Merge;
use LaravelSqlParser\LaravelSqlParser\Expressions\Placeholder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\RenameColumn;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Union;
use LaravelSqlParser\LaravelSqlParser\Expressions\Update;
use LaravelSqlParser\LaravelSqlParser\Expressions\Values;
use LaravelSqlParser\LaravelSqlParser\Expressions\When;
use LaravelSqlParser\LaravelSqlParser\Expressions\Whens;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Expressions\With;
use LaravelSqlParser\LaravelSqlParser\Optimizer\NormalizeIdentifiers;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;
use Stringable;

/**
 * The fluent query builders of sqlglot's expressions/builders.py (select, from_, update, delete,
 * insert, merge, union, ...) and the `_apply_*_builder` helpers the expression methods share.
 *
 * Builder methods mirror sqlglot's keyword arguments with PHP named arguments; variadic builders
 * take their options as named arguments after the expressions, e.g.
 * `Builder::select('x', 'y', dialect: 'mysql')`. Strings are parsed with the given dialect.
 */
trait BuildsQueries
{
    // ---------------------------------------------------------------------------------------
    // Free builders (sqlglot expressions/builders.py)
    // ---------------------------------------------------------------------------------------

    /**
     * SELECT <expressions> (sqlglot select). Options: dialect, copy.
     */
    public static function select(mixed ...$args): Select
    {
        [$expressions, $options] = self::splitOptions($args, ['dialect' => null, 'copy' => true]);

        return (new Select)->select(...$expressions, dialect: self::dialectOption($options), copy: (bool) $options['copy']);
    }

    /**
     * SELECT ... FROM <expression> (sqlglot from_).
     */
    public static function from(mixed $expression, string|Dialect|null $dialect = null, bool $copy = true): Select
    {
        return (new Select)->from($expression, $dialect, $copy);
    }

    /**
     * UPDATE <table> SET <properties> (sqlglot update). Property values are converted with convert().
     *
     * @param  array<string, mixed>|null  $properties  column => value
     * @param  array<string, mixed>|null  $with  CTE alias => query
     */
    public static function update(Table|string $table, ?array $properties = null, mixed $where = null, mixed $from = null, ?array $with = null, string|Dialect|null $dialect = null, bool $copy = true): Update
    {
        $update = new Update(['this' => self::parseSql($table, Table::class, $dialect, copy: $copy)]);

        if ($properties !== null && $properties !== []) {
            $update->set('expressions', array_map(
                static fn (string|int $key, mixed $value): EQ => new EQ([
                    'this' => self::parseSql((string) $key, dialect: $dialect, copy: $copy),
                    'expression' => self::convert($value),
                ]),
                array_keys($properties),
                array_values($properties),
            ));
        }

        if (self::truthy($from)) {
            $update->set('from_', self::parseSql($from, From::class, $dialect, 'FROM', $copy));
        }

        if ($where instanceof Condition && $where instanceof Expression) {
            $where = new Where(['this' => $where]);
        }

        if (self::truthy($where)) {
            $update->set('where', self::parseSql($where, Where::class, $dialect, 'WHERE', $copy));
        }

        if ($with !== null && $with !== []) {
            $ctes = [];
            foreach ($with as $alias => $query) {
                $ctes[] = self::alias(new CTE(['this' => self::parseSql($query, dialect: $dialect, copy: $copy)]), (string) $alias, table: true);
            }

            $update->set('with_', new With(['expressions' => $ctes]));
        }

        return $update;
    }

    /**
     * DELETE FROM <table> [WHERE ...] [RETURNING ...] (sqlglot delete).
     */
    public static function delete(mixed $table, mixed $where = null, mixed $returning = null, string|Dialect|null $dialect = null): Delete
    {
        $delete = (new Delete)->delete($table, $dialect, copy: false);

        if (self::truthy($where)) {
            $delete = $delete->where($where, dialect: $dialect, copy: false);
        }

        if (self::truthy($returning)) {
            $delete = $delete->returning($returning, $dialect, copy: false);
        }

        return $delete;
    }

    /**
     * INSERT INTO <into> [(columns)] <expression> (sqlglot insert).
     *
     * @param  list<Identifier|string>|null  $columns
     */
    public static function insert(mixed $expression, Table|string $into, ?array $columns = null, ?bool $overwrite = null, mixed $returning = null, string|Dialect|null $dialect = null, bool $copy = true): Insert
    {
        $expression = self::parseSql($expression, dialect: $dialect, copy: $copy);
        $this_ = self::parseSql($into, Table::class, $dialect, copy: $copy);

        if ($columns !== null && $columns !== []) {
            $this_ = new Schema([
                'this' => $this_,
                'expressions' => array_map(static fn (Identifier|string $c): ?Identifier => self::toIdentifier($c, copy: $copy), $columns),
            ]);
        }

        $insert = new Insert(['this' => $this_, 'expression' => $expression, 'overwrite' => $overwrite]);

        if (self::truthy($returning)) {
            $insert = $insert->returning($returning, $dialect, copy: false);
        }

        return $insert;
    }

    /**
     * MERGE INTO <into> USING <using> ON <on> <whens> (sqlglot merge).
     * Options: into, using, on (required), returning, dialect, copy.
     */
    public static function merge(mixed ...$args): Merge
    {
        [$whens, $options] = self::splitOptions($args, ['into' => null, 'using' => null, 'on' => null, 'returning' => null, 'dialect' => null, 'copy' => true]);
        $dialect = self::dialectOption($options);
        $copy = (bool) $options['copy'];

        foreach (['into', 'using', 'on'] as $required) {
            if ($options[$required] === null) {
                throw new InvalidArgumentException("merge() requires the '{$required}' option.");
            }
        }

        $expressions = [];
        foreach ($whens as $when) {
            $expression = self::parseSql($when, Whens::class, $dialect, copy: $copy);
            array_push($expressions, ...($expression instanceof When ? [$expression] : $expression->argList('expressions')));
        }

        $merge = new Merge([
            'this' => self::parseSql($options['into'], dialect: $dialect, copy: $copy),
            'using' => self::parseSql($options['using'], dialect: $dialect, copy: $copy),
            'on' => self::parseSql($options['on'], dialect: $dialect, copy: $copy),
            'whens' => new Whens(['expressions' => $expressions]),
        ]);

        if (self::truthy($options['returning'])) {
            $merge = $merge->returning($options['returning'], $dialect, copy: false);
        }

        $using = $merge->args['using'] ?? null;

        if ($using instanceof Alias && ($alias = $using->args['alias'] ?? null) instanceof Identifier) {
            $using->replace(self::alias($using->args['this'], $alias, table: true));
        }

        return $merge;
    }

    /**
     * Combine queries with UNION (sqlglot union). Options: distinct, dialect, copy.
     */
    public static function union(mixed ...$args): Union
    {
        /** @var Union */
        return self::setOperation(Union::class, $args);
    }

    /**
     * Combine queries with INTERSECT (sqlglot intersect). Options: distinct, dialect, copy.
     */
    public static function intersect(mixed ...$args): Intersect
    {
        /** @var Intersect */
        return self::setOperation(Intersect::class, $args);
    }

    /**
     * Combine queries with EXCEPT (sqlglot except_). Options: distinct, dialect, copy.
     */
    public static function except(mixed ...$args): Except
    {
        /** @var Except */
        return self::setOperation(Except::class, $args);
    }

    /**
     * SELECT ... FROM (<expression>) AS <alias> (sqlglot subquery).
     */
    public static function subquery(mixed $expression, Identifier|string|null $alias = null, string|Dialect|null $dialect = null, bool $copy = true): Select
    {
        $query = self::parseSql($expression, dialect: $dialect);

        if (! $query instanceof Query) {
            throw new InvalidArgumentException('subquery() expects a query, got '.$query::class);
        }

        return (new Select)->from($query->subquery($alias, $copy), $dialect);
    }

    /**
     * A VALUES clause from rows of PHP values (sqlglot values).
     *
     * @param  iterable<array<mixed>|Tuple>  $values
     * @param  list<string>|array<string, DataType>|null  $columns
     */
    public static function values(iterable $values, ?string $alias = null, ?array $columns = null): Values
    {
        $names = [];
        foreach ($columns ?? [] as $key => $column) {
            $names[] = is_string($key) ? $key : (string) $column;
        }
        $columns = $names;

        if ($columns !== [] && ($alias === null || $alias === '')) {
            throw new InvalidArgumentException('Alias is required when providing columns');
        }

        $rows = [];
        foreach ($values as $row) {
            $rows[] = $row instanceof Tuple ? $row : new Tuple(['expressions' => array_map(static fn (mixed $v): Expression => self::convert($v), array_values($row))]);
        }

        $tableAlias = null;

        if ($columns !== []) {
            $tableAlias = new TableAlias([
                'this' => self::toIdentifier($alias),
                'columns' => array_map(static fn (string $c): ?Identifier => self::toIdentifier($c), $columns),
            ]);
        } elseif ($alias !== null && $alias !== '') {
            $tableAlias = new TableAlias(['this' => self::toIdentifier($alias)]);
        }

        return new Values(['expressions' => $rows, 'alias' => $tableAlias]);
    }

    /**
     * CASE [<expression>] ... END, built with ->when() and ->else() (sqlglot case).
     */
    public static function case(mixed $expression = null, bool $copy = true, string|Dialect|null $dialect = null): CaseExpr
    {
        return new CaseExpr([
            'this' => $expression !== null ? self::parseSql($expression, dialect: $dialect, copy: $copy) : null,
            'ifs' => [],
        ]);
    }

    /**
     * ARRAY(<expressions>) (sqlglot array). Options: dialect, copy.
     */
    public static function array(mixed ...$args): ArrayExpr
    {
        return new ArrayExpr(['expressions' => self::parseAll($args)]);
    }

    /**
     * (<expressions>) (sqlglot tuple_). Options: dialect, copy.
     */
    public static function tuple(mixed ...$args): Tuple
    {
        return new Tuple(['expressions' => self::parseAll($args)]);
    }

    /**
     * Parse a (possibly qualified) table name, falling back to splitting on dots (sqlglot to_table).
     *
     * @param  array<string, mixed>  $args  extra args set on the table
     */
    public static function toTable(Table|string $sqlPath, string|Dialect|null $dialect = null, bool $copy = true, array $args = []): Table
    {
        if ($sqlPath instanceof Table) {
            return $copy ? $sqlPath->copy() : $sqlPath;
        }

        try {
            $table = self::parseSql($sqlPath, Table::class, $dialect);
        } catch (ParseError $e) {
            $parts = explode('.', $sqlPath, 3);
            [$catalog, $db, $name] = array_pad($parts, -3, null);

            if ($name === null || $name === '') {
                throw $e;
            }

            $table = self::table($name, $db, $catalog);
        }

        if (! $table instanceof Table) {
            throw new InvalidArgumentException("Cannot parse table '{$sqlPath}'");
        }

        foreach ($args as $key => $value) {
            $table->set($key, $value);
        }

        return $table;
    }

    /**
     * Parse a (possibly qualified) column name, falling back to splitting on dots (sqlglot to_column).
     *
     * @param  array<string, mixed>  $args  extra args set on the column
     */
    public static function toColumn(Column|string $sqlPath, ?bool $quoted = null, string|Dialect|null $dialect = null, bool $copy = true, array $args = []): Expression
    {
        if ($sqlPath instanceof Column) {
            return $copy ? $sqlPath->copy() : $sqlPath;
        }

        try {
            $column = self::parseSql($sqlPath, Column::class, $dialect);
        } catch (ParseError) {
            $parts = array_reverse(explode('.', $sqlPath));

            return self::column($parts[0], $parts[1] ?? null, $parts[2] ?? null, $parts[3] ?? null, quoted: $quoted);
        }

        foreach ($args as $key => $value) {
            $column->set($key, $value);
        }

        if ($quoted === true) {
            foreach ($column->findAll(Identifier::class) as $identifier) {
                $identifier->set('quoted', true);
            }
        }

        return $column;
    }

    /**
     * ALTER TABLE <old> RENAME TO <new> (sqlglot rename_table).
     */
    public static function renameTable(Table|string $oldName, Table|string $newName, string|Dialect|null $dialect = null): Alter
    {
        return new Alter([
            'this' => self::toTable($oldName, $dialect),
            'kind' => 'TABLE',
            'actions' => [new AlterRename(['this' => self::toTable($newName, $dialect)])],
        ]);
    }

    /**
     * ALTER TABLE <table> RENAME COLUMN <old> TO <new> (sqlglot rename_column).
     */
    public static function renameColumn(Table|string $tableName, Column|string $oldColumnName, Column|string $newColumnName, ?bool $exists = null, string|Dialect|null $dialect = null): Alter
    {
        return new Alter([
            'this' => self::toTable($tableName, $dialect),
            'kind' => 'TABLE',
            'actions' => [new RenameColumn([
                'this' => self::toColumn($oldColumnName, dialect: $dialect),
                'to' => self::toColumn($newColumnName, dialect: $dialect),
                'exists' => $exists,
            ])],
        ]);
    }

    /**
     * Parse a name into an identifier, falling back to quoting it (sqlglot parse_identifier).
     */
    public static function parseIdentifier(Identifier|string $name, string|Dialect|null $dialect = null): Identifier
    {
        if (is_string($name) && preg_match('/^[_a-zA-Z]\w*$/', $name) === 1) {
            return new Identifier(['this' => $name, 'quoted' => false]);
        }

        try {
            $identifier = self::parseSql($name, Identifier::class, $dialect);
        } catch (ParseError|TokenError) {
            $identifier = self::toIdentifier($name);
        }

        if (! $identifier instanceof Identifier) {
            throw new InvalidArgumentException('Cannot parse identifier.');
        }

        return $identifier;
    }

    // ---------------------------------------------------------------------------------------
    // Shared builder plumbing (sqlglot _apply_builder and friends)
    // ---------------------------------------------------------------------------------------

    /**
     * Separate positional builder arguments from named options, rejecting unknown options.
     *
     * @param  array<int|string, mixed>  $args
     * @param  array<string, mixed>  $defaults
     * @return array{0: list<mixed>, 1: array<string, mixed>}
     */
    public static function splitOptions(array $args, array $defaults): array
    {
        $positional = [];
        $options = $defaults;

        foreach ($args as $key => $value) {
            if (is_int($key)) {
                $positional[] = $value;

                continue;
            }

            if (! array_key_exists($key, $defaults)) {
                throw new InvalidArgumentException("Unknown builder option '{$key}'.");
            }

            $options[$key] = $value;
        }

        return [$positional, $options];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public static function dialectOption(array $options): string|Dialect|null
    {
        $dialect = $options['dialect'] ?? null;

        if ($dialect !== null && ! is_string($dialect) && ! $dialect instanceof Dialect) {
            throw new InvalidArgumentException('The dialect option must be a dialect name or instance.');
        }

        return $dialect;
    }

    /**
     * maybe_parse for builder input: expressions pass through (copied if asked); strings, numbers
     * and booleans are parsed as SQL with the dialect.
     *
     * @param  class-string|list<class-string>|null  $into
     */
    public static function parseSql(mixed $sql, string|array|null $into = null, string|Dialect|null $dialect = null, ?string $prefix = null, bool $copy = false): Expression
    {
        return self::maybeParse(self::sqlArg($sql), $into, $dialect, $prefix, $copy);
    }

    /**
     * Normalize builder input to an expression or SQL string (Python's str() of a value).
     */
    public static function sqlArg(mixed $sql): Expression|string
    {
        return match (true) {
            $sql instanceof Expression, is_string($sql) => $sql,
            $sql === null => throw new ParseError('SQL cannot be None'),
            is_bool($sql) => $sql ? 'TRUE' : 'FALSE',
            is_int($sql), is_float($sql), $sql instanceof Stringable => (string) $sql,
            default => throw new InvalidArgumentException('Cannot build SQL from '.get_debug_type($sql)),
        };
    }

    /**
     * Set a single builder argument, wrapping and parsing as needed (sqlglot _apply_builder).
     *
     * @template T of Expression
     *
     * @param  T  $instance
     * @param  class-string<Expression>|null  $into
     * @return T
     */
    public static function applyBuilder(mixed $expression, Expression $instance, string $arg, bool $copy = true, ?string $prefix = null, ?string $into = null, string|Dialect|null $dialect = null, string $intoArg = 'this'): Expression
    {
        if ($into !== null && $expression instanceof Expression && ! $expression instanceof $into) {
            $expression = new $into([$intoArg => $expression]);
        }

        $instance = $copy ? $instance->copy() : $instance;
        $instance->set($arg, self::parseSql($expression, $into, $dialect, $prefix));

        return $instance;
    }

    /**
     * Merge expressions into a child node's list, e.g. ORDER BY (sqlglot _apply_child_list_builder).
     *
     * @template T of Expression
     *
     * @param  list<mixed>  $expressions
     * @param  T  $instance
     * @param  class-string<Expression>  $into
     * @param  array<string, mixed>  $properties
     * @return T
     */
    public static function applyChildListBuilder(array $expressions, Expression $instance, string $arg, string $into, bool $append = true, bool $copy = true, ?string $prefix = null, string|Dialect|null $dialect = null, array $properties = []): Expression
    {
        $instance = $copy ? $instance->copy() : $instance;
        $parsed = [];

        foreach ($expressions as $expression) {
            if ($expression === null) {
                continue;
            }

            if ($expression instanceof Expression && ! $expression instanceof $into) {
                $expression = new $into(['expressions' => [$expression]]);
            }

            $expression = self::parseSql($expression, $into, $dialect, $prefix);

            foreach ($expression->args as $key => $value) {
                if ($key === 'expressions') {
                    array_push($parsed, ...(is_array($value) ? array_values($value) : []));
                } else {
                    $properties[$key] = $value;
                }
            }
        }

        $existing = $instance->args[$arg] ?? null;

        if ($append && $existing instanceof Expression) {
            $parsed = [...$existing->argList('expressions'), ...$parsed];
        }

        $child = new $into(['expressions' => $parsed]);

        foreach ($properties as $key => $value) {
            $child->set($key, $value);
        }

        $instance->set($arg, $child);

        return $instance;
    }

    /**
     * Append parsed expressions to a list argument (sqlglot _apply_list_builder).
     *
     * @template T of Expression
     *
     * @param  list<mixed>  $expressions
     * @param  T  $instance
     * @param  class-string|null  $into
     * @return T
     */
    public static function applyListBuilder(array $expressions, Expression $instance, string $arg, bool $append = true, bool $copy = true, ?string $prefix = null, ?string $into = null, string|Dialect|null $dialect = null): Expression
    {
        $instance = $copy ? $instance->copy() : $instance;
        $parsed = [];

        foreach ($expressions as $expression) {
            if ($expression !== null) {
                $parsed[] = self::parseSql($expression, $into, $dialect, $prefix);
            }
        }

        $existing = $instance->args[$arg] ?? null;

        if ($append && is_array($existing) && $existing !== []) {
            $parsed = [...array_values($existing), ...$parsed];
        }

        $instance->set($arg, $parsed);

        return $instance;
    }

    /**
     * AND expressions into an argument, e.g. WHERE (sqlglot _apply_conjunction_builder).
     *
     * @template T of Expression
     *
     * @param  list<mixed>  $expressions
     * @param  T  $instance
     * @param  class-string<Expression>|null  $into
     * @return T
     */
    public static function applyConjunctionBuilder(array $expressions, Expression $instance, string $arg, ?string $into = null, bool $append = true, bool $copy = true, string|Dialect|null $dialect = null): Expression
    {
        $filtered = [];
        foreach ($expressions as $expression) {
            if ($expression !== null && $expression !== '') {
                $filtered[] = self::sqlArg($expression);
            }
        }

        if ($filtered === []) {
            return $instance;
        }

        $instance = $copy ? $instance->copy() : $instance;
        $existing = $instance->args[$arg] ?? null;

        if ($append && $existing instanceof Expression) {
            $previous = $into !== null ? $existing->args['this'] ?? null : $existing;

            if ($previous instanceof Expression) {
                array_unshift($filtered, $previous);
            }
        }

        $node = self::and($filtered, $dialect, $copy);
        $instance->set($arg, $into !== null ? new $into(['this' => $node]) : $node);

        return $instance;
    }

    /**
     * Add a common table expression (sqlglot _apply_cte_builder).
     *
     * @template T of Expression
     *
     * @param  T  $instance
     * @return T
     */
    public static function applyCteBuilder(Expression $instance, mixed $alias, mixed $as, ?bool $recursive = null, ?bool $materialized = null, bool $append = true, string|Dialect|null $dialect = null, bool $copy = true, ?bool $scalar = null): Expression
    {
        $aliasExpression = self::parseSql($alias, TableAlias::class, $dialect);
        $asExpression = self::parseSql($as, dialect: $dialect, copy: $copy);

        if ($scalar === true && ! $asExpression instanceof Subquery) {
            // A scalar CTE must be wrapped in a subquery
            $asExpression = new Subquery(['this' => $asExpression]);
        }

        $cte = new CTE(['this' => $asExpression, 'alias' => $aliasExpression, 'materialized' => $materialized, 'scalar' => $scalar]);

        return self::applyChildListBuilder([$cte], $instance, 'with_', With::class, $append, $copy, properties: $recursive === true ? ['recursive' => true] : []);
    }

    /**
     * @param  class-string<SetOperation>  $class
     * @param  array<int|string, mixed>  $args
     */
    private static function setOperation(string $class, array $args): SetOperation
    {
        [$expressions, $options] = self::splitOptions($args, ['distinct' => true, 'dialect' => null, 'copy' => true]);

        if (count($expressions) < 2) {
            throw new InvalidArgumentException('At least two expressions are required by a set operation.');
        }

        $dialect = self::dialectOption($options);
        $result = null;

        foreach ($expressions as $expression) {
            $expression = self::parseSql($expression, dialect: $dialect, copy: (bool) $options['copy']);
            $result = $result === null ? $expression : new $class(['this' => $result, 'expression' => $expression, 'distinct' => (bool) $options['distinct']]);
        }

        /** @var SetOperation $result */
        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $args
     * @return list<Expression>
     */
    private static function parseAll(array $args): array
    {
        [$expressions, $options] = self::splitOptions($args, ['dialect' => null, 'copy' => true]);
        $dialect = self::dialectOption($options);

        return array_map(static fn (mixed $e): Expression => self::parseSql($e, dialect: $dialect, copy: (bool) $options['copy']), $expressions);
    }

    /**
     * Python truthiness for builder arguments.
     */
    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== [] && $value !== 0;
    }

    /**
     * Every table a query reads (sqlglot find_tables), CTE references excluded.
     *
     * @return list<Table>
     */
    public static function findTables(Expression $expression): array
    {
        $tables = [];

        foreach (Scope::traverse($expression) as $scope) {
            foreach ($scope->tables() as $table) {
                if ($table instanceof Table && $table->name() !== '' && ! isset($scope->cteSources[$table->name()])) {
                    $tables[spl_object_id($table)] = $table;
                }
            }
        }

        return array_values($tables);
    }

    /**
     * A table's full dotted name, quoting only the parts that need it (sqlglot table_name).
     */
    public static function tableName(Table|string $table, string|Dialect|null $dialect = null, bool $identify = false): string
    {
        $expression = self::maybeParse($table, into: Table::class, dialect: $dialect);

        if (! $expression instanceof Table) {
            throw new InvalidArgumentException('Cannot parse '.(is_string($table) ? $table : $table->sql()));
        }

        $parts = [];

        foreach ($expression->parts() as $part) {
            $parts[] = $identify || ! self::isSafeIdentifier($part->name())
                ? $part->sql($dialect, ['identify' => true, 'comments' => false], copy: false)
                : $part->name();
        }

        return implode('.', $parts);
    }

    /**
     * A table name, case-normalized for the dialect and without quotes (sqlglot normalize_table_name).
     */
    public static function normalizeTableName(Table|string $table, string|Dialect|null $dialect = null, bool $copy = true): string
    {
        $normalized = NormalizeIdentifiers::normalizeIdentifiers(self::toTable($table, dialect: $dialect, copy: $copy), dialect: $dialect);

        return implode('.', array_map(static fn (Expression $part): string => $part->name(), $normalized->parts()));
    }

    /**
     * Replace tables by name, e.g. ['a.b' => 'c'] (sqlglot replace_tables). The original name is
     * kept as a comment.
     *
     * @param  array<string, string>  $mapping
     */
    public static function replaceTables(Expression $expression, array $mapping, string|Dialect|null $dialect = null, bool $copy = true): Expression
    {
        $normalized = [];

        foreach ($mapping as $name => $newName) {
            $normalized[self::normalizeTableName((string) $name, $dialect)] = $newName;
        }

        return $expression->transform(static function (Expression $node) use ($normalized, $dialect): Expression {
            if (! $node instanceof Table || $node->metaGet('replace') === false) {
                return $node;
            }

            $original = self::normalizeTableName($node, $dialect);
            $newName = $normalized[$original] ?? '';

            if ($newName === '') {
                return $node;
            }

            $args = array_diff_key($node->args, ['this' => true, 'db' => true, 'catalog' => true]);
            $table = self::toTable($newName, dialect: $dialect, args: $args);
            $table->addComments([$original]);

            return $table;
        }, $copy) ?? $expression;
    }

    /**
     * Replace placeholders: unnamed ones (?) by $args in order, named ones (:name) by $named
     * (sqlglot replace_placeholders). Values go through convert().
     *
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $named
     */
    public static function replacePlaceholders(Expression $expression, array $args = [], array $named = []): Expression
    {
        return $expression->transform(static function (Expression $node) use (&$args, $named): Expression {
            if (! $node instanceof Placeholder) {
                return $node;
            }

            $name = $node->args['this'] ?? null;

            if ($name !== null && $name !== '') {
                $value = $named[$name instanceof Expression ? $name->name() : (string) $name] ?? null;

                return $value === null ? $node : self::convert($value);
            }

            return $args === [] ? $node : self::convert(array_shift($args));
        }) ?? $expression;
    }

    /**
     * Expand tables into subqueries of the given sources (sqlglot expand), recursively. A source
     * is a query or a closure returning one.
     *
     * @param  array<string, Query|Closure(): Query>  $sources
     */
    public static function expand(Expression $expression, array $sources, string|Dialect|null $dialect = null, bool $copy = true): Expression
    {
        $normalized = [];

        foreach ($sources as $name => $source) {
            $normalized[self::normalizeTableName((string) $name, $dialect)] = $source;
        }

        $expandNode = static function (Expression $node) use (&$expandNode, $normalized, $dialect): Expression {
            if (! $node instanceof Table) {
                return $node;
            }

            $name = self::normalizeTableName($node, $dialect);
            $source = $normalized[$name] ?? null;

            if ($source === null) {
                return $node;
            }

            $query = $source instanceof Closure ? $source() : $source;
            $alias = $node->alias();
            $subquery = $query->subquery($alias !== '' ? $alias : $name);
            $subquery->comments = ["source: {$name}"];

            return $subquery->transform($expandNode, copy: false) ?? $subquery;
        };

        return $expression->transform($expandNode, $copy) ?? $expression;
    }
}
