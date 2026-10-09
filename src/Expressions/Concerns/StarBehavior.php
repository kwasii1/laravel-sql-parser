<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Star;

/**
 * @mixin Star
 */
trait StarBehavior
{
    public function name(): string
    {
        return '*';
    }

    public function outputName(): string
    {
        return '*';
    }
}
