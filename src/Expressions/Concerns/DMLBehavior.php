<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Returning;

/**
 * Builders shared by DML statements (sqlglot exp.DML).
 *
 * @phpstan-require-extends Expression
 */
trait DMLBehavior
{
    /**
     * Set the RETURNING clause (sqlglot DML.returning).
     */
    public function returning(mixed $expression, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        return Builder::applyBuilder($expression, $this, 'returning', $copy, 'RETURNING', Returning::class, $dialect);
    }
}
