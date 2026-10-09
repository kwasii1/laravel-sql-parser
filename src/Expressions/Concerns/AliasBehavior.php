<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;

/**
 * @mixin Alias
 */
trait AliasBehavior
{
    public function outputName(): string
    {
        return $this->alias();
    }
}
