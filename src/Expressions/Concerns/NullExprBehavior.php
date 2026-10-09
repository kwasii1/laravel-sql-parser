<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;

/**
 * @mixin NullExpr
 */
trait NullExprBehavior
{
    public function name(): string
    {
        return 'NULL';
    }

    public function toPhp(): mixed
    {
        return null;
    }
}
