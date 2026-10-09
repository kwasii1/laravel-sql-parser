<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;

/**
 * @mixin From
 */
trait FromBehavior
{
    public function name(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->name() : '';
    }

    public function aliasOrName(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->aliasOrName() : '';
    }
}
