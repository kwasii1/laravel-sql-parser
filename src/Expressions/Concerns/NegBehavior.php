<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Neg;

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
