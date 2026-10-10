<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Boolean;

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
