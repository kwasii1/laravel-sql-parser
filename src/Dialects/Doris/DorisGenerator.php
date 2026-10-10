<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Doris;

use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySqlGenerator;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Create;
use LaravelSqlParser\LaravelSqlParser\Expressions\Delete;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lag;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\MaterializedProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Update;

/**
 * Apache Doris generator (sqlglot generators/doris.py).
 */
class DorisGenerator extends MySqlGenerator
{
    public const string TABLES = DorisGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function lagLeadSql(Expression $expression): string
    {
        return $this->func(
            $expression instanceof Lag ? 'LAG' : 'LEAD',
            $expression->args['this'] ?? null,
            $this->truthy($expression->args['offset'] ?? null) ? $expression->args['offset'] : Literal::number(1),
            $this->truthy($expression->args['default'] ?? null) ? $expression->args['default'] : Builder::null(),
        );
    }

    protected function transformCurrentDate(Expression $e): string
    {
        return $this->func('CURRENT_DATE');
    }

    protected function transformCurrentTimestamp(Expression $e): string
    {
        return $this->func('NOW');
    }

    protected function transformDateTrunc(Expression $e): string
    {
        return $this->func('DATE_TRUNC', $e->args['this'] ?? null, $this->weekstartUnitToStr($e));
    }

    protected function transformTimestampTrunc(Expression $e): string
    {
        return $this->func('DATE_TRUNC', $e->args['this'] ?? null, $this->weekstartUnitToStr($e));
    }

    protected function transformGroupConcat(Expression $e): string
    {
        $separator = $e->args['separator'] ?? null;

        return $this->func('GROUP_CONCAT', $e->args['this'] ?? null, $this->truthy($separator) ? $separator : Literal::string(','));
    }

    protected function transformJSONExtractScalar(Expression $e): string
    {
        return $this->func('JSON_EXTRACT', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformSchemaCommentProperty(Expression $e): string
    {
        return $this->nakedProperty($e);
    }

    protected function transformStrToUnix(Expression $e): string
    {
        return $this->func('UNIX_TIMESTAMP', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformTsOrDsAdd(Expression $e): string
    {
        return $this->func('DATE_ADD', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformTsOrDsToDate(Expression $e): string
    {
        return $this->func('TO_DATE', $e->args['this'] ?? null);
    }

    protected function transformUnixToStr(Expression $e): string
    {
        $format = $this->formatTime($e);

        return $this->func('FROM_UNIXTIME', $e->args['this'] ?? null, $format !== $this->dialect->settings['TIME_FORMAT'] ? $format : null);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function uniquekeypropertySql(Expression $expression, string $prefix = 'UNIQUE KEY'): string
    {
        $createStmt = $expression->findAncestor(Create::class);
        $properties = $createStmt?->args['properties'] ?? null;

        if ($properties instanceof Expression && $properties->find(MaterializedProperty::class) !== null) {
            return parent::uniquekeypropertySql($expression, 'KEY');
        }

        return parent::uniquekeypropertySql($expression);
    }

    protected function partitionrangeSql(Expression $expression): string
    {
        $name = $this->sql($expression, 'this');
        $values = $expression->args['expressions'] ?? [];
        $values = is_array($values) ? array_values($values) : [];

        if (count($values) !== 1) {
            $parts = [];

            foreach ($values as $value) {
                if (is_array($value)) {
                    $parts[] = '('.implode(', ', array_map(fn (mixed $v): string => $this->sql($v), $value)).')';
                } else {
                    $parts[] = '('.$this->sql($value).')';
                }
            }

            return "PARTITION {$name} VALUES [".implode(', ', $parts).')';
        }

        return "PARTITION {$name} VALUES LESS THAN (".$this->sql($values[0]).')';
    }

    protected function partitionbyrangepropertydynamicSql(Expression $expression): string
    {
        $start = $this->sql($expression, 'start');
        $end = $this->sql($expression, 'end');
        $every = $expression->args['every'] ?? null;

        if ($every instanceof Expression) {
            $interval = 'INTERVAL '.$this->sql($every, 'this').' '.$this->sql($every, 'unit');
        } else {
            $interval = '';
        }

        return "FROM ({$start}) TO ({$end}) {$interval}";
    }

    protected function partitionedbypropertySql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Schema) {
            return 'PARTITION BY ('.$this->expressions($this_, flat: true).')';
        }

        return 'PARTITION BY ('.$this->sql($this_).')';
    }

    protected function tableSql(Expression $expression, string $sep = ' AS '): string
    {
        $ancestor = $expression->findAncestor(Update::class, Delete::class, Select::class);

        if (! $ancestor instanceof Select) {
            $sep = ' ';
        }

        return parent::tableSql($expression, $sep);
    }
}
