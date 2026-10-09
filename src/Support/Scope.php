<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Support;

use Closure;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\UDTF;

/**
 * Scope-bounded traversal (sqlglot optimizer/scope.py walk_in_scope / find_all_in_scope): visits a
 * query's nodes without descending into the nodes that start child scopes (CTEs, derived tables,
 * subqueries).
 */
final class Scope
{
    /**
     * @param  (Closure(Expression): bool)|null  $prune
     * @return list<Expression>
     */
    public static function walkInScope(Expression $expression, ?Closure $prune = null): array
    {
        $nodes = [];
        $stack = [$expression];

        while ($stack !== []) {
            $node = array_pop($stack);
            $nodes[] = $node;

            // Only CTEs and Queries can start child scopes
            if (
                $node !== $expression
                && ($node instanceof CTE || $node instanceof Query)
                && (
                    $node instanceof CTE
                    || (($node->parent instanceof From || $node->parent instanceof Join) && self::isDerivedTable($node))
                    || $node->parent instanceof UDTF
                    || $node instanceof Select
                    || $node instanceof SetOperation
                )
            ) {
                if ($node instanceof Subquery || $node instanceof UDTF) {
                    foreach (['joins', 'laterals', 'pivots'] as $key) {
                        foreach ($node->argList($key) as $arg) {
                            array_push($nodes, ...self::walkInScope($arg));
                        }
                    }
                }

                continue;
            }

            if ($prune !== null && $prune($node)) {
                continue;
            }

            foreach (array_reverse($node->args) as $value) {
                if (is_array($value)) {
                    for ($i = count($value) - 1; $i >= 0; $i--) {
                        if (($value[$i] ?? null) instanceof Expression) {
                            $stack[] = $value[$i];
                        }
                    }
                } elseif ($value instanceof Expression) {
                    $stack[] = $value;
                }
            }
        }

        return $nodes;
    }

    /**
     * @template T of Expression
     *
     * @param  class-string<T>  ...$types
     * @return list<T>
     */
    public static function findAllInScope(Expression $expression, string ...$types): array
    {
        $found = [];

        foreach (self::walkInScope($expression) as $node) {
            foreach ($types as $type) {
                if ($node instanceof $type) {
                    $found[] = $node;

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * (tbl1 JOIN tbl2) is a Subquery but not a derived table, unless it's aliased.
     */
    public static function isDerivedTable(Expression $expression): bool
    {
        return $expression instanceof Subquery
            && ($expression->alias() !== '' || $expression->args['this'] instanceof Select || $expression->args['this'] instanceof SetOperation);
    }
}
