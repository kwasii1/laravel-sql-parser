<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

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

    /**
     * Append to or set the projections. Options: append, dialect, copy.
     */
    public function select(mixed ...$args): static;

    public function limit(mixed $expression, string|Dialect|null $dialect = null, bool $copy = true): static;

    public function offset(mixed $expression, string|Dialect|null $dialect = null, bool $copy = true): static;

    public function orderBy(mixed ...$args): static;

    public function where(mixed ...$args): static;

    public function with(mixed $alias, mixed $as, ?bool $recursive = null, ?bool $materialized = null, bool $append = true, string|Dialect|null $dialect = null, bool $copy = true, ?bool $scalar = null): static;

    public function union(mixed ...$args): Union;

    public function intersect(mixed ...$args): Intersect;

    public function except(mixed ...$args): Except;
}
