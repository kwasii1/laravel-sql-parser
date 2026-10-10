<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\SetOperation;
use Kwasii\LaravelSqlParser\Expressions\Subquery;

/**
 * @mixin Subquery
 */
trait SubqueryBehavior
{
    /**
     * Strip all wrapping subqueries.
     */
    public function unnest(): Expression
    {
        return self::unnestSubqueries($this);
    }

    /**
     * The loop lives in a static method: starting it from $this trips a type inference warning in
     * PHP's optimizer when the class is preloaded.
     */
    private static function unnestSubqueries(Expression $expression): Expression
    {
        while ($expression instanceof Subquery && ($expression->args['this'] ?? null) instanceof Expression) {
            $expression = $expression->args['this'];
        }

        return $expression;
    }

    public function unwrap(): Subquery
    {
        $expression = $this;

        while ($expression->sameParent() && $expression->isWrapper() && $expression->parent instanceof Subquery) {
            $expression = $expression->parent;
        }

        return $expression;
    }

    /**
     * Whether this subquery only wraps another query (it has no alias, modifiers, ...).
     */
    public function isWrapper(): bool
    {
        foreach ($this->args as $key => $value) {
            if ($key !== 'this' && $value !== null) {
                return false;
            }
        }

        return true;
    }

    public function outputName(): string
    {
        return $this->alias();
    }

    /**
     * Whether the query projects a star (sqlglot _is_star).
     */
    public function isStar(): bool
    {
        $stack = [$this];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node instanceof SetOperation) {
                $stack[] = $node->args['this'] ?? null;
                $stack[] = $node->args['expression'] ?? null;
            } elseif ($node instanceof Subquery) {
                $stack[] = $node->args['this'] ?? null;
            } elseif ($node instanceof Expression && $node->isStar()) {
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
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Query ? $this_->selects() : [];
    }

    /**
     * @return list<string>
     */
    public function namedSelects(): array
    {
        return array_map(static fn (Expression $e): string => $e->outputName(), $this->selects());
    }

    /**
     * Append to or set the projections of the wrapped query (sqlglot Subquery.select).
     * Options: append, dialect, copy.
     */
    public function select(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);
        $instance = $options['copy'] === false ? $this : $this->copy();
        $inner = $instance->unnest();

        if ($inner instanceof Query) {
            $inner->select(...$expressions, append: (bool) $options['append'], dialect: Builder::dialectOption($options), copy: false);
        }

        return $instance;
    }
}
