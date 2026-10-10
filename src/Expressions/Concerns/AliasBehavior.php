<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Alias;

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
