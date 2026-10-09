<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;

/**
 * Behavior of queries (sqlglot exp.Query): SELECT, set operations and subqueries.
 */
trait QueryBehavior
{
    /**
     * @return list<CTE>
     */
    public function ctes(): array
    {
        $with = $this->args['with_'] ?? null;

        if (! $with instanceof Expression) {
            return [];
        }

        return array_values(array_filter($with->argList('expressions'), static fn (Expression $e): bool => $e instanceof CTE));
    }

    /**
     * Wrap this query in a subquery (sqlglot Query.subquery).
     */
    public function subquery(Expression|Identifier|string|null $alias = null, bool $copy = true): Subquery
    {
        $instance = $copy ? $this->copy() : $this;

        if (! $alias instanceof Expression) {
            $alias = $alias !== null && $alias !== '' ? new TableAlias(['this' => Builder::toIdentifier($alias)]) : null;
        }

        return new Subquery(['this' => $instance, 'alias' => $alias]);
    }
}
