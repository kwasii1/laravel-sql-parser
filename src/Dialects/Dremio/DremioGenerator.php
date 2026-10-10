<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Dremio;

use Kwasii\LaravelSqlParser\Expressions\AtTimeZone;
use Kwasii\LaravelSqlParser\Expressions\CurrentTimestamp;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * Dremio generator (sqlglot generators/dremio.py).
 */
class DremioGenerator extends Generator
{
    public const string TABLES = DremioGeneratorTables::class;

    /**
     * sqlglot _date_delta_sql.
     */
    protected function dateDeltaSqlDremio(Expression $expression, string $name): string
    {
        $unit = strtoupper($expression->text('unit'));

        // Fallback to default behavior if unit is missing or 'DAY'
        if ($unit === '' || $unit === 'DAY') {
            return $this->func($name, $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
        }

        $thisSql = $this->sql($expression, 'this');
        $exprSql = $this->sql($expression, 'expression');

        return "{$name}({$thisSql}, CAST({$exprSql} AS INTERVAL {$unit}))";
    }

    protected function transformRegexpSplit(Expression $e): string
    {
        return $this->func('REGEXP_SPLIT', $e->args['this'] ?? null, $e->args['expression'] ?? null, $e->args['mode'] ?? null, $e->args['limit'] ?? null);
    }

    protected function transformTimeToStr(Expression $e): string
    {
        return $this->func('TO_CHAR', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function versionSql(Expression $expression): string
    {
        if ($expression->text('kind') === 'AS OF') {
            $this_ = $expression->name() === 'VERSION' ? 'SNAPSHOT' : $expression->name();

            return "AT {$this_} ".$this->sql($expression, 'expression');
        }

        $this->unsupported('Range time travel is not supported in Dremio');

        return parent::versionSql($expression);
    }

    protected function datatypeSql(DataType $expression): string
    {
        // Reject time-zone-aware TIMESTAMPs, which Dremio does not accept
        if ($expression->isType(DType::TIMESTAMPTZ, DType::TIMESTAMPLTZ)) {
            $this->unsupported('Dremio does not support time-zone-aware TIMESTAMP');
        }

        return parent::datatypeSql($expression);
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        // Match: CAST(CURRENT_TIMESTAMP AT TIME ZONE 'UTC' AS DATE)
        if ($expression->isType(DType::DATE)) {
            $atTimeZone = $expression->args['this'] ?? null;

            if (
                $atTimeZone instanceof AtTimeZone
                && ($atTimeZone->args['this'] ?? null) instanceof CurrentTimestamp
                && ($atTimeZone->args['zone'] ?? null) instanceof Literal
                && strtoupper($atTimeZone->text('zone')) === 'UTC'
            ) {
                return 'CURRENT_DATE_UTC';
            }
        }

        return parent::castSql($expression, $safePrefix);
    }
}
