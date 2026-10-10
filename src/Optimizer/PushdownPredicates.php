<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Dialects\Presto\Presto;
use LaravelSqlParser\LaravelSqlParser\Expressions\AggFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\AndExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\OrExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Predicate;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;
use LaravelSqlParser\LaravelSqlParser\Expressions\With;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;
use RuntimeException;

/**
 * Push predicates down into FROMs and JOINs (sqlglot optimizer/pushdown_predicates.py).
 *
 * @phpstan-type Sources array<string, array{0: Expression, 1: Table|Scope}>
 */
final class PushdownPredicates
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function pushdownPredicates(Expression $expression, string|Dialect|null $dialect = null): Expression
    {
        $root = Scope::build($expression);
        $dialect = Dialect::get($dialect);
        // Presto, Trino and Athena don't support inner joins where the RHS is an UNNEST expression
        $unnestRequiresCrossJoin = $dialect instanceof Presto;

        if ($root === null) {
            return $expression;
        }

        $refCount = $root->refCount();

        foreach (array_reverse($root->traverseTree()) as $scope) {
            $select = $scope->expression;
            $where = $select->args['where'] ?? null;
            /** @var list<Join> $joins */
            $joins = $select->argList('joins');

            if ($where instanceof Expression) {
                $joinIndex = [];
                $lastNullExtending = -1;

                foreach ($joins as $i => $join) {
                    $joinIndex[$join->aliasOrName()] = $i;

                    // A RIGHT or FULL join null-extends everything joined before it
                    if (in_array($join->side(), ['RIGHT', 'FULL'], true)) {
                        $lastNullExtending = $i;
                    }
                }

                $pushdownAllowed = true;
                $reachable = [];

                foreach ($scope->selectedSources() as $k => [$node, $source]) {
                    $parent = $node->findAncestor(Join::class, From::class);
                    $position = -1;

                    if ($parent instanceof Join) {
                        if ($node instanceof Unnest && $unnestRequiresCrossJoin) {
                            $pushdownAllowed = false;

                            break;
                        }

                        $position = $joinIndex[$parent->aliasOrName()] ?? -1;
                    }

                    if ($position >= $lastNullExtending) {
                        $reachable[(string) $k] = [$node, $source];
                    }
                }

                if ($pushdownAllowed) {
                    self::pushdown($where->args['this'] ?? null, $reachable, $refCount, $dialect, $joinIndex);
                }
            }

            // Joins should only push down into themselves, not into other joins
            foreach ($joins as $join) {
                $name = $join->aliasOrName();

                if (in_array($join->side(), ['RIGHT', 'FULL'], true)) {
                    continue;
                }

                $selected = $scope->selectedSources();

                if (isset($selected[$name])) {
                    self::pushdown($join->args['on'] ?? null, [$name => $selected[$name]], $refCount, $dialect);
                }
            }
        }

        return $expression;
    }

    /**
     * @param  Sources  $sources
     * @param  array<int, int>  $refCount
     * @param  array<string, int>|null  $joinIndex
     */
    public static function pushdown(mixed $condition, array $sources, array $refCount, Dialect $dialect, ?array $joinIndex = null): void
    {
        if (! $condition instanceof Expression) {
            return;
        }

        $condition = $condition->replace(Simplify::simplify($condition, dialect: $dialect));

        $cnfLike = Normalize::normalized($condition) || ! Normalize::normalized($condition, dnf: true);
        $predicates = $condition instanceof ($cnfLike ? AndExpr::class : OrExpr::class) ? $condition->flatten() : [$condition];

        if ($cnfLike) {
            self::pushdownCnf($predicates, $sources, $refCount, $joinIndex);
        } else {
            self::pushdownDnf($predicates, $sources, $refCount, $joinIndex);
        }
    }

    /**
     * If the predicates are in CNF like form, we can simply replace each block in the parent.
     *
     * @param  list<Expression>  $predicates
     * @param  Sources  $sources
     * @param  array<int, int>  $refCount
     * @param  array<string, int>|null  $joinIndex
     */
    public static function pushdownCnf(array $predicates, array $sources, array $refCount, ?array $joinIndex = null): void
    {
        foreach ($predicates as $predicate) {
            foreach (self::nodesForPredicate($predicate, $sources, $refCount) as $node) {
                if ($node instanceof Join) {
                    $name = $node->aliasOrName();

                    if ($joinIndex !== null && $joinIndex !== []) {
                        // Don't push the predicate if it references tables that appear in later joins
                        $thisIndex = $joinIndex[$name];
                        $earlier = true;

                        foreach (Builder::columnTableNames($predicate, $name) as $table) {
                            if (($joinIndex[$table] ?? -1) >= $thisIndex) {
                                $earlier = false;

                                break;
                            }
                        }

                        if ($earlier) {
                            $predicate->replace(Builder::true());
                            $node->on($predicate, copy: false);

                            break;
                        }
                    }
                }

                if ($node instanceof Select) {
                    $predicate->replace(Builder::true());
                    self::addToSelect($node, self::replaceAliases($node, $predicate));
                }
            }
        }
    }

    /**
     * If the predicates are in DNF form, we can only push down conditions that are in all blocks,
     * and we can't remove predicates from their original form.
     *
     * @param  list<Expression>  $predicates
     * @param  Sources  $sources
     * @param  array<int, int>  $refCount
     * @param  array<string, int>|null  $joinIndex
     */
    public static function pushdownDnf(array $predicates, array $sources, array $refCount, ?array $joinIndex = null): void
    {
        // Tables referenced in all blocks of the DNF: (a.x AND b.x) OR (a.y AND c.y) -> only a
        $pushdownTables = [];

        foreach ($predicates as $a) {
            $aTables = Builder::columnTableNames($a);

            foreach ($predicates as $b) {
                $aTables = array_intersect($aTables, Builder::columnTableNames($b));
            }

            foreach ($aTables as $table) {
                $pushdownTables[$table] = true;
            }
        }

        $tables = array_map(strval(...), array_keys($pushdownTables));
        sort($tables, SORT_STRING);

        $conditions = [];
        $nodes = [];

        foreach ($tables as $table) {
            foreach ($predicates as $predicate) {
                $nodes = self::nodesForPredicate($predicate, $sources, $refCount);

                if (! isset($nodes[$table])) {
                    continue;
                }

                $conditions[$table] = isset($conditions[$table]) ? Builder::or([$conditions[$table], $predicate]) : $predicate;
            }

            // Like sqlglot, this uses the nodes of the last predicate
            foreach ($nodes as $name => $node) {
                if (! isset($conditions[$name])) {
                    continue;
                }

                $predicate = $conditions[$name];

                if ($node instanceof Join) {
                    if ($joinIndex !== null && $joinIndex !== []) {
                        $thisIndex = $joinIndex[$name];

                        foreach (Builder::columnTableNames($predicate, (string) $name) as $t) {
                            if (($joinIndex[$t] ?? -1) >= $thisIndex) {
                                continue 2;
                            }
                        }
                    }

                    $node->on($predicate, copy: false);
                } elseif ($node instanceof Select) {
                    self::addToSelect($node, self::replaceAliases($node, $predicate));
                }
            }
        }
    }

    private static function addToSelect(Select $node, Expression $predicate): void
    {
        if (Scope::findInScope($predicate, AggFunc::class) !== null) {
            $node->having($predicate, copy: false);
        } else {
            $node->where($predicate, copy: false);
        }
    }

    /**
     * @param  Sources  $sources
     * @param  array<int, int>  $refCount
     * @return array<string, Expression>
     */
    public static function nodesForPredicate(Expression $predicate, array $sources, array $refCount): array
    {
        $nodes = [];
        $tables = Builder::columnTableNames($predicate);
        sort($tables, SORT_STRING);
        $whereCondition = $predicate->findAncestor(Join::class, Where::class) instanceof Where;

        foreach ($tables as $table) {
            [$node, $source] = $sources[$table] ?? [null, null];

            // In a WHERE, push down to the root join or FROM
            if ($node !== null && $whereCondition) {
                $node = $node->findAncestor(Join::class, From::class);
            }

            // A node can reference a CTE which should be pushed down
            if ($node instanceof From && $source instanceof Scope) {
                $parent = $source->parent ?? throw new RuntimeException('Source node has no parent');
                $with = $parent->expression->args['with_'] ?? null;

                if ($with instanceof With && ($with->args['recursive'] ?? false) === true) {
                    return [];
                }

                $node = $source->expression;
            }

            if ($node instanceof Join) {
                if ($node->side() !== '') {
                    // A right join preserves its own source, so a WHERE predicate on it can only be
                    // pushed into that source, never into the match-only ON clause.
                    if ($node->side() !== 'RIGHT' || ! $source instanceof Scope) {
                        return [];
                    }

                    $node = $source->expression;
                } else {
                    $nodes[$table] = $node;
                }
            }

            if ($node instanceof Select && count($tables) === 1) {
                // We can't push down window expressions
                $hasWindow = false;
                foreach ($node->selects() as $select) {
                    if (Scope::findInScope($select, Window::class) !== null) {
                        $hasWindow = true;

                        break;
                    }
                }

                // Nor into selects referenced in multiple places, or that pick rows before the predicate
                if (
                    ($node->args['group'] ?? null) === null
                    && ($source === null ? 0 : $refCount[spl_object_id($source)] ?? 0) < 2
                    && ! $hasWindow
                    && ($node->args['limit'] ?? null) === null
                    && ($node->args['offset'] ?? null) === null
                    && ($node->args['qualify'] ?? null) === null
                ) {
                    $nodes[$table] = $node;
                }
            }
        }

        return $nodes;
    }

    public static function replaceAliases(Select $source, Expression $predicate): Expression
    {
        $aliases = [];

        foreach ($source->selects() as $select) {
            if ($select instanceof Alias) {
                $aliases[$select->alias()] = $select->args['this'];
            } else {
                $aliases[$select->name()] = $select;
            }
        }

        $isOperator = static fn (mixed $e): bool => $e instanceof Binary || $e instanceof Unary || $e instanceof Predicate;

        return $predicate->transform(static function (Expression $column) use ($aliases, $isOperator): Expression {
            if ($column instanceof Column && isset($aliases[$column->name()]) && $aliases[$column->name()] instanceof Expression) {
                $replaced = $aliases[$column->name()]->copy();

                if ($isOperator($replaced) && $isOperator($column->parent)) {
                    $replaced = Builder::paren($replaced, copy: false);
                }

                return $replaced;
            }

            return $column;
        }) ?? $predicate;
    }
}
