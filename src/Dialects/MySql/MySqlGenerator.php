<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\MySql;

use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Create;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DateAdd;
use Kwasii\LaravelSqlParser\Expressions\Div;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\Kwarg;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\PartitionByListProperty;
use Kwasii\LaravelSqlParser\Expressions\PartitionByRangeProperty;
use Kwasii\LaravelSqlParser\Expressions\Properties;
use Kwasii\LaravelSqlParser\Expressions\SqlSecurityProperty;
use Kwasii\LaravelSqlParser\Expressions\Sub;
use Kwasii\LaravelSqlParser\Expressions\TimestampDiff;
use Kwasii\LaravelSqlParser\Expressions\WeekStart;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * MySQL generator (sqlglot generators/mysql.py).
 */
class MySqlGenerator extends Generator
{
    public const string TABLES = MySqlGeneratorTables::class;

    private const array MAKE_INTERVAL_UNIT_ALIASES = [
        'years' => 'year',
        'months' => 'month',
        'weeks' => 'week',
        'days' => 'day',
        'hours' => 'hour',
        'minutes' => 'minute',
        'mins' => 'minute',
        'seconds' => 'second',
        'secs' => 'second',
    ];

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function dateTruncSqlHandler(Expression $expression): string
    {
        $expr = $this->sql($expression, 'this');
        $unitExpr = $expression->args['unit'] ?? null;
        $unit = $unitExpr instanceof WeekStart ? $this->weekstartName($unitExpr) : strtoupper($expression->text('unit'));

        if ($unit === 'WEEK') {
            $concat = "CONCAT(YEAR({$expr}), ' ', WEEK({$expr}, 1), ' 1')";
            $dateFormat = '%Y %u %w';
        } elseif ($unit === 'MONTH') {
            $concat = "CONCAT(YEAR({$expr}), ' ', MONTH({$expr}), ' 1')";
            $dateFormat = '%Y %c %e';
        } elseif ($unit === 'QUARTER') {
            $concat = "CONCAT(YEAR({$expr}), ' ', QUARTER({$expr}) * 3 - 2, ' 1')";
            $dateFormat = '%Y %c %e';
        } elseif ($unit === 'YEAR') {
            $concat = "CONCAT(YEAR({$expr}), ' 1 1')";
            $dateFormat = '%Y %c %e';
        } else {
            if ($unit !== 'DAY') {
                $this->unsupported("Unexpected interval unit: {$unit}");
            }

            return $this->func('DATE', $expr);
        }

        return $this->func('STR_TO_DATE', $concat, "'{$dateFormat}'");
    }

    protected function strToDateSqlHandler(Expression $expression): string
    {
        return $this->func('STR_TO_DATE', $expression->args['this'] ?? null, $this->formatTime($expression));
    }

    protected function unixToTimeSqlHandler(Expression $expression): string
    {
        $scale = $expression->args['scale'] ?? null;
        $timestamp = $expression->args['this'] ?? null;

        if ($scale === null || ($scale instanceof Literal && $scale->equals(Literal::number(0)))) {
            return $this->func('FROM_UNIXTIME', $timestamp, $this->formatTime($expression));
        }

        return $this->func(
            'FROM_UNIXTIME',
            new Div(['this' => $timestamp, 'expression' => Builder::func('POW', [Literal::number(10), $scale])]),
            $this->formatTime($expression),
        );
    }

    protected function dateAddSqlHandler(Expression $expression, string $kind): string
    {
        return $this->func(
            "DATE_{$kind}",
            $expression->args['this'] ?? null,
            new Interval(['this' => $expression->args['expression'] ?? null, 'unit' => Builder::unitToVar($expression)]),
        );
    }

    protected function tsOrDsToDateSqlHandler(Expression $expression): string
    {
        return $this->truthy($expression->args['format'] ?? null)
            ? $this->strToDateSqlHandler($expression)
            : $this->func('DATE', $expression->args['this'] ?? null);
    }

    protected function transformChr(Expression $e): string
    {
        return $this->chrSql($e, 'CHAR');
    }

    protected function transformDateDiffToSql(Expression $e): string
    {
        return $this->func('DATEDIFF', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformGroupConcat(Expression $e): string
    {
        $separator = $this->sql($e, 'separator');

        return 'GROUP_CONCAT('.$this->sql($e, 'this').' SEPARATOR '.($separator !== '' ? $separator : "','").')';
    }

    protected function transformNullSafeEQ(Expression $e): string
    {
        return $this->binary($e, '<=>');
    }

    protected function transformNullSafeNEQ(Expression $e): string
    {
        return 'NOT '.$this->binary($e, '<=>');
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'LOCATE', supportsPosition: true);
    }

    protected function transformSessionUser(Expression $e): string
    {
        return 'SESSION_USER()';
    }

    protected function transformTimestampDiff(Expression $e): string
    {
        return $this->func('TIMESTAMPDIFF', Builder::unitToVar($e), $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformTimeStrToTime(Expression $e): string
    {
        return $this->timestrtotimeSqlHandler($e, includePrecision: ! $e->flag('zone'));
    }

    protected function transformTimeToStrToSql(Expression $e): string
    {
        return $this->func('DATE_FORMAT', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformTsOrDsDiff(Expression $e): string
    {
        return $this->func('DATEDIFF', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformUnicode(Expression $e): string
    {
        return 'ORD(CONVERT('.$this->sql($e, 'this').' USING utf32))';
    }

    // ---------------------------------------------------------------------------------------
    // Generator overrides
    // ---------------------------------------------------------------------------------------

    protected function makeintervalSql(Expression $expression): string
    {
        $intervals = [];

        foreach ($expression->args as $argKey => $value) {
            if ($value === null) {
                continue;
            }

            if ($value instanceof Kwarg) {
                $name = strtolower(($value->argExpr('this') ?? new Literal)->name());
                $unitName = self::MAKE_INTERVAL_UNIT_ALIASES[$name] ?? $name;
                $value = $value->args['expression'] ?? null;
            } else {
                $unitName = $argKey;
            }

            $intervals[] = new Interval(['this' => $value instanceof Expression ? $value->copy() : $value, 'unit' => Builder::var(strtoupper($unitName))]);
        }

        if ($intervals === []) {
            return $this->functionFallbackSql($expression);
        }

        $parent = $expression->parent;
        $sep = $parent instanceof Sub && ($parent->args['expression'] ?? null) === $expression ? ' - ' : ' + ';

        return implode($sep, array_map(fn (Interval $interval): string => $this->sql($interval), $intervals));
    }

    protected function locateProperties(Properties $properties): array
    {
        $locations = parent::locateProperties($properties);

        // MySQL puts SQL SECURITY before VIEW but after the schema for functions/procedures
        $create = $properties->parent;

        if ($create instanceof Create && $create->text('kind') === 'VIEW') {
            foreach ($locations['POST_SCHEMA'] ?? [] as $i => $p) {
                if ($p instanceof SqlSecurityProperty) {
                    array_splice($locations['POST_SCHEMA'], $i, 1);
                    $locations[$this->t['SQL_SECURITY_VIEW_LOCATION']][] = $p;

                    break;
                }
            }
        }

        return $locations;
    }

    protected function computedcolumnconstraintSql(Expression $expression): string
    {
        $persisted = $expression->flag('persisted') ? 'STORED' : 'VIRTUAL';

        return 'GENERATED ALWAYS AS ('.$this->sql(($expression->argExpr('this') ?? new Literal)->unnest()).") {$persisted}";
    }

    protected function arraySql(Expression $expression): string
    {
        $this->unsupported('Arrays are not supported by MySQL');

        return $this->functionFallbackSql($expression);
    }

    protected function arraycontainsallSql(Expression $expression): string
    {
        $this->unsupported('Array operations are not supported by MySQL');

        return $this->functionFallbackSql($expression);
    }

    protected function arraycontainedbySql(Expression $expression): string
    {
        $this->unsupported('Array operations are not supported by MySQL');

        return $this->functionFallbackSql($expression);
    }

    protected function dpipeSql(Expression $expression): string
    {
        return $this->funcWith('CONCAT', $expression->flatten());
    }

    protected function extractSql(Expression $expression): string
    {
        $unit = $expression->name();

        if ($unit !== '' && strtolower($unit) === 'epoch') {
            return $this->func('UNIX_TIMESTAMP', $expression->args['expression'] ?? null);
        }

        return parent::extractSql($expression);
    }

    protected function datatypeSql(DataType $expression): string
    {
        if ($this->t['VARCHAR_REQUIRES_SIZE'] === true && $expression->isType(DType::VARCHAR) && $expression->argList('expressions') === []) {
            // `VARCHAR` must always have a size - if it doesn't, we always generate `TEXT`
            return 'TEXT';
        }

        // https://dev.mysql.com/doc/refman/8.0/en/numeric-type-syntax.html
        $result = parent::datatypeSql($expression);
        $type = $expression->args['this'] ?? null;

        if (is_string($type) && isset($this->t['UNSIGNED_TYPE_MAPPING'][$type])) {
            $result = "{$result} UNSIGNED";
        }

        return $result;
    }

    protected function jsonarraycontainsSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' MEMBER OF('.$this->sql($expression, 'expression').')';
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        $to = $expression->args['to'] ?? null;
        $type = $to instanceof Expression ? ($to->args['this'] ?? null) : null;

        if (is_string($type) && isset($this->t['TIMESTAMP_FUNC_TYPES'][$type])) {
            return $this->func('TIMESTAMP', $expression->args['this'] ?? null);
        }

        $mapped = is_string($type) ? ($this->t['CAST_MAPPING'][$type] ?? null) : null;

        if ($mapped !== null) {
            $to->set('this', $mapped);
        }

        return parent::castSql($expression);
    }

    protected function showSql(Expression $expression): string
    {
        $name = $expression->name();
        $this_ = " {$name}";
        $full = $expression->flag('full') ? ' FULL' : '';
        $global = $expression->flag('global_') ? ' GLOBAL' : '';

        $target = $this->sql($expression, 'target');
        $dbId = $this->sql($expression, 'db');

        // SHOW CREATE ... takes a possibly qualified name, it has no FROM clause
        if (str_starts_with($name, 'CREATE') && $dbId !== '') {
            $target = "{$dbId}.{$target}";
            $db = '';
        } else {
            $db = $this->prefixedSqlInternal('FROM', $expression, 'db');
        }

        $target = $target !== '' ? " {$target}" : '';

        if ($name === 'COLUMNS' || $name === 'INDEX') {
            $target = " FROM{$target}";
        } elseif ($name === 'GRANTS') {
            $target = " FOR{$target}";
        } elseif ($name === 'LINKS' || $name === 'PARTITIONS') {
            $target = $target !== '' ? " ON{$target}" : '';
        } elseif ($name === 'PROJECTIONS') {
            $target = $target !== '' ? " ON TABLE{$target}" : '';
        }

        $like = $this->prefixedSqlInternal('LIKE', $expression, 'like');
        $where = $this->sql($expression, 'where');

        $types = $this->expressions($expression, key: 'types');
        $types = $types !== '' ? " {$types}" : $types;
        $query = $this->prefixedSqlInternal('FOR QUERY', $expression, 'query');

        if ($name === 'PROFILE') {
            $offset = $this->prefixedSqlInternal('OFFSET', $expression, 'offset');
            $limit = $this->prefixedSqlInternal('LIMIT', $expression, 'limit');
        } else {
            $offset = '';
            $limit = $this->oldstyleLimitSqlInternal($expression);
        }

        $log = $this->prefixedSqlInternal('IN', $expression, 'log');
        $position = $this->prefixedSqlInternal('FROM', $expression, 'position');
        $channel = $this->prefixedSqlInternal('FOR CHANNEL', $expression, 'channel');

        $mutexOrStatus = $name === 'ENGINE' ? ($expression->flag('mutex') ? ' MUTEX' : ' STATUS') : '';

        $forTable = $this->prefixedSqlInternal('FOR TABLE', $expression, 'for_table');
        $forGroup = $this->prefixedSqlInternal('FOR GROUP', $expression, 'for_group');
        $forUser = $this->prefixedSqlInternal('FOR USER', $expression, 'for_user');
        $forRole = $this->prefixedSqlInternal('FOR ROLE', $expression, 'for_role');
        $intoOutfile = $this->prefixedSqlInternal('INTO OUTFILE', $expression, 'into_outfile');
        $json = $expression->flag('json') ? ' JSON' : '';

        return "SHOW{$full}{$global}{$this_}{$json}{$target}{$forTable}{$types}{$db}{$query}{$log}{$position}{$channel}{$mutexOrStatus}{$like}{$where}{$offset}{$limit}{$forGroup}{$forUser}{$forRole}{$intoOutfile}";
    }

    /**
     * Avoid the TO keyword in ALTER ... RENAME statements (shared by MySQL, Doris and StarRocks).
     */
    protected function alterrenameSql(Expression $expression, bool $includeTo = true): string
    {
        return parent::alterrenameSql($expression, includeTo: false);
    }

    protected function altercolumnSql(Expression $expression): string
    {
        $dtype = $this->sql($expression, 'dtype');

        if ($dtype === '') {
            return parent::altercolumnSql($expression);
        }

        if ($expression->flag('exists')) {
            $this->unsupported('ALTER COLUMN IF EXISTS is not supported by this dialect');
        }

        $this_ = $this->sql($expression, 'this');
        $nullConstraint = $this->alterColumnNullConstraintSqlInternal($expression);

        return "MODIFY COLUMN {$this_} {$dtype}{$nullConstraint}";
    }

    protected function prefixedSqlInternal(string $prefix, Expression $expression, string $arg): string
    {
        $sql = $this->sql($expression, $arg);

        return $sql !== '' ? " {$prefix} {$sql}" : '';
    }

    protected function oldstyleLimitSqlInternal(Expression $expression): string
    {
        $limit = $this->sql($expression, 'limit');
        $offset = $this->sql($expression, 'offset');

        if ($limit !== '') {
            return ' LIMIT '.($offset !== '' ? "{$offset}, {$limit}" : $limit);
        }

        return '';
    }

    protected function timestamptruncSql(Expression $expression): string
    {
        $unit = $expression->args['unit'] ?? null;

        if ($unit instanceof WeekStart) {
            $unit = Builder::var($this->weekstartName($unit));
        }

        // Pick an old-enough date to avoid negative timestamp diffs
        $startTs = "'0000-01-01 00:00:00'";

        // Source: https://stackoverflow.com/a/32955740
        $timestampDiff = new TimestampDiff(['this' => $expression->args['this'] ?? null, 'expression' => $startTs, 'unit' => $unit]);
        $interval = new Interval(['this' => $timestampDiff, 'unit' => $unit instanceof Expression ? $unit->copy() : $unit]);
        $dateAdd = new DateAdd(['this' => $startTs, 'expression' => $timestampDiff, 'unit' => Builder::unitToStr($interval)]);

        return $this->sql($dateAdd);
    }

    protected function converttimezoneSql(Expression $expression): string
    {
        return $this->func('CONVERT_TZ', $expression->args['timestamp'] ?? null, $expression->args['source_tz'] ?? null, $expression->args['target_tz'] ?? null);
    }

    protected function attimezoneSql(Expression $expression): string
    {
        $this->unsupported('AT TIME ZONE is not supported by MySQL');

        return $this->sql($expression, 'this');
    }

    protected function isasciiSql(Expression $expression): string
    {
        return 'REGEXP_LIKE('.$this->sql($expression, 'this').", '^[[:ascii:]]*$')";
    }

    protected function ignorenullsSql(Expression $expression): string
    {
        // https://dev.mysql.com/doc/refman/8.4/en/window-function-descriptions.html
        $this->unsupported('MySQL does not support IGNORE NULLS.');

        return $this->sql($expression, 'this');
    }

    protected function currentschemaSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'this');

        return $this->func('SCHEMA');
    }

    protected function partitionSql(Expression $expression): string
    {
        $parent = $expression->parent;

        if ($parent instanceof PartitionByRangeProperty || $parent instanceof PartitionByListProperty) {
            return $this->expressions($expression, flat: true);
        }

        return parent::partitionSql($expression);
    }

    protected function partitionBySqlInternal(Expression $expression, string $kind): string
    {
        $partitions = $this->expressions($expression, key: 'partition_expressions', flat: true);
        $create = $this->expressions($expression, key: 'create_expressions', flat: true);

        return "PARTITION BY {$kind} ({$partitions}) ({$create})";
    }

    protected function partitionbyrangepropertySql(Expression $expression): string
    {
        return $this->partitionBySqlInternal($expression, 'RANGE');
    }

    protected function partitionbylistpropertySql(Expression $expression): string
    {
        return $this->partitionBySqlInternal($expression, 'LIST');
    }

    protected function partitionlistSql(Expression $expression): string
    {
        return 'PARTITION '.$this->sql($expression, 'this').' VALUES IN ('.$this->expressions($expression, flat: true).')';
    }

    protected function partitionrangeSql(Expression $expression): string
    {
        return 'PARTITION '.$this->sql($expression, 'this').' VALUES LESS THAN ('.$this->expressions($expression, flat: true).')';
    }
}
