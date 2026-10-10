<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\DataTypeParam;
use Kwasii\LaravelSqlParser\Expressions\Expression;

/**
 * @mixin DataTypeParam
 */
trait DataTypeParamBehavior
{
    public function name(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->name() : '';
    }
}
