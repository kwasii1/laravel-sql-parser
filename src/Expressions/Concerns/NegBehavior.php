<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Neg;

/**
 * @mixin Neg
 */
trait NegBehavior
{
    public function toPhp(): mixed
    {
        $this_ = $this->args['this'] ?? null;

        if ($this->isNumber() && $this_ instanceof Expression) {
            $value = $this_->toPhp();

            return is_int($value) ? -$value : '-'.$value;
        }

        return parent::toPhp();
    }
}
