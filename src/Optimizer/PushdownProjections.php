<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use LaravelSqlParser\LaravelSqlParser\Errors\OptimizeError;
use LaravelSqlParser\LaravelSqlParser\Expressions\AggFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cube;
use LaravelSqlParser\LaravelSqlParser\Expressions\Except;
use LaravelSqlParser\LaravelSqlParser\Expressions\ExplodingGenerateSeries;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\GroupingSets;
use LaravelSqlParser\LaravelSqlParser\Expressions\Intersect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Max;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\QueryTransform;
use LaravelSqlParser\LaravelSqlParser\Expressions\Rollup;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\UDTF;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;
use LaravelSqlParser\LaravelSqlParser\Expressions\With;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;

/**
 * Remove unused projections and CTEs while preserving all outermost outputs
 * (sqlglot optimizer/pushdown_projections.py).
 *
 * Reachability is stored as integer bitsets (bit i = root output i), keyed by spl_object_id of
 * the scope. Per-output bits therefore cover at most 63 root outputs; whole_query uses one bit.
 *
 * @phpstan-type Reachability array{
 *     scopes: list<Scope>,
 *     live: array<int, int>,
 *     selections: array<int, list<int>>,
 *     isAgg: array<int, bool>,
 *     groupByOrdinals: array<int, list<array{0: Literal, 1: Expression}>>,
 *     setNames: array<int, list<string>>,
 * }
 */
final class PushdownProjections
{
    /** GROUP BY constructs whose children are grouping items. */
    private const array GROUPING_CONSTRUCTS = [Cube::class, GroupingSets::class, Paren::class, Rollup::class, Tuple::class];

    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @param  mixed  $journal  accepted for signature parity with sqlglot; mutations aren't journaled
     * @return T
     */
    public static function pushdownProjections(Expression $expression, mixed $journal = null): Expression
    {
        self::pruneProjections(self::projectionReachability($expression, wholeQuery: true), root: 0);

        return $expression;
    }

    /**
     * Find which scopes and projections each outermost output reaches through dependencies,
     * without changing the query. The input must be qualified with projection stars expanded.
     *
     * @return Reachability
     */
    public static function projectionReachability(Expression $expression, bool $wholeQuery = false): array
    {
        if (! $wholeQuery && ! $expression instanceof Query) {
            throw new OptimizeError('projection_reachability requires a query');
        }

        $scopes = Scope::traverse($expression);

        if ($scopes === []) {
            if ($wholeQuery) {
                return ['scopes' => [], 'live' => [], 'selections' => [], 'isAgg' => [], 'groupByOrdinals' => [], 'setNames' => []];
            }

            throw new OptimizeError('projection_reachability requires a query scope');
        }

        // Fast path: a single SELECT retaining all outputs needs no dependency graph
        $scope = $scopes[0];
        $query = $scope->expression;

        if ($wholeQuery && count($scopes) === 1 && $query instanceof Select) {
            if ($query->isStar()) {
                throw new OptimizeError('projection_reachability requires star-free selections');
            }

            $windows = $query->argList('windows');
            $isAgg = false;
            foreach ($query->selects() as $s) {
                if (Helpers::projectionHasAggregate($s, $windows)) {
                    $isAgg = true;

                    break;
                }
            }
            $id = spl_object_id($scope);

            return [
                'scopes' => $scopes,
                'live' => [$id => 1],
                'selections' => [$id => array_fill(0, count($query->selects()), 1)],
                'isAgg' => [$id => $isAgg],
                'groupByOrdinals' => [$id => self::groupByOrdinalRefs($query)],
                'setNames' => [],
            ];
        }

        // Dependency nodes are integers: $deps[n] lists what node n requires, $requiredBy[n] its bitset
        /** @var list<list<int>> $deps */
        $deps = [];
        $newNode = static function () use (&$deps): int {
            $deps[] = [];

            return count($deps) - 1;
        };

        /** @var array<int, int> $scopeNodes */
        $scopeNodes = [];
        /** @var array<int, list<string>> $outputNames */
        $outputNames = [];
        /** @var array<int, list<int>> $outputNodes */
        $outputNodes = [];
        /** @var array<int, array<string, list<int>>> $outputsByName */
        $outputsByName = [];
        /** @var array<int, int> $owners expression id => node */
        $owners = [];
        /** @var array<int, Scope> $scopesByExpression */
        $scopesByExpression = [];
        /** @var array<int, bool> $isAgg */
        $isAgg = [];
        /** @var array<int, list<array{0: Literal, 1: Expression}>> $groupByOrdinals */
        $groupByOrdinals = [];

        // Register every node before connecting scopes: correlated and recursive references
        // can point outside the descendants already visited in this post-order traversal.
        foreach ($scopes as $scope) {
            $sid = spl_object_id($scope);
            $query = $scope->expression;

            if ($query instanceof Select && $query->isStar()) {
                throw new OptimizeError('projection_reachability requires star-free selections');
            }

            $scopesByExpression[spl_object_id($query)] = $scope;
            $scopeNode = $scopeNodes[$sid] = $newNode();
            $owners[spl_object_id($query)] = $scopeNode;

            if ($query instanceof SetOperation) {
                [$left, $right] = $scope->setOperationScopes;
                $leftNames = $outputNames[spl_object_id($left)];
                $outputNames[$sid] = ($query->args['by_name'] ?? null)
                    ? array_values(array_unique([...$leftNames, ...$outputNames[spl_object_id($right)]]))
                    : $leftNames;
            } else {
                $outputNames[$sid] = array_map(static fn (Expression $s): string => $s->aliasOrName(), self::selectsOf($query));
            }

            $outputs = [];
            $outputsByName[$sid] = [];
            foreach ($outputNames[$sid] as $name) {
                $outputNode = $newNode();
                $outputs[] = $outputNode;
                $outputsByName[$sid][$name][] = $outputNode;
                $deps[$outputNode][] = $scopeNode;
            }
            $outputNodes[$sid] = $outputs;

            if ($query instanceof Select) {
                foreach ($query->selects() as $i => $s) {
                    if (isset($outputs[$i])) {
                        $owners[spl_object_id($s)] = $outputs[$i];
                    }
                }
            }
        }

        // The node whose dependencies include an expression's references: walk up to a registered
        // projection (its output node) or scope expression (its scope node).
        $owner = static function (Expression $node) use (&$owners): int {
            while (! isset($owners[spl_object_id($node)])) {
                $node = $node->parent ?? throw new OptimizeError('Expression outside any scope');
            }

            return $owners[spl_object_id($node)];
        };

        foreach ($scopes as $scope) {
            $sid = spl_object_id($scope);
            $query = $scope->expression;
            $scopeNode = $scopeNodes[$sid];
            $outputs = $outputNodes[$sid];
            $order = $query->args['order'] ?? null;
            $keepAll = (bool) ($query->args['distinct'] ?? null)
                || $query instanceof Intersect
                || $query instanceof Except
                || self::isSelfReferencingCte($scope)
                || ! ($query instanceof Select || $query instanceof SetOperation);

            // The containing expression requires the subquery's scope node and all its outputs
            foreach ($scope->subqueryScopes as $child) {
                $anchor = $child->expression->parent ?? throw new OptimizeError('Subquery without parent');
                $cid = spl_object_id($child);
                array_push($deps[$owner($anchor)], $scopeNodes[$cid], ...$outputNodes[$cid]);
            }

            // A wrapper such as (SELECT ...) LIMIT 1 exposes its inner query's outputs
            if ($query instanceof Subquery) {
                foreach ($scope->derivedTableScopes as $child) {
                    $cid = spl_object_id($child);
                    array_push($deps[$scopeNode], $scopeNodes[$cid], ...$outputNodes[$cid]);
                }
            }

            if ($query instanceof SetOperation) {
                [$left, $right] = $scope->setOperationScopes;
                $lid = spl_object_id($left);
                $rid = spl_object_id($right);
                array_push($deps[$scopeNode], $scopeNodes[$lid], $scopeNodes[$rid]);
                $byName = (bool) ($query->args['by_name'] ?? null);

                // Special set-operation modes need different column matching
                if ($query->kind() !== '' || $query->side() !== '' || ($byName && $scope->outerColumns !== [])) {
                    $keepAll = true;
                }

                if (! $byName && count($outputNodes[$lid]) !== count($outputNodes[$rid])) {
                    throw new OptimizeError("Invalid set operation due to column mismatch: {$query->sql()}.");
                }

                // Match outputs by name for BY NAME, otherwise by position, with edges both ways
                foreach ([$lid, $rid] as $bid) {
                    foreach ($outputNodes[$bid] as $i => $outputNode) {
                        $targets = $byName ? ($outputsByName[$sid][$outputNames[$bid][$i]] ?? []) : [$outputs[$i]];

                        foreach ($targets as $output) {
                            $deps[$output][] = $outputNode;
                            $deps[$outputNode][] = $output;
                        }
                    }
                }
            }

            if ($keepAll) {
                array_push($deps[$scopeNode], ...$outputs);
            } elseif ($order instanceof Expression) {
                // Keeping the prefix preserves positions referenced by remaining ORDER BY ordinals
                $maxOrdinal = 0;
                foreach ($order->argList('expressions') as $ordered) {
                    $this_ = $ordered->args['this'] ?? null;

                    if ($this_ instanceof Literal && $this_->isInt()) {
                        $maxOrdinal = max($maxOrdinal, (int) $this_->name());
                    }
                }
                array_push($deps[$scopeNode], ...array_slice($outputs, 0, max(0, $maxOrdinal)));
            }

            foreach (self::outputColumnRefs($query, scoped: ! $query instanceof Select) as $name) {
                array_push($deps[$scopeNode], ...($outputsByName[$sid][$name] ?? []));
            }

            array_push($deps[$scopeNode], ...array_slice($outputs, 0, count($scope->outerColumns)));

            if ($query instanceof Select) {
                $windows = $query->argList('windows');
                $groupAll = self::isImplicitGroupByAll($query);
                $groupByOrdinals[$sid] = self::groupByOrdinalRefs($query);
                $ordinals = [];
                foreach ($groupByOrdinals[$sid] as [, $s]) {
                    $ordinals[spl_object_id($s)] = true;
                }
                $firstAggregate = null;
                $nonAggregates = [];
                $hasGroupingKey = false;

                foreach ($query->selects() as $i => $selection) {
                    if (! isset($outputs[$i])) {
                        break;
                    }

                    $outputNode = $outputs[$i];
                    [$aggregate, $hasColumn, $hasSrf] = self::projectionProperties($selection, $windows);

                    if ($aggregate) {
                        $firstAggregate ??= $outputNode;
                    } else {
                        $nonAggregates[] = $outputNode;
                    }

                    if ($groupAll && ! $aggregate && $hasColumn) {
                        $hasGroupingKey = true;
                    }

                    if (isset($ordinals[spl_object_id($selection)]) || ($groupAll && ! $aggregate) || $hasSrf) {
                        $deps[$scopeNode][] = $outputNode;
                    }
                }

                $isAgg[$sid] = $firstAggregate !== null;

                if ($firstAggregate !== null && (($query->args['group'] ?? null) === null || ($groupAll && ! $hasGroupingKey))) {
                    // Keeping a constant while dropping the last aggregate would turn one row into one
                    // per input row; an empty projection list uses MAX(1) when pruning instead.
                    foreach ($nonAggregates as $outputNode) {
                        $deps[$outputNode][] = $firstAggregate;
                    }
                }
            }

            foreach ($scope->references() as [$name, $reference]) {
                $source = $scope->sources[$name] ?? null;

                if (! $source instanceof Scope) {
                    continue;
                }

                // Recursive references can use a second Scope object for the same expression
                $source = $scopesByExpression[spl_object_id($source->expression)];
                $srcId = spl_object_id($source);
                $deps[$scopeNode][] = $scopeNodes[$srcId];
                $sourceOutputs = $outputNodes[$srcId];
                $first = self::selectsOf($source->expression)[0] ?? null;

                if (
                    isset($scope->semiOrAntiJoinTables()[$name])
                    || $scope->scansAllSubscopeColumns()
                    || $scope->pivots() !== []
                    || $first instanceof QueryTransform
                ) {
                    array_push($deps[$scopeNode], ...$sourceOutputs);
                }

                // Column alias lists bind by position, so keep every projection they name
                array_push($deps[$scopeNode], ...array_slice($sourceOutputs, 0, count($reference->aliasColumnNames())));
            }

            foreach ($scope->columns() as $col) {
                $table = $col->table();
                $source = $scope->sources[$table !== '' ? $table : $col->name()] ?? null;

                if ($source instanceof Scope) {
                    $srcId = spl_object_id($scopesByExpression[spl_object_id($source->expression)]);
                    array_push($deps[$owner($col)], ...($table !== '' ? ($outputsByName[$srcId][$col->name()] ?? []) : $outputNodes[$srcId]));
                }
            }

            foreach ($scope->tableColumns() as $tableColumn) {
                $source = $scope->sources[$tableColumn->name()] ?? null;

                if ($source instanceof Scope) {
                    $srcId = spl_object_id($scopesByExpression[spl_object_id($source->expression)]);
                    array_push($deps[$owner($tableColumn)], ...$outputNodes[$srcId]);
                }
            }
        }

        // DML/DDL can have several independent queries and CTEs referenced outside any query
        if ($expression instanceof Query) {
            $roots = [$scopes[count($scopes) - 1]];
        } else {
            $roots = array_values(array_filter($scopes, static fn (Scope $s): bool => $s->parent === null || ! isset($scopeNodes[spl_object_id($s->parent)])));
        }

        $requiredBy = array_fill(0, count($deps), 0);
        $pending = [];

        foreach ($roots as $root) {
            $rid = spl_object_id($root);
            $rootOutputs = $outputNodes[$rid];
            $allRoots = $wholeQuery ? 1 : (1 << count($rootOutputs)) - 1;
            $requiredBy[$scopeNodes[$rid]] = $allRoots;

            foreach ($rootOutputs as $i => $outputNode) {
                $requiredBy[$outputNode] = $wholeQuery ? $allRoots : 1 << $i;
            }

            array_push($pending, $scopeNodes[$rid], ...$rootOutputs);
        }

        $queued = array_fill_keys($pending, true);
        $head = 0;

        while (isset($pending[$head])) {
            $node = $pending[$head];
            unset($pending[$head]);
            $head++;
            unset($queued[$node]);

            foreach ($deps[$node] as $dependency) {
                $bits = $requiredBy[$dependency] | $requiredBy[$node];

                if ($bits !== $requiredBy[$dependency]) {
                    $requiredBy[$dependency] = $bits;

                    if (! isset($queued[$dependency])) {
                        $queued[$dependency] = true;
                        $pending[] = $dependency;
                    }
                }
            }
        }

        $live = $selections = $setNames = [];

        foreach ($scopes as $scope) {
            $sid = spl_object_id($scope);
            $live[$sid] = $requiredBy[$scopeNodes[$sid]];
            $selections[$sid] = array_map(static fn (int $n): int => $requiredBy[$n], $outputNodes[$sid]);

            if ($scope->expression instanceof SetOperation) {
                $setNames[$sid] = $scope->expression->namedSelects();
            }
        }

        return [
            'scopes' => $scopes,
            'live' => $live,
            'selections' => $selections,
            'isAgg' => $isAgg,
            'groupByOrdinals' => $groupByOrdinals,
            'setNames' => $setNames,
        ];
    }

    /**
     * Prune the analyzed tree to the scopes and projections reachable from root output `$root`
     * (0 for whole-query reachability): edits SELECT lists, renumbers bare GROUP BY ordinals and
     * removes unused CTEs unless `$removeCtes` is false.
     *
     * @param  Reachability  $reachability
     */
    public static function pruneProjections(array $reachability, int $root, mixed $journal = null, bool $removeCtes = true): void
    {
        $bit = 1 << $root;

        // Visit parents before their children when removing unused CTEs
        foreach (array_reverse($reachability['scopes']) as $scope) {
            $sid = spl_object_id($scope);

            if (($reachability['live'][$sid] & $bit) === 0) {
                if ($removeCtes && $scope->isCte()) {
                    $cteNode = $scope->expression->parent;

                    if ($cteNode instanceof CTE) {
                        $withNode = $cteNode->parent;
                        $cteNode->pop();

                        if ($withNode !== null && $withNode->argList('expressions') === []) {
                            $withNode->pop();
                        }
                    }
                }

                continue;
            }

            $expression = $scope->expression;

            if (! $expression instanceof Select) {
                continue;
            }

            $selects = $expression->selects();
            $subset = [];
            foreach ($selects as $i => $selection) {
                if ((($reachability['selections'][$sid][$i] ?? 0) & $bit) !== 0) {
                    $subset[] = $selection;
                }
            }

            if (count($subset) === count($selects)) {
                continue;
            }

            $ordinalRefs = $reachability['groupByOrdinals'][$sid] ?? [];
            $scopeIsAgg = $reachability['isAgg'][$sid] ?? false;

            if ($subset === []) {
                $placeholder = self::defaultSelection($scopeIsAgg);
                $ancestor = $scope;

                while ($ancestor->isSetOperation() && $ancestor->parent !== null) {
                    $ancestor = $ancestor->parent;
                    $aid = spl_object_id($ancestor);
                    $retainedName = null;

                    foreach ($reachability['setNames'][$aid] ?? [] as $i => $name) {
                        if ((($reachability['selections'][$aid][$i] ?? 0) & $bit) !== 0) {
                            $retainedName = $name;

                            break;
                        }
                    }

                    if ($retainedName !== null) {
                        // An empty BY NAME arm must contribute NULL under an existing output name
                        $placeholder->set('this', $scopeIsAgg ? new Max(['this' => new NullExpr]) : new NullExpr);
                        $placeholder->set('alias', Builder::toIdentifier($retainedName, quoted: true));

                        break;
                    }
                }

                $subset = [$placeholder];
            }

            $expression->set('expressions', $subset);

            if ($ordinalRefs !== []) {
                $newPos = [];
                foreach ($subset as $i => $selection) {
                    $newPos[spl_object_id($selection)] = $i + 1;
                }

                foreach ($ordinalRefs as [$node, $oldSelection]) {
                    $pos = $newPos[spl_object_id($oldSelection)] ?? null;

                    if ($pos !== null && (int) $node->name() !== $pos) {
                        $node->set('this', (string) $pos);
                    }
                }
            }
        }
    }

    /**
     * Selection to use if the selection list is empty.
     */
    public static function defaultSelection(bool $isAgg): Alias
    {
        $alias = Builder::alias($isAgg ? new Max(['this' => Literal::number(1)]) : '1', '_');

        return $alias instanceof Alias ? $alias : throw new OptimizeError('Expected an alias');
    }

    /**
     * @return list<Expression>
     */
    private static function selectsOf(Expression $expression): array
    {
        return $expression instanceof Query || $expression instanceof Table ? $expression->selects() : [];
    }

    /**
     * Whether a projection aggregates rows, reads columns, or contains a set-returning function.
     *
     * @param  list<Expression>  $windows
     * @return array{0: bool, 1: bool, 2: bool}
     */
    private static function projectionProperties(Expression $selection, array $windows): array
    {
        $hasAggregateOrWindow = $hasColumn = $hasSrf = false;

        foreach (Scope::findAllInScope($selection, AggFunc::class, Window::class, Column::class, Anonymous::class, UDTF::class, ExplodingGenerateSeries::class) as $node) {
            if ($node instanceof AggFunc || $node instanceof Window) {
                $hasAggregateOrWindow = true;
            }

            if ($node instanceof Column) {
                $hasColumn = true;
            }

            if ($node instanceof Anonymous || $node instanceof UDTF || $node instanceof ExplodingGenerateSeries) {
                $hasSrf = true;
            }
        }

        return [$hasAggregateOrWindow && Helpers::projectionHasAggregate($selection, $windows), $hasColumn, $hasSrf];
    }

    /**
     * @return list<string>
     */
    private static function outputColumnRefs(Expression $expression, bool $scoped): array
    {
        $refs = [];

        foreach (['order', 'sort', 'distribute', 'cluster'] as $arg) {
            $node = $expression->args[$arg] ?? null;

            if (! $node instanceof Expression) {
                continue;
            }

            $columns = $scoped ? Scope::findAllInScope($node, Column::class) : $node->findAll(Column::class);

            foreach ($columns as $column) {
                if ($column->table() === '') {
                    $refs[$column->name()] = true;
                }
            }
        }

        return array_map(strval(...), array_keys($refs));
    }

    private static function isSelfReferencingCte(Scope $scope): bool
    {
        $cte = $scope->expression->parent;

        if (! $cte instanceof CTE || ! $cte->parent instanceof With || ($cte->parent->args['recursive'] ?? null) !== true) {
            return false;
        }

        foreach ($scope->expression->findAll(Table::class) as $table) {
            if ($table->db() === '' && $table->name() === $cte->alias()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bare GROUP BY ALL infers its keys from the SELECT list, unlike ALL as a grouping-sets modifier.
     */
    private static function isImplicitGroupByAll(Select $select): bool
    {
        $group = $select->args['group'] ?? null;

        if (! $group instanceof Expression || ! ($group->args['all'] ?? null)) {
            return false;
        }

        return $group->argList('expressions') === []
            && ! ($group->args['cube'] ?? null)
            && ! ($group->args['rollup'] ?? null)
            && ! ($group->args['grouping_sets'] ?? null);
    }

    /**
     * Each GROUP BY integer ordinal with its pre-prune projection, including ordinals nested in
     * GROUPING SETS / CUBE / ROLLUP.
     *
     * @return list<array{0: Literal, 1: Expression}>
     */
    private static function groupByOrdinalRefs(Select $select): array
    {
        $group = $select->args['group'] ?? null;

        if (! $group instanceof Expression) {
            return [];
        }

        $selects = $select->selects();
        $n = count($selects);
        $refs = [];

        $collect = static function (array $nodes) use (&$collect, &$refs, $selects, $n): void {
            foreach ($nodes as $node) {
                if (! $node instanceof Expression) {
                    continue;
                }

                if (in_array($node::class, self::GROUPING_CONSTRUCTS, true) || self::isGroupingConstruct($node)) {
                    $collect($node->children());
                } elseif ($node instanceof Literal && $node->isInt()) {
                    $pos = (int) $node->name();

                    if ($pos >= 1 && $pos <= $n) {
                        $refs[] = [$node, $selects[$pos - 1]];
                    }
                }
            }
        };

        $collect($group->children());

        return $refs;
    }

    private static function isGroupingConstruct(Expression $node): bool
    {
        foreach (self::GROUPING_CONSTRUCTS as $class) {
            if ($node instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
