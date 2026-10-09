<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;

/**
 * @mixin Boolean
 */
trait BooleanBehavior
{
    public function toPhp(): bool
    {
        return ($this->args['this'] ?? false) === true;
    }
}
