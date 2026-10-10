<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Presto;

use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Optimizer\TypeAnnotator;

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
