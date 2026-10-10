<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Paren;

/**
 * @mixin Paren
 */
trait ParenBehavior
{
    public function outputName(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->name() : '';
    }
}
