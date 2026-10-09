<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Placeholder;

/**
 * @mixin Placeholder
 */
trait PlaceholderBehavior
{
    public function name(): string
    {
        $name = $this->text('this');

        return $name !== '' ? $name : '?';
    }
}
