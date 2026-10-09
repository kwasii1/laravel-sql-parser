<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\DynamicIdentifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * @mixin DynamicIdentifier
 */
trait DynamicIdentifierBehavior
{
    public function name(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->name() : '';
    }
}
