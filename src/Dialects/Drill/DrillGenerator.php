<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Drill;

use LaravelSqlParser\LaravelSqlParser\Expressions\All;
use LaravelSqlParser\LaravelSqlParser\Expressions\Any;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\ILike;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;

/**
 * Apache Drill generator (sqlglot generators/drill.py).
 */
class DrillGenerator extends Generator
{
    public const string TABLES = DrillGeneratorTables::class;

    protected function transformCurrentTimestamp(Expression $e): string
    {
        return 'CURRENT_TIMESTAMP';
    }

    protected function dateAddSqlHandler(Expression $expression, string $kind): string
    {
        return $this->func(
            "DATE_{$kind}",
            $expression->args['this'] ?? null,
            new Interval(['this' => $expression->args['expression'] ?? null, 'unit' => Builder::unitToVar($expression)]),
        );
    }

    protected function transformDateToDi(Expression $e): string
    {
        return 'CAST(TO_DATE('.$this->sql($e, 'this').', '.DrillDialectTables::DATEINT_FORMAT.') AS INT)';
    }

    protected function transformDiToDate(Expression $e): string
    {
        return 'TO_DATE(CAST('.$this->sql($e, 'this').' AS VARCHAR), '.DrillDialectTables::DATEINT_FORMAT.')';
    }

    protected function transformIf(Expression $e): string
    {
        return '`IF`('.$this->formatArgs([$e->args['this'] ?? null, $e->args['true'] ?? null, $e->args['false'] ?? null]).')';
    }

    protected function transformPartitionedByProperty(Expression $e): string
    {
        return 'PARTITION BY '.$this->sql($e, 'this');
    }

    protected function strToDate(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $timeFormat = $this->formatTime($expression);

        if ($timeFormat === DrillDialectTables::DATE_FORMAT) {
            return $this->sql(Builder::cast($this_, 'DATE'));
        }

        return $this->func('TO_DATE', $this_, $timeFormat);
    }

    protected function transformStrToTime(Expression $e): string
    {
        return $this->func('TO_TIMESTAMP', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformTimeStrToDate(Expression $e): string
    {
        $this_ = $e->args['this'] ?? null;

        return $this->sql(Builder::cast($this_ instanceof Expression ? $this_ : 'NULL', 'DATE'));
    }

    protected function transformTimeToStr(Expression $e): string
    {
        return $this->func('TO_CHAR', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformToChar(Expression $e): string
    {
        return $this->functionFallbackSql($e);
    }

    protected function transformTsOrDsAdd(Expression $e): string
    {
        $interval = new Interval(['this' => $e->args['expression'] ?? null, 'unit' => Builder::var('DAY')]);

        return 'DATE_ADD(CAST('.$this->sql($e, 'this').' AS DATE), '.$this->sql($interval).')';
    }

    protected function transformTsOrDiToDi(Expression $e): string
    {
        return 'CAST(SUBSTR(REPLACE(CAST('.$this->sql($e, 'this')." AS VARCHAR), '-', ''), 1, 8) AS INT)";
    }

    protected function ilikeSql(Expression $expression): string
    {
        $rhs = $expression->args['expression'] ?? null;

        if ($rhs instanceof All || $rhs instanceof Any) {
            return parent::ilikeSql($expression);
        }

        return $this->ilikeFuncSql($expression);
    }

    protected function escapeSql(Expression $expression): string
    {
        // Drill takes the escape character as ILIKE's third argument instead of an ESCAPE clause
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof ILike) {
            $rhs = $this_->args['expression'] ?? null;

            if (! ($rhs instanceof All || $rhs instanceof Any)) {
                $escape = $expression->args['expression'] ?? null;

                return $this->ilikeFuncSql($this_, $escape instanceof Expression ? $escape : null);
            }
        }

        return parent::escapeSql($expression);
    }

    protected function ilikeFuncSql(Expression $expression, ?Expression $escape = null): string
    {
        $ilike = $this->func('ILIKE', $expression->args['this'] ?? null, $expression->args['expression'] ?? null, $escape);

        return $expression->flag('negate') ? "NOT {$ilike}" : $ilike;
    }
}
