<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * Behavior of binary operators (sqlglot exp.Binary).
 */
trait BinaryBehavior
{
    public function left(): ?Expression
    {
        $left = $this->args['this'] ?? null;

        return $left instanceof Expression ? $left : null;
    }

    public function right(): ?Expression
    {
        $right = $this->args['expression'] ?? null;

        return $right instanceof Expression ? $right : null;
    }
}
