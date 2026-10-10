<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Star;

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
