<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Delete;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;

/**
 * @mixin Delete
 */
trait DeleteBehavior
{
    /**
     * Set the table to delete from (sqlglot Delete.delete).
     */
    public function delete(mixed $table, string|Dialect|null $dialect = null, bool $copy = true): static
    {
        return Builder::applyBuilder($table, $this, 'this', $copy, null, Table::class, $dialect);
    }

    /**
     * AND conditions into the WHERE (sqlglot Delete.where). Options: append, dialect, copy.
     */
    public function where(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);

        return Builder::applyConjunctionBuilder($expressions, $this, 'where', Where::class, (bool) $options['append'], (bool) $options['copy'], Builder::dialectOption($options));
    }
}
