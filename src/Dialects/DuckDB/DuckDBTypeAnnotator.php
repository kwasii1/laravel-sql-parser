<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB;

use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Optimizer\TypeAnnotator;

/**
 * DuckDB type inference (sqlglot typing/duckdb.py).
 */
class DuckDBTypeAnnotator extends TypeAnnotator
{
    private const array DATETIME_WITHOUT_TZ = [
        DType::DATE, DType::TIMESTAMP, DType::TIMESTAMPNTZ, DType::TIMESTAMP_S, DType::TIMESTAMP_MS, DType::TIMESTAMP_NS,
    ];

    protected function annotateAtTimeZone(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;

        return match (true) {
            $this_ instanceof Expression && $this_->isType(...self::DATETIME_WITHOUT_TZ) => $this->setType($expression, DType::TIMESTAMPTZ),
            $this_ instanceof Expression && $this_->isType(DType::TIMESTAMPTZ) => $this->setType($expression, DType::TIMESTAMP),
            $this_ instanceof Expression && $this_->isType(DType::TIMETZ) => $this->setType($expression, DType::TIMETZ),
            default => $this->setType($expression, DType::UNKNOWN),
        };
    }

    protected function annotateDateTrunc(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Expression && $this_->isType(DType::DATE, DType::TIMESTAMP_S, DType::TIMESTAMP_MS, DType::TIMESTAMP_NS)) {
            return $this->setType($expression, DType::TIMESTAMP);
        }

        return $this->setType($expression, $this_ instanceof Expression ? $this_->type() : null);
    }
}
