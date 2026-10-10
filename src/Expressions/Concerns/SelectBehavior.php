<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Errors\ParseError;
use LaravelSqlParser\LaravelSqlParser\Expressions\Aliases;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cluster;
use LaravelSqlParser\LaravelSqlParser\Expressions\Create;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Group;
use LaravelSqlParser\LaravelSqlParser\Expressions\Having;
use LaravelSqlParser\LaravelSqlParser\Expressions\Hint;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lateral;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lock;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Qualify;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sort;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;

/**
 * @mixin Select
 */
trait SelectBehavior
{
    public function isStar(): bool
    {
        foreach ($this->argList('expressions') as $projection) {
            if ($projection->isStar()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Expression>
     */
    public function selects(): array
    {
        return $this->argList('expressions');
    }

    /**
     * The output names of the projections that have one.
     *
     * @return list<string>
     */
    public function namedSelects(): array
    {
        $selects = [];

        foreach ($this->argList('expressions') as $e) {
            if ($e->aliasOrName() !== '') {
                $selects[] = $e->outputName();
            } elseif ($e instanceof Aliases) {
                foreach ($e->argList('expressions') as $alias) {
                    $selects[] = $alias->name();
                }
            }
        }

        return $selects;
    }

    /**
     * Set the FROM (sqlglot Select.from_).
     */
    public function from(mixed $expression, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        return Builder::applyBuilder($expression, $this, 'from_', $copy, 'FROM', From::class, $dialect);
    }

    /**
     * Append to or set the GROUP BY (sqlglot Select.group_by). Options: append, dialect, copy.
     */
    public function groupBy(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        if ($expressions === []) {
            return $options['copy'] === false ? $this : $this->copy();
        }

        return Builder::applyChildListBuilder($expressions, $this, 'group', Group::class, (bool) $options['append'], (bool) $options['copy'], 'GROUP BY', Builder::dialectOption($options));
    }

    /**
     * Append to or set the SORT BY (sqlglot Select.sort_by). Options: append, dialect, copy.
     */
    public function sortBy(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyChildListBuilder($expressions, $this, 'sort', Sort::class, (bool) $options['append'], (bool) $options['copy'], 'SORT BY', Builder::dialectOption($options));
    }

    /**
     * Append to or set the CLUSTER BY (sqlglot Select.cluster_by). Options: append, dialect, copy.
     */
    public function clusterBy(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyChildListBuilder($expressions, $this, 'cluster', Cluster::class, (bool) $options['append'], (bool) $options['copy'], 'CLUSTER BY', Builder::dialectOption($options));
    }

    /**
     * Append to or set the projections (sqlglot Select.select). Options: append, dialect, copy.
     */
    public function select(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyListBuilder($expressions, $this, 'expressions', (bool) $options['append'], (bool) $options['copy'], into: Expression::class, dialect: Builder::dialectOption($options));
    }

    /**
     * Append to or set the LATERAL VIEWs (sqlglot Select.lateral). Options: append, dialect, copy.
     */
    public function lateral(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyListBuilder($expressions, $this, 'laterals', (bool) $options['append'], (bool) $options['copy'], 'LATERAL VIEW', Lateral::class, Builder::dialectOption($options));
    }

    /**
     * Append to or set the joins (sqlglot Select.join).
     *
     * @param  mixed  $on  a condition or a list of conditions to AND
     * @param  mixed  $using  a column or a list of columns
     * @param  string|null  $joinType  e.g. 'left outer', 'cross'
     */
    public function join(mixed $expression, mixed $on = null, mixed $using = null, bool $append = true, ?string $joinType = null, Identifier|string|null $joinAlias = null, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        try {
            $expression = Builder::parseSql($expression, Join::class, $dialect, 'JOIN');
        } catch (ParseError) {
            $expression = Builder::parseSql($expression, [Join::class, Expression::class], $dialect);
        }

        $join = $expression instanceof Join ? $expression : new Join(['this' => $expression]);
        $joined = $join->args['this'] ?? null;

        if ($joined instanceof Select) {
            $joined->replace($joined->subquery());
        }

        if ($joinType !== null && $joinType !== '') {
            $newJoin = Builder::parseSql("FROM _ {$joinType} JOIN _", dialect: $dialect)->find(Join::class);

            if ($newJoin instanceof Join) {
                foreach (['method' => $newJoin->method(), 'side' => $newJoin->side(), 'kind' => $newJoin->kind()] as $key => $value) {
                    if ($value !== '') {
                        $join->set($key, $value);
                    }
                }
            }
        }

        if ($on !== null && $on !== '' && $on !== []) {
            $join->set('on', Builder::and(array_map(Builder::sqlArg(...), is_array($on) ? array_values($on) : [$on]), $dialect, $copy));
        }

        if ($using !== null && $using !== '' && $using !== []) {
            $join = Builder::applyListBuilder(is_array($using) ? array_values($using) : [$using], $join, 'using', $append, $copy, into: Identifier::class);
        }

        if ($joinAlias !== null && $joinAlias !== '') {
            $join->set('this', Builder::alias($join->args['this'], $joinAlias, table: true));
        }

        return Builder::applyListBuilder([$join], $this, 'joins', $append, $copy);
    }

    /**
     * AND conditions into the HAVING (sqlglot Select.having). Options: append, dialect, copy.
     */
    public function having(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyConjunctionBuilder($expressions, $this, 'having', Having::class, (bool) $options['append'], (bool) $options['copy'], Builder::dialectOption($options));
    }

    /**
     * Append to or set the named windows (sqlglot Select.window). Options: append, dialect, copy.
     */
    public function window(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyListBuilder($expressions, $this, 'windows', (bool) $options['append'], (bool) $options['copy'], into: Window::class, dialect: Builder::dialectOption($options));
    }

    /**
     * AND conditions into the QUALIFY (sqlglot Select.qualify). Options: append, dialect, copy.
     */
    public function qualify(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyConjunctionBuilder($expressions, $this, 'qualify', Qualify::class, (bool) $options['append'], (bool) $options['copy'], Builder::dialectOption($options));
    }

    /**
     * Set DISTINCT [ON (...)] (sqlglot Select.distinct). Options: distinct, copy.
     */
    public function distinct(mixed ...$args): static
    {
        [$ons, $options] = Builder::splitOptions($args, ['distinct' => true, 'copy' => true]);
        $copy = (bool) $options['copy'];
        $instance = $copy ? $this->copy() : $this;
        $on = null;

        if ($ons !== []) {
            $expressions = [];
            foreach ($ons as $expression) {
                if ($expression !== null && $expression !== '' && $expression !== false) {
                    $expressions[] = Builder::parseSql($expression, copy: $copy);
                }
            }
            $on = new Tuple(['expressions' => $expressions]);
        }

        $instance->set('distinct', $options['distinct'] === false ? null : new Distinct(['on' => $on]));

        return $instance;
    }

    /**
     * CREATE TABLE <table> AS <this select> (sqlglot Select.ctas).
     *
     * @param  array<string, mixed>|null  $properties
     */
    public function ctas(mixed $table, ?array $properties = null, string|Dialect|null $dialect = null, bool $copy = true): Create
    {
        $instance = $copy ? $this->copy() : $this;

        return new Create([
            'this' => Builder::parseSql($table, Table::class, $dialect),
            'kind' => 'TABLE',
            'expression' => $instance,
            'properties' => $properties !== null && $properties !== [] ? Properties::fromDict($properties) : null,
        ]);
    }

    /**
     * Set FOR UPDATE / FOR SHARE (sqlglot Select.lock).
     */
    public function lock(bool $update = true, bool $copy = true): static
    {
        $instance = $copy ? $this->copy() : $this;
        $instance->set('locks', [new Lock(['update' => $update])]);

        return $instance;
    }

    /**
     * Set optimizer hints (sqlglot Select.hint). Options: dialect, copy.
     */
    public function hint(mixed ...$args): static
    {
        [$hints, $options] = Builder::splitOptions($args, ['dialect' => null, 'copy' => true]);
        $copy = (bool) $options['copy'];
        $dialect = Builder::dialectOption($options);
        $instance = $copy ? $this->copy() : $this;
        $instance->set('hint', new Hint(['expressions' => array_map(static fn (mixed $h): Expression => Builder::parseSql($h, dialect: $dialect, copy: $copy), $hints)]));

        return $instance;
    }
}
