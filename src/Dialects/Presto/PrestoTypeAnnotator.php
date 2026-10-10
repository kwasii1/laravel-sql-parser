<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Presto;

use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Optimizer\TypeAnnotator;

/**
 * Presto type inference (sqlglot typing/presto.py).
 */
class PrestoTypeAnnotator extends TypeAnnotator
{
    protected function annotateRand(Expression $expression): Expression
    {
        if ($expression->argExpr('this') instanceof Expression) {
            return $this->annotateByArgs($expression, ['this']);
        }

        return $this->setType($expression, DType::DOUBLE);
    }
}
