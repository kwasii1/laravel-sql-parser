<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Identifier;

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
