<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;

/**
 * @mixin SetOperation
 */
trait SetOperationBehavior
{
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
     * The leftmost query's projections.
     *
     * @return list<Expression>
     */
    public function selects(): array
    {
        $expr = $this->leftmostQuery();

        return $expr instanceof Query ? $expr->selects() : [];
    }

    /**
     * @return list<string>
     */
    public function namedSelects(): array
    {
        $expr = $this;

        while ($expr instanceof SetOperation) {
            if ($expr->flag('by_name')) {
                $left = ($expr->args['this'] ?? null)?->unnest();
                $right = ($expr->args['expression'] ?? null)?->unnest();

                return array_values(array_unique([
                    ...($left instanceof Query ? $left->namedSelects() : []),
                    ...($right instanceof Query ? $right->namedSelects() : []),
                ]));
            }

            $expr = ($expr->args['this'] ?? null)?->unnest();
        }

        // A set operation names its columns after its leftmost query's projections
        return $expr instanceof Query ? array_map(static fn (Expression $e): string => $e->outputName(), $expr->selects()) : [];
    }

    public function leftmostQuery(): ?Expression
    {
        $expr = $this;

        while ($expr instanceof SetOperation) {
            $expr = ($expr->args['this'] ?? null)?->unnest();
        }

        return $expr;
    }

    public function kind(): string
    {
        return strtoupper($this->text('kind'));
    }

    public function side(): string
    {
        return strtoupper($this->text('side'));
    }
}
