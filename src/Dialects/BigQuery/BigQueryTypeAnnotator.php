<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\BigQuery;

use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateSub;
use LaravelSqlParser\LaravelSqlParser\Expressions\DatetimeTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampTrunc;
use LaravelSqlParser\LaravelSqlParser\Optimizer\TypeAnnotator;

/**
 * BigQuery type inference (sqlglot typing/bigquery.py).
 */
class BigQueryTypeAnnotator extends TypeAnnotator
{
    /**
     * DATE_ADD / DATE_SUB / *_TRUNC with a string literal first argument: BigQuery casts it to
     * the function's own temporal type.
     */
    private const array DATE_FUNC_LITERAL_TYPE = [
        DateAdd::class => DType::DATE,
        DateSub::class => DType::DATE,
        DateTrunc::class => DType::DATE,
        DatetimeTrunc::class => DType::DATETIME,
        TimestampTrunc::class => DType::TIMESTAMPTZ,
    ];

    /**
     * DATE_ADD / DATE_SUB / *_TRUNC return the type of their first argument; a string literal
     * first argument is implicitly cast to the function's own type.
     */
    protected function annotateDateFunc(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Literal && $this_->isString() && isset(self::DATE_FUNC_LITERAL_TYPE[$expression::class])) {
            return $this->setType($expression, self::DATE_FUNC_LITERAL_TYPE[$expression::class]);
        }

        return $this->annotateByArgs($expression, ['this']);
    }

    /**
     * CEIL, FLOOR, ...: INT64 becomes FLOAT64, other types are kept.
     */
    protected function annotateMathFunctions(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;

        return $this->setType($expression, $this_ instanceof Expression && $this_->isType(...DataType::INTEGER_TYPES) ? DType::DOUBLE : ($this_ instanceof Expression ? $this_->type() : null));
    }

    /**
     * SAFE_DIVIDE: INT64 / INT64 is FLOAT64, otherwise the coerced argument type.
     */
    protected function annotateSafeDivide(Expression $expression): Expression
    {
        $left = $expression->args['this'] ?? null;
        $right = $expression->args['expression'] ?? null;

        if ($left instanceof Expression && $right instanceof Expression && $left->isType(...DataType::INTEGER_TYPES) && $right->isType(...DataType::INTEGER_TYPES)) {
            return $this->setType($expression, DType::DOUBLE);
        }

        return $this->annotateByArgsWithCoerce($expression);
    }

    protected function annotateByArgsWithCoerce(Expression $expression): Expression
    {
        $left = $expression->args['this'] ?? null;
        $right = $expression->args['expression'] ?? null;
        $leftType = $left instanceof Expression ? $left->type() : null;
        $rightType = $right instanceof Expression ? $right->type() : null;

        return $this->setType($expression, $this->maybeCoerce($leftType ?? DType::UNKNOWN, $rightType ?? DType::UNKNOWN));
    }

    protected function annotateByArgsApproxTop(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;
        $structType = new DataType([
            'this' => DType::STRUCT,
            'expressions' => [$this_ instanceof Expression ? $this_->type() : null, new DataType(['this' => DType::BIGINT])],
            'nested' => true,
        ]);

        return $this->setType($expression, new DataType(['this' => DType::ARRAY, 'expressions' => [$structType], 'nested' => true]));
    }

    /**
     * CONCAT returns BYTES for BYTES arguments, STRING otherwise.
     * https://cloud.google.com/bigquery/docs/reference/standard-sql/string_functions#concat
     */
    protected function annotateConcat(Expression $expression): Expression
    {
        $annotated = $this->annotateByArgs($expression, ['expressions']);

        if (! $annotated->isType(DType::BINARY, DType::UNKNOWN)) {
            $this->setType($annotated, DType::VARCHAR);
        }

        return $annotated;
    }

    protected function annotateJSONFormat(Expression $expression): Expression
    {
        return $this->setType($expression, $expression->flag('to_json') ? DType::JSON : DType::VARCHAR);
    }

    /**
     * SELECT t, TYPEOF(t) FROM (SELECT 'foo') AS t            -- foo, STRUCT<STRING>
     * SELECT ARRAY(SELECT 'foo'), TYPEOF(ARRAY(SELECT 'foo')) -- foo, ARRAY<STRING>
     * ARRAY(SELECT ... UNION ALL SELECT ...)                  -- ARRAY<type from coerced projections>
     * ARRAY(SELECT AS STRUCT 1 AS a, 'b' AS b)                -- ARRAY<STRUCT<INT64, STRING>>
     */
    protected function annotateArray(Expression $expression): Expression
    {
        $arrayArgs = $expression->argList('expressions');

        if (count($arrayArgs) === 1) {
            $unnested = $arrayArgs[0]->unnest();
            $projectionType = null;

            if ($unnested instanceof Select) {
                $queryType = $unnested->metaGet('query_type');

                if ($queryType instanceof DataType && $queryType->isType(DType::STRUCT)) {
                    $queryExprs = $queryType->argList('expressions');
                    $columnDefs = array_values(array_filter(
                        $queryExprs,
                        static fn (Expression $e): bool => $e instanceof ColumnDef && ! (($e->args['kind'] ?? null) instanceof DataType && $e->args['kind']->isType(DType::UNKNOWN)),
                    ));

                    if (count($columnDefs) === count($queryExprs)) {
                        if ($unnested->text('kind') === 'STRUCT') {
                            // ARRAY(SELECT AS STRUCT ...) -> ARRAY<STRUCT<col1, col2, ...>>
                            $projectionType = $queryType;
                        } elseif (count($columnDefs) === 1 && ($columnDefs[0]->args['kind'] ?? null) instanceof DataType) {
                            // ARRAY(SELECT col FROM ...) -> ARRAY<col_type>
                            $projectionType = $columnDefs[0]->args['kind'];
                        }
                    }
                }
            } elseif ($unnested instanceof SetOperation) {
                // An ARRAY constructor has a single projection
                // https://docs.cloud.google.com/bigquery/docs/reference/standard-sql/array_functions#array
                $columnTypes = $this->setopColumnTypes($unnested);
                $left = $unnested->args['this'] ?? null;
                $firstSelect = $left instanceof Query ? ($left->selects()[0] ?? null) : null;

                if ($columnTypes !== [] && $firstSelect !== null) {
                    $projectionType = $columnTypes[$firstSelect->aliasOrName()] ?? null;
                }
            }

            if ($projectionType !== null && self::typeName($projectionType) !== DType::UNKNOWN) {
                $elementType = $projectionType instanceof DataType ? $projectionType->copy() : new DataType(['this' => $projectionType]);

                return $this->setType($expression, new DataType(['this' => DType::ARRAY, 'expressions' => [$elementType], 'nested' => true]));
            }
        }

        return $this->annotateByArgs($expression, ['expressions'], array: true);
    }
}
