<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;

/**
 * @mixin Identifier
 */
trait IdentifierBehavior
{
    public function quoted(): bool
    {
        return ($this->args['quoted'] ?? false) === true;
    }

    public function outputName(): string
    {
        return $this->name();
    }
}
