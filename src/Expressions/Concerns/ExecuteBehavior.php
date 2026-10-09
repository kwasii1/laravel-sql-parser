<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Execute;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * @mixin Execute
 */
trait ExecuteBehavior
{
    public function name(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->name() : '';
    }
}
