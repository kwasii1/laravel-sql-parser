<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\TableColumn;

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
