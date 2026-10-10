<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\StarRocks;

use Kwasii\LaravelSqlParser\Dialects\MySql\MySqlGenerator;
use Kwasii\LaravelSqlParser\Expressions\Between;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\Create;
use Kwasii\LaravelSqlParser\Expressions\EngineProperty;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\FirstValue;
use Kwasii\LaravelSqlParser\Expressions\GTE;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\IgnoreNulls;
use Kwasii\LaravelSqlParser\Expressions\Lag;
use Kwasii\LaravelSqlParser\Expressions\LastValue;
use Kwasii\LaravelSqlParser\Expressions\Lead;
use Kwasii\LaravelSqlParser\Expressions\LTE;
use Kwasii\LaravelSqlParser\Expressions\PrimaryKey;
use Kwasii\LaravelSqlParser\Expressions\Properties;
use Kwasii\LaravelSqlParser\Expressions\Schema;

/**
 * StarRocks generator (sqlglot generators/starrocks.py).
 */
class StarRocksGenerator extends MySqlGenerator
{
    public const string TABLES = StarRocksGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    /**
     * StarRocks doesn't support BETWEEN in DELETE statements, so BETWEEN becomes explicit comparisons.
     */
    public function eliminateBetweenInDelete(Expression $expression): Expression
    {
        $where = $expression->args['where'] ?? null;

        if ($where instanceof Expression) {
            foreach ($where->findAll(Between::class) as $between) {
                $this_ = $between->args['this'] ?? null;
                $copy = static fn (): mixed => $this_ instanceof Expression ? $this_->copy() : $this_;

                $between->replace(Builder::and([
                    new GTE(['this' => $copy(), 'expression' => $between->args['low'] ?? null]),
                    new LTE(['this' => $copy(), 'expression' => $between->args['high'] ?? null]),
                ], copy: false));
            }
        }

        return $expression;
    }

    protected function stDistanceSphere(Expression $expression): string
    {
        $point1 = $expression->args['this'] ?? null;
        $point2 = $expression->args['expression'] ?? null;

        return $this->func(
            'ST_Distance_Sphere',
            $this->func('ST_X', $point1),
            $this->func('ST_Y', $point1),
            $this->func('ST_X', $point2),
            $this->func('ST_Y', $point2),
        );
    }

    protected function transformArrayContainedBy(Expression $e): string
    {
        return $this->func('ARRAY_CONTAINS_ALL', $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformCurrentVersion(Expression $e): string
    {
        return 'CURRENT_VERSION()';
    }

    protected function transformDateDiff(Expression $e): string
    {
        return $this->func('DATE_DIFF', $this->weekstartUnitToStr($e), $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformMap(Expression $e): string
    {
        return $this->varMapSql($e, 'MAP');
    }

    protected function transformVarMap(Expression $e): string
    {
        return $this->varMapSql($e, 'MAP');
    }

    protected function transformSchemaCommentProperty(Expression $e): string
    {
        return $this->nakedProperty($e);
    }

    protected function transformSqlSecurityProperty(Expression $e): string
    {
        return 'SECURITY '.$this->sql($e, 'this');
    }

    protected function transformStrToUnix(Expression $e): string
    {
        return $this->func('UNIX_TIMESTAMP', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformTimestampTrunc(Expression $e): string
    {
        return $this->func('DATE_TRUNC', $this->weekstartUnitToStr($e), $e->args['this'] ?? null);
    }

    protected function transformUnixToStr(Expression $e): string
    {
        return $this->func('FROM_UNIXTIME', $e->args['this'] ?? null, $this->formatTime($e));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function ignorenullsSql(Expression $expression): string
    {
        // IGNORE NULLS follows the first argument, e.g. LAG(x IGNORE NULLS, 1, 0)
        $func = $expression->args['this'] ?? null;

        if ($func instanceof FirstValue || $func instanceof LastValue || $func instanceof Lag || $func instanceof Lead) {
            return $this->func(
                $func::sqlName(),
                $this->embedIgnoreNulls(new IgnoreNulls(['this' => $func->args['this'] ?? null]), 'IGNORE NULLS'),
                $func->args['offset'] ?? null,
                $func->args['default'] ?? null,
            );
        }

        return parent::ignorenullsSql($expression);
    }

    protected function respectnullsSql(Expression $expression): string
    {
        // RESPECT NULLS is the default behavior, and StarRocks rejects the keyword
        return $this->sql($expression, 'this');
    }

    protected function createSql(Expression $expression): string
    {
        // StarRocks' primary key is defined outside of the schema, so we need to move it there
        $schema = $expression->args['this'] ?? null;

        if ($schema instanceof Schema) {
            $primaryKey = $schema->find(PrimaryKey::class);

            if ($primaryKey !== null) {
                $props = $expression->args['properties'] ?? null;

                if (! $props instanceof Expression) {
                    $props = new Properties(['expressions' => []]);
                    $expression->set('properties', $props);
                }

                $engine = $props->find(EngineProperty::class);
                $engineIndex = $engine !== null ? ($engine->index ?? 0) : -1;
                $props->set('expressions', $primaryKey->pop(), $engineIndex + 1, overwrite: false);
            }
        }

        return parent::createSql($expression);
    }

    protected function partitionedbypropertySql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Schema) {
            // For MVs, StarRocks needs outer parentheses.
            $create = $expression->findAncestor(Create::class);
            $sql = $this->expressions($this_, flat: true);

            $allColumns = true;

            foreach ($this_->argList('expressions') as $col) {
                if (! $col instanceof Column && ! $col instanceof Identifier) {
                    $allColumns = false;

                    break;
                }
            }

            if (($create !== null && $create->text('kind') === 'VIEW') || $allColumns) {
                $sql = "({$sql})";
            }

            return "PARTITION BY {$sql}";
        }

        return 'PARTITION BY '.$this->sql($this_);
    }

    protected function clusterpropertySql(Expression $expression): string
    {
        if ($this->truthy($expression->args['this'] ?? null)) {
            $this->unsupported('Unsupported CLUSTER BY '.$this->sql($expression, 'this'));

            return '';
        }

        return 'ORDER BY ('.$this->expressions($expression, flat: true).')';
    }

    protected function refreshtriggerpropertySql(Expression $expression): string
    {
        $method = $this->sql($expression, 'method');
        $method = $method !== '' ? " {$method}" : '';
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? " {$kind}" : '';
        $starts = $this->sql($expression, 'starts');
        $starts = $starts !== '' ? " START ({$starts})" : '';
        $every = $this->sql($expression, 'every');
        $unit = $this->sql($expression, 'unit');
        $every = $every !== '' && $unit !== '' ? " EVERY (INTERVAL {$every} {$unit})" : '';

        return "REFRESH{$method}{$kind}{$starts}{$every}";
    }
}
