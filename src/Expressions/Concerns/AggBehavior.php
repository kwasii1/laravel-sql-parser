<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Agg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * @mixin Agg
 */
trait AggBehavior
{
    public function outputName(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->name() : '';
    }
}
