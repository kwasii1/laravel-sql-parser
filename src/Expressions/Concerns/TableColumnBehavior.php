<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\TableColumn;

/**
 * @mixin TableColumn
 */
trait TableColumnBehavior
{
    public function outputName(): string
    {
        return $this->name();
    }
}
