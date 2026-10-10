<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Support\Scope;

/**
 * Remove unused CTEs (sqlglot optimizer/eliminate_ctes.py).
 */
final class EliminateCtes
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @param  mixed  $journal  accepted for signature parity with sqlglot; mutations aren't journaled
     * @return T
     */
    public static function eliminateCtes(Expression $expression, mixed $journal = null): Expression
    {
        $root = Scope::build($expression);

        if ($root === null) {
            return $expression;
        }

        $refCount = $root->refCount();

        // Traverse the scope tree in reverse so we can remove chains of unused CTEs
        foreach (array_reverse($root->traverseTree()) as $scope) {
            if (! $scope->isCte() || ($refCount[spl_object_id($scope)] ?? 0) > 0) {
                continue;
            }

            $cteNode = $scope->expression->parent;

            if ($cteNode === null) {
                continue;
            }

            $withNode = $cteNode->parent;
            $cteNode->pop();

            // Pop the entire WITH clause if this is the last CTE
            if ($withNode !== null && $withNode->argList('expressions') === []) {
                $withNode->pop();
            }

            // Decrement the ref count for all sources this CTE selects from
            foreach ($scope->selectedSources() as [, $source]) {
                if ($source instanceof Scope) {
                    $id = spl_object_id($source);
                    $refCount[$id] = ($refCount[$id] ?? 0) - 1;
                }
            }
        }

        return $expression;
    }
}
