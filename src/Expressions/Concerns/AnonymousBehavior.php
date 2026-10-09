<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * @mixin Anonymous
 */
trait AnonymousBehavior
{
    public function name(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->name() : (is_string($this_) ? $this_ : '');
    }
}
