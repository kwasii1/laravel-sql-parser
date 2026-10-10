<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Spark2;

use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Optimizer\TypeAnnotator;

/**
 * Spark 2 type inference (sqlglot typing/spark2.py), shared by Spark and Databricks.
 */
class Spark2TypeAnnotator extends TypeAnnotator
{
    protected function annotateApproxQuantile(Expression $expression): Expression
    {
        $quantile = $expression->args['quantile'] ?? null;

        return $this->annotateByArgs($expression, ['this'], array: $quantile instanceof Expression && $quantile->isType(DType::ARRAY));
    }

    protected function annotateConcat(Expression $expression): Expression
    {
        return $this->annotateBySimilarArgs($expression, 'expressions');
    }

    protected function annotatePad(Expression $expression): Expression
    {
        return $this->annotateBySimilarArgs($expression, 'this', 'fill_pattern');
    }

    /**
     * sqlglot _annotate_by_similar_args: type inference for CONCAT-family expressions.
     * All-BINARY is BINARY; otherwise any known, non-array, non-binary arg makes it STRING.
     */
    protected function annotateBySimilarArgs(Expression $expression, string ...$argKeys): Expression
    {
        $argExprs = [];

        foreach ($argKeys as $key) {
            $value = $expression->args[$key] ?? null;

            foreach (is_array($value) ? $value : [$value] as $e) {
                if ($e instanceof Expression) {
                    $argExprs[] = $e;
                }
            }
        }

        $allBinary = $argExprs !== [];
        $anyKnown = false;

        foreach ($argExprs as $e) {
            if (! $e->isType(DType::BINARY)) {
                $allBinary = false;
            }

            if ($e->type() !== null && ! $e->isType(DType::UNKNOWN, DType::ARRAY, DType::BINARY)) {
                $anyKnown = true;
            }
        }

        $result = $allBinary ? DType::BINARY : ($anyKnown ? DType::TEXT : DType::UNKNOWN);

        return $this->setType($expression, $result);
    }
}
