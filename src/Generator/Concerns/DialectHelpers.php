<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Generator\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\AndExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayFilter;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayRemove;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseAnd;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseRightShift;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Coalesce;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataTypeParam;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateDiff;
use LaravelSqlParser\LaravelSqlParser\Expressions\DatetimeAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DatetimeSub;
use LaravelSqlParser\LaravelSqlParser\Expressions\Day;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\DPipe;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Explode;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\GT;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\Insert;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPath;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPathPart;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPathSubscript;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPathWildcard;
use LaravelSqlParser\LaravelSqlParser\Expressions\Kwarg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lambda;
use LaravelSqlParser\LaravelSqlParser\Expressions\LastDay;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lateral;
use LaravelSqlParser\LaravelSqlParser\Expressions\Length;
use LaravelSqlParser\LaravelSqlParser\Expressions\Like;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lower;
use LaravelSqlParser\LaravelSqlParser\Expressions\LT;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\OrExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Posexplode;
use LaravelSqlParser\LaravelSqlParser\Expressions\Predicate;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sub;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Substring;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToTimestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Update;
use LaravelSqlParser\LaravelSqlParser\Expressions\Upper;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\WeekStart;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithinGroup;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;
use LaravelSqlParser\LaravelSqlParser\Support\TimeZones;
use ReflectionClass;

/**
 * Generator helpers shared by dialects (sqlglot dialects/dialect.py). Helpers that sqlglot uses as
 * TRANSFORMS entries are named after the Python function; ones that clash with a generator method
 * carry a "Handler" suffix (see tools/generator/Dialects.php).
 */
trait DialectHelpers
{
    /** Days of week to ISO 8601 day-of-week numbers (Monday = 1). */
    protected const array WEEK_START_DAY_TO_DOW = [
        'MONDAY' => 1,
        'TUESDAY' => 2,
        'WEDNESDAY' => 3,
        'THURSDAY' => 4,
        'FRIDAY' => 5,
        'SATURDAY' => 6,
        'SUNDAY' => 7,
    ];

    /**
     * Convert a dialect's WEEK_OFFSET (days relative to Monday) to the ISO day number of its week start.
     */
    public static function weekOffsetToDow(int $offset): int
    {
        return (($offset % 7) + 7) % 7 + 1;
    }

    /**
     * Map a date part to this dialect's canonical name (sqlglot map_date_part).
     */
    public function mapDatePart(?Expression $part): ?Expression
    {
        if ($part === null || ($part instanceof Column && count($part->parts()) !== 1)) {
            return $part;
        }

        $mapped = $this->dialect->settings['DATE_PART_MAPPING'][strtoupper($part->name())] ?? null;

        if (is_string($mapped) && $mapped !== '') {
            return $part->isString() ? Literal::string($mapped) : Builder::var($mapped);
        }

        return $part;
    }

    public function concatToDpipeSql(Expression $expression): string
    {
        $args = $this->convertConcatArgs($expression);
        $result = array_shift($args);

        foreach ($args as $arg) {
            $result = new DPipe(['this' => $result, 'expression' => $arg]);
        }

        return $this->sql($result);
    }

    /**
     * sqlglot var_map_sql: MAP(k1, v1, k2, v2, ...) from a Map / VarMap with array keys and values.
     */
    public function varMapSql(Expression $expression, string $mapFuncName = 'MAP'): string
    {
        $keys = $expression->args['keys'] ?? null;
        $values = $expression->args['values'] ?? null;

        if (! $keys instanceof ArrayExpr || ! $values instanceof ArrayExpr) {
            $this->unsupported('Cannot convert array columns into map.');

            return $this->func($mapFuncName, $keys, $values);
        }

        $args = [];
        $valueList = $values->argList('expressions');

        foreach ($keys->argList('expressions') as $i => $key) {
            if (! isset($valueList[$i])) {
                break;
            }

            $args[] = $this->sql($key);
            $args[] = $this->sql($valueList[$i]);
        }

        return $this->funcWith($mapFuncName, $args);
    }

    /**
     * sqlglot jarowinkler_similarity(func).
     */
    public function jarowinklerSimilarity(Expression $expression, string $func): string
    {
        $this_ = $expression->args['this'] ?? null;
        $expr = $expression->args['expression'] ?? null;

        if ($expression->flag('case_insensitive')) {
            $this_ = new Upper(['this' => $this_]);
            $expr = new Upper(['this' => $expr]);
        }

        return $this->func($func, $this_, $expr);
    }

    /**
     * sqlglot remove_from_array_using_filter: ARRAY_REMOVE(arr, x) as a filter with _u <> x.
     */
    public function removeFromArrayUsingFilter(Expression $expression): string
    {
        $lambdaId = Builder::toIdentifier('_u');
        $cond = new NEQ(['this' => $lambdaId, 'expression' => $expression->args['expression'] ?? null]);
        $filterSql = $this->sql(new ArrayFilter([
            'this' => $expression->args['this'] ?? null,
            'expression' => new Lambda(['this' => $cond, 'expressions' => [$lambdaId]]),
        ]));

        // The source propagates NULLs (Snowflake) and the target doesn't (DuckDB): a NULL removal
        // value returns NULL instead of applying the filter
        if ($expression->flag('null_propagation') && $this->dialect->settings['ARRAY_FUNCS_PROPAGATES_NULLS'] !== true) {
            $removalValue = $expression->args['expression'] ?? null;

            // A non-NULL literal (5, 'a', TRUE) or an array literal can't be NULL
            if ($removalValue instanceof Literal || $removalValue instanceof ArrayExpr) {
                return $filterSql;
            }

            return $this->sql(new IfExpr([
                'this' => new Is(['this' => $removalValue, 'expression' => new NullExpr]),
                'true' => new NullExpr,
                'false' => $filterSql,
            ]));
        }

        return $filterSql;
    }

    /**
     * sqlglot no_make_interval_sql: MAKE_INTERVAL(...) as an INTERVAL string.
     */
    public function noMakeIntervalSql(Expression $expression, string $sep = ', '): string
    {
        $args = [];

        foreach ($expression->args as $unit => $value) {
            if ($value instanceof Kwarg) {
                $value = $value->args['expression'] ?? null;
            }

            if ($value === null || $value === false) {
                continue;
            }

            $args[] = ($value instanceof Expression ? $value->sql() : (string) $value)." {$unit}";
        }

        return "INTERVAL '".$this->formatArgs($args, $sep)."'";
    }

    /**
     * sqlglot no_timestamp_sql: TIMESTAMP(x [, zone]) as a cast, AT TIME ZONE for a known zone.
     */
    public function noTimestampSql(Expression $expression): string
    {
        $zone = $expression->args['zone'] ?? null;

        if (! $zone instanceof Expression) {
            $this->dialect->annotateTypes($expression);

            return $this->sql(Builder::cast($expression->args['this'] ?? null, $expression->type() ?? DType::TIMESTAMP));
        }

        if (isset(TimeZones::NAMES[strtolower($zone->name())])) {
            return $this->sql(new AtTimeZone(['this' => Builder::cast($expression->args['this'] ?? null, DType::TIMESTAMP), 'zone' => $zone]));
        }

        return $this->func('TIMESTAMP', $expression->args['this'] ?? null, $zone);
    }

    /**
     * sqlglot timestampdiff_sql: TIMESTAMPDIFF(unit, start, end).
     */
    public function timestampdiffSqlHandler(Expression $expression): string
    {
        return $this->func('TIMESTAMPDIFF', $expression->args['unit'] ?? null, $expression->args['expression'] ?? null, $expression->args['this'] ?? null);
    }

    /**
     * sqlglot week_unit_to_dow: the ISO week start day of a week unit (WEEK / ISOWEEK is Monday,
     * WEEK(<day>) is that day), or null.
     */
    public static function weekUnitToDow(?Expression $unit): ?int
    {
        if ($unit instanceof VarExpr && in_array(strtoupper($unit->name()), ['WEEK', 'ISOWEEK'], true)) {
            return 1;
        }

        if ($unit instanceof WeekStart) {
            return self::WEEK_START_DAY_TO_DOW[strtoupper($unit->name())] ?? null;
        }

        return null;
    }

    /**
     * sqlglot array_compact_sql: ARRAY_COMPACT(a) as a filter of the non-NULL elements.
     */
    public function arrayCompactSqlHandler(Expression $expression): string
    {
        $lambdaId = Builder::toIdentifier('_u');
        $cond = Builder::not(new Is(['this' => $lambdaId, 'expression' => new NullExpr]));

        return $this->sql(new ArrayFilter([
            'this' => $expression->args['this'] ?? null,
            'expression' => new Lambda(['this' => $cond, 'expressions' => [$lambdaId->copy()]]),
        ]));
    }

    /**
     * sqlglot encode_decode_sql: only the UTF-8 character set is supported.
     */
    public function encodeDecodeSql(Expression $expression, string $name, bool $replace = true): string
    {
        $charset = $expression->args['charset'] ?? null;

        if ($charset instanceof Expression && ! in_array(strtolower($charset->name()), ['utf-8', 'utf8'], true)) {
            $this->unsupported('Expected utf-8 character set, got '.$charset->sql().'.');
        }

        return $this->func($name, $expression->args['this'] ?? null, $replace ? ($expression->args['replace'] ?? null) : null);
    }

    /**
     * sqlglot explode_to_unnest_sql: LATERAL VIEW [POS]EXPLODE as a cross joined UNNEST.
     */
    public function explodeToUnnestSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $alias = $expression->args['alias'] ?? null;
        $crossJoinExpr = null;

        if ($this_ instanceof Posexplode && $alias instanceof Expression) {
            // Spark's LATERAL VIEW POSEXPLODE(y) t AS pos, col has the 0-based position first and
            // the exploded values after it, while WITH ORDINALITY is 1-based and comes last:
            // CROSS JOIN LATERAL (SELECT pos - 1 AS pos, col FROM UNNEST(y) WITH ORDINALITY AS t(col, pos))
            $columns = $alias->argList('columns');
            $pos = $columns[0] ?? null;
            $cols = array_slice($columns, 1);
            $lateralSubquery = new Select([
                'expressions' => [Builder::alias(Builder::binop(Sub::class, $pos, Literal::number(1)), $pos instanceof Identifier ? $pos : null), ...array_map(static fn (Expression $c): Expression => $c->copy(), $cols)],
                'from_' => new From(['this' => new Unnest([
                    'expressions' => [$this_->args['this'] ?? null],
                    'offset' => true,
                    'alias' => new TableAlias(['this' => $alias->args['this'] ?? null, 'columns' => [...$cols, $pos]]),
                ])]),
            ]);
            $crossJoinExpr = new Lateral(['this' => new Subquery(['this' => $lateralSubquery])]);
        } elseif ($this_ instanceof Explode) {
            $crossJoinExpr = new Unnest(['expressions' => [$this_->args['this'] ?? null], 'alias' => $alias]);
        }

        if ($crossJoinExpr !== null) {
            return $this->sql(new Join(['this' => $crossJoinExpr, 'kind' => 'cross']));
        }

        return $this->lateralSql($expression);
    }

    /**
     * sqlglot months_between_sql: MONTHS_BETWEEN as whole months plus a fraction, Snowflake style,
     * DATEDIFF('month', date2, date1) + (DAY(date1) - DAY(date2)) / 31.0, the fraction being 0
     * when both dates are the last day of their month.
     */
    public function monthsBetweenSqlHandler(Expression $expression): string
    {
        $date1 = Builder::cast($expression->args['this'] ?? null, DType::DATE, copy: false);
        $date2 = Builder::cast($expression->args['expression'] ?? null, DType::DATE, copy: false);
        $wholeMonths = new DateDiff(['this' => $date1, 'expression' => $date2, 'unit' => Builder::var('month')]);
        $day1 = new Day(['this' => $date1->copy()]);
        $day2 = new Day(['this' => $date2->copy()]);
        $bothLastDay = new AndExpr([
            'this' => new EQ(['this' => $day1->copy(), 'expression' => new Day(['this' => new LastDay(['this' => $date1->copy()])])]),
            'expression' => new EQ(['this' => $day2->copy(), 'expression' => new Day(['this' => new LastDay(['this' => $date2->copy()])])]),
        ]);
        $fractional = new Div([
            'this' => new Paren(['this' => new Sub(['this' => $day1->copy(), 'expression' => $day2->copy()])]),
            'expression' => Literal::number('31.0'),
        ]);

        return $this->sql(new Add([
            'this' => $wholeMonths,
            'expression' => new IfExpr(['this' => $bothLastDay, 'true' => Literal::number('0'), 'false' => $fractional]),
        ]));
    }

    /**
     * sqlglot no_time_sql: BigQuery's TIME(timestamp, zone) as
     * CAST(CAST(timestamp AS TIMESTAMPTZ) AT TIME ZONE zone AS TIME).
     */
    public function noTimeSql(Expression $expression): string
    {
        $this_ = Builder::cast($expression->args['this'] ?? null, DType::TIMESTAMPTZ);

        return $this->sql(Builder::cast(new AtTimeZone(['this' => $this_, 'zone' => $expression->args['zone'] ?? null]), DType::TIME));
    }

    /**
     * sqlglot no_datetime_sql: BigQuery's DATETIME(timestamp, zone) via AT TIME ZONE, and
     * DATETIME(date, time) as a date plus a time.
     */
    public function noDatetimeSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $expr = $expression->args['expression'] ?? null;

        if ($expr instanceof Expression && isset(TimeZones::NAMES[strtolower($expr->name())])) {
            $this_ = Builder::cast($this_, DType::TIMESTAMPTZ);

            return $this->sql(Builder::cast(new AtTimeZone(['this' => $this_, 'zone' => $expr]), DType::TIMESTAMP));
        }

        return $this->sql(Builder::cast(new Add(['this' => Builder::cast($this_, DType::DATE), 'expression' => Builder::cast($expr, DType::TIME)]), DType::TIMESTAMP));
    }

    public function noCommentColumnConstraintSql(Expression $expression): string
    {
        $this->unsupported('CommentColumnConstraint unsupported');

        return '';
    }

    /**
     * sqlglot date_delta_to_binary_interval_op(cast): DATE_ADD(x, n, unit) as x + INTERVAL n unit;
     * with $cast, a string literal is cast to the type the arithmetic needs.
     */
    public function dateDeltaToBinaryIntervalOp(Expression $expression, bool $cast = true): string
    {
        $this_ = $expression->args['this'] ?? null;
        $unit = Builder::unitToVar($expression);
        $isAdd = $expression instanceof DateAdd || $expression instanceof TimeAdd || $expression instanceof DatetimeAdd || $expression instanceof TsOrDsAdd || $expression instanceof TimestampAdd;
        $toType = null;

        if ($cast) {
            if ($expression instanceof TsOrDsAdd) {
                $returnType = $expression->args['return_type'] ?? null;
                $toType = $returnType instanceof DataType ? $returnType : DataType::build(is_string($returnType) ? $returnType : DType::DATE);
            } elseif ($this_ instanceof Expression && $this_->isString()) {
                // String literals need the right type for +/- INTERVAL to work
                $toType = $expression instanceof DatetimeAdd || $expression instanceof DatetimeSub ? DType::DATETIME : DType::DATE;
            }
        }

        if ($toType !== null) {
            $this_ = Builder::cast($this_, $toType);
        }

        $expr = $expression->args['expression'] ?? null;
        $interval = $expr instanceof Interval ? $expr : new Interval(['this' => $expr, 'unit' => $unit]);

        return $this->sql($this_).' '.($isAdd ? '+' : '-').' '.$this->sql($interval);
    }

    /**
     * sqlglot nth_value_from_sql: NTH_VALUE(x, n) FROM FIRST / FROM LAST.
     */
    public function nthValueFromSql(Expression $expression): string
    {
        $this_ = $this->func('NTH_VALUE', $expression->args['this'] ?? null, $expression->args['offset'] ?? null);
        $fromFirst = $expression->args['from_first'] ?? null;

        if ($fromFirst === null) {
            return $this_;
        }

        return $this_.' FROM '.($this->truthy($fromFirst) ? 'FIRST' : 'LAST');
    }

    /**
     * sqlglot concat_ws_to_dpipe_sql: CONCAT_WS(d, a, b) as a || d || b.
     */
    public function concatWsToDpipeSql(Expression $expression): string
    {
        $args = $expression->argList('expressions');
        $delimiter = array_shift($args);
        $result = array_shift($args);

        foreach ($args as $arg) {
            $result = new DPipe(['this' => $result, 'expression' => new DPipe(['this' => $delimiter, 'expression' => $arg])]);
        }

        return $this->sql($result);
    }

    /**
     * sqlglot rename_func(name): the node's arguments, flattened, as a call to $name.
     */
    public function renameFunc(Expression $expression, string $name): string
    {
        $args = [];

        foreach ($expression->args as $value) {
            if (is_array($value)) {
                array_push($args, ...$value);
            } else {
                $args[] = $value;
            }
        }

        return $this->funcWith($name, $args);
    }

    /**
     * sqlglot's @unsupported_args decorator around another handler.
     *
     * @param  array<string, string|null>  $diagnosticByArg
     * @param  string|array<int|string, mixed>  $func
     */
    public function unsupportedArgsHandler(Expression $expression, array $diagnosticByArg, string|array $func): string
    {
        foreach ($diagnosticByArg as $argName => $diagnostic) {
            if ($expression->flag($argName)) {
                $this->unsupported($diagnostic ?? "Argument '{$argName}' is not supported for expression '".(new ReflectionClass($expression))->getShortName()."' when targeting ".$this->dialectClassName().'.');
            }
        }

        return $this->callTransform($func, $expression);
    }

    /**
     * sqlglot arg_max_or_min_no_count(name) (wrapped in @unsupported_args("count")).
     */
    public function argMaxOrMinNoCount(Expression $expression, string $name): string
    {
        return $this->func($name, $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
    }

    /**
     * sqlglot regexp_replace_sql (wrapped in @unsupported_args("position", "occurrence", "modifiers")).
     */
    public function regexpReplaceSqlHandler(Expression $expression): string
    {
        return $this->func('REGEXP_REPLACE', $expression->args['this'] ?? null, $expression->args['expression'] ?? null, $expression->args['replacement'] ?? null);
    }

    public function ifSqlHandler(Expression $expression, string $name = 'IF', Expression|string|null $falseValue = null): string
    {
        $false = $expression->args['false'] ?? null;

        return $this->func($name, $expression->args['this'] ?? null, $expression->args['true'] ?? null, $this->truthy($false) ? $false : $falseValue);
    }

    public function trimSqlHandler(Expression $expression, string $defaultTrimType = ''): string
    {
        $removeChars = $this->sql($expression, 'expression');

        // Use TRIM/LTRIM/RTRIM syntax if the expression isn't database-specific
        if ($removeChars === '') {
            return $this->trimSql($expression);
        }

        $target = $this->sql($expression, 'this');
        $trimType = $this->sql($expression, 'position');
        $trimType = $trimType !== '' ? $trimType : $defaultTrimType;
        $collation = $this->sql($expression, 'collation');

        $trimType = $trimType !== '' ? "{$trimType} " : '';
        $removeChars = "{$removeChars} ";
        $fromPart = 'FROM ';
        $collation = $collation !== '' ? " COLLATE {$collation}" : '';

        return "TRIM({$trimType}{$removeChars}{$fromPart}{$target}{$collation})";
    }

    public function propertySqlHandler(Expression $expression): string
    {
        return $this->propertyName($expression, stringKey: true).'='.$this->sql($expression, 'value');
    }

    public function generatedasidentitycolumnconstraintSqlHandler(Expression $expression): string
    {
        $start = $this->sql($expression, 'start');
        $increment = $this->sql($expression, 'increment');

        return 'IDENTITY('.($start !== '' ? $start : '1').', '.($increment !== '' ? $increment : '1').')';
    }

    public function arrowJsonExtractSql(Expression $expression, ?string $op = null): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this->t['JSON_TYPE_REQUIRED_FOR_EXTRACTION'] === true && $this_ instanceof Literal && $this_->isString()) {
            $this_->replace(Builder::cast($this_->copy(), DType::JSON));
        }

        $path = $expression->args['expression'] ?? null;

        if (($path instanceof Binary || $path instanceof Predicate || $path instanceof Not) && $path instanceof Expression) {
            $expression->set('expression', Builder::paren($path, copy: false));
        }

        return $this->binary($expression, $op ?? ($expression instanceof JSONExtract ? '->' : '->>'));
    }

    public function noIlikeSql(Expression $expression): string
    {
        return $this->likeSql(new Like([
            'this' => new Lower(['this' => $expression->args['this'] ?? null]),
            'expression' => new Lower(['this' => $expression->args['expression'] ?? null]),
            'negate' => $expression->args['negate'] ?? null,
        ]));
    }

    public function noParenCurrentDateSql(Expression $expression): string
    {
        $zone = $this->sql($expression, 'this');

        return $zone !== '' ? "CURRENT_DATE AT TIME ZONE {$zone}" : 'CURRENT_DATE';
    }

    public function noTablesampleSql(Expression $expression): string
    {
        $this->unsupported('TABLESAMPLE unsupported');

        return $this->sql($expression, 'this');
    }

    public function noPivotSql(Expression $expression): string
    {
        $this->unsupported('PIVOT unsupported');

        return '';
    }

    public function noTrycastSql(Expression $expression): string
    {
        return $this->castSql($expression);
    }

    public function noMapFromEntriesSql(Expression $expression): string
    {
        $this->unsupported('MAP_FROM_ENTRIES unsupported');

        return '';
    }

    public function structExtractSqlHandler(Expression $expression): string
    {
        return $this->sql($expression, 'this').'.'.$this->sql(Builder::toIdentifier(($expression->argExpr('expression') ?? new NullExpr)->name()));
    }

    public function arrayAppendSqlHandler(Expression $expression, string $name, bool $swapParams = false): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $element = $expression->args['expression'] ?? null;
        $funcSql = $this->func($name, ...($swapParams ? [$element, $this_] : [$this_, $element]));

        $sourceNullPropagation = $expression->flag('null_propagation');
        $targetNullPropagation = $this->dialect->settings['ARRAY_FUNCS_PROPAGATES_NULLS'] === true;

        if ($sourceNullPropagation === $targetNullPropagation) {
            return $funcSql;
        }

        if ($sourceNullPropagation) {
            return $this->sql(new IfExpr(['this' => new Is(['this' => $this_, 'expression' => new NullExpr]), 'true' => new NullExpr, 'false' => $funcSql]));
        }

        $this_ = new Coalesce(['expressions' => [$this_, new ArrayExpr(['expressions' => []])]]);

        return $this->func($name, ...($swapParams ? [$element, $this_] : [$this_, $element]));
    }

    public function generateSeriesSqlHandler(Expression $expression, string $funcName, ?string $exclusiveFuncName = null): string
    {
        $start = $expression->args['start'] ?? null;
        $end = $expression->args['end'] ?? null;
        $step = $expression->args['step'] ?? null;

        if ($expression->flag('is_end_exclusive')) {
            if ($exclusiveFuncName !== null && $exclusiveFuncName !== '') {
                return $this->func($exclusiveFuncName, $start, $end, $step);
            }

            return $this->func($funcName, $start, new Sub(['this' => $end, 'expression' => Literal::number(1)]), $step);
        }

        return $this->func($funcName, $start, $end, $step);
    }

    /**
     * @param  string|array<int|string, mixed>|null  $buildFuncCall  sqlglot's nested helper (inlined here)
     */
    public function arrayConcatSqlHandler(Expression $expression, string $name, string|array|null $buildFuncCall = null): string
    {
        $this_ = $expression->args['this'] ?? null;
        $exprs = $expression->argList('expressions');
        $allArgs = [$this_, ...$exprs];

        $sourceNullPropagation = $expression->flag('null_propagation');
        $targetNullPropagation = $this->dialect->settings['ARRAY_FUNCS_PROPAGATES_NULLS'] === true;

        if ($sourceNullPropagation === $targetNullPropagation || $this_ instanceof ArrayExpr || $exprs === []) {
            return $this->arrayConcatCall($name, $allArgs);
        }

        if ($sourceNullPropagation) {
            $check = null;

            foreach ($allArgs as $arg) {
                $isNull = new Is(['this' => $arg instanceof Expression ? $arg->copy() : $arg, 'expression' => new NullExpr]);
                $check = $check === null ? $isNull : new OrExpr(['this' => $check, 'expression' => $isNull]);
            }

            return $this->sql(new IfExpr(['this' => $check, 'true' => new NullExpr, 'false' => $this->arrayConcatCall($name, $allArgs)]));
        }

        $wrapped = array_map(
            static fn (mixed $arg): Expression => new Coalesce(['expressions' => [$arg instanceof Expression ? $arg->copy() : $arg, new ArrayExpr(['expressions' => []])]]),
            $allArgs,
        );

        return $this->arrayConcatCall($name, $wrapped);
    }

    /**
     * @param  list<mixed>  $args
     */
    private function arrayConcatCall(string $funcName, array $args): string
    {
        if ($this->t['ARRAY_CONCAT_IS_VAR_LEN'] === true) {
            return $this->funcWith($funcName, $args);
        }

        if (count($args) === 1) {
            return $this->func($funcName, $args[0], new ArrayExpr(['expressions' => []]));
        }

        $result = $this->func($funcName, $args[count($args) - 2], $args[count($args) - 1]);

        foreach (array_reverse(array_slice($args, 0, -2)) as $arg) {
            $result = "{$funcName}(".$this->sql($arg instanceof Expression ? $arg : null).", {$result})";
        }

        return $result;
    }

    public function dateAddIntervalSql(Expression $expression, string $dataType, string $kind): string
    {
        $this_ = $this->sql($expression, 'this');
        $interval = new Interval(['this' => $expression->args['expression'] ?? null, 'unit' => Builder::unitToVar($expression)]);

        return "{$dataType}_{$kind}({$this_}, ".$this->sql($interval).')';
    }

    public function weekstartUnitToStr(Expression $expression, string $default = 'DAY'): ?Expression
    {
        $unit = $expression->args['unit'] ?? null;

        if ($unit instanceof WeekStart) {
            return Literal::string($this->weekstartName($unit));
        }

        return Builder::unitToStr($expression, $default);
    }

    public function timestamptruncSqlHandler(Expression $expression, string $func = 'DATE_TRUNC', bool $zone = false): string
    {
        $args = [$this->weekstartUnitToStr($expression), $expression->args['this'] ?? null];

        if ($zone) {
            $args[] = $expression->args['zone'] ?? null;
        }

        return $this->funcWith($func, $args);
    }

    public function timestrtotimeSqlHandler(Expression $expression, bool $includePrecision = false): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $datatype = new DataType(['this' => $expression->flag('zone') ? DType::TIMESTAMPTZ : DType::TIMESTAMP]);

        if ($this_ instanceof Literal && $includePrecision) {
            $precision = self::subsecondPrecision($this_->name());

            if ($precision > 0) {
                $datatype = DataType::build($datatype->args['this'], args: ['expressions' => [new DataTypeParam(['this' => Literal::number($precision)])]]);
            }
        }

        return $this->sql(Builder::cast($this_, $datatype, dialect: $this->dialect));
    }

    /**
     * The subsecond precision of an ISO-8601 timestamp literal: 0, 3 (millis) or 6 (micros).
     */
    public static function subsecondPrecision(string $timestamp): int
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}(?::\d{2}(?::\d{2}(?:[.,](\d{1,9}))?)?)?)?/', trim($timestamp), $m) !== 1) {
            return 0;
        }

        $digits = strlen(rtrim(str_pad(substr($m[1] ?? '', 0, 6), 6, '0'), '0'));

        return $digits > 3 ? 6 : ($digits > 0 ? 3 : 0);
    }

    public function datestrtodateSqlHandler(Expression $expression): string
    {
        return $this->sql(Builder::cast($expression->argExpr('this') ?? new NullExpr, DType::DATE));
    }

    public function minOrLeast(Expression $expression): string
    {
        return $this->renameFunc($expression, $expression->argList('expressions') !== [] ? 'LEAST' : 'MIN');
    }

    public function maxOrGreatest(Expression $expression): string
    {
        return $this->renameFunc($expression, $expression->argList('expressions') !== [] ? 'GREATEST' : 'MAX');
    }

    /** sqlglot to_number_with_nls_param. */
    public function toNumberWithNlsParam(Expression $expression): string
    {
        return $this->func('TO_NUMBER', $expression->args['this'] ?? null, $expression->args['format'] ?? null, $expression->args['nlsparam'] ?? null);
    }

    public function countIfToSum(Expression $expression): string
    {
        $cond = $expression->args['this'] ?? null;

        if ($cond instanceof Distinct) {
            $cond = $cond->argList('expressions')[0] ?? null;
            $this->unsupported('DISTINCT is not supported when converting COUNT_IF to SUM');
        }

        return $this->func('sum', Builder::func('if', [$cond instanceof Expression ? $cond : new NullExpr, Literal::number(1), Literal::number(0)]));
    }

    public function anyValueToMaxSql(Expression $expression): string
    {
        return $this->func('MAX', $expression->args['this'] ?? null);
    }

    public function boolXorSql(Expression $expression): string
    {
        $a = $this->sql($expression, 'this');
        $b = $this->sql($expression, 'expression');

        return "({$a} AND (NOT {$b})) OR ((NOT {$a}) AND {$b})";
    }

    /**
     * Unwrap TsOrDsToDate / TsOrDsToTimestamp arguments without a format before generating.
     *
     * @param  list<string>  $args
     * @param  string|array<int|string, mixed>|null  $toSql
     */
    public function removeTsOrDsToDate(Expression $expression, array $args = ['this'], string|array|null $toSql = null): string
    {
        foreach ($args as $argKey) {
            $arg = $expression->args[$argKey] ?? null;

            if (($arg instanceof TsOrDsToDate || $arg instanceof TsOrDsToTimestamp) && ! $arg->flag('format')) {
                $expression->set($argKey, $arg->args['this'] ?? null);
            }
        }

        return $toSql !== null ? $this->callTransform($toSql, $expression) : $this->functionFallbackSql($expression);
    }

    public function dateDeltaSql(Expression $expression, string $name, bool $cast = false): string
    {
        if ($cast && $expression instanceof TsOrDsAdd) {
            $expression = self::tsOrDsAddCast($expression);
        }

        return $this->func($name, Builder::unitToVar($expression), $expression->args['expression'] ?? null, $expression->args['this'] ?? null);
    }

    public static function tsOrDsAddCast(Expression $expression): Expression
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $copy = $this_->copy();
        $returnType = $expression->args['return_type'] ?? null;
        $returnType = $returnType instanceof DataType ? $returnType : DataType::build(DType::DATE);

        if ($returnType->isType(DType::DATE)) {
            $copy = Builder::cast($copy, DType::TIMESTAMP);
        }

        $this_->replace(Builder::cast($copy, $returnType));

        return $expression;
    }

    public function noLastDaySql(Expression $expression): string
    {
        $truncCurrDate = Builder::func('date_trunc', ['month', $expression->argExpr('this') ?? new NullExpr]);
        $plusOneMonth = Builder::func('date_add', [$truncCurrDate, Literal::number(1), 'month']);
        $minusOneDay = Builder::func('date_sub', [$plusOneMonth, Literal::number(1), 'day']);

        return $this->sql(Builder::cast($minusOneDay, DType::DATE));
    }

    /**
     * Remove the target table qualifier from MERGE's UPDATE SET / INSERT columns.
     */
    public function mergeWithoutTargetSql(Expression $expression): string
    {
        $target = $expression->argExpr('this') ?? new NullExpr;
        $alias = $target->args['alias'] ?? null;
        $normalize = fn (mixed $identifier): ?string => $identifier instanceof Expression ? $this->dialect->normalizeIdentifier($identifier->copy())->name() : null;

        $targets = [$normalize($target->args['this'] ?? null)];

        if ($alias instanceof Expression) {
            $targets[] = $normalize($alias->args['this'] ?? null);
        }

        foreach (($expression->argExpr('whens') ?? new NullExpr)->argList('expressions') as $when) {
            $then = $when->args['then'] ?? null;

            if ($then instanceof Update) {
                foreach (Scope::findAllInScope($then, EQ::class) as $equals) {
                    $lhs = $equals->args['this'] ?? null;

                    if ($lhs instanceof Column && in_array($normalize($lhs->args['table'] ?? null), $targets, true)) {
                        $lhs->replace(Builder::column($lhs->args['this']));
                    }
                }
            }

            if ($then instanceof Insert && ($columns = $then->args['this'] ?? null) instanceof Tuple) {
                foreach ($columns->argList('expressions') as $column) {
                    if (in_array($normalize($column->args['table'] ?? null), $targets, true)) {
                        $column->replace(Builder::column($column->args['this']));
                    }
                }
            }
        }

        return $this->mergeSql($expression);
    }

    public function jsonPathKeyOnlyName(Expression $expression): string
    {
        if (($expression->args['this'] ?? null) instanceof JSONPathWildcard) {
            $this->unsupported('Unsupported wildcard in JSONPathKey expression');
        }

        return $expression->name();
    }

    public function filterArrayUsingUnnest(Expression $expression): string
    {
        $cond = $expression->args['expression'] ?? null;

        if ($cond instanceof Lambda && count($cond->argList('expressions')) === 1) {
            $alias = $cond->argList('expressions')[0];
            $cond = $cond->args['this'] ?? null;
        } elseif ($cond instanceof Predicate) {
            $alias = '_u';
        } elseif ($expression instanceof ArrayRemove) {
            $alias = '_u';
            $cond = new NEQ(['this' => Builder::column($alias), 'expression' => $expression->args['expression'] ?? null]);
        } else {
            $this->unsupported('Unsupported filter condition');

            return '';
        }

        $aliasName = $alias instanceof Expression ? $alias->name() : $alias;
        $unnest = new Unnest(['expressions' => [$expression->args['this'] ?? null]]);
        $filtered = new Select([
            'expressions' => [Builder::column($aliasName)],
            'from_' => new From(['this' => Builder::alias($unnest, null, table: [$aliasName])]),
            'where' => new Where(['this' => $cond]),
        ]);

        return $this->sql(new ArrayExpr(['expressions' => [$filtered]]));
    }

    public function sha256Sql(Expression $expression): string
    {
        $length = $expression->text('length');

        return $this->func('SHA'.($length !== '' ? $length : '256'), $expression->args['this'] ?? null);
    }

    public function sha2DigestSqlHandler(Expression $expression): string
    {
        return $this->sha256Sql($expression);
    }

    public function lengthOrCharLengthSql(Expression $expression): string
    {
        return $this->func($expression->flag('binary') ? 'LENGTH' : 'CHAR_LENGTH', $expression->args['this'] ?? null);
    }

    public function getbitSqlHandler(Expression $expression): string
    {
        $value = $expression->args['this'] ?? null;
        $position = $expression->args['expression'] ?? null;

        if (! $expression->flag('zero_is_msb') && $expression->isType(...DataType::SIGNED_INTEGER_TYPES, ...DataType::UNSIGNED_INTEGER_TYPES)) {
            return $this->sql(new BitwiseAnd(['this' => new BitwiseRightShift(['this' => $value, 'expression' => $position]), 'expression' => Literal::number(1)]));
        }

        return $this->func('GET_BIT', $value, $position);
    }

    public function strpositionSqlHandler(Expression $expression, string $funcName = 'STRPOS', bool $supportsPosition = false, bool $supportsOccurrence = false, bool $useAnsiPosition = true): string
    {
        $string = $expression->args['this'] ?? null;
        $substr = $expression->args['substr'] ?? null;
        $position = $expression->args['position'] ?? null;
        $occurrence = $expression->args['occurrence'] ?? null;
        $zero = Literal::number(0);
        $one = Literal::number(1);

        if ($supportsOccurrence && $this->truthy($occurrence) && $supportsPosition && ! $this->truthy($position)) {
            $position = $one;
        }

        $transpilePosition = $this->truthy($position) && ! $supportsPosition;

        if ($transpilePosition) {
            $string = new Substring(['this' => $string, 'start' => $position]);
        }

        if ($funcName === 'POSITION' && $useAnsiPosition) {
            $func = new Anonymous(['this' => $funcName, 'expressions' => [new In(['this' => $substr, 'field' => $string])]]);
        } else {
            $args = $funcName === 'LOCATE' || $funcName === 'CHARINDEX' ? [$substr, $string] : [$string, $substr];

            if ($supportsPosition) {
                $args[] = $position;
            }

            if ($this->truthy($occurrence)) {
                if ($supportsOccurrence) {
                    $args[] = $occurrence;
                } else {
                    $this->unsupported("{$funcName} does not support the occurrence parameter.");
                }
            }

            $func = new Anonymous(['this' => $funcName, 'expressions' => $args]);
        }

        if ($transpilePosition && $position instanceof Expression) {
            $funcWithOffset = new Sub(['this' => new Add(['this' => $func->copy(), 'expression' => $position->copy()]), 'expression' => $one]);

            return $this->sql(new IfExpr(['this' => new EQ(['this' => $func, 'expression' => $zero]), 'true' => $zero->copy(), 'false' => $funcWithOffset]));
        }

        return $this->sql($func);
    }

    public function inlineArraySql(Expression $expression): string
    {
        return '['.$this->expressions($expression, dynamic: true, newLine: true, skipFirst: true, skipLast: true).']';
    }

    public function inlineArrayUnlessQuery(Expression $expression): string
    {
        $elem = $expression->argList('expressions')[0] ?? null;

        if ($elem !== null && $elem->find(Query::class) !== null) {
            return $this->func('ARRAY', $elem);
        }

        return $this->inlineArraySql($expression);
    }

    /**
     * sqlglot json_extract_segments(name, quoted_index, op): a JSON path as a list of segments.
     */
    public function jsonExtractSegments(Expression $expression, string $name, bool $quotedIndex = true, ?string $op = null): string
    {
        $path = $expression->args['expression'] ?? null;

        if (! $path instanceof JSONPath) {
            return $this->renameFunc($expression, $name);
        }

        $segments = [];

        foreach ($path->argList('expressions') as $segment) {
            $segmentSql = $this->sql($segment);

            if ($segmentSql !== '') {
                if ($segment instanceof JSONPathPart && ($quotedIndex || ! $segment instanceof JSONPathSubscript)) {
                    $segmentSql = $this->dialect->settings['QUOTE_START'].$this->escapeStr($segmentSql).$this->dialect->settings['QUOTE_END'];
                }

                $segments[] = $segmentSql;
            }
        }

        if ($op !== null && $op !== '') {
            return implode(" {$op} ", [$this->sql($expression, 'this'), ...$segments]);
        }

        return $this->funcWith($name, [$expression->args['this'] ?? null, ...$segments]);
    }

    /**
     * sqlglot groupconcat_sql: GROUP_CONCAT as LISTAGG / STRING_AGG.
     */
    public function approxCountDistinctSql(Expression $expression): string
    {
        return $this->func('APPROX_COUNT_DISTINCT', $expression->argExpr('this'));
    }

    public function groupconcatSqlHandler(Expression $expression, string $funcName = 'LISTAGG', ?string $sep = ',', bool $withinGroup = true, bool $onOverflow = false): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $separatorArg = $expression->args['separator'] ?? null;
        $separator = $this->sql($separatorArg instanceof Expression ? $separatorArg : ($sep !== null && $sep !== '' ? Literal::string($sep) : null));
        $onOverflowSql = $this->sql($expression, 'on_overflow');
        $onOverflowSql = $onOverflow && $onOverflowSql !== '' ? " ON OVERFLOW {$onOverflowSql}" : '';

        $limit = null;

        if ($this_ instanceof Limit && ($limited = $this_->argExpr('this')) !== null) {
            $limit = $this_;
            $this_ = $limited->pop();
        }

        $order = $this_->find(Order::class);

        if ($order !== null && ($ordered = $order->argExpr('this')) !== null) {
            $this_ = $ordered->pop();
        }

        $args = $this->formatArgs([$this_, $separator !== '' || $onOverflowSql !== '' ? "{$separator}{$onOverflowSql}" : null]);
        $listagg = new Anonymous(['this' => $funcName, 'expressions' => [$args]]);
        $modifiers = $this->sql($limit);

        if ($order !== null) {
            if ($withinGroup) {
                $listagg = new WithinGroup(['this' => $listagg, 'expression' => $order]);
            } else {
                $modifiers = $this->sql($order).$modifiers;
            }
        }

        if ($modifiers !== '') {
            $listagg->set('expressions', ["{$args}{$modifiers}"]);
        }

        return $this->sql($listagg);
    }

    public static function regexpReplaceGlobalModifier(Expression $expression): mixed
    {
        $modifiers = $expression->args['modifiers'] ?? null;
        $occurrence = $expression->args['occurrence'] ?? null;

        if (! $expression->flag('single_replace') && (! $occurrence instanceof Expression || ($occurrence->isInt() && (int) $occurrence->toPhp() === 0))) {
            if (! $modifiers instanceof Expression || $modifiers->isString()) {
                $value = $modifiers instanceof Expression ? $modifiers->name() : '';
                $modifiers = Literal::string($value.'g');
            }
        }

        return $modifiers;
    }

    /**
     * sqlglot left_to_substring_sql.
     */
    public function leftToSubstringSql(Expression $expression): string
    {
        return $this->sql(new Substring([
            'this' => $expression->args['this'] ?? null,
            'start' => Literal::number(1),
            'length' => $expression->args['expression'] ?? null,
        ]));
    }

    /**
     * sqlglot right_to_substring_sql.
     */
    public function rightToSubstringSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        return $this->sql(new Substring([
            'this' => $this_,
            'start' => Builder::binop(
                Sub::class,
                new Length(['this' => $this_?->copy()]),
                Builder::paren(Builder::binop(Sub::class, $expression->argExpr('expression'), 1)),
            ),
        ]));
    }

    /**
     * sqlglot regexp_extract_sql.
     */
    public function regexpExtractSqlHandler(Expression $expression): string
    {
        $group = $expression->argExpr('group');

        // Do not render group if it's the default value for this dialect
        if ($group !== null && $group->name() === (string) ($this->dialect->settings['REGEXP_EXTRACT_DEFAULT_GROUP'] ?? '')) {
            $group = null;
        }

        return $this->func($expression::sqlName(), $expression->args['this'] ?? null, $expression->args['expression'] ?? null, $group);
    }

    /**
     * sqlglot no_recursive_cte_sql.
     */
    public function noRecursiveCteSql(Expression $expression): string
    {
        if ($expression->flag('recursive')) {
            $this->unsupported('Recursive CTEs are unsupported');
            $expression->set('recursive', false);
        }

        return $this->withSql($expression);
    }

    /**
     * sqlglot sequence_sql (GenerateSeries / GenerateDateArray -> SEQUENCE).
     */
    public function sequenceSql(Expression $expression): string
    {
        $start = $expression->argExpr('start');
        $end = $expression->argExpr('end');
        $step = $expression->argExpr('step');

        if ($start instanceof Cast) {
            $targetType = $start->argExpr('to');
        } elseif ($end instanceof Cast) {
            $targetType = $end->argExpr('to');
        } else {
            $targetType = null;
        }

        if ($start !== null && $end !== null) {
            if ($targetType instanceof DataType && $targetType->isType('date', 'timestamp')) {
                if ($start instanceof Cast && $targetType === $start->argExpr('to')) {
                    $end = Builder::cast($end, $targetType);
                } else {
                    $start = Builder::cast($start, $targetType);
                }
            }

            if ($expression->flag('is_end_exclusive')) {
                $stepValue = $step ?? Literal::number(1);
                $end = Builder::paren(new Sub(['this' => $end, 'expression' => $stepValue]), false);

                $sequenceCall = new Anonymous([
                    'this' => 'SEQUENCE',
                    'expressions' => $step !== null ? [$start, $end, $step] : [$start, $end],
                ]);
                $zero = Literal::number(0);
                $shouldReturnEmpty = Builder::or([
                    new EQ(['this' => $stepValue->copy(), 'expression' => $zero->copy()]),
                    Builder::and([
                        new GT(['this' => $stepValue->copy(), 'expression' => $zero->copy()]),
                        new GT(['this' => $start->copy(), 'expression' => $end->copy()]),
                    ]),
                    Builder::and([
                        new LT(['this' => $stepValue->copy(), 'expression' => $zero->copy()]),
                        new LT(['this' => $start->copy(), 'expression' => $end->copy()]),
                    ]),
                ]);
                $emptyArrayOrSequence = new IfExpr([
                    'this' => $shouldReturnEmpty,
                    'true' => new ArrayExpr(['expressions' => []]),
                    'false' => $sequenceCall,
                ]);

                return $this->sql($this->simplifyUnlessLiteral($emptyArrayOrSequence));
            }
        }

        return $this->func('SEQUENCE', $start, $end, $step);
    }
}
