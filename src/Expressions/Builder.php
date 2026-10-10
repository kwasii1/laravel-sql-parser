<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions;

use InvalidArgumentException;
use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Errors\ParseError;
use Kwasii\LaravelSqlParser\Optimizer\Simplify;

/**
 * Expression builders (sqlglot's module-level helpers in expressions/core.py and builders.py).
 */
final class Builder
{
    use Concerns\BuildsQueries;

    /** sqlglot SAFE_IDENTIFIER_RE: names that never need quoting. */
    public const string SAFE_IDENTIFIER = '/^[_a-zA-Z][\p{L}\p{N}_]*\n?\z/u';

    public static function isSafeIdentifier(string $name): bool
    {
        return preg_match(self::SAFE_IDENTIFIER, $name) === 1;
    }

    /**
     * Parse a SQL string into an expression, or return an expression as-is (copied if asked).
     *
     * @param  class-string|list<class-string>|null  $into
     */
    public static function maybeParse(Expression|string|int|float $sql, string|array|null $into = null, string|Dialect|null $dialect = null, ?string $prefix = null, bool $copy = false): Expression
    {
        if ($sql instanceof Expression) {
            return $copy ? $sql->copy() : $sql;
        }

        $sql = (string) $sql;

        if ($prefix !== null && $prefix !== '') {
            $sql = "{$prefix} {$sql}";
        }

        $dialect = Dialect::get($dialect);

        return $into === null ? $dialect->parseOne($sql) : $dialect->parseInto($into, $sql);
    }

    public static function var(Expression|string|null $name): VarExpr
    {
        if ($name instanceof Expression) {
            $name = $name->name();
        }

        if ($name === null || $name === '') {
            throw new InvalidArgumentException('Cannot convert empty name into var.');
        }

        return new VarExpr(['this' => $name]);
    }

    public static function toIdentifier(Identifier|string|null $name, ?bool $quoted = null, bool $copy = true): ?Identifier
    {
        if ($name === null) {
            return null;
        }

        if ($name instanceof Identifier) {
            return $copy ? $name->copy() : $name;
        }

        return new Identifier(['this' => $name, 'quoted' => $quoted ?? ! self::isSafeIdentifier($name)]);
    }

    /**
     * @param  list<Identifier|string>|null  $fields
     */
    public static function column(Identifier|Star|string $col, Identifier|string|null $table = null, Identifier|string|null $db = null, Identifier|string|null $catalog = null, ?array $fields = null, ?bool $quoted = null, bool $copy = true): Expression
    {
        if (! $col instanceof Star) {
            $col = self::toIdentifier($col, $quoted, $copy);
        }

        $column = new Column([
            'this' => $col,
            'table' => self::toIdentifier($table, $quoted, $copy),
            'db' => self::toIdentifier($db, $quoted, $copy),
            'catalog' => self::toIdentifier($catalog, $quoted, $copy),
        ]);

        if ($fields !== null && $fields !== []) {
            $parts = [$column];
            foreach ($fields as $field) {
                $parts[] = self::toIdentifier($field, $quoted, $copy);
            }

            return Dot::build($parts);
        }

        return $column;
    }

    public static function table(Identifier|string|null $table, Identifier|string|null $db = null, Identifier|string|null $catalog = null, ?bool $quoted = null, Identifier|string|null $alias = null): Table
    {
        return new Table([
            'this' => $table !== null && $table !== '' ? self::toIdentifier($table, $quoted) : null,
            'db' => $db !== null && $db !== '' ? self::toIdentifier($db, $quoted) : null,
            'catalog' => $catalog !== null && $catalog !== '' ? self::toIdentifier($catalog, $quoted) : null,
            'alias' => $alias !== null && $alias !== '' ? new TableAlias(['this' => self::toIdentifier($alias)]) : null,
        ]);
    }

    /**
     * Alias an expression (sqlglot alias_). With $table, the alias becomes a TableAlias.
     *
     * @param  bool|list<Identifier|string>  $table
     */
    public static function alias(Expression|string $expression, Identifier|string|null $alias, bool|array $table = false, ?bool $quoted = null, string|Dialect|null $dialect = null, bool $copy = true): Expression
    {
        $expression = self::maybeParse($expression, dialect: $dialect, copy: $copy);
        $alias = self::toIdentifier($alias, $quoted);

        if ($table !== false) {
            $tableAlias = new TableAlias(['this' => $alias]);
            $expression->set('alias', $tableAlias);

            if (is_array($table)) {
                foreach ($table as $column) {
                    $tableAlias->append('columns', self::toIdentifier($column, $quoted));
                }
            }

            return $expression;
        }

        if (array_key_exists('alias', $expression::ARG_TYPES) && ! $expression instanceof Window) {
            $expression->set('alias', $alias);

            return $expression;
        }

        return new Alias(['this' => $expression, 'alias' => $alias]);
    }

    /**
     * Wrap a node in parentheses if it's of the given kind (sqlglot _wrap).
     *
     * @param  class-string  $kind
     */
    public static function wrap(?Expression $expression, string $kind): ?Expression
    {
        return $expression instanceof $kind ? new Paren(['this' => $expression]) : $expression;
    }

    public static function condition(Expression|string $expression, string|Dialect|null $dialect = null, bool $copy = true): Expression
    {
        return self::maybeParse($expression, Condition::class, $dialect, copy: $copy);
    }

    /**
     * @param  list<Expression|string|null>  $expressions
     */
    public static function and(array $expressions, string|Dialect|null $dialect = null, bool $copy = true, bool $wrap = true): Expression
    {
        return self::combine($expressions, AndExpr::class, $dialect, $copy, $wrap);
    }

    /**
     * @param  list<Expression|string|null>  $expressions
     */
    public static function or(array $expressions, string|Dialect|null $dialect = null, bool $copy = true, bool $wrap = true): Expression
    {
        return self::combine($expressions, OrExpr::class, $dialect, $copy, $wrap);
    }

    /**
     * @param  list<Expression|string|null>  $expressions
     */
    public static function xor(array $expressions, string|Dialect|null $dialect = null, bool $copy = true, bool $wrap = true): Expression
    {
        return self::combine($expressions, XorExpr::class, $dialect, $copy, $wrap);
    }

    /**
     * A binary node built like sqlglot's Python operators (a + b, a > b, a.eq(b)): copies of the
     * operands, wrapped in parentheses when they're binary nodes of another kind.
     *
     * @param  class-string<Expression>  $class
     */
    public static function binop(string $class, mixed $left, mixed $right): Expression
    {
        $left = $left instanceof Expression ? $left->copy() : self::convert($left);
        $right = $right instanceof Expression ? $right->copy() : self::convert($right);

        if (! $left instanceof $class && ! $right instanceof $class) {
            $left = self::wrap($left, Binary::class);
            $right = self::wrap($right, Binary::class);
        }

        return new $class(['this' => $left, 'expression' => $right]);
    }

    public static function not(Expression|string $expression, string|Dialect|null $dialect = null, bool $copy = true): Not
    {
        $this_ = self::condition($expression, $dialect, $copy);

        return new Not(['this' => self::wrap($this_, Connector::class)]);
    }

    public static function paren(Expression|string $expression, bool $copy = true): Paren
    {
        return new Paren(['this' => self::maybeParse($expression, copy: $copy)]);
    }

    /**
     * @param  list<Expression|string|null>  $expressions
     * @param  class-string<Expression>  $operator
     */
    private static function combine(array $expressions, string $operator, string|Dialect|null $dialect, bool $copy, bool $wrap): Expression
    {
        $conditions = [];
        foreach ($expressions as $expression) {
            if ($expression !== null) {
                $conditions[] = self::condition($expression, $dialect, $copy);
            }
        }

        if ($conditions === []) {
            throw new InvalidArgumentException('At least one condition is required.');
        }

        $this_ = array_shift($conditions);

        if ($conditions !== [] && $wrap) {
            $this_ = self::wrap($this_, Connector::class);
        }

        foreach ($conditions as $expression) {
            $this_ = new $operator(['this' => $this_, 'expression' => $wrap ? self::wrap($expression, Connector::class) : $expression]);
        }

        return $this_;
    }

    /**
     * The table qualifiers of the columns in an expression, except `$exclude` (sqlglot exp.column_table_names).
     *
     * @return list<string>
     */
    public static function columnTableNames(Expression $expression, string $exclude = ''): array
    {
        $names = [];

        foreach ($expression->findAll(Column::class) as $column) {
            $table = $column->table();

            if ($table !== '' && $table !== $exclude) {
                $names[$table] = true;
            }
        }

        return array_map(strval(...), array_keys($names));
    }

    public static function true(): Boolean
    {
        return new Boolean(['this' => true]);
    }

    public static function false(): Boolean
    {
        return new Boolean(['this' => false]);
    }

    public static function null(): NullExpr
    {
        return new NullExpr;
    }

    /**
     * SELECT * FROM <source> (sqlglot exp.select("*").from_(source)).
     */
    public static function selectStarFrom(Expression $source): Select
    {
        return new Select([
            'expressions' => [new Star],
            'from_' => $source instanceof From ? $source : new From(['this' => $source]),
        ]);
    }

    /**
     * Cast an expression to a data type (sqlglot exp.cast). Casting a cast to the same type is a no-op.
     */
    public static function cast(Expression|string $expression, DataType|string $to, bool $copy = true, string|Dialect|null $dialect = null): Cast
    {
        $expression = self::maybeParse($expression, dialect: $dialect, copy: $copy);
        $dataType = DataType::build($to, $dialect, copy: $copy);

        // Don't re-cast if the expression is already a cast to the same (or an equivalent) type
        if ($expression instanceof Cast) {
            $existing = $expression->args['to'] ?? null;

            if ($existing instanceof DataType) {
                $typeMapping = constant(Dialect::get($dialect)::GENERATOR::TABLES.'::TYPE_MAPPING');
                $existingType = $existing->args['this'] ?? null;
                $newType = $dataType->args['this'] ?? null;
                $equivalent = is_string($existingType) && is_string($newType)
                    && ($typeMapping[$existingType] ?? DType::VALUES[$existingType] ?? $existingType) === ($typeMapping[$newType] ?? DType::VALUES[$newType] ?? $newType);

                if ($existing->isType($dataType) || $equivalent) {
                    return $expression;
                }
            }
        }

        $cast = new Cast(['this' => $expression, 'to' => $dataType]);
        $cast->setType($dataType);

        return $cast;
    }

    /**
     * Convert a PHP value into an expression (sqlglot convert).
     */
    public static function convert(mixed $value, bool $copy = false): Expression
    {
        return match (true) {
            $value instanceof Expression => $copy ? $value->copy() : $value,
            is_string($value) => Literal::string($value),
            is_bool($value) => new Boolean(['this' => $value]),
            $value === null, is_float($value) && is_nan($value) => new NullExpr,
            is_int($value), is_float($value) => Literal::number($value),
            is_array($value) && array_is_list($value) => new ArrayExpr(['expressions' => array_map(static fn (mixed $v): Expression => self::convert($v, $copy), $value)]),
            is_array($value) => new Map([
                'keys' => new ArrayExpr(['expressions' => array_map(static fn (int|string $k): Expression => self::convert($k, $copy), array_keys($value))]),
                'values' => new ArrayExpr(['expressions' => array_map(static fn (mixed $v): Expression => self::convert($v, $copy), array_values($value))]),
            ]),
            default => throw new InvalidArgumentException('Cannot convert '.get_debug_type($value)),
        };
    }

    /**
     * Build a function call by name, using the dialect's function table (sqlglot exp.func).
     *
     * @param  list<Expression|string>  $args
     */
    public static function func(string $name, array $args = [], string|Dialect|null $dialect = null, bool $copy = true): Expression
    {
        $dialect = Dialect::get($dialect);
        $converted = array_map(static fn (Expression|string $arg): Expression => self::maybeParse($arg, dialect: $dialect, copy: $copy), $args);
        $function = $dialect->parser()->buildFunction(strtoupper($name), $converted) ?? new Anonymous(['this' => $name, 'expressions' => $converted]);

        foreach ($function->errorMessages($converted) as $message) {
            throw new ParseError($message);
        }

        return $function;
    }

    /**
     * Shift an array index by a dialect's index offset (sqlglot apply_index_offset).
     *
     * sqlglot decides with type inference; until that is ported, integer literals are shifted
     * unless the indexed value is clearly not an array.
     *
     * @param  list<Expression>  $expressions
     * @return list<Expression>
     */
    public static function applyIndexOffset(Expression $this_, array $expressions, int $offset, ?Dialect $dialect = null): array
    {
        if ($offset === 0 || count($expressions) !== 1) {
            return $expressions;
        }

        $dialect ??= Dialect::get();
        $expression = $expressions[0];

        if ($this_->type() === null) {
            $dialect->annotateTypes($this_);
        }

        if (! $this_->isType(DType::UNKNOWN, DType::ARRAY)) {
            return $expressions;
        }

        if ($expression->type() === null) {
            $dialect->annotateTypes($expression);
        }

        if (! $expression->isType(...DataType::INTEGER_TYPES)) {
            return $expressions;
        }

        return [Simplify::simplify(self::binop(Add::class, $expression, Literal::number($offset)))];
    }

    /**
     * An expression's unit as a string literal (sqlglot unit_to_str).
     */
    public static function unitToStr(Expression $expression, ?string $default = 'DAY'): ?Expression
    {
        $unit = $expression->args['unit'] ?? null;

        if (! $unit instanceof Expression) {
            return $default !== null && $default !== '' ? Literal::string($default) : null;
        }

        if ($unit instanceof WeekStart) {
            // WEEK(<day>) is BigQuery-only syntax, so it degrades to the plain WEEK unit
            return Literal::string('WEEK');
        }

        if ($unit instanceof Placeholder || ($unit::class !== VarExpr::class && $unit::class !== Literal::class)) {
            return $unit;
        }

        return Literal::string($unit->name());
    }

    /**
     * An expression's unit as a variable (sqlglot unit_to_var).
     */
    public static function unitToVar(Expression $expression, ?string $default = 'DAY'): ?Expression
    {
        $unit = $expression->args['unit'] ?? null;

        if ($unit instanceof VarExpr || $unit instanceof Placeholder || $unit instanceof WeekStart || $unit instanceof Column) {
            return $unit;
        }

        $value = $unit instanceof Expression ? $unit->name() : $default;

        return $value !== null && $value !== '' ? new VarExpr(['this' => $value]) : null;
    }

    /**
     * Build an interval from a string like '1 day' or '5 months' (sqlglot to_interval).
     */
    public static function toInterval(Expression|string $interval): Interval
    {
        if ($interval instanceof Literal) {
            if (! $interval->isString()) {
                throw new InvalidArgumentException('Invalid interval string.');
            }

            $interval = $interval->name();
        }

        $parsed = self::maybeParse('INTERVAL '.($interval instanceof Expression ? $interval->sql() : $interval));

        if (! $parsed instanceof Interval) {
            throw new InvalidArgumentException('Invalid interval string.');
        }

        return $parsed;
    }
}
