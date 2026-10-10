<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Spark;

use Kwasii\LaravelSqlParser\Dialects\Spark2\Spark2Generator;
use Kwasii\LaravelSqlParser\Expressions\ArrayAgg;
use Kwasii\LaravelSqlParser\Expressions\ArrayToString;
use Kwasii\LaravelSqlParser\Expressions\Block;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\Drop;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Is;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Not;
use Kwasii\LaravelSqlParser\Expressions\NullExpr;
use Kwasii\LaravelSqlParser\Expressions\ObjectId;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsAdd;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * Spark 3 generator (sqlglot generators/spark.py).
 */
class SparkGenerator extends Spark2Generator
{
    public const string TABLES = SparkGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function transformArrayConstructCompact(Expression $e): string
    {
        return $this->func('ARRAY_COMPACT', $this->funcWith('ARRAY', $e->argList('expressions')));
    }

    protected function transformPartitionedByProperty(Expression $e): string
    {
        $this_ = $e->args['this'] ?? null;
        $sqls = [];

        foreach ($this_ instanceof Expression ? $this_->argList('expressions') : [] as $part) {
            // Normalize the expressions in PARTITION BY (<expression>, <expression>, ...)
            $sqls[] = $part instanceof Literal ? Builder::toIdentifier($part->name()) : $part;
        }

        return 'PARTITIONED BY '.$this->wrap($this->expressions(sqls: $sqls, skipFirst: true));
    }

    protected function transformStabilityProperty(Expression $e): string
    {
        return $e->name() === 'IMMUTABLE' ? 'DETERMINISTIC' : 'NOT DETERMINISTIC';
    }

    protected function transformTryCast(Expression $e): string
    {
        return $e->flag('safe') ? $this->trycastSql($e) : $this->castSql($e);
    }

    /**
     * sqlglot _dateadd_sql (TsOrDsAdd, TimestampAdd).
     */
    protected function dateaddSqlHandler(Expression $expression): string
    {
        if (! $expression->flag('unit') || ($expression instanceof TsOrDsAdd && strtoupper($expression->text('unit')) === 'DAY')) {
            // Coming from Hive/Spark2 DATE_ADD or roundtripping the 2-arg version of Spark3/DB
            return $this->func('DATE_ADD', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
        }

        $this_ = $this->func('DATE_ADD', Builder::unitToVar($expression), $expression->args['expression'] ?? null, $expression->args['this'] ?? null);

        if ($expression instanceof TsOrDsAdd) {
            // The 3 arg version of DATE_ADD produces a timestamp in Spark3/DB but possibly not
            // in other dialects
            $returnType = $expression->args['return_type'] ?? null;
            $returnType = DataType::build($returnType instanceof DataType ? $returnType : DType::DATE);

            if (! $returnType->isType(DType::TIMESTAMP, DType::DATETIME)) {
                $this_ = "CAST({$this_} AS ".$this->sql($returnType).')';
            }
        }

        return $this_;
    }

    protected function groupconcatSqlSpark(Expression $expression): string
    {
        if ($this->dialect->version < [4, 0, 0]) {
            $separator = $expression->args['separator'] ?? null;

            return $this->sql(new ArrayToString([
                'this' => new ArrayAgg(['this' => $expression->args['this'] ?? null]),
                'expression' => $this->truthy($separator) ? $separator : Literal::string(''),
            ]));
        }

        return $this->groupconcatSqlHandler($expression);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function ignorenullsSql(Expression $expression): string
    {
        return Generator::ignorenullsSql($expression);
    }

    protected function arrayinsertSql(Expression $expression, int $indexOffset = 0): string
    {
        return parent::arrayinsertSql($expression, 1);
    }

    protected function bracketSql(Expression $expression): string
    {
        if ($expression->flag('safe')) {
            $key = $this->bracketOffsetExpressions($expression, 1)[0] ?? null;

            return $this->func('TRY_ELEMENT_AT', $expression->args['this'] ?? null, $key);
        }

        return parent::bracketSql($expression);
    }

    protected function computedcolumnconstraintSql(Expression $expression): string
    {
        return 'GENERATED ALWAYS AS ('.$this->sql($expression, 'this').')';
    }

    protected function anyvalueSql(Expression $expression): string
    {
        return $this->functionFallbackSql($expression);
    }

    protected function datediffSql(Expression $expression): string
    {
        $end = $this->sql($expression, 'this');
        $start = $this->sql($expression, 'expression');

        if ($expression->flag('unit')) {
            return $this->func('DATEDIFF', Builder::unitToVar($expression), $start, $end);
        }

        return $this->func('DATEDIFF', $end, $start);
    }

    protected function placeholderSql(Expression $expression): string
    {
        if (! $expression->flag('widget')) {
            return parent::placeholderSql($expression);
        }

        return '{'.$expression->name().'}';
    }

    protected function readparquetSql(Expression $expression): string
    {
        $expressions = $expression->argList('expressions');

        if (count($expressions) !== 1) {
            $this->unsupported('READ_PARQUET with multiple arguments is not supported');

            return '';
        }

        return 'parquet.`'.$expressions[0]->name().'`';
    }

    protected function ifblockSql(Expression $expression): string
    {
        $condition = $expression->args['this'] ?? null;
        $trueBlock = $expression->args['true'] ?? null;

        $conditionExpr = null;

        if ($condition instanceof Not) {
            $inner = $condition->args['this'] ?? null;

            if ($inner instanceof Is && ($inner->args['expression'] ?? null) instanceof NullExpr) {
                $conditionExpr = $inner->args['this'] ?? null;
            }
        }

        if ($conditionExpr instanceof ObjectId) {
            $objectType = $conditionExpr->argExpr('expression');
            $drop = $trueBlock instanceof Block ? ($trueBlock->argList('expressions')[0] ?? null) : null;

            if (($objectType === null || strtoupper($objectType->name()) === 'U') && $drop instanceof Drop) {
                $drop->set('exists', true);

                return $this->sql($drop);
            }
        }

        return parent::ifblockSql($expression);
    }
}
