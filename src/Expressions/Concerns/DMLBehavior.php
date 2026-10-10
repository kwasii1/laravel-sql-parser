<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Returning;

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
