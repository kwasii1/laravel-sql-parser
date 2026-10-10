<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\DDL;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lateral;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\With;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;

/**
 * Rewrite derived tables as CTEs, deduplicating if possible (sqlglot optimizer/eliminate_subqueries.py).
 */
final class EliminateSubqueries
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function eliminateSubqueries(Expression $expression): Expression
    {
        if ($expression instanceof Subquery) {
            // It's possible to have subqueries at the root, e.g. (SELECT * FROM x) LIMIT 1
            $inner = $expression->args['this'] ?? null;

            if ($inner instanceof Expression) {
                self::eliminateSubqueries($inner);
            }

            return $expression;
        }

        $root = Scope::build($expression);

        if ($root === null) {
            return $expression;
        }

        // Aliases already used in the expression, which new CTEs mustn't conflict with
        /** @var array<string, Scope|Expression> $taken */
        $taken = [];

        foreach ($root->cteScopes as $scope) {
            $parent = $scope->expression->parent;

            if ($parent !== null) {
                $taken[$parent->alias()] = $scope;
            }
        }

        foreach ($root->traverseTree() as $scope) {
            foreach ($scope->sources as $source) {
                if ($source instanceof Table) {
                    $taken[$source->name()] = $source;
                }
            }
        }

        // Existing CTEs in the root expression, keyed by their query, for deduplication
        /** @var array<string, string> $existingCtes */
        $existingCtes = [];

        $with = $root->expression->args['with_'] ?? null;
        $recursive = false;

        if ($with instanceof With) {
            $recursive = $with->args['recursive'] ?? null;

            foreach ($with->argList('expressions') as $cte) {
                $this_ = $cte->args['this'] ?? null;

                if ($this_ instanceof Expression) {
                    $existingCtes[self::key($this_)] = $cte->alias();
                }
            }
        }

        $newCtes = [];

        // Derived tables within an existing CTE need to come before the existing CTE (DAG order)
        foreach ($root->cteScopes as $cteScope) {
            foreach ($cteScope->traverseTree() as $scope) {
                if ($scope === $cteScope) {
                    continue;
                }

                $newCte = self::eliminate($scope, $existingCtes, $taken);

                if ($newCte !== null) {
                    $newCtes[] = $newCte;
                }
            }

            $cteParent = $cteScope->expression->parent;

            if ($cteParent !== null) {
                $newCtes[] = $cteParent;
            }
        }

        foreach ([...$root->setOperationScopes, ...$root->subqueryScopes, ...$root->tableScopes] as $scope) {
            foreach ($scope->traverseTree() as $childScope) {
                $newCte = self::eliminate($childScope, $existingCtes, $taken);

                if ($newCte !== null) {
                    $newCtes[] = $newCte;
                }
            }
        }

        if ($newCtes !== []) {
            $query = $expression instanceof DDL ? $expression->args['expression'] ?? null : $expression;

            if (! $query instanceof Query || ! $query instanceof Expression) {
                // DMLs shouldn't hold the WITH clause; attach it to the root query
                $query = $root->expression->unnest();
            }

            $query->set('with_', new With(['expressions' => $newCtes, 'recursive' => $recursive]));
        }

        return $expression;
    }

    private static function key(Expression $expression): string
    {
        return $expression::class.'|'.$expression->hashKey();
    }

    /**
     * @param  array<string, string>  $existingCtes
     * @param  array<string, Scope|Expression>  $taken
     */
    private static function eliminate(Scope $scope, array &$existingCtes, array &$taken): ?Expression
    {
        if ($scope->isDerivedTableScope()) {
            return self::eliminateDerivedTable($scope, $existingCtes, $taken);
        }

        if ($scope->isCte()) {
            return self::eliminateCte($scope, $existingCtes, $taken);
        }

        return null;
    }

    /**
     * @param  array<string, string>  $existingCtes
     * @param  array<string, Scope|Expression>  $taken
     */
    private static function eliminateDerivedTable(Scope $scope, array &$existingCtes, array &$taken): ?Expression
    {
        // Don't drop the "pivot" arg of a pivoted subquery or eliminate a lateral correlated subquery
        $parentScope = $scope->parent;

        if ($parentScope === null || $parentScope->pivots() !== [] || $parentScope->expression instanceof Lateral) {
            return null;
        }

        $exprParent = $scope->expression->parent;

        if (! $exprParent instanceof Subquery || $exprParent === $parentScope->expression) {
            return null;
        }

        // Get rid of redundant Subquery wrappers
        $toReplace = $exprParent->unwrap();
        [$name, $cte] = self::newCte($scope, $existingCtes, $taken);
        $alias = $toReplace->alias();
        $table = Builder::alias(Builder::table($name), $alias !== '' ? $alias : $name);
        $table->set('joins', $toReplace->args['joins'] ?? null);

        $toReplace->replace($table);

        return $cte;
    }

    /**
     * @param  array<string, string>  $existingCtes
     * @param  array<string, Scope|Expression>  $taken
     */
    private static function eliminateCte(Scope $scope, array &$existingCtes, array &$taken): ?Expression
    {
        $parent = $scope->expression->parent;

        if ($parent === null) {
            return null;
        }

        [$name, $cte] = self::newCte($scope, $existingCtes, $taken);

        $with = $parent->parent;
        $parent->pop();

        if ($with !== null && $with->argList('expressions') === []) {
            $with->pop();
        }

        // Rename references to this CTE
        if ($scope->parent === null) {
            return $cte;
        }

        foreach ($scope->parent->traverseTree() as $childScope) {
            foreach ($childScope->selectedSources() as [$table, $source]) {
                if ($source === $scope) {
                    $table->replace(Builder::alias(Builder::table($name), $table->aliasOrName(), copy: false));
                }
            }
        }

        return $cte;
    }

    /**
     * A new name for this CTE in the root scope, and a new CTE (null when it duplicates an existing one).
     *
     * @param  array<string, string>  $existingCtes
     * @param  array<string, Scope|Expression>  $taken
     * @return array{0: string, 1: CTE|null}
     */
    private static function newCte(Scope $scope, array &$existingCtes, array &$taken): array
    {
        $duplicateAlias = $existingCtes[self::key($scope->expression)] ?? '';
        $parent = $scope->expression->parent;
        $name = $parent !== null ? $parent->alias() : '';

        if ($name === '') {
            $name = self::findNewName($taken, 'cte');
        }

        if ($duplicateAlias !== '') {
            $name = $duplicateAlias;
        } elseif (isset($taken[$name])) {
            $name = self::findNewName($taken, $name);
        }

        $taken[$name] = $scope;

        if ($duplicateAlias !== '') {
            return [$name, null];
        }

        $existingCtes[self::key($scope->expression)] = $name;

        return [$name, new CTE([
            'this' => $scope->expression,
            'alias' => new TableAlias(['this' => Builder::toIdentifier($name)]),
        ])];
    }

    /**
     * @param  array<string, mixed>  $taken
     */
    private static function findNewName(array $taken, string $base): string
    {
        if (! array_key_exists($base, $taken)) {
            return $base;
        }

        $i = 2;

        while (array_key_exists("{$base}_{$i}", $taken)) {
            $i++;
        }

        return "{$base}_{$i}";
    }
}
