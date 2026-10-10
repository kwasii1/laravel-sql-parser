<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Agg;
use Kwasii\LaravelSqlParser\Expressions\Expression;

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
