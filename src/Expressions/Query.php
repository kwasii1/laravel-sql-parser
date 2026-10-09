<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

/**
 * Marks queries (SELECT, set operations and subqueries).
 *
 * sqlglot: exp.Query (a mixin; PHP models it as an interface, implemented by Concerns\QueryBehavior).
 */
interface Query extends Selectable
{
    /**
     * @return list<CTE>
     */
    public function ctes(): array;

    /**
     * Wrap this query in a subquery, optionally aliased.
     */
    public function subquery(Expression|Identifier|string|null $alias = null, bool $copy = true): Subquery;

    /**
     * @return list<Expression>
     */
    public function selects(): array;

    /**
     * @return list<string>
     */
    public function namedSelects(): array;
}
