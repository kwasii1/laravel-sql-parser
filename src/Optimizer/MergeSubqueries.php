<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Kwasii\LaravelSqlParser\Expressions\AggFunc;
use Kwasii\LaravelSqlParser\Expressions\Anonymous;
use Kwasii\LaravelSqlParser\Expressions\Binary;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\ExplodingGenerateSeries;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\Func;
use Kwasii\LaravelSqlParser\Expressions\Group;
use Kwasii\LaravelSqlParser\Expressions\Having;
use Kwasii\LaravelSqlParser\Expressions\Join;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\NEQ;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\Paren;
use Kwasii\LaravelSqlParser\Expressions\QueryTransform;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\Subquery;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\TableAlias;
use Kwasii\LaravelSqlParser\Expressions\UDTF;
use Kwasii\LaravelSqlParser\Expressions\Unary;
use Kwasii\LaravelSqlParser\Expressions\Window;
use Kwasii\LaravelSqlParser\Support\Scope;

/**
 * Merge derived tables and CTEs selected from once into the outer query (sqlglot
 * optimizer/merge_subqueries.py).
 */
final class MergeSubqueries
{
    /** Smallest copy factor that changed no ordinary query among 1,121 real-world queries */
    public const int MAX_COPY_FACTOR = 8;

    /** Spares small statements */
    public const int MIN_COPY_BUDGET = 1000;

    /** Select args that make a derived table unmergeable: every arg except these */
    private const array MERGEABLE_ARGS = ['expressions' => true, 'from_' => true, 'joins' => true, 'where' => true, 'order' => true, 'hint' => true];

    /** Outer projections of these types can be replaced without parentheses */
    private const array SAFE_TO_REPLACE_UNWRAPPED = [Column::class, EQ::class, Func::class, NEQ::class, Paren::class];

    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function mergeSubqueries(
        Expression $expression,
        bool $leaveTablesIsolated = false,
        ?int $maxCopyFactor = self::MAX_COPY_FACTOR,
        int $minCopyBudget = self::MIN_COPY_BUDGET,
    ): Expression {
        $scopes = Scope::traverse($expression);
        $budget = ['expression' => $expression, 'factor' => $maxCopyFactor, 'min' => $minCopyBudget, 'remaining' => null];

        $merged = self::mergeCtesInternal($expression, $leaveTablesIsolated, $scopes, $budget);

        if ($merged) {
            $scopes = Scope::traverse($expression);
        }

        self::mergeDerivedTablesInternal($leaveTablesIsolated, $scopes, $budget);

        return $expression;
    }

    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function mergeCtes(Expression $expression, bool $leaveTablesIsolated = false): Expression
    {
        $budget = ['expression' => $expression, 'factor' => self::MAX_COPY_FACTOR, 'min' => self::MIN_COPY_BUDGET, 'remaining' => null];
        self::mergeCtesInternal($expression, $leaveTablesIsolated, Scope::traverse($expression), $budget);

        return $expression;
    }

    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function mergeDerivedTables(Expression $expression, bool $leaveTablesIsolated = false): Expression
    {
        $budget = ['expression' => $expression, 'factor' => self::MAX_COPY_FACTOR, 'min' => self::MIN_COPY_BUDGET, 'remaining' => null];
        self::mergeDerivedTablesInternal($leaveTablesIsolated, Scope::traverse($expression), $budget);

        return $expression;
    }

    /**
     * @param  list<Scope>  $scopes
     * @param  array{expression: Expression, factor: int|null, min: int, remaining: int|null}  $budget
     */
    private static function mergeCtesInternal(Expression $expression, bool $leaveTablesIsolated, array $scopes, array &$budget): bool
    {
        /** @var array<int, list<array{0: Scope, 1: Scope, 2: Expression}>> $cteSelections */
        $cteSelections = [];

        foreach ($scopes as $outerScope) {
            foreach ($outerScope->selectedSources() as [$table, $innerScope]) {
                if ($innerScope instanceof Scope && $innerScope->isCte()) {
                    $cteSelections[spl_object_id($innerScope)][] = [$outerScope, $innerScope, $table];
                }
            }
        }

        $merged = false;

        foreach ($cteSelections as $selections) {
            if (count($selections) !== 1) {
                continue;
            }

            [$outerScope, $innerScope, $table] = $selections[0];
            $fromOrJoin = $table->findAncestor(From::class, Join::class);

            if (! $fromOrJoin instanceof From && ! $fromOrJoin instanceof Join) {
                continue;
            }

            $alias = $table->aliasOrName();

            if (self::mergeable($outerScope, $innerScope, $leaveTablesIsolated, $fromOrJoin) && self::consume($budget, $outerScope, $innerScope, $alias)) {
                self::renameInnerSources($outerScope, $innerScope, $alias);
                self::mergeFrom($outerScope, $innerScope, $table, $alias);
                self::mergeExpressions($outerScope, $innerScope, $alias);
                self::mergeOrder($outerScope, $innerScope);
                self::mergeJoins($outerScope, $innerScope, $fromOrJoin);
                self::mergeWhere($outerScope, $innerScope, $fromOrJoin);
                self::mergeHints($outerScope, $innerScope);
                self::popCte($innerScope);
                $outerScope->clearCache();
                $merged = true;
            }
        }

        return $merged;
    }

    /**
     * @param  list<Scope>  $scopes
     * @param  array{expression: Expression, factor: int|null, min: int, remaining: int|null}  $budget
     */
    private static function mergeDerivedTablesInternal(bool $leaveTablesIsolated, array $scopes, array &$budget): void
    {
        foreach ($scopes as $outerScope) {
            foreach ($outerScope->derivedTables() as $subquery) {
                $fromOrJoin = $subquery->findAncestor(From::class, Join::class);

                if (! $fromOrJoin instanceof From && ! $fromOrJoin instanceof Join) {
                    continue;
                }

                $alias = $subquery->aliasOrName();
                $innerScope = $outerScope->sources[$alias] ?? null;

                if (! $innerScope instanceof Scope) {
                    continue;
                }

                if (self::mergeable($outerScope, $innerScope, $leaveTablesIsolated, $fromOrJoin) && self::consume($budget, $outerScope, $innerScope, $alias)) {
                    self::renameInnerSources($outerScope, $innerScope, $alias);
                    self::mergeFrom($outerScope, $innerScope, $subquery, $alias);
                    self::mergeExpressions($outerScope, $innerScope, $alias);
                    self::mergeOrder($outerScope, $innerScope);
                    self::mergeJoins($outerScope, $innerScope, $fromOrJoin);
                    self::mergeWhere($outerScope, $innerScope, $fromOrJoin);
                    self::mergeHints($outerScope, $innerScope);
                    $outerScope->clearCache();
                }
            }
        }
    }

    /**
     * Charges the copies needed to merge `$innerScope` into `$outerScope`, if they fit (sqlglot CopyBudget.consume).
     *
     * @param  array{expression: Expression, factor: int|null, min: int, remaining: int|null}  $budget
     */
    private static function consume(array &$budget, Scope $outerScope, Scope $innerScope, string $alias): bool
    {
        if ($budget['factor'] === null) {
            return true;
        }

        if ($budget['remaining'] === null) {
            $size = 0;

            foreach ($budget['expression']->walk() as $_) {
                $size++;
            }

            $budget['remaining'] = max($budget['factor'] * $size, $budget['min']);
        }

        $references = [];

        foreach ($outerScope->columns() as $c) {
            if ($c->text('table') === $alias) {
                $references[$c->name()] = ($references[$c->name()] ?? 0) + 1;
            }
        }

        $copies = 0;

        foreach ($innerScope->expression->expressions() as $projection) {
            $count = ($references[$projection->aliasOrName()] ?? 0) - 1;

            if ($count > 0) {
                foreach ($projection->unalias()->walk() as $_) {
                    $copies += $count;

                    if ($copies > $budget['remaining']) {
                        return false;
                    }
                }
            }
        }

        $budget['remaining'] -= $copies;

        return true;
    }

    private static function mergeable(Scope $outerScope, Scope $innerScope, bool $leaveTablesIsolated, From|Join $fromOrJoin): bool
    {
        $innerSelect = $innerScope->expression->unnest();
        $outer = $outerScope->expression;
        $innerName = $fromOrJoin->aliasOrName();

        if (! $outer instanceof Select || $outer->isStar() || ! $innerSelect instanceof Select) {
            return false;
        }

        foreach ($innerSelect->args as $key => $value) {
            if (! isset(self::MERGEABLE_ARGS[$key]) && self::truthy($value)) {
                return false;
            }
        }

        $innerWhere = self::truthy($innerSelect->args['where'] ?? null);
        $outerJoins = $outer->argList('joins');

        if (
            ! ($innerSelect->args['from_'] ?? null) instanceof Expression
            || $outerScope->pivots() !== []
            || ($leaveTablesIsolated && count($outerScope->selectedSources()) > 1)
            || ($fromOrJoin instanceof Join && $innerSelect->argList('joins') !== [])
            || ($fromOrJoin instanceof Join && $innerWhere && in_array($fromOrJoin->side(), ['FULL', 'LEFT', 'RIGHT'], true))
            || ($fromOrJoin instanceof From && $innerWhere && array_filter($outerJoins, static fn (Expression $j): bool => $j instanceof Join && in_array($j->side(), ['FULL', 'RIGHT'], true)) !== [])
            || (self::truthy($innerSelect->args['order'] ?? null) && $outerScope->isSetOperation())
            || ($innerSelect->expressions()[0] ?? null) instanceof QueryTransform
        ) {
            return false;
        }

        $windowAliases = [];
        $numberLiteralAliases = [];
        $projections = [];

        foreach ($innerSelect->selects() as $s) {
            $name = $s->aliasOrName();
            $projections[$name] = $s;

            if ($s->unalias()->isNumber()) {
                $numberLiteralAliases[$name] = true;
            }

            foreach ($s->walk() as $node) {
                if ($node instanceof AggFunc || $node instanceof Select || $node instanceof Anonymous || $node instanceof UDTF || $node instanceof ExplodingGenerateSeries) {
                    return false;
                }

                if ($node instanceof Window) {
                    $windowAliases[$name] = true;
                }
            }
        }

        // All columns from the inner select in the ON clause must be from the first FROM table
        $outerSelectJoinsOnInnerSelectJoin = static function () use ($fromOrJoin, $innerName, $innerScope, $projections): bool {
            if (! $fromOrJoin instanceof Join) {
                return false;
            }

            $on = $fromOrJoin->args['on'] ?? null;

            if (! $on instanceof Expression) {
                return false;
            }

            $innerFrom = $innerScope->expression->args['from_'] ?? null;

            if (! $innerFrom instanceof Expression) {
                return false;
            }

            $innerFromTable = $innerFrom->aliasOrName();

            foreach ($on->findAll(Column::class) as $c) {
                if ($c->text('table') !== $innerName) {
                    continue;
                }

                foreach (($projections[$c->name()] ?? null)?->findAll(Column::class) ?? [] as $col) {
                    if ($col->text('table') !== $innerFromTable) {
                        return true;
                    }
                }
            }

            return false;
        };

        $windowProjectionBlocksMerge = static function () use ($windowAliases, $outer, $outerScope, $innerName): bool {
            if ($windowAliases === []) {
                return false;
            }

            if (self::truthy($outer->args['where'] ?? null) || self::truthy($outer->args['joins'] ?? null)) {
                return true;
            }

            foreach ($outerScope->columns() as $column) {
                if (
                    $column->text('table') === $innerName
                    && isset($windowAliases[$column->name()])
                    && $column->findAncestor(Group::class, Order::class, Having::class, AggFunc::class) !== null
                ) {
                    return true;
                }
            }

            return false;
        };

        $literalGroupUnmergeable = static function () use ($numberLiteralAliases, $outer, $innerName): bool {
            $group = $outer->args['group'] ?? null;

            if (! $group instanceof Expression || $numberLiteralAliases === []) {
                return false;
            }

            $grouped = [];
            $topLevelIds = [];

            foreach ($group->expressions() as $e) {
                $topLevelIds[spl_object_id($e->unnest())] = true;
            }

            foreach ($group->findAll(Column::class) as $col) {
                if ($col->text('table') !== $innerName || ! isset($numberLiteralAliases[$col->name()])) {
                    continue;
                }

                if (! isset($topLevelIds[spl_object_id($col)])) {
                    return true;
                }

                $grouped[$col->name()] = true;
            }

            if ($grouped === []) {
                return false;
            }

            $projected = [];

            foreach ($outer->selects() as $s) {
                $unaliased = $s->unalias();

                if ($unaliased instanceof Column && $unaliased->text('table') === $innerName) {
                    $projected[$unaliased->name()] = true;
                }
            }

            return array_diff_key($grouped, $projected) !== [];
        };

        $literalInOrderBy = static function () use ($numberLiteralAliases, $outer, $innerName): bool {
            $order = $outer->args['order'] ?? null;

            if (! $order instanceof Expression) {
                return false;
            }

            foreach ($order->expressions() as $o) {
                $key = $o->this();
                $key = $key instanceof Expression ? $key->unnest() : null;

                if ($key instanceof Column && $key->text('table') === $innerName && isset($numberLiteralAliases[$key->name()])) {
                    return true;
                }
            }

            return false;
        };

        $isRecursive = static function () use ($innerScope, $outer): bool {
            $cte = $innerScope->expression->parent;
            $node = $outer->parent;

            while ($node !== null) {
                if ($node === $cte) {
                    return true;
                }

                $node = $node->parent;
            }

            return false;
        };

        return ! $outerSelectJoinsOnInnerSelectJoin()
            && ! $windowProjectionBlocksMerge()
            && ! $literalGroupUnmergeable()
            && ! $literalInOrderBy()
            && ! ($innerScope->isCte() && $isRecursive());
    }

    /**
     * Renames any sources in the inner query that conflict with names in the outer query.
     */
    private static function renameInnerSources(Scope $outerScope, Scope $innerScope, string $alias): void
    {
        $innerTaken = array_map(strval(...), array_keys($innerScope->selectedSources()));
        $outerTaken = array_map(strval(...), array_keys($outerScope->selectedSources()));
        $conflicts = array_values(array_diff(array_intersect($outerTaken, $innerTaken), [$alias]));
        $taken = array_values(array_unique([...$outerTaken, ...$innerTaken]));

        foreach ($conflicts as $conflict) {
            $newName = self::findNewName($taken, $conflict);
            [$source] = $innerScope->selectedSources()[$conflict];
            $newAlias = Builder::toIdentifier($newName);

            if ($source instanceof Table && $source->alias() !== '') {
                $source->set('alias', new TableAlias(['this' => $newAlias]));
            } elseif ($source instanceof Table) {
                $source->replace(Builder::alias($source, $newAlias));
            } elseif ($source->parent instanceof Subquery) {
                $source->parent->set('alias', new TableAlias(['this' => $newAlias]));
            }

            foreach ($innerScope->sourceColumns($conflict) as $column) {
                $column->set('table', Builder::toIdentifier($newName));
            }

            $innerScope->renameSource($conflict, $newName);
        }
    }

    /**
     * @param  list<string>  $taken
     */
    private static function findNewName(array $taken, string $base): string
    {
        $i = 2;
        $new = "{$base}_{$i}";

        while (in_array($new, $taken, true)) {
            $i++;
            $new = "{$base}_{$i}";
        }

        return $new;
    }

    /**
     * Merge FROM clause of inner query into outer query.
     */
    private static function mergeFrom(Scope $outerScope, Scope $innerScope, Expression $nodeToReplace, string $alias): void
    {
        $from = $innerScope->expression->args['from_'];
        $newSubquery = $from->args['this'];
        $newSubquery->set('joins', $nodeToReplace->args['joins'] ?? null);
        $nodeToReplace->replace($newSubquery);

        foreach ($outerScope->joinHints() as $joinHint) {
            foreach ($joinHint->findAll(Table::class) as $table) {
                if ($table->aliasOrName() === $nodeToReplace->aliasOrName()) {
                    $table->set('this', Builder::toIdentifier($newSubquery->aliasOrName()));
                }
            }
        }

        $outerScope->removeSource($alias);
        $outerScope->addSource($newSubquery->aliasOrName(), $innerScope->sources[$newSubquery->aliasOrName()]);
    }

    /**
     * Merge JOIN clauses of inner query into outer query.
     */
    private static function mergeJoins(Scope $outerScope, Scope $innerScope, From|Join $fromOrJoin): void
    {
        $newJoins = [];

        foreach ($innerScope->expression->argList('joins') as $join) {
            $newJoins[] = $join;
            $outerScope->addSource($join->aliasOrName(), $innerScope->sources[$join->aliasOrName()]);
        }

        if ($newJoins !== []) {
            $outerJoins = $outerScope->expression->argList('joins');

            if ($fromOrJoin instanceof From) {
                $position = 0;
            } else {
                $index = array_search($fromOrJoin, $outerJoins, true);
                $position = $index === false ? count($outerJoins) : $index + 1;
            }

            array_splice($outerJoins, $position, 0, $newJoins);
            $outerScope->expression->set('joins', $outerJoins);
        }
    }

    /**
     * Merge projections of inner query into outer query.
     */
    private static function mergeExpressions(Scope $outerScope, Scope $innerScope, string $alias): void
    {
        /** @var array<string, list<Column>> $outerColumns */
        $outerColumns = [];

        foreach ($outerScope->columns() as $column) {
            if ($column->text('table') === $alias) {
                $outerColumns[$column->name()][] = $column;
            }
        }

        $group = $outerScope->expression->args['group'] ?? null;

        foreach ($innerScope->expression->expressions() as $expression) {
            $projectionName = $expression->aliasOrName();

            if ($projectionName === '') {
                continue;
            }

            $columnsToReplace = $outerColumns[$projectionName] ?? [];

            if ($columnsToReplace === []) {
                continue;
            }

            $expression = $expression->unalias();
            $mustWrap = true;

            foreach (self::SAFE_TO_REPLACE_UNWRAPPED as $class) {
                if ($expression instanceof $class) {
                    $mustWrap = false;
                }
            }

            $isNumber = $expression->isNumber();
            $last = count($columnsToReplace) - 1;
            $groupOrdinal = null;

            if ($isNumber && $outerScope->expression instanceof Select) {
                foreach ($outerScope->expression->selects() as $j => $s) {
                    $unaliased = $s->unalias();

                    if ($unaliased instanceof Column && $unaliased->text('table') === $alias && $unaliased->name() === $projectionName) {
                        $groupOrdinal = $j + 1;

                        break;
                    }
                }
            }

            foreach ($columnsToReplace as $i => $column) {
                $parent = $column->parent;

                if ($isNumber && $group instanceof Expression) {
                    $item = null;

                    foreach ($group->expressions() as $e) {
                        if ($e->unnest() === $column) {
                            $item = $e;

                            break;
                        }
                    }

                    if ($item !== null) {
                        $item->replace(Literal::number((int) $groupOrdinal));

                        continue;
                    }
                }

                $replacement = $i < $last ? $expression->copy() : $expression;

                if (($parent instanceof Unary || $parent instanceof Binary) && $mustWrap) {
                    $replacement = Builder::paren($replacement, copy: false);
                }

                if ($parent instanceof Select && $column->name() !== $expression->name()) {
                    $replacement = Builder::alias($replacement, $column->name(), copy: false);
                }

                $column->replace($replacement);
            }
        }
    }

    /**
     * Merge WHERE clause of inner query into outer query.
     */
    private static function mergeWhere(Scope $outerScope, Scope $innerScope, From|Join $fromOrJoin): void
    {
        $where = $innerScope->expression->args['where'] ?? null;
        $condition = $where instanceof Expression ? ($where->args['this'] ?? null) : null;

        if (! $condition instanceof Expression) {
            return;
        }

        $expression = $outerScope->expression;

        if ($fromOrJoin instanceof Join) {
            $from = $expression->args['from_'] ?? null;
            $sources = $from instanceof Expression ? [$from->aliasOrName() => true] : [];

            foreach ($expression->argList('joins') as $join) {
                $source = $join->aliasOrName();
                $sources[$source] = true;

                if ($source === $fromOrJoin->aliasOrName()) {
                    break;
                }
            }

            if (array_diff(Builder::columnTableNames($condition), array_map(strval(...), array_keys($sources))) === []) {
                $fromOrJoin->on($condition, copy: false);
                $fromOrJoin->set('on', $fromOrJoin->args['on'] ?? null);

                return;
            }
        }

        if ($expression instanceof Select) {
            $expression->where($condition, copy: false);
        }
    }

    /**
     * Merge ORDER clause of inner query into outer query.
     */
    private static function mergeOrder(Scope $outerScope, Scope $innerScope): void
    {
        $innerOrder = $innerScope->expression->args['order'] ?? null;

        if (! $innerOrder instanceof Expression) {
            return;
        }

        foreach (['group', 'distinct', 'having', 'order'] as $arg) {
            if (self::truthy($outerScope->expression->args[$arg] ?? null)) {
                return;
            }
        }

        if (count($outerScope->selectedSources()) !== 1) {
            return;
        }

        foreach ($outerScope->expression->expressions() as $expression) {
            if ($expression->find(AggFunc::class) !== null) {
                return;
            }
        }

        $outerScope->expression->set('order', $innerOrder);
    }

    private static function mergeHints(Scope $outerScope, Scope $innerScope): void
    {
        $innerHint = $innerScope->expression->args['hint'] ?? null;

        if (! $innerHint instanceof Expression) {
            return;
        }

        $outerHint = $outerScope->expression->args['hint'] ?? null;

        if ($outerHint instanceof Expression) {
            foreach ($innerHint->expressions() as $hintExpression) {
                $outerHint->append('expressions', $hintExpression);
            }
        } else {
            $outerScope->expression->set('hint', $innerHint);
        }
    }

    /**
     * Remove CTE from the AST.
     */
    private static function popCte(Scope $innerScope): void
    {
        $cte = $innerScope->expression->parent;
        $with = $cte?->parent;

        if ($cte === null || $with === null) {
            return;
        }

        if (count($with->expressions()) === 1) {
            $with->pop();
        } else {
            $cte->pop();
        }
    }

    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== [] && $value !== '';
    }
}
