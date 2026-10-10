<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\NullExpr;

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
