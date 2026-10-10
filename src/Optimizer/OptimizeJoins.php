<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use LaravelSqlParser\LaravelSqlParser\Errors\OptimizeError;
use LaravelSqlParser\LaravelSqlParser\Expressions\AndExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;

/**
 * Remove cross joins if possible and reorder joins by predicate dependencies
 * (sqlglot optimizer/optimize_joins.py).
 */
final class OptimizeJoins
{
    private const array JOIN_ATTRS = ['on', 'side', 'kind', 'using', 'method'];

    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function optimizeJoins(Expression $expression): Expression
    {
        foreach ($expression->findAll(Select::class) as $select) {
            /** @var list<Join> $joins */
            $joins = $select->argList('joins');

            if (! self::isReorderable($joins)) {
                continue;
            }

            /** @var array<string, list<Join>> $references */
            $references = [];
            $crossJoins = [];

            foreach ($joins as $join) {
                $tables = self::otherTableNames($join);

                if ($tables !== []) {
                    foreach ($tables as $table) {
                        $references[$table][] = $join;
                    }
                } else {
                    $crossJoins[] = [$join->aliasOrName(), $join];
                }
            }

            foreach ($crossJoins as [$name, $join]) {
                foreach ($references[$name] ?? [] as $dep) {
                    // An ANTI join's ON clause is negated, so its conjuncts can't be extracted
                    if ($dep->kind() === 'ANTI') {
                        continue;
                    }

                    $on = $dep->args['on'] ?? null;

                    // Only conjuncts can be extracted, i.e. `a OR b` is not `b AND (a OR TRUE)`
                    if (! $on instanceof AndExpr || count(self::otherTableNames($dep)) < 2) {
                        continue;
                    }

                    foreach ($on->flatten() as $predicate) {
                        if (in_array($name, Builder::columnTableNames($predicate), true)) {
                            $predicate->replace(Builder::true());
                            $existing = $join->args['on'] ?? null;
                            $predicate = Builder::and([$existing instanceof Expression ? $existing : null, $predicate], copy: false);
                            $join->on($predicate, append: false, copy: false);
                        }
                    }
                }
            }
        }

        return self::normalize(self::reorderJoins($expression));
    }

    /**
     * Reorder joins by topological sort order based on predicate references.
     *
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function reorderJoins(Expression $expression): Expression
    {
        foreach ($expression->findAll(From::class) as $from) {
            $parent = $from->parent ?? throw new OptimizeError('FROM clause without parent expression');
            /** @var list<Join> $joins */
            $joins = $parent->argList('joins');

            if (! self::isReorderable($joins)) {
                continue;
            }

            $joinsByName = [];
            foreach ($joins as $join) {
                $joinsByName[$join->aliasOrName()] = $join;
            }

            $dag = [];
            foreach ($joinsByName as $name => $join) {
                $dag[(string) $name] = self::otherTableNames($join);
            }

            $ordered = [];
            foreach (self::tsort($dag) as $name) {
                if ($name !== $from->aliasOrName() && isset($joinsByName[$name])) {
                    $ordered[] = $joinsByName[$name];
                }
            }

            $parent->set('joins', $ordered);
        }

        return $expression;
    }

    /**
     * Remove INNER and OUTER from joins as they are optional.
     *
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function normalize(Expression $expression): Expression
    {
        foreach ($expression->findAll(Join::class) as $join) {
            $any = false;
            foreach (self::JOIN_ATTRS as $key) {
                $value = $join->args[$key] ?? null;

                if ($value !== null && $value !== '' && $value !== false && $value !== []) {
                    $any = true;

                    break;
                }
            }

            if (! $any) {
                $join->set('kind', 'CROSS');
            }

            if ($join->kind() === 'CROSS') {
                $join->set('on', null);
            } else {
                if (in_array($join->kind(), ['INNER', 'OUTER'], true)) {
                    $join->set('kind', null);
                }

                if (($join->args['on'] ?? null) === null && $join->argList('using') === []) {
                    $join->set('on', Builder::true());
                }
            }
        }

        return $expression;
    }

    /**
     * @return list<string>
     */
    public static function otherTableNames(Join $join): array
    {
        $on = $join->args['on'] ?? null;

        return $on instanceof Expression ? Builder::columnTableNames($on, $join->aliasOrName()) : [];
    }

    /**
     * @param  list<Join>  $joins
     */
    private static function isReorderable(array $joins): bool
    {
        foreach ($joins as $join) {
            if ($join->side() !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Topological sort of a DAG, alphabetical within each level (sqlglot helper.tsort).
     *
     * @param  array<string, list<string>>  $dag
     * @return list<string>
     */
    private static function tsort(array $dag): array
    {
        $graph = [];
        foreach ($dag as $node => $deps) {
            $graph[(string) $node] = array_fill_keys($deps, true);
        }
        foreach ($dag as $deps) {
            foreach ($deps as $dep) {
                $graph[$dep] ??= [];
            }
        }

        $result = [];

        while ($graph !== []) {
            $current = [];
            foreach ($graph as $node => $deps) {
                if ($deps === []) {
                    $current[] = (string) $node;
                }
            }

            if ($current === []) {
                throw new OptimizeError('Cycle error');
            }

            foreach ($current as $node) {
                unset($graph[$node]);
            }

            foreach ($graph as $node => $deps) {
                foreach ($current as $done) {
                    unset($deps[$done]);
                }
                $graph[$node] = $deps;
            }

            sort($current, SORT_STRING);
            array_push($result, ...$current);
        }

        return $result;
    }
}
