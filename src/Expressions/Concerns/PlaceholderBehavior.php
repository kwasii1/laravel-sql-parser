<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Placeholder;

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
