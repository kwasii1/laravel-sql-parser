<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Databricks;

use LaravelSqlParser\LaravelSqlParser\Dialects\Spark\SparkGenerator;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\GeneratedAsIdentityColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\ReturnExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ReturnsProperty;

/**
 * Databricks generator (sqlglot generators/databricks.py).
 */
class DatabricksGenerator extends SparkGenerator
{
    public const string TABLES = DatabricksGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function transformCurrentVersion(Expression $e): string
    {
        return 'CURRENT_VERSION()';
    }

    protected function transformCurrentCatalog(Expression $e): string
    {
        return 'CURRENT_CATALOG()';
    }

    protected function transformDatetimeAdd(Expression $e): string
    {
        return $this->func('TIMESTAMPADD', $e->args['unit'] ?? null, $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformDatetimeSub(Expression $e): string
    {
        return $this->func(
            'TIMESTAMPADD',
            $e->args['unit'] ?? null,
            new Mul(['this' => $e->args['expression'] ?? null, 'expression' => Literal::number(-1)]),
            $e->args['this'] ?? null,
        );
    }

    protected function transformJSONExtract(Expression $e): string
    {
        return $this->sql($e, 'this').':'.$this->sql($e, 'expression');
    }

    protected function transformJSONPathRoot(Expression $e): string
    {
        return $e->parent?->parent instanceof JSONExtractScalar ? '$' : '';
    }

    protected function transformToChar(Expression $e): string
    {
        return $e->flag('is_numeric')
            ? $this->castSql(new Cast(['this' => $e->args['this'] ?? null, 'to' => new DataType(['this' => 'STRING'])]))
            : $this->functionFallbackSql($e);
    }

    protected function transformRegrAvgx(Expression $e): string
    {
        return $this->regrSql($e);
    }

    protected function transformRegrSxx(Expression $e): string
    {
        return $this->regrSql($e);
    }

    protected function transformRegrSyy(Expression $e): string
    {
        return $this->regrSql($e);
    }

    protected function regrSql(Expression $expression): string
    {
        $name = $expression::sqlName();
        $x = $expression->args['expression'] ?? null;

        if ($x instanceof Distinct) {
            return $this->funcWith($name, [new Distinct(['expressions' => [$expression->args['this'] ?? null]]), ...$x->argList('expressions')]);
        }

        return $this->func($name, $expression->args['this'] ?? null, $x);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function createSql(Expression $expression): string
    {
        $body = $expression->argExpr('expression');

        if ($body !== null && ! $body instanceof ReturnExpr && $expression->text('kind') === 'FUNCTION') {
            foreach ($expression->findAll(ReturnsProperty::class) as $p) {
                if ($p->flag('is_table')) {
                    $expression->set('expression', new ReturnExpr(['this' => $body]));

                    break;
                }
            }
        }

        return parent::createSql($expression);
    }

    protected function columndefSql(Expression $expression, string $sep = ' '): string
    {
        $constraint = $expression->find(GeneratedAsIdentityColumnConstraint::class);
        $kind = $expression->args['kind'] ?? null;

        if ($constraint !== null && $kind instanceof DataType && in_array($kind->args['this'] ?? null, DataType::INTEGER_TYPES, true)) {
            // only BIGINT generated identity constraints are supported
            $expression->set('kind', new DataType(['this' => DType::BIGINT]));
        }

        return parent::columndefSql($expression, $sep);
    }

    protected function timeserieskeySql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' TIMESERIES';
    }

    protected function jsonpathSql(Expression $expression): string
    {
        $path = parent::jsonpathSql($expression);

        if ($expression->parent instanceof JSONExtractScalar) {
            $path = $this->escapeStr($path);

            return $this->dialect->settings['QUOTE_START'].$path.$this->dialect->settings['QUOTE_END'];
        }

        return $path;
    }

    protected function uniformSql(Expression $expression): string
    {
        $gen = $expression->args['gen'] ?? null;
        $seed = $expression->args['seed'] ?? null;

        // From Snowflake UNIFORM(min, max, gen) as RANDOM(), RANDOM(seed), or constant value -> Extract seed
        if ($gen instanceof Expression) {
            $seed = $gen->args['this'] ?? null;
        }

        return $this->func('UNIFORM', $expression->args['this'] ?? null, $expression->args['expression'] ?? null, $seed);
    }

    protected function clusterpropertySql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? $this_ : '('.$this->expressions($expression, flat: true).')';

        return "CLUSTER BY {$this_}";
    }
}
