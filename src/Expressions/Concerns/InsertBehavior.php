<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Insert;

/**
 * @mixin Insert
 */
trait InsertBehavior
{
    /**
     * Add a common table expression (sqlglot Insert.with_).
     */
    public function with(mixed $alias, mixed $as, ?bool $recursive = null, ?bool $materialized = null, bool $append = true, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        return Builder::applyCteBuilder($this, $alias, $as, $recursive, $materialized, $append, $dialect, $copy);
    }
}
