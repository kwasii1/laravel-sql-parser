<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\ClickHouse;

use Kwasii\LaravelSqlParser\Expressions\Add;
use Kwasii\LaravelSqlParser\Expressions\Any;
use Kwasii\LaravelSqlParser\Expressions\ArrayExpr;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DataTypeParam;
use Kwasii\LaravelSqlParser\Expressions\Div;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Fetch;
use Kwasii\LaravelSqlParser\Expressions\In;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\Limit;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Not;
use Kwasii\LaravelSqlParser\Expressions\ParameterizedAgg;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Schema;
use Kwasii\LaravelSqlParser\Expressions\SchemaCommentProperty;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\StrToDate;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsToTimestamp;
use Kwasii\LaravelSqlParser\Expressions\Tuple;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * ClickHouse generator (sqlglot generators/clickhouse.py).
 */
class ClickHouseGenerator extends Generator
{
    public const string TABLES = ClickHouseGeneratorTables::class;

    /** String is the standard ClickHouse type; every other string type is an alias of it. */
    private const array STRING_TYPES = [
        DType::BLOB => true, DType::CHAR => true, DType::LONGBLOB => true, DType::LONGTEXT => true,
        DType::MEDIUMBLOB => true, DType::MEDIUMTEXT => true, DType::TINYBLOB => true, DType::TINYTEXT => true,
        DType::TEXT => true, DType::VARBINARY => true, DType::VARCHAR => true,
    ];

    /** Kinds of CREATE that take ON CLUSTER (ClickHouse src/Parsers/ParserCreate*.cpp). */
    private const array ON_CLUSTER_TARGETS = [
        'SCHEMA' => true, 'DATABASE' => true, 'TABLE' => true, 'VIEW' => true, 'DICTIONARY' => true,
        'INDEX' => true, 'FUNCTION' => true, 'NAMED COLLECTION' => true,
    ];

    /** https://clickhouse.com/docs/en/sql-reference/data-types/nullable */
    private const array NON_NULLABLE_TYPES = [
        DType::ARRAY, DType::MAP, DType::STRUCT, DType::POINT, DType::RING, DType::LINESTRING,
        DType::MULTILINESTRING, DType::POLYGON, DType::MULTIPOLYGON,
    ];

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function unixToTimeSqlHandler(Expression $expression): string
    {
        $scale = $expression->args['scale'] ?? null;
        $timestamp = $expression->args['this'] ?? null;
        $bigint = static fn (mixed $value): Expression => Builder::cast($value, DType::BIGINT);

        if ($scale === null || ($scale instanceof Literal && $scale->equals(Literal::number(0)))) {
            return $this->func('fromUnixTimestamp', $bigint($timestamp));
        }

        foreach (['3' => 'fromUnixTimestamp64Milli', '6' => 'fromUnixTimestamp64Micro', '9' => 'fromUnixTimestamp64Nano'] as $digits => $name) {
            if ($scale instanceof Literal && $scale->equals(Literal::number($digits))) {
                return $this->func($name, $bigint($timestamp));
            }
        }

        return $this->func('fromUnixTimestamp', $bigint(new Div(['this' => $timestamp, 'expression' => Builder::func('POW', [Literal::number(10), $scale])])));
    }

    private static function lowerFunc(string $sql): string
    {
        $index = strpos($sql, '(');

        return $index === false ? $sql : strtolower(substr($sql, 0, $index)).substr($sql, $index);
    }

    protected function quantileSqlHandler(Expression $expression): string
    {
        $quantile = $expression->args['quantile'] ?? null;
        $args = '('.$this->sql($expression, 'this').')';
        $func = $quantile instanceof ArrayExpr ? $this->funcWith('quantiles', $quantile->argList('expressions')) : $this->func('quantile', $quantile);

        return $func.$args;
    }

    protected function datetimeDeltaSql(Expression $expression, string $name): string
    {
        if (! $this->truthy($expression->args['unit'] ?? null)) {
            return $this->renameFunc($expression, $name);
        }

        return $this->func(
            $name,
            Builder::unitToVar($expression),
            $expression->args['expression'] ?? null,
            $expression->args['this'] ?? null,
            $expression->args['zone'] ?? null,
        );
    }

    protected function timestrtotimeSqlClickhouse(Expression $expression): string
    {
        $ts = $expression->args['this'] ?? null;
        $tz = $expression->args['zone'] ?? null;

        if ($this->truthy($tz) && $ts instanceof Literal) {
            // ClickHouse encodes the timezone as a data type parameter and won't accept a
            // timestamp string with a UTC offset, e.g. '2020-01-01 12:13:14-08:00' becomes
            // '2020-01-01 12:13:14'
            $ts = Literal::string(self::isoWithoutTimezone(trim($ts->name())));
        }

        // Non-nullable DateTime64 with microsecond precision
        $expressions = [new DataTypeParam(['this' => Literal::number(6)])];

        if ($this->truthy($tz)) {
            $expressions[] = new DataTypeParam(['this' => $tz]);
        }

        $datatype = new DataType(['this' => DType::DATETIME64, 'expressions' => $expressions, 'nullable' => false]);

        return $this->sql(Builder::cast($ts, $datatype, dialect: $this->dialect));
    }

    /**
     * Python's datetime.fromisoformat(text).replace(tzinfo=None).isoformat(sep=" ").
     */
    private static function isoWithoutTimezone(string $text): string
    {
        $parts = explode('.', $text);

        if (count($parts) === 2) {
            // Pad the fractional seconds to 6 digits, keeping the UTC offset if any
            $offsetSep = str_contains($parts[1], '+') ? '+' : '-';
            $fracParts = explode($offsetSep, $parts[1], 2);
            $fracParts[0] = str_pad($fracParts[0], 6, '0');
            $text = $parts[0].'.'.$fracParts[0].(isset($fracParts[1]) ? $offsetSep.$fracParts[1] : '');
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:[T ](\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d{1,6}))?)?)?)?/', $text, $m) !== 1) {
            return $text;
        }

        $result = $m[1].' '.($m[2] ?? '00').':'.(($m[3] ?? '') !== '' ? $m[3] : '00').':'.(($m[4] ?? '') !== '' ? $m[4] : '00');
        $fraction = $m[5] ?? '';

        if ($fraction !== '' && (int) $fraction !== 0) {
            $result .= '.'.str_pad($fraction, 6, '0');
        }

        return $result;
    }

    protected function mapSqlHandler(Expression $expression): string
    {
        if (! ($expression->parent !== null && $expression->parent->argKey === 'settings')) {
            return self::lowerFunc($this->varMapSql($expression));
        }

        $keys = $expression->args['keys'] ?? null;
        $values = $expression->args['values'] ?? null;

        if (! $keys instanceof ArrayExpr || ! $values instanceof ArrayExpr) {
            $this->unsupported('Cannot convert array columns into map.');

            return '';
        }

        $args = [];
        $valueList = $values->argList('expressions');

        foreach ($keys->argList('expressions') as $i => $key) {
            if (! isset($valueList[$i])) {
                break;
            }

            $args[] = $this->sql($key).': '.$this->sql($valueList[$i]);
        }

        return '{'.implode(', ', $args).'}';
    }

    protected function jsonCastSqlHandler(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $to = $expression->args['to'] ?? null;
        $toSql = $this->sql($to);

        if ($to instanceof Expression && $to->argList('expressions') !== []) {
            $toSql = $this->sql(Builder::toIdentifier($toSql));
        }

        return "{$this_}.:{$toSql}";
    }

    protected function transformArrayFilter(Expression $e): string
    {
        return $this->func('arrayFilter', $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformTransform(Expression $e): string
    {
        return $this->func('arrayMap', $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformCompressColumnConstraint(Expression $e): string
    {
        return 'CODEC('.$this->expressions($e, key: 'this', flat: true).')';
    }

    protected function transformComputedColumnConstraint(Expression $e): string
    {
        return ($e->flag('persisted') ? 'MATERIALIZED' : 'ALIAS').' '.$this->sql($e, 'this');
    }

    protected function transformCurrentDate(Expression $e): string
    {
        return $this->func('CURRENT_DATE');
    }

    protected function transformFinal(Expression $e): string
    {
        return $this->sql($e, 'this').' FINAL';
    }

    protected function transformJSONPathRoot(Expression $e): string
    {
        return '';
    }

    protected function transformPartitionedByProperty(Expression $e): string
    {
        return 'PARTITION BY '.$this->sql($e, 'this');
    }

    protected function transformRegexpLike(Expression $e): string
    {
        return $this->func('match', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'POSITION', supportsPosition: true, useAnsiPosition: false);
    }

    protected function transformTimeToStr(Expression $e): string
    {
        $this_ = $e->args['this'] ?? null;

        return $this->func(
            'formatDateTime',
            $this_ instanceof TsOrDsToTimestamp ? ($this_->args['this'] ?? null) : $this_,
            $this->formatTime($e),
            $e->args['zone'] ?? null,
        );
    }

    protected function transformXor(Expression $e): string
    {
        return $this->func('xor', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformMd5(Expression $e): string
    {
        return $this->func('LOWER', $this->func('HEX', $this->func('MD5', $e->args['this'] ?? null)));
    }

    protected function transformSplit(Expression $e): string
    {
        return $this->func('splitByString', $e->args['expression'] ?? null, $e->args['this'] ?? null, $e->args['limit'] ?? null);
    }

    protected function transformRegexpSplit(Expression $e): string
    {
        return $this->func('splitByRegexp', $e->args['expression'] ?? null, $e->args['this'] ?? null, $e->args['limit'] ?? null);
    }

    protected function transformTrim(Expression $e): string
    {
        return $this->trimSqlHandler($e, defaultTrimType: 'BOTH');
    }

    protected function transformSchemaCommentProperty(Expression $e): string
    {
        return $this->nakedProperty($e);
    }

    protected function transformParseDatetime(Expression $e): string
    {
        return $this->func('parseDateTime', $e->args['this'] ?? null, $e->args['format'] ?? null, $e->args['zone'] ?? null);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function groupconcatSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $separator = $expression->args['separator'] ?? null;

        if ($this_ instanceof Limit && ($this_->args['this'] ?? null) instanceof Expression) {
            $limit = $this_;
            $this_ = $limit->args['this']->pop();

            return $this->sql(new ParameterizedAgg([
                'this' => 'groupConcat',
                'params' => [$this_],
                'expressions' => [$separator, $limit->args['expression'] ?? null],
            ]));
        }

        if ($this->truthy($separator)) {
            return $this->sql(new ParameterizedAgg(['this' => 'groupConcat', 'params' => [$this_], 'expressions' => [$separator]]));
        }

        return $this->func('groupConcat', $this_);
    }

    protected function selectSql(Select $expression): string
    {
        $limit = $expression->args['limit'] ?? null;

        if ($limit instanceof Fetch && ! $this->truthy($expression->args['order'] ?? null)) {
            $count = $limit->args['count'] ?? null;
            $expression->set('limit', new Limit(['expression' => $count ?? Literal::number(1)]));
        }

        return parent::selectSql($expression);
    }

    protected function offsetSql(Expression $expression): string
    {
        $offset = parent::offsetSql($expression);

        // OFFSET ... FETCH requires a ROW or ROWS keyword
        // https://clickhouse.com/docs/sql-reference/statements/select/offset
        $parent = $expression->parent;

        if ($parent instanceof Select && ($parent->args['limit'] ?? null) instanceof Fetch) {
            $offset .= ' ROWS';
        }

        return $offset;
    }

    protected function strtodateSql(Expression $expression): string
    {
        $sql = $this->functionFallbackSql($expression);

        if (! $expression->parent instanceof Cast) {
            // StrToDate returns DATEs in other dialects (e.g. postgres)
            return $this->castSql(Builder::cast($expression, 'DATE'));
        }

        return $sql;
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        $this_ = $expression->args['this'] ?? null;
        $to = $expression->args['to'] ?? null;

        if ($this_ instanceof StrToDate && $to instanceof DataType && $to->equals(new DataType(['this' => DType::DATETIME]))) {
            return $this->sql($this_);
        }

        return parent::castSql($expression, safePrefix: $safePrefix);
    }

    protected function trycastSql(Expression $expression): string
    {
        $dtype = $expression->args['to'] ?? null;

        if ($dtype instanceof DataType && ! $dtype->isTypeCheckNullable(...self::NON_NULLABLE_TYPES)) {
            // Casting x into Nullable(T) behaves like TRY_CAST(x AS T)
            $dtype->set('nullable', true);
        }

        return parent::castSql($expression);
    }

    public function jsonpathsubscriptSqlInternal(Expression $expression): string
    {
        $this_ = $this->jsonPathPart($expression->args['this'] ?? null);

        return preg_match('/^-?\d+$/', $this_) === 1 ? (string) ((int) $this_ + 1) : $this_;
    }

    protected function likepropertySql(Expression $expression): string
    {
        return 'AS '.$this->sql($expression, 'this');
    }

    /**
     * @param  callable(Expression): string  $default
     */
    private function anyToHas(Expression $expression, callable $default, string $prefix = ''): string
    {
        $left = $expression->args['this'] ?? null;
        $right = $expression->args['expression'] ?? null;

        if ($left instanceof Any) {
            [$arr, $this_] = [$left, $right];
        } elseif ($right instanceof Any) {
            [$arr, $this_] = [$right, $left];
        } else {
            return $default($expression);
        }

        $inner = $arr->args['this'] ?? null;

        return $prefix.$this->func('has', $inner instanceof Expression ? $inner->unnest() : $inner, $this_);
    }

    protected function eqSql(Expression $expression): string
    {
        return $this->anyToHas($expression, fn (Expression $e): string => parent::eqSql($e));
    }

    protected function neqSql(Expression $expression): string
    {
        return $this->anyToHas($expression, fn (Expression $e): string => parent::neqSql($e), 'NOT ');
    }

    protected function regexpilikeSql(Expression $expression): string
    {
        // Manually add a flag to make the search case-insensitive
        $regex = $this->func('CONCAT', "'(?i)'", $expression->args['expression'] ?? null);

        return $this->func('match', $expression->args['this'] ?? null, $regex);
    }

    protected function datatypeSql(DataType $expression): string
    {
        // String is the standard ClickHouse type, every other variant is just an alias, and any
        // length parameter is ignored
        // https://clickhouse.com/docs/en/sql-reference/data-types/string
        $dtype = isset(self::STRING_TYPES[(string) ($expression->args['this'] ?? '')]) ? 'String' : parent::datatypeSql($expression);

        // Nullable(...), when the type is marked nullable, or isn't marked at all and is neither
        // a Map key (which must be an integer, String, FixedString, UUID or IPv6 type) nor a
        // composite type (Nullable(Array(...)) isn't valid)
        $parent = $expression->parent;
        $nullable = $expression->args['nullable'] ?? null;

        if (
            $nullable === true
            || (
                $nullable === null
                && ! ($parent instanceof DataType && $parent->isTypeCheckNullable(DType::MAP) && ($expression->index === null || $expression->index === 0))
                && ! $expression->isTypeCheckNullable(...self::NON_NULLABLE_TYPES)
            )
        ) {
            $dtype = "Nullable({$dtype})";
        }

        return $dtype;
    }

    protected function cteSql(Expression $expression): string
    {
        if ($expression->flag('scalar')) {
            return $this->sql($expression, 'this').' AS '.$this->sql($expression, 'alias');
        }

        return parent::cteSql($expression);
    }

    protected function afterLimitModifiers(Expression $expression): array
    {
        return [
            ...parent::afterLimitModifiers($expression),
            $this->truthy($expression->args['settings'] ?? null) ? $this->seg('SETTINGS ').$this->expressions($expression, key: 'settings', flat: true) : '',
            $this->truthy($expression->args['format'] ?? null) ? $this->seg('FORMAT ').$this->sql($expression, 'format') : '',
        ];
    }

    protected function placeholderSql(Expression $expression): string
    {
        return '{'.$expression->name().': '.$this->sql($expression, 'kind').'}';
    }

    protected function onclusterSql(Expression $expression): string
    {
        return 'ON CLUSTER '.$this->sql($expression, 'this');
    }

    private function refreshIntervalSql(?Expression $expression): string
    {
        if ($expression instanceof Add) {
            return $this->refreshIntervalSql($expression->argExpr('this')).' '.$this->refreshIntervalSql($expression->argExpr('expression'));
        }

        return $expression instanceof Interval ? $this->sql($expression) : '';
    }

    protected function autorefreshpropertySql(Expression $expression): string
    {
        $cadence = $this->sql($expression, 'cadence');
        $interval = $expression->argExpr('this');
        $schedule = $cadence !== '' && $interval !== null ? " {$cadence} ".$this->refreshIntervalSql($interval) : '';
        $offset = $expression->argExpr('offset');
        $offset = $offset !== null ? ' OFFSET '.$this->refreshIntervalSql($offset) : '';
        $randomize = $expression->argExpr('randomize');
        $randomize = $randomize !== null ? ' RANDOMIZE FOR '.$this->refreshIntervalSql($randomize) : '';
        $dependencies = $this->expressions($expression, flat: true);
        $dependencies = $dependencies !== '' ? " DEPENDS ON {$dependencies}" : '';
        $settings = $this->sql($expression, 'settings');
        $settings = $settings !== '' ? " {$settings}" : '';
        $append = $expression->flag('append') ? ' APPEND' : '';

        return "REFRESH{$schedule}{$offset}{$randomize}{$dependencies}{$settings}{$append}";
    }

    protected function createableSql(Expression $expression, array &$locations): string
    {
        $postName = $locations['POST_NAME'] ?? [];

        if (isset(self::ON_CLUSTER_TARGETS[$expression->text('kind')]) && $postName !== []) {
            $schema = $expression->args['this'] ?? null;
            $thisName = $this->sql($schema instanceof Schema ? $schema : $expression, 'this');
            $thisProperties = implode(' ', array_map(fn (Expression $prop): string => $this->sql($prop), $postName));
            $thisSchema = $schema instanceof Expression ? $this->schemaColumnsSql($schema) : '';
            $thisSchema = $thisSchema !== '' ? $this->sep().$thisSchema : '';

            return $thisName.$this->sep().$thisProperties.$thisSchema;
        }

        return parent::createableSql($expression, $locations);
    }

    protected function createSql(Expression $expression): string
    {
        // The comment property comes last in CTAS statements, i.e. after the query
        $query = $expression->args['expression'] ?? null;
        $commentProp = null;

        if ($query instanceof Query && $query instanceof Expression) {
            $commentProp = $expression->find(SchemaCommentProperty::class);

            if ($commentProp !== null) {
                $commentProp->pop();
                $query->replace(Builder::paren($query));
            }
        }

        $createSql = parent::createSql($expression);
        $commentSql = $this->sql($commentProp);
        $commentSql = $commentSql !== '' ? " {$commentSql}" : '';

        return "{$createSql}{$commentSql}";
    }

    protected function prewhereSql(Expression $expression): string
    {
        $this_ = $this->indent($this->sql($expression, 'this'));

        return $this->seg('PREWHERE').$this->sep().$this_;
    }

    protected function indexcolumnconstraintSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $expr = $this->sql($expression, 'expression');
        $expr = $expr !== '' ? " {$expr}" : '';
        $indexType = $this->sql($expression, 'index_type');
        $indexType = $indexType !== '' ? " TYPE {$indexType}" : '';
        $granularity = $this->sql($expression, 'granularity');
        $granularity = $granularity !== '' ? " GRANULARITY {$granularity}" : '';

        return "INDEX{$this_}{$expr}{$indexType}{$granularity}";
    }

    protected function partitionSql(Expression $expression): string
    {
        return 'PARTITION '.$this->expressions($expression, flat: true);
    }

    protected function partitionidSql(Expression $expression): string
    {
        return 'ID '.$this->sql($expression->args['this'] ?? null);
    }

    protected function replacepartitionSql(Expression $expression): string
    {
        return 'REPLACE '.$this->sql($expression->args['expression'] ?? null).' FROM '.$this->sql($expression, 'source');
    }

    protected function projectiondefSql(Expression $expression): string
    {
        $inner = $expression->args['expression'] ?? null;

        return 'PROJECTION '.$this->sql($expression->args['this'] ?? null).' '.$this->wrap($inner instanceof Expression ? $inner : '');
    }

    protected function nestedjsonselectSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').'.^'.$this->sql($expression, 'expression');
    }

    protected function isSql(Expression $expression): string
    {
        $isSql = parent::isSql($expression);

        if ($expression->parent instanceof Not) {
            // value IS NOT NULL -> NOT (value IS NULL)
            $isSql = $this->wrap($isSql);
        }

        return $isSql;
    }

    protected function inSql(Expression $expression): string
    {
        $inSql = parent::inSql($expression);

        if ($expression->parent instanceof Not && $expression->flag('is_global')) {
            $position = strpos($inSql, 'GLOBAL IN');

            if ($position !== false) {
                $inSql = substr_replace($inSql, 'GLOBAL NOT IN', $position, strlen('GLOBAL IN'));
            }
        }

        return $inSql;
    }

    protected function notSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof In) {
            if ($this_->flag('is_global')) {
                // Let the GLOBAL IN child interpose NOT
                return $this->sql($expression, 'this');
            }

            $expression->set('this', Builder::paren($this_, copy: false));
        }

        return parent::notSql($expression);
    }

    protected function valuesSql(Expression $expression, bool $valuesAsTable = true): string
    {
        // VALUES with tuples of expressions is treated as a table, since ClickHouse aliases it so
        $alias = $expression->args['alias'] ?? null;

        if ($alias instanceof Expression && $alias->argList('columns') !== [] && $expression->argList('expressions') !== []) {
            $valuesAsTable = false;

            foreach ($expression->argList('expressions')[0]->argList('expressions') as $value) {
                if ($value instanceof Tuple) {
                    $valuesAsTable = true;

                    break;
                }
            }
        } else {
            $valuesAsTable = true;
        }

        return parent::valuesSql($expression, valuesAsTable: $valuesAsTable);
    }

    protected function timestamptruncSql(Expression $expression): string
    {
        $unit = $this->weekstartUnitToStr($expression);

        // https://clickhouse.com/docs/whats-new/changelog/2023#improvement
        if ($this->dialect->version < [23, 12, 0] && $unit instanceof Expression && $unit->isString()) {
            $unit = Literal::string(strtolower($unit->name()));
        }

        return $this->func('dateTrunc', $unit, $expression->args['this'] ?? null, $expression->args['zone'] ?? null);
    }

    protected function datetruncSql(Expression $expression): string
    {
        return $this->timestamptruncSql($expression);
    }
}
