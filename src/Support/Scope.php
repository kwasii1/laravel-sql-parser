<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Support;

use Closure;
use Generator;
use Kwasii\LaravelSqlParser\Errors\OptimizeError;
use Kwasii\LaravelSqlParser\Expressions\Cluster;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\Count;
use Kwasii\LaravelSqlParser\Expressions\CTE;
use Kwasii\LaravelSqlParser\Expressions\DDL;
use Kwasii\LaravelSqlParser\Expressions\DerivedTable;
use Kwasii\LaravelSqlParser\Expressions\Distinct;
use Kwasii\LaravelSqlParser\Expressions\DML;
use Kwasii\LaravelSqlParser\Expressions\Dot;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\FinalExpr;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\Func;
use Kwasii\LaravelSqlParser\Expressions\Having;
use Kwasii\LaravelSqlParser\Expressions\Hint;
use Kwasii\LaravelSqlParser\Expressions\Join;
use Kwasii\LaravelSqlParser\Expressions\JoinHint;
use Kwasii\LaravelSqlParser\Expressions\Lateral;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\Pivot;
use Kwasii\LaravelSqlParser\Expressions\Qualify;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\SetOperation;
use Kwasii\LaravelSqlParser\Expressions\Star;
use Kwasii\LaravelSqlParser\Expressions\Subquery;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\TableAlias;
use Kwasii\LaravelSqlParser\Expressions\TableColumn;
use Kwasii\LaravelSqlParser\Expressions\UDTF;
use Kwasii\LaravelSqlParser\Expressions\Unnest;
use Kwasii\LaravelSqlParser\Expressions\Window;
use Kwasii\LaravelSqlParser\Expressions\WithinGroup;

/**
 * A selection scope (sqlglot optimizer/scope.py): a query plus the sources it can reference. A
 * source is a Table, or the Scope of a derived table, CTE or table function.
 *
 * The static helpers (walkInScope, findAllInScope) visit a query's nodes without descending into
 * the nodes that start child scopes (CTEs, derived tables, subqueries).
 */
final class Scope
{
    public const string ROOT = 'ROOT';

    public const string SUBQUERY = 'SUBQUERY';

    public const string DERIVED_TABLE = 'DERIVED_TABLE';

    public const string CTE = 'CTE';

    public const string SET_OPERATION = 'SET_OPERATION';

    public const string UDTF = 'UDTF';

    /** @var array<string, Table|Scope> */
    public array $sources;

    /** @var array<string, Table|Scope> */
    public array $lateralSources;

    /** @var array<string, Table|Scope> */
    public array $cteSources;

    /** @var list<string> */
    public array $outerColumns;

    /** @var list<Scope> */
    public array $subqueryScopes = [];

    /** @var list<Scope> */
    public array $derivedTableScopes = [];

    /** @var list<Scope> */
    public array $tableScopes = [];

    /** @var list<Scope> */
    public array $cteScopes = [];

    /** @var list<Scope> */
    public array $setOperationScopes = [];

    /** @var list<Scope> */
    public array $udtfScopes = [];

    private bool $collected = false;

    private bool $scansAllSubscopeColumns = false;

    /** @var list<Column> */
    private array $rawColumns = [];

    /** @var list<TableColumn> */
    private array $tableColumns = [];

    /** @var list<Expression> */
    private array $tables = [];

    /** @var list<CTE> */
    private array $ctes = [];

    /** @var list<Expression> */
    private array $derivedTables = [];

    /** @var list<Expression> */
    private array $udtfs = [];

    /** @var list<Expression> */
    private array $subqueries = [];

    /** @var array<string, true> */
    private array $semiAntiJoinTables = [];

    /** @var list<array{0: string, 1: Expression}>|null */
    private ?array $references = null;

    /** @var list<Pivot>|null */
    private ?array $pivots = null;

    /** @var list<Expression> star columns (t.*) and star dots (s.f.*) */
    private array $stars = [];

    /** @var list<JoinHint> */
    private array $joinHints = [];

    /** @var array<int, true> object ids of the columns of this scope's expression */
    private array $columnIndex = [];

    /** @var array<string, array{0: Expression, 1: Table|Scope}>|null */
    private ?array $selectedSources = null;

    /** @var list<Column>|null */
    private ?array $externalColumnsCache = null;

    /** @var list<Column>|null */
    private ?array $localColumns = null;

    /**
     * @param  array<string, Table|Scope>  $sources
     * @param  list<string>  $outerColumns
     * @param  array<string, Table|Scope>  $lateralSources
     * @param  array<string, Table|Scope>  $cteSources
     */
    public function __construct(
        public readonly Expression $expression,
        array $sources = [],
        array $outerColumns = [],
        public readonly ?Scope $parent = null,
        public readonly string $scopeType = self::ROOT,
        array $lateralSources = [],
        array $cteSources = [],
        public readonly bool $canBeCorrelated = false,
    ) {
        $this->lateralSources = $lateralSources;
        $this->cteSources = $cteSources;
        $this->sources = array_merge($sources, $lateralSources, $cteSources);
        $this->outerColumns = $outerColumns;
    }

    /**
     * Branch from this scope to a new, inner scope.
     *
     * @param  array<string, Table|Scope>  $sources
     * @param  array<string, Table|Scope>  $cteSources
     * @param  array<string, Table|Scope>|null  $lateralSources
     * @param  list<string>  $outerColumns
     */
    public function branch(Expression $expression, string $scopeType, array $sources = [], array $cteSources = [], ?array $lateralSources = null, array $outerColumns = []): self
    {
        return new self(
            $expression->unnest(),
            sources: $sources,
            outerColumns: $outerColumns,
            parent: $this,
            scopeType: $scopeType,
            lateralSources: $lateralSources ?? [],
            cteSources: array_merge($this->cteSources, $cteSources),
            canBeCorrelated: $this->canBeCorrelated || $scopeType === self::SUBQUERY || $scopeType === self::UDTF,
        );
    }

    public function clearCache(): void
    {
        $this->collected = false;
        $this->references = null;
        $this->pivots = null;
        $this->selectedSources = null;
        $this->clearColumnCache();
    }

    /**
     * Invalidate the column classification caches, e.g. after columns are qualified in place.
     */
    public function clearColumnCache(): void
    {
        $this->columns = null;
        $this->externalColumnsCache = null;
        $this->localColumns = null;
    }

    private function collect(): void
    {
        $this->tables = $this->ctes = $this->subqueries = $this->derivedTables = $this->udtfs = [];
        $this->rawColumns = $this->tableColumns = [];
        $this->semiAntiJoinTables = [];
        $this->stars = $this->joinHints = [];
        $this->columnIndex = [];
        $this->scansAllSubscopeColumns = false;

        // The inner query of a Subquery-rooted scope is scoped as a derived table by
        // traverseTables(), so it must not also be collected as a subquery
        $innerQuery = $this->expression instanceof Subquery ? $this->expression->unnest() : null;

        foreach (self::walkInScope($this->expression) as $node) {
            if ($node === $this->expression) {
                continue;
            }

            if ($node instanceof Dot && $node->isStar()) {
                $this->stars[] = $node;
            } elseif ($node instanceof Column && $node::class === Column::class) {
                $this->columnIndex[spl_object_id($node)] = true;

                if (! ($node->args['this'] ?? null) instanceof Star) {
                    $this->rawColumns[] = $node;
                } else {
                    $this->stars[] = $node;
                }
            } elseif ($node instanceof Table && ! $node->parent instanceof JoinHint) {
                if ($node->parent instanceof Join && $node->parent->isSemiOrAntiJoin()) {
                    $this->semiAntiJoinTables[$node->aliasOrName()] = true;
                }

                $this->tables[] = $node;
            } elseif ($node instanceof JoinHint) {
                $this->joinHints[] = $node;
            } elseif ($node::class === Lateral::class || ($node instanceof UDTF && ($node->parent instanceof From || $node->parent instanceof Join))) {
                $this->udtfs[] = $node;
            } elseif ($node instanceof CTE) {
                $this->ctes[] = $node;
            } elseif (self::isDerivedTable($node) && self::isFromOrJoin($node)) {
                $this->derivedTables[] = $node;
            } elseif (($node instanceof Select || $node instanceof SetOperation) && ! self::isFromOrJoin($node) && $node !== $innerQuery) {
                $this->subqueries[] = $node;
            } elseif ($node instanceof TableColumn) {
                $this->tableColumns[] = $node;
            } elseif ($node instanceof Star && (($node->args['except_'] ?? null) !== null || ! $node->parent instanceof Count)) {
                $this->scansAllSubscopeColumns = true;
            }
        }

        $this->collected = true;
    }

    /**
     * Whether this scope selects every column of its sources (e.g. SELECT * or a bare star argument).
     */
    public function scansAllSubscopeColumns(): bool
    {
        $this->collected || $this->collect();

        return $this->scansAllSubscopeColumns;
    }

    /**
     * The scope tree from this node, in depth-first post-order (sqlglot Scope.traverse).
     *
     * @return list<Scope>
     */
    public function traverseTree(): array
    {
        $stack = [$this];
        $result = [];

        while ($stack !== []) {
            $scope = array_pop($stack);
            $result[] = $scope;
            array_push($stack, ...$scope->cteScopes, ...$scope->setOperationScopes, ...$scope->tableScopes, ...$scope->subqueryScopes);
        }

        return array_reverse($result);
    }

    /**
     * How many times each scope of this tree is referenced, keyed by spl_object_id (sqlglot Scope.ref_count).
     *
     * @return array<int, int>
     */
    public function refCount(): array
    {
        $counts = [];

        foreach ($this->traverseTree() as $scope) {
            foreach ($scope->selectedSources() as [, $source]) {
                $id = spl_object_id($source);
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }

            foreach (array_keys($scope->semiOrAntiJoinTables()) as $name) {
                if (isset($scope->sources[$name])) {
                    $id = spl_object_id($scope->sources[$name]);
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
        }

        return $counts;
    }

    /**
     * @return list<Expression>
     */
    public function tables(): array
    {
        $this->collected || $this->collect();

        return $this->tables;
    }

    /**
     * @return list<CTE>
     */
    public function ctes(): array
    {
        $this->collected || $this->collect();

        return $this->ctes;
    }

    /**
     * @return list<Expression>
     */
    public function derivedTables(): array
    {
        $this->collected || $this->collect();

        return $this->derivedTables;
    }

    /**
     * @return list<Expression>
     */
    public function udtfs(): array
    {
        $this->collected || $this->collect();

        return $this->udtfs;
    }

    /**
     * @return list<Expression>
     */
    public function subqueries(): array
    {
        $this->collected || $this->collect();

        return $this->subqueries;
    }

    /**
     * @return list<TableColumn>
     */
    public function tableColumns(): array
    {
        $this->collected || $this->collect();

        return $this->tableColumns;
    }

    /**
     * Columns written in this scope (not counting columns of correlated subqueries).
     *
     * @return list<Column>
     */
    public function rawColumns(): array
    {
        $this->collected || $this->collect();

        return $this->rawColumns;
    }

    /**
     * @return list<array{0: string, 1: Expression}>
     */
    public function references(): array
    {
        if ($this->references === null) {
            $this->references = [];

            foreach ($this->tables() as $table) {
                $this->references[] = [$table->aliasOrName(), $table];
            }

            foreach ([...$this->derivedTables(), ...$this->udtfs()] as $expression) {
                $this->references[] = [
                    self::sourceAlias($expression),
                    $expression->argList('pivots') !== [] ? $expression : $expression->unnest(),
                ];
            }
        }

        return $this->references;
    }

    /**
     * @return list<Pivot>
     */
    public function pivots(): array
    {
        if ($this->pivots === null) {
            $this->pivots = [];

            foreach ($this->references() as [, $node]) {
                foreach ($node->argList('pivots') as $pivot) {
                    if ($pivot instanceof Pivot) {
                        $this->pivots[] = $pivot;
                    }
                }
            }
        }

        return $this->pivots;
    }

    /**
     * Sources on the right of a SEMI or ANTI join, which aren't selected from.
     *
     * @return array<string, true>
     */
    public function semiOrAntiJoinTables(): array
    {
        $this->collected || $this->collect();

        return $this->semiAntiJoinTables;
    }

    /**
     * The root scope of an expression's scope tree, or null (sqlglot build_scope).
     */
    public static function build(Expression $expression): ?self
    {
        $scopes = self::traverse($expression);

        return $scopes === [] ? null : $scopes[count($scopes) - 1];
    }

    /**
     * Replace a node and keep the scope up to date.
     */
    public function replace(Expression $old, Expression $new): void
    {
        $old->replace($new);
        $this->clearCache();
    }

    /** @var list<Column>|null */
    private ?array $columns = null;

    /**
     * The columns of this scope, plus columns of correlated subqueries that reference it.
     *
     * @return list<Column>
     */
    public function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $external = [];

        foreach ([...$this->subqueryScopes, ...$this->udtfScopes, ...array_filter($this->derivedTableScopes, static fn (Scope $s): bool => $s->canBeCorrelated)] as $scope) {
            array_push($external, ...$scope->externalColumns());
        }

        $namedSelects = $this->expression instanceof Query ? array_fill_keys($this->expression->namedSelects(), true) : [];
        $columns = [];

        foreach ([...$this->rawColumns(), ...$external] as $column) {
            $ancestor = $column->findAncestor(Select::class, Qualify::class, Order::class, Cluster::class, Having::class, Hint::class, Table::class, Star::class, Distinct::class);
            $ancestorParent = $ancestor?->parent;

            if (
                $ancestor === null
                || $column->text('table') !== ''
                || $ancestor instanceof Select
                || ($ancestor instanceof Table && ! ($ancestor->args['this'] ?? null) instanceof Func)
                || (
                    ($ancestor instanceof Order || $ancestor instanceof Cluster || $ancestor instanceof Distinct)
                    && ($ancestorParent instanceof Window || $ancestorParent instanceof WithinGroup || ! $ancestorParent instanceof Select || ! isset($namedSelects[$column->name()]))
                )
                || ($ancestor instanceof Star && $column->argKey !== 'except_')
            ) {
                $columns[] = $column;
            }
        }

        return $this->columns = $columns;
    }

    /**
     * Columns that reference sources of outer scopes.
     *
     * @return list<Column>
     */
    public function externalColumns(): array
    {
        return $this->externalColumnsCache ??= $this->computeExternalColumns();
    }

    /**
     * @return list<Column>
     */
    private function computeExternalColumns(): array
    {
        if ($this->expression instanceof SetOperation && count($this->setOperationScopes) === 2) {
            return [...$this->setOperationScopes[0]->externalColumns(), ...$this->setOperationScopes[1]->externalColumns()];
        }

        $localSourceNames = [];

        foreach ($this->references() as [$name]) {
            $localSourceNames[$name] = true;
        }

        $semiAnti = $this->semiOrAntiJoinTables();

        return array_values(array_filter(
            $this->columns(),
            static fn (Column $c): bool => ! isset($localSourceNames[$c->text('table')]) && ! isset($semiAnti[$c->text('table')]),
        ));
    }

    public function isRoot(): bool
    {
        return $this->scopeType === self::ROOT;
    }

    public function isSubquery(): bool
    {
        return $this->scopeType === self::SUBQUERY;
    }

    public function isDerivedTableScope(): bool
    {
        return $this->scopeType === self::DERIVED_TABLE;
    }

    public function isSetOperation(): bool
    {
        return $this->scopeType === self::SET_OPERATION;
    }

    public function isCte(): bool
    {
        return $this->scopeType === self::CTE;
    }

    public function isUdtf(): bool
    {
        return $this->scopeType === self::UDTF;
    }

    /**
     * Whether this scope is a subquery that references columns of outer scopes.
     */
    public function isCorrelatedSubquery(): bool
    {
        return $this->canBeCorrelated && $this->externalColumns() !== [];
    }

    /**
     * Star columns (t.*) and star dots (s.f.*) of this scope.
     *
     * @return list<Expression>
     */
    public function stars(): array
    {
        $this->collected || $this->collect();

        return $this->stars;
    }

    /**
     * @return list<JoinHint>
     */
    public function joinHints(): array
    {
        $this->collected || $this->collect();

        return $this->joinHints;
    }

    /**
     * Object ids (spl_object_id) of the columns that belong to this scope's expression.
     *
     * @return array<int, true>
     */
    public function columnIndex(): array
    {
        $this->collected || $this->collect();

        return $this->columnIndex;
    }

    /**
     * The sources actually selected from in this scope (in a FROM or JOIN), by name: [node, source].
     *
     * @return array<string, array{0: Expression, 1: Table|Scope}>
     */
    public function selectedSources(): array
    {
        if ($this->selectedSources === null) {
            $result = [];
            $semiAnti = $this->semiOrAntiJoinTables();

            foreach ($this->references() as [$name, $node]) {
                if (isset($semiAnti[$name])) {
                    // The right side of a SEMI / ANTI join isn't selected from
                    continue;
                }

                if (isset($result[$name])) {
                    throw new OptimizeError("Alias already used: {$name}");
                }

                if (isset($this->sources[$name])) {
                    $result[$name] = [$node, $this->sources[$name]];
                }
            }

            $this->selectedSources = $result;
        }

        return $this->selectedSources;
    }

    /**
     * Columns of this scope that aren't external.
     *
     * @return list<Column>
     */
    public function localColumns(): array
    {
        if ($this->localColumns === null) {
            $external = [];

            foreach ($this->externalColumns() as $column) {
                $external[spl_object_id($column)] = true;
            }

            $this->localColumns = array_values(array_filter($this->columns(), static fn (Column $c): bool => ! isset($external[spl_object_id($c)])));
        }

        return $this->localColumns;
    }

    /**
     * @return list<Column>
     */
    public function unqualifiedColumns(): array
    {
        return array_values(array_filter($this->columns(), static fn (Column $c): bool => $c->text('table') === ''));
    }

    /**
     * The columns of this scope that reference a source.
     *
     * @return list<Column>
     */
    public function sourceColumns(string $sourceName): array
    {
        return array_values(array_filter($this->columns(), static fn (Column $c): bool => $c->text('table') === $sourceName));
    }

    public function renameSource(?string $oldName, string $newName): void
    {
        $oldName ??= '';

        if (array_key_exists($oldName, $this->sources)) {
            $source = $this->sources[$oldName];
            unset($this->sources[$oldName]);
            $this->sources[$newName] = $source;
        }
    }

    public function addSource(string $name, Table|Scope $source): void
    {
        $this->sources[$name] = $source;
        $this->clearCache();
    }

    public function removeSource(string $name): void
    {
        unset($this->sources[$name]);
        $this->clearCache();
    }

    /**
     * Every scope of an expression, in depth-first post-order: a scope's descendants come right
     * before it (sqlglot traverse_scope).
     *
     * @return list<Scope>
     */
    public static function traverse(Expression $expression): array
    {
        if ($expression instanceof Query || $expression instanceof DDL || $expression instanceof DML) {
            return iterator_to_array(self::traverseScope(new self($expression)), false);
        }

        return [];
    }

    /**
     * @return Generator<int, Scope>
     */
    private static function traverseScope(self $scope): Generator
    {
        $expression = $scope->expression;

        if ($expression instanceof Select) {
            yield from self::traverseSelect($scope);
        } elseif ($expression instanceof SetOperation) {
            yield from self::traverseCtes($scope);
            yield from self::traverseSetOperation($scope);

            return;
        } elseif ($expression instanceof Subquery) {
            if ($scope->isRoot()) {
                yield from self::traverseSelect($scope);
            } else {
                yield from self::traverseSubqueries($scope);
            }
        } elseif ($expression instanceof Table) {
            yield from self::traverseTables($scope);
        } elseif ($expression instanceof UDTF) {
            yield from self::traverseUdtfs($scope);
        } elseif ($expression instanceof DDL) {
            $query = $expression->args['expression'] ?? null;

            if ($query instanceof Query && $query instanceof Expression) {
                yield from self::traverseCtes($scope);
                yield from self::traverseScope(new self($query, cteSources: $scope->cteSources));
            }

            return;
        } elseif ($expression instanceof DML) {
            yield from self::traverseCtes($scope);

            // Bare tables in relation position (UPDATE ... FROM t, DELETE / MERGE ... USING t)
            // aren't part of any query, so they're scoped as standalone tables
            $relations = [];
            $from = $expression->args['from_'] ?? null;

            if ($from instanceof From && ($from->args['this'] ?? null) instanceof Expression) {
                $relations[] = $from->args['this'];
            }

            $using = $expression->args['using'] ?? null;

            if (is_array($using)) {
                array_push($relations, ...array_filter($using, static fn (mixed $u): bool => $u instanceof Expression));
            } elseif ($using instanceof Expression) {
                $relations[] = $using;
            }

            foreach ($relations as $relation) {
                if ($relation instanceof Table) {
                    yield from self::traverseScope(new self($relation, cteSources: $scope->cteSources));
                }
            }

            foreach (self::walkInScope($expression) as $query) {
                if (! $query instanceof Query) {
                    continue;
                }

                // Don't yield the CTE / nested queries twice
                if ($query->parent instanceof CTE || $query->parent instanceof Subquery) {
                    continue;
                }

                if (self::isFromOrJoin($query)) {
                    $parent = $query->parent;

                    if ($parent instanceof Join && ($parent->parent instanceof Subquery || $parent->parent instanceof Table)) {
                        continue;
                    }

                    yield from self::traverseScope(new self($query, cteSources: $scope->cteSources));
                } else {
                    yield from self::traverseScope($scope->branch($query, self::SUBQUERY));
                }
            }

            return;
        } else {
            return;
        }

        yield $scope;
    }

    /**
     * @return Generator<int, Scope>
     */
    private static function traverseSelect(self $scope): Generator
    {
        yield from self::traverseCtes($scope);
        yield from self::traverseTables($scope);
        yield from self::traverseSubqueries($scope);
    }

    /**
     * @return Generator<int, Scope>
     */
    private static function traverseSetOperation(self $scope): Generator
    {
        $prevScope = null;
        $setOpScopeStack = [$scope];
        $setOp = $scope->expression;
        $expressionStack = [$setOp->args['expression'] ?? null, $setOp->args['this'] ?? null];

        while ($expressionStack !== []) {
            $expression = array_pop($expressionStack);
            $setOpScope = $setOpScopeStack[count($setOpScopeStack) - 1];

            if (! $expression instanceof Expression) {
                continue;
            }

            $newScope = $setOpScope->branch($expression, self::SET_OPERATION, outerColumns: $setOpScope->outerColumns);

            if ($expression instanceof SetOperation) {
                yield from self::traverseCtes($newScope);

                $setOpScopeStack[] = $newScope;
                $expressionStack[] = $expression->args['expression'] ?? null;
                $expressionStack[] = $expression->args['this'] ?? null;

                continue;
            }

            $branchScope = null;

            foreach (self::traverseScope($newScope) as $branchScope) {
                yield $branchScope;
            }

            if ($branchScope === null) {
                continue;
            }

            if ($prevScope !== null) {
                array_pop($setOpScopeStack);
                $setOpScope->setOperationScopes = [$prevScope, $branchScope];
                $prevScope = $setOpScope;

                yield $setOpScope;
            } else {
                $prevScope = $branchScope;
            }
        }
    }

    /**
     * @return Generator<int, Scope>
     */
    private static function traverseCtes(self $scope): Generator
    {
        $sources = [];

        foreach ($scope->ctes() as $cte) {
            $cteName = $cte->alias();

            // A recursive CTE has the form base_case UNION recursive, so the recursive scope is
            // the first part of the union
            $with = $scope->expression->args['with_'] ?? null;

            if ($with instanceof Expression && $with->flag('recursive')) {
                $union = $cte->args['this'] ?? null;

                if ($union instanceof SetOperation && ($union->args['this'] ?? null) instanceof Expression) {
                    $sources[$cteName] = $scope->branch($union->args['this'], self::CTE);
                }
            }

            $childScope = null;
            $body = $cte->args['this'] ?? null;

            if ($body instanceof Expression) {
                foreach (self::traverseScope($scope->branch($body, self::CTE, cteSources: $sources, outerColumns: $cte->aliasColumnNames())) as $childScope) {
                    yield $childScope;
                }
            }

            if ($childScope !== null) {
                $sources[$cteName] = $childScope;
                $scope->cteScopes[] = $childScope;
            }
        }

        $scope->sources = array_merge($scope->sources, $sources);
        $scope->cteSources = array_merge($scope->cteSources, $sources);
    }

    /**
     * @return Generator<int, Scope>
     */
    private static function traverseTables(self $scope): Generator
    {
        $sources = [];
        $expressions = [];
        $from = $scope->expression->args['from_'] ?? null;

        if ($from instanceof Expression && ($from->args['this'] ?? null) instanceof Expression) {
            $expressions[] = $from->args['this'];
        }

        foreach ($scope->expression->argList('joins') as $join) {
            if (($join->args['this'] ?? null) instanceof Expression) {
                $expressions[] = $join->args['this'];
            }
        }

        if ($scope->expression instanceof Table || $scope->expression instanceof Subquery) {
            // A Subquery-rooted scope (the FROM of a DML statement, a DDL source, (SELECT ...)
            // LIMIT 1) scopes its own inner query as a derived table
            $expressions[] = $scope->expression;
        }

        array_push($expressions, ...$scope->expression->argList('laterals'));

        for ($i = 0; $i < count($expressions); $i++) {
            $expression = $expressions[$i];

            if ($expression instanceof FinalExpr && ($expression->args['this'] ?? null) instanceof Expression) {
                $expression = $expression->args['this'];
            }

            if ($expression instanceof Table) {
                $tableName = $expression->name();
                $sourceName = $expression->aliasOrName();

                if (isset($scope->sources[$tableName]) && $expression->text('db') === '') {
                    // A reference to a parent source (e.g. a CTE), not an actual table, unless it
                    // is pivoted, because then it's a new table and hence a new source
                    $pivots = $expression->argList('pivots');

                    if ($pivots !== []) {
                        $sources[$pivots[count($pivots) - 1]->alias()] = $expression;
                    } else {
                        $sources[$sourceName] = $scope->sources[$tableName];
                    }
                } elseif (isset($sources[$sourceName])) {
                    $sources[self::findNewName(array_keys($sources), $tableName)] = $expression;
                } else {
                    $sources[$sourceName] = $expression;
                }

                // Make sure to not include the joins twice
                if ($expression !== $scope->expression) {
                    foreach ($expression->argList('joins') as $join) {
                        if (($join->args['this'] ?? null) instanceof Expression) {
                            $expressions[] = $join->args['this'];
                        }
                    }
                }

                continue;
            }

            if (! $expression instanceof DerivedTable) {
                continue;
            }

            if ($expression instanceof UDTF) {
                $lateralSources = $sources;
                $scopeType = self::UDTF;
            } elseif (self::isDerivedTable($expression)) {
                $lateralSources = null;
                $scopeType = self::DERIVED_TABLE;

                if ($expression !== $scope->expression) {
                    foreach ($expression->argList('joins') as $join) {
                        if (($join->args['this'] ?? null) instanceof Expression) {
                            $expressions[] = $join->args['this'];
                        }
                    }
                }
            } else {
                // Check for possible sources in nested table constructs
                if (($expression->args['this'] ?? null) instanceof Expression) {
                    $expressions[] = $expression->args['this'];
                }

                if ($expression !== $scope->expression) {
                    foreach ($expression->argList('joins') as $join) {
                        if (($join->args['this'] ?? null) instanceof Expression) {
                            $expressions[] = $join->args['this'];
                        }
                    }
                }

                continue;
            }

            $childScope = null;

            foreach (self::traverseScope($scope->branch($expression, $scopeType, lateralSources: $lateralSources, outerColumns: $expression->aliasColumnNames())) as $childScope) {
                yield $childScope;

                // Unaliased derived tables are keyed by '' (the latest one wins)
                $sources[self::sourceAlias($expression)] = $childScope;
            }

            if ($childScope !== null) {
                if ($scopeType === self::UDTF) {
                    $scope->udtfScopes[] = $childScope;
                } else {
                    $scope->derivedTableScopes[] = $childScope;
                }

                $scope->tableScopes[] = $childScope;
            }
        }

        $scope->sources = array_merge($scope->sources, $sources);
    }

    /**
     * @return Generator<int, Scope>
     */
    private static function traverseSubqueries(self $scope): Generator
    {
        foreach ($scope->subqueries() as $subquery) {
            $top = null;

            foreach (self::traverseScope($scope->branch($subquery, self::SUBQUERY)) as $childScope) {
                yield $childScope;
                $top = $childScope;
            }

            if ($top !== null) {
                $scope->subqueryScopes[] = $top;
            }
        }
    }

    /**
     * @return Generator<int, Scope>
     */
    private static function traverseUdtfs(self $scope): Generator
    {
        if ($scope->expression instanceof Unnest) {
            $udtfExpressions = $scope->expression->argList('expressions');
        } elseif ($scope->expression instanceof Lateral && ($scope->expression->args['this'] ?? null) instanceof Expression) {
            $udtfExpressions = [$scope->expression->args['this']];
        } else {
            $udtfExpressions = [];
        }

        $sources = [];

        foreach ($udtfExpressions as $expression) {
            if (! $expression instanceof Subquery) {
                continue;
            }

            $top = null;

            foreach (self::traverseScope($scope->branch($expression, self::SUBQUERY, outerColumns: $expression->aliasColumnNames())) as $childScope) {
                yield $childScope;
                $top = $childScope;
                $sources[self::sourceAlias($expression)] = $childScope;
            }

            if ($top !== null) {
                $scope->subqueryScopes[] = $top;
            }
        }

        $scope->sources = array_merge($scope->sources, $sources);
    }

    /**
     * Whether $expression is the FROM or JOIN clause of a SELECT.
     */
    public static function isFromOrJoin(Expression $expression): bool
    {
        $parent = $expression->parent;

        // Subqueries can be arbitrarily nested
        while ($parent !== null && $parent::class === Subquery::class) {
            $parent = $parent->parent;
        }

        return $parent !== null && ($parent::class === From::class || $parent::class === Join::class);
    }

    private static function sourceAlias(Expression $expression): string
    {
        $aliasArg = $expression->args['alias'] ?? null;
        $aliasName = $expression->alias();

        if ($aliasName === '' && $aliasArg instanceof TableAlias && count($aliasArg->argList('columns')) === 1) {
            $aliasName = $aliasArg->argList('columns')[0]->name();
        }

        return $aliasName;
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
     * @template T of object
     *
     * @param  class-string<T>  ...$types
     * @return list<T&Expression>
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
     * The first node of the given types in the expression's scope.
     *
     * @template T of object
     *
     * @param  class-string<T>  ...$types
     * @return (T&Expression)|null
     */
    public static function findInScope(Expression $expression, string ...$types): ?Expression
    {
        return self::findAllInScope($expression, ...$types)[0] ?? null;
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
