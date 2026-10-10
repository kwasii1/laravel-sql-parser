<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Except;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Intersect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Offset;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Union;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;

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

    /**
     * Set the LIMIT (sqlglot Query.limit). Expressions that aren't a Limit are wrapped in one.
     */
    public function limit(mixed $expression, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        return Builder::applyBuilder($expression, $this, 'limit', $copy, 'LIMIT', Limit::class, $dialect, 'expression');
    }

    /**
     * Set the OFFSET (sqlglot Query.offset).
     */
    public function offset(mixed $expression, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        return Builder::applyBuilder($expression, $this, 'offset', $copy, 'OFFSET', Offset::class, $dialect, 'expression');
    }

    /**
     * Append to or set the ORDER BY (sqlglot Query.order_by). Options: append, dialect, copy.
     */
    public function orderBy(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyChildListBuilder($expressions, $this, 'order', Order::class, (bool) $options['append'], (bool) $options['copy'], 'ORDER BY', Builder::dialectOption($options));
    }

    /**
     * AND conditions into the WHERE (sqlglot Query.where). Options: append, dialect, copy.
     */
    public function where(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);
        $expressions = array_map(static fn (mixed $e): mixed => $e instanceof Where ? $e->args['this'] ?? null : $e, $expressions);

        return Builder::applyConjunctionBuilder($expressions, $this, 'where', Where::class, (bool) $options['append'], (bool) $options['copy'], Builder::dialectOption($options));
    }

    /**
     * Add a common table expression (sqlglot Query.with_).
     */
    public function with(mixed $alias, mixed $as, ?bool $recursive = null, ?bool $materialized = null, bool $append = true, string|Dialect|null $dialect = null, bool $copy = true, ?bool $scalar = null): static
    {
        return Builder::applyCteBuilder($this, $alias, $as, $recursive, $materialized, $append, $dialect, $copy, $scalar);
    }

    /**
     * UNION this query with others (sqlglot Query.union). Options: distinct, dialect, copy.
     */
    public function union(mixed ...$args): Union
    {
        return Builder::union($this, ...$args);
    }

    /**
     * INTERSECT this query with others (sqlglot Query.intersect). Options: distinct, dialect, copy.
     */
    public function intersect(mixed ...$args): Intersect
    {
        return Builder::intersect($this, ...$args);
    }

    /**
     * EXCEPT others from this query (sqlglot Query.except_). Options: distinct, dialect, copy.
     */
    public function except(mixed ...$args): Except
    {
        return Builder::except($this, ...$args);
    }
}
