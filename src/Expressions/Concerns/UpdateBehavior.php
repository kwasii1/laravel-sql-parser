<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Update;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;

/**
 * @mixin Update
 */
trait UpdateBehavior
{
    /**
     * Set the table to update (sqlglot Update.table).
     */
    public function table(mixed $expression, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        return Builder::applyBuilder($expression, $this, 'this', $copy, null, Table::class, $dialect);
    }

    /**
     * Append to or set the SET assignments, e.g. 'x = 1' (sqlglot Update.set_; `set` is taken by
     * Expression::set). Options: append, dialect, copy.
     */
    public function assign(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyListBuilder($expressions, $this, 'expressions', (bool) $options['append'], (bool) $options['copy'], into: Expression::class, dialect: Builder::dialectOption($options));
    }

    /**
     * AND conditions into the WHERE (sqlglot Update.where). Options: append, dialect, copy.
     */
    public function where(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyConjunctionBuilder($expressions, $this, 'where', Where::class, (bool) $options['append'], (bool) $options['copy'], Builder::dialectOption($options));
    }

    /**
     * Set the FROM (sqlglot Update.from_).
     */
    public function from(mixed $expression = null, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        if ($expression === null || $expression === '' || $expression === false) {
            return $copy ? $this->copy() : $this;
        }

        return Builder::applyBuilder($expression, $this, 'from_', $copy, 'FROM', From::class, $dialect);
    }

    /**
     * Add a common table expression (sqlglot Update.with_).
     */
    public function with(mixed $alias, mixed $as, ?bool $recursive = null, ?bool $materialized = null, bool $append = true, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        return Builder::applyCteBuilder($this, $alias, $as, $recursive, $materialized, $append, $dialect, $copy);
    }
}
