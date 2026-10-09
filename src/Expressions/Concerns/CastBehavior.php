<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * @mixin Cast
 */
trait CastBehavior
{
    public function name(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->name() : '';
    }

    public function outputName(): string
    {
        return $this->name();
    }
}
