<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Kwasii\LaravelSqlParser\Expressions\AggFunc;
use Kwasii\LaravelSqlParser\Expressions\AndExpr;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Join;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Support\Scope;

/**
 * Remove unused joins that can't produce duplicate rows (sqlglot optimizer/eliminate_joins.py).
 */
final class EliminateJoins
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function eliminateJoins(Expression $expression): Expression
    {
        foreach (Scope::traverse($expression) as $scope) {
            /** @var list<Join> $joins */
            $joins = $scope->expression->argList('joins');

            // If any columns in this scope aren't qualified, it's hard to determine if a join isn't used
            if ($joins === [] || $scope->unqualifiedColumns() !== []) {
                continue;
            }

            // Reverse the joins so we can remove chains of unused joins
            foreach (array_reverse($joins) as $join) {
                if ($join->isSemiOrAntiJoin()) {
                    continue;
                }

                $alias = $join->aliasOrName();

                if (self::shouldEliminateJoin($scope, $join, $alias)) {
                    $join->pop();
                    $scope->removeSource($alias);
                }
            }
        }

        return $expression;
    }

    private static function shouldEliminateJoin(Scope $scope, Join $join, string $alias): bool
    {
        $inner = $scope->sources[$alias] ?? null;

        return $inner instanceof Scope
            && ! self::joinIsUsed($scope, $join, $alias)
            && (
                ($join->side() === 'LEFT' && self::isJoinedOnAllUniqueOutputs($inner, $join))
                || (($join->args['on'] ?? null) === null && self::hasSingleOutputRow($inner))
            );
    }

    private static function joinIsUsed(Scope $scope, Join $join, string $alias): bool
    {
        // Columns in the ON clause don't count
        $onColumns = [];
        $on = $join->args['on'] ?? null;

        if ($on instanceof Expression) {
            foreach ($on->findAll(Column::class) as $column) {
                $onColumns[spl_object_id($column)] = true;
            }
        }

        foreach ($scope->sourceColumns($alias) as $column) {
            if (! isset($onColumns[spl_object_id($column)])) {
                return true;
            }
        }

        return false;
    }

    private static function isJoinedOnAllUniqueOutputs(Scope $scope, Join $join): bool
    {
        $uniqueOutputs = self::uniqueOutputs($scope);

        if ($uniqueOutputs === []) {
            return false;
        }

        [, $joinKeys] = self::joinCondition($join);

        return array_diff($uniqueOutputs, array_map(static fn (Expression $c): string => $c->name(), $joinKeys)) === [];
    }

    /**
     * Output columns of a scope that must have a unique combination per row.
     *
     * @return list<string>
     */
    private static function uniqueOutputs(Scope $scope): array
    {
        $expr = $scope->expression;

        if (! $expr instanceof Query) {
            return [];
        }

        if (($expr->args['distinct'] ?? null) !== null) {
            return $expr->namedSelects();
        }

        $group = $expr->args['group'] ?? null;

        if ($group instanceof Expression) {
            $grouped = [];
            foreach ($group->argList('expressions') as $e) {
                $grouped[$e::class.'|'.$e->hashKey()] = true;
            }

            $groupedOutputs = [];
            $uniqueOutputs = [];

            foreach ($expr->selects() as $select) {
                $output = $select->unalias();
                $key = $output::class.'|'.$output->hashKey();

                if (isset($grouped[$key])) {
                    $groupedOutputs[$key] = true;
                    $uniqueOutputs[$select->aliasOrName()] = true;
                }
            }

            // All the grouped expressions must be in the output
            return array_diff_key($grouped, $groupedOutputs) === [] ? array_map(strval(...), array_keys($uniqueOutputs)) : [];
        }

        return self::hasSingleOutputRow($scope) ? $expr->namedSelects() : [];
    }

    private static function hasSingleOutputRow(Scope $scope): bool
    {
        $expr = $scope->expression;

        if (! $expr instanceof Select) {
            return false;
        }

        foreach ($expr->selects() as $e) {
            if (! $e->unalias() instanceof AggFunc) {
                return self::isLimit1($scope) || ($expr->args['from_'] ?? null) === null;
            }
        }

        return true;
    }

    private static function isLimit1(Scope $scope): bool
    {
        $limit = $scope->expression->args['limit'] ?? null;
        $value = $limit instanceof Expression ? ($limit->args['expression'] ?? null) : null;

        return $value instanceof Expression && ($value->args['this'] ?? null) === '1';
    }

    /**
     * Extract the join condition from a join: (source keys, join keys, remaining predicate).
     *
     * @return array{0: list<Expression>, 1: list<Expression>, 2: Expression}
     */
    public static function joinCondition(Join $join): array
    {
        $name = $join->aliasOrName();
        $on = $join->args['on'] ?? null;
        $on = $on instanceof Expression ? $on->copy() : Builder::true();
        $sourceKey = [];
        $joinKey = [];

        $extract = static function (Expression $condition) use ($name, &$sourceKey, &$joinKey): void {
            [$left, $right] = $condition->unnestOperands();

            $leftTables = Builder::columnTableNames($left);
            $rightTables = Builder::columnTableNames($right);
            $inLeft = in_array($name, $leftTables, true);
            $inRight = in_array($name, $rightTables, true);

            if ($inLeft && ! $inRight) {
                $joinKey[] = $left;
                $sourceKey[] = $right;
                $condition->replace(Builder::true());
            } elseif ($inRight && ! $inLeft) {
                $joinKey[] = $right;
                $sourceKey[] = $left;
                $condition->replace(Builder::true());
            }
        };

        // SELECT FROM x JOIN y ON x.a = y.b AND y.b > 1 pulls y.b as the join key and x.a as the source key
        if (Normalize::normalized($on)) {
            $on = $on instanceof AndExpr ? $on : Builder::and([$on, Builder::true()], copy: false);

            foreach ($on->flatten() as $condition) {
                if ($condition instanceof EQ) {
                    $extract($condition);
                }
            }
        } elseif (Normalize::normalized($on, dnf: true)) {
            /** @var list<Expression> $conditions */
            $conditions = [];

            foreach ($on->flatten() as $condition) {
                $parts = array_values(array_filter($condition->flatten(), static fn (Expression $part): bool => $part instanceof EQ));

                if ($conditions === []) {
                    $conditions = $parts;
                } else {
                    $temp = [];
                    foreach ($parts as $p) {
                        $cs = array_values(array_filter($conditions, static fn (Expression $c): bool => $p->equals($c)));

                        if ($cs !== []) {
                            $temp[] = $p;
                            array_push($temp, ...$cs);
                        }
                    }
                    $conditions = $temp;
                }
            }

            foreach ($conditions as $condition) {
                $extract($condition);
            }
        }

        return [$sourceKey, $joinKey, $on];
    }
}
