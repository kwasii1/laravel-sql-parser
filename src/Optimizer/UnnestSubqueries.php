<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use Closure;
use LaravelSqlParser\LaravelSqlParser\Expressions\AggFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\All;
use LaravelSqlParser\LaravelSqlParser\Expressions\AndExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Any;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayAny;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Coalesce;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Condition;
use LaravelSqlParser\LaravelSqlParser\Expressions\Count;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cube;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Exists;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Fetch;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Func;
use LaravelSqlParser\LaravelSqlParser\Expressions\GroupingSets;
use LaravelSqlParser\LaravelSqlParser\Expressions\Having;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lambda;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Max;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\Offset;
use LaravelSqlParser\LaravelSqlParser\Expressions\OrExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Predicate;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Rollup;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;

/**
 * Rewrite predicates with subqueries into joins (sqlglot optimizer/unnest_subqueries.py):
 * scalar subqueries become cross joins, correlated or vectorized subqueries become a
 * GROUP BY so the join isn't many to many.
 */
final class UnnestSubqueries
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function unnestSubqueries(Expression $expression): Expression
    {
        $counter = 0;
        $nextAliasName = static function () use (&$counter): string {
            return '_u_'.$counter++;
        };

        foreach (Scope::traverse($expression) as $scope) {
            $select = $scope->expression;
            $parent = $select->parentSelect();

            if ($parent === null) {
                continue;
            }

            if ($scope->externalColumns() !== []) {
                if (! $scope->isSetOperation()) {
                    self::decorrelate($select, $parent, $scope->externalColumns(), $nextAliasName);
                }
            } elseif ($scope->isSubquery()) {
                self::unnest($select, $parent, $nextAliasName);
            }
        }

        return $expression;
    }

    /**
     * @param  Closure(): string  $nextAliasName
     */
    private static function unnest(Expression $select, Select $parentSelect, Closure $nextAliasName): void
    {
        if (count(self::selectsOf($select)) > 1) {
            return;
        }

        $predicate = $select->findAncestor(Condition::class);

        if (
            $predicate === null
            || ($predicate instanceof Func && ($predicate->parent instanceof Table || $predicate->parent instanceof From || $predicate->parent instanceof Join))
            || $parentSelect !== $predicate->parentSelect()
            || ! ($parentSelect->args['from_'] ?? null) instanceof Expression
            || ($predicate instanceof In && self::isNegated($predicate))
        ) {
            return;
        }

        if ($select instanceof SetOperation) {
            $innerAlias = $nextAliasName();
            $select = Builder::select(...array_map(
                static fn (Expression $s): Expression => Builder::alias(Builder::column($s->aliasOrName(), $innerAlias), $s->aliasOrName()),
                $select->selects(),
            ))->from($select->subquery($innerAlias));
        }

        $alias = $nextAliasName();
        $clause = $predicate->findAncestor(Having::class, Where::class, Join::class);

        if (! $predicate instanceof In && ! $predicate instanceof Any) {
            $first = self::selectsOf($select)[0] ?? null;

            if ($first === null) {
                return;
            }

            $column = Builder::column($first->aliasOrName(), $alias);
            $clauseParentSelect = $clause?->parentSelect();

            $hasAgg = false;

            foreach ($parentSelect->selects() as $s) {
                if (Scope::findInScope($s, AggFunc::class) !== null) {
                    $hasAgg = true;
                }
            }

            if (
                ($clause instanceof Having && $clauseParentSelect === $parentSelect)
                || (
                    ($clause === null || $clauseParentSelect !== $parentSelect)
                    && (self::truthy($parentSelect->args['group'] ?? null) || $hasAgg)
                )
            ) {
                $column = new Max(['this' => $column]);
            } elseif (! $select->parent instanceof Subquery) {
                return;
            }

            $joinType = 'CROSS';
            $onClause = null;

            if ($predicate instanceof Exists) {
                $column = $column->is(Builder::null())->not();
                $joinType = 'LEFT';
                $onClause = Builder::true();
            }

            if ($select->parent !== null) {
                self::replace($select->parent, $column);
            }

            $parentSelect->join($select, on: $onClause, joinType: $joinType, joinAlias: $alias, copy: false);

            return;
        }

        if (Scope::findInScope($select, Limit::class, Offset::class) !== null) {
            return;
        }

        if ($predicate instanceof Any) {
            $predicate = $predicate->findAncestor(EQ::class);

            if ($predicate === null || $parentSelect !== $predicate->parentSelect()) {
                return;
            }
        }

        $column = self::otherOperand($predicate);
        $value = self::selectsOf($select)[0] ?? null;

        if ($column === null || $value === null || ! $select instanceof Select) {
            return;
        }

        $joinKey = Builder::column($value->alias(), $alias);
        $joinKeyNotNull = $joinKey->is(Builder::null())->not();

        if ($clause instanceof Join) {
            self::replace($predicate, Builder::true());
            $parentSelect->where($joinKeyNotNull, copy: false);
        } else {
            self::replace($predicate, $joinKeyNotNull);
        }

        $group = $select->args['group'] ?? null;
        $valueThis = $value->args['this'] ?? null;

        if ($group instanceof Expression) {
            $groupKeys = [];

            foreach ($group->expressions() as $g) {
                $groupKeys[$g->hashKey()] = true;
            }

            if (! $valueThis instanceof Expression || array_keys($groupKeys) !== [$valueThis->hashKey()]) {
                $select = Builder::select(Builder::alias(Builder::column($value->alias(), '_q'), $value->alias()))
                    ->from($select->subquery('_q', copy: false), copy: false)
                    ->groupBy(Builder::column($value->alias(), '_q'), copy: false);
            }
        } elseif ($valueThis instanceof Expression && Scope::findInScope($valueThis, AggFunc::class) === null) {
            $select = $select->groupBy($valueThis, copy: false);
        }

        $parentSelect->join($select, on: $column->eq($joinKey), joinType: 'LEFT', joinAlias: $alias, copy: false);
    }

    /**
     * @param  list<Column>  $externalColumns
     * @param  Closure(): string  $nextAliasName
     */
    private static function decorrelate(Expression $select, Select $parentSelect, array $externalColumns, Closure $nextAliasName): void
    {
        if (! $select instanceof Select) {
            return;
        }

        $where = $select->args['where'] ?? null;

        if (! $where instanceof Expression || $where->find(OrExpr::class) !== null || $select->find(Limit::class, Offset::class, Fetch::class) !== null) {
            return;
        }

        $parentPredicate = $select->findAncestor(Predicate::class);

        if ($parentPredicate !== null && $parentPredicate->parentSelect() !== $parentSelect) {
            return;
        }

        if ($parentPredicate instanceof Exists) {
            if (self::truthy($select->args['having'] ?? null) || self::truthy($select->args['qualify'] ?? null)) {
                return;
            }

            $group = $select->args['group'] ?? null;
            $allAgg = true;

            foreach ($select->selects() as $projection) {
                if (Scope::findInScope($projection, AggFunc::class) === null) {
                    $allAgg = false;
                }
            }

            if (! $group instanceof Expression || ($group->flag('all') && $allAgg)) {
                if (self::hasAggregateProjection($select)) {
                    self::replace($parentPredicate, Builder::true());

                    return;
                }
            } elseif (! self::isPlainGroup($group)) {
                return;
            }
        }

        $tableAlias = $nextAliasName();
        /** @var list<array{0: Expression, 1: Column, 2: Expression}> $keys */
        $keys = [];
        $eqCount = 0;
        $externalIds = [];

        foreach ($externalColumns as $column) {
            if ($column->findAncestor(Where::class) !== $where) {
                return;
            }

            $predicate = $column->findAncestor(Predicate::class);
            $ancestor = $predicate?->parent;

            while ($ancestor instanceof AndExpr || $ancestor instanceof Paren) {
                $ancestor = $ancestor->parent;
            }

            if ($ancestor !== $where) {
                return;
            }

            if (! $predicate instanceof Binary) {
                return;
            }

            $left = $predicate->args['this'];
            $right = $predicate->args['expression'];
            $inLeft = false;

            foreach ($left->walk() as $node) {
                if ($node === $column) {
                    $inLeft = true;

                    break;
                }
            }

            $key = $inLeft ? $right : $left;
            $keys[] = [$key, $column, $predicate];
            $externalIds[spl_object_id($column)] = true;
            $eqCount += $predicate instanceof EQ ? 1 : 0;
        }

        if ($eqCount === 0 || (count($keys) > $eqCount && ! $parentPredicate instanceof Exists)) {
            return;
        }

        $isSubqueryProjection = false;

        foreach ($parentSelect->selects() as $s) {
            $node = $s->unalias();

            if ($node instanceof Subquery && $node === $select->parent) {
                $isSubqueryProjection = true;
            }
        }

        $value = $select->selects()[0] ?? null;

        if ($value === null) {
            return;
        }

        $valueThis = $value->args['this'] ?? null;
        $valueHash = $valueThis instanceof Expression ? $valueThis->hashKey() : null;

        /** @var array<string, string> $keyAliases hash => alias */
        $keyAliases = [];
        /** @var array<string, Expression> $keyByHash first key node per hash */
        $keyByHash = [];
        /** @var array<string, Expression> $groupBy hash => key */
        $groupBy = [];

        foreach ($keys as [$key, , $predicate]) {
            $other = $key === $predicate->args['this'] ? $predicate->args['expression'] : $predicate->args['this'];

            foreach ($key->findAll(Column::class) as $c) {
                if (isset($externalIds[spl_object_id($c)])) {
                    return;
                }
            }

            foreach ($other->findAll(Column::class) as $c) {
                if (! isset($externalIds[spl_object_id($c)])) {
                    return;
                }
            }

            $hash = $key->hashKey();
            $keyByHash[$hash] ??= $key;

            if ($hash === $valueHash && $predicate instanceof EQ) {
                $keyAliases[$hash] = $value->alias();
                $groupBy[$hash] ??= $key;
            } else {
                if (! isset($keyAliases[$hash])) {
                    $keyAliases[$hash] = $nextAliasName();
                }

                if ($predicate instanceof EQ && ! isset($groupBy[$hash])) {
                    $groupBy[$hash] = $key;
                }
            }
        }

        if ($parentPredicate === null && ! $isSubqueryProjection) {
            return;
        }

        if ($parentPredicate instanceof In && self::isNegated($parentPredicate)) {
            return;
        }

        $valueInGroupBy = $valueHash !== null && isset($groupBy[$valueHash]);

        if (! $value instanceof Subquery && Scope::findInScope($value, AggFunc::class) === null && ! $valueInGroupBy) {
            $agg = $isSubqueryProjection ? new Max(['this' => $valueThis]) : new ArrayAgg(['this' => $valueThis]);
            $select->select(Builder::alias($agg, $value->alias(), quoted: false), append: false, copy: false);
        }

        if ($parentPredicate instanceof Exists) {
            $select->set('expressions', []);
            $select->set('group', null);
            $select->set('distinct', null);
            $select->set('order', null);
        }

        foreach ($groupBy as $hash => $key) {
            if ($parentPredicate instanceof Exists || $hash !== $valueHash) {
                $select->select(Builder::alias($key, $keyAliases[$hash]), copy: false);
            }
        }

        $arrayKeys = array_values(array_filter(array_keys($keyAliases), static fn (string $h): bool => ! isset($groupBy[$h])));
        $useStruct = count($arrayKeys) > 1;
        $arrayAlias = '';

        if ($arrayKeys !== []) {
            $arrayAlias = $useStruct ? $nextAliasName() : $keyAliases[$arrayKeys[0]];
            $arrayItem = $useStruct
                ? new Struct(['expressions' => array_map(
                    static fn (string $h): PropertyEQ => new PropertyEQ(['this' => Builder::toIdentifier($keyAliases[$h]), 'expression' => $keyByHash[$h]->copy()]),
                    $arrayKeys,
                )])
                : $keyByHash[$arrayKeys[0]]->copy();
            $select->select(Builder::alias(new ArrayAgg(['this' => $arrayItem]), $arrayAlias, quoted: false), copy: false);
        }

        $alias = Builder::column($value->alias(), $tableAlias);
        $other = self::otherOperand($parentPredicate);
        $opParent = $parentPredicate?->parent;
        $opType = $opParent !== null ? $opParent::class : null;

        if ($parentPredicate instanceof Exists) {
            $firstGroupHash = (string) array_key_first($groupBy);
            $alias = Builder::column($keyAliases[$firstGroupHash], $tableAlias);
            $parentPredicate = self::replace($parentPredicate, "NOT {$alias->sql()} IS NULL");
        } elseif ($parentPredicate instanceof All && $opParent instanceof Binary && $opType !== null) {
            $predicate = new $opType(['this' => $other, 'expression' => Builder::column('_x')]);
            $parentPredicate = self::replace($opParent, "ARRAY_ALL({$alias->sql()}, _x -> {$predicate->sql()})");
        } elseif ($parentPredicate instanceof Any && $opParent instanceof Binary && $opType !== null) {
            if ($valueInGroupBy) {
                $predicate = new $opType(['this' => $other, 'expression' => $alias]);
                $parentPredicate = self::replace($opParent, $predicate);
            } else {
                $predicate = new $opType(['this' => $other, 'expression' => Builder::column('_x')]);
                $parentPredicate = self::replace($parentPredicate, "ARRAY_ANY({$alias->sql()}, _x -> {$predicate->sql()})");
            }
        } elseif ($parentPredicate instanceof In) {
            $otherSql = $other?->sql() ?? '';

            if ($valueInGroupBy) {
                $parentPredicate = self::replace($parentPredicate, "{$otherSql} = {$alias->sql()}");
            } else {
                $inThis = $parentPredicate->args['this'] ?? null;
                $inThisSql = $inThis instanceof Expression ? $inThis->sql() : '';
                $parentPredicate = self::replace($parentPredicate, "ARRAY_ANY({$alias->sql()}, _x -> _x = {$inThisSql})");
            }
        } else {
            $selectParent = $select->parent;

            if ($isSubqueryProjection && $selectParent !== null && $selectParent->alias() !== '') {
                $alias = Builder::alias($alias, $selectParent->alias());
            }

            if (Scope::findInScope($value, Count::class) !== null && $valueThis instanceof Expression) {
                $removed = $valueThis->transform(static function (Expression $node): Expression {
                    if ($node instanceof Count) {
                        return Literal::number(0);
                    }

                    if ($node instanceof AggFunc) {
                        return Builder::null();
                    }

                    return $node;
                });
                $alias = new Coalesce(['this' => $alias, 'expressions' => [$removed]]);
            }

            $selectParent?->replace($alias);
        }

        $arrayPredicates = [];

        foreach ($keys as [$key, , $predicate]) {
            $predicate->replace(Builder::true());
            $hash = $key->hashKey();

            if (isset($groupBy[$hash])) {
                $key->replace(Builder::column($keyAliases[$hash], $tableAlias));
            } else {
                $key->replace($useStruct ? Builder::column($keyAliases[$hash], '_x') : Builder::toIdentifier('_x'));
                $arrayPredicates[] = $predicate;
            }
        }

        if ($arrayPredicates !== [] && $parentPredicate instanceof Expression) {
            $right = new ArrayAny([
                'this' => Builder::column($arrayAlias, $tableAlias),
                'expression' => new Lambda([
                    'this' => Builder::and($arrayPredicates, copy: false),
                    'expressions' => [Builder::toIdentifier('_x')],
                ]),
            ]);
            $parentPredicate = self::replace(
                $parentPredicate,
                Builder::paren(Builder::and([$parentPredicate->copy(), $right], copy: false)),
            );
        }

        $on = [];

        foreach ($keys as [$key, , $predicate]) {
            if (isset($groupBy[$key->hashKey()])) {
                $on[] = $predicate;
            }
        }

        $parentSelect->join(
            $select->groupBy(...[...array_values($groupBy), 'copy' => false]),
            on: $on,
            joinType: 'LEFT',
            joinAlias: $tableAlias,
            copy: false,
        );
    }

    /**
     * @return list<Expression>
     */
    private static function selectsOf(Expression $select): array
    {
        return method_exists($select, 'selects') ? array_values($select->selects()) : [];
    }

    private static function isNegated(Expression $expression): bool
    {
        $parent = $expression->parent;

        while ($parent instanceof Paren) {
            $parent = $parent->parent;
        }

        return $parent instanceof Not;
    }

    private static function replace(Expression $expression, Expression|string $condition): Expression
    {
        $new = Builder::condition($condition);
        $expression->replace($new);

        return $new;
    }

    private static function isPlainGroup(Expression $group): bool
    {
        foreach (['grouping_sets', 'cube', 'rollup', 'totals'] as $arg) {
            if (self::truthy($group->args[$arg] ?? null)) {
                return false;
            }
        }

        foreach ($group->expressions() as $e) {
            if ($e instanceof Rollup || $e instanceof Cube || $e instanceof GroupingSets || ($e instanceof Tuple && $e->expressions() === [])) {
                return false;
            }
        }

        return true;
    }

    private static function hasAggregateProjection(Select $select): bool
    {
        /** @var list<Expression> $windows */
        $windows = $select->argList('windows');

        foreach ($select->selects() as $projection) {
            if (Helpers::projectionHasAggregate($projection, $windows === [] ? null : $windows)) {
                return true;
            }
        }

        return false;
    }

    private static function otherOperand(?Expression $expression): ?Expression
    {
        if ($expression instanceof In) {
            $this_ = $expression->args['this'] ?? null;

            return $this_ instanceof Expression ? $this_ : null;
        }

        if ($expression instanceof Any || $expression instanceof All) {
            return self::otherOperand($expression->parent);
        }

        if ($expression instanceof Binary) {
            $left = $expression->args['this'] ?? null;
            $right = $expression->args['expression'] ?? null;
            $picked = ($left instanceof Subquery || $left instanceof Any || $left instanceof Exists || $left instanceof All) ? $right : $left;

            return $picked instanceof Expression ? $picked : null;
        }

        return null;
    }

    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== [] && $value !== '';
    }
}
