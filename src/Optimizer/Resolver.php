<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Generator;
use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Errors\OptimizeError;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Explode;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Join;
use Kwasii\LaravelSqlParser\Expressions\Lateral;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\QueryTransform;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\SetOperation;
use Kwasii\LaravelSqlParser\Expressions\Subquery;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\UDTF;
use Kwasii\LaravelSqlParser\Expressions\Unnest;
use Kwasii\LaravelSqlParser\Expressions\Values;
use Kwasii\LaravelSqlParser\Schema\Schema;
use Kwasii\LaravelSqlParser\Support\Scope;

/**
 * Resolves the sources of columns in a scope (sqlglot optimizer/resolver.py).
 */
final class Resolver
{
    public readonly Dialect $dialect;

    /** @var array<string, list<string>>|null */
    private ?array $sourceColumns = null;

    /** @var array<string, string>|null */
    private ?array $unambiguousColumns = null;

    /** @var array<string, true>|null */
    private ?array $allColumns = null;

    /** @var array<string, list<string>> */
    private array $sourceColumnsCache = [];

    /** @var array<string, DataType|null> */
    private array $columnTypeFromScopeCache = [];

    public function __construct(public readonly Scope $scope, public readonly Schema $schema, private readonly bool $inferSchema = true)
    {
        $this->dialect = $schema->dialect() ?? Dialect::get();
    }

    /**
     * The table of a column (by name or expression), when it can be found or inferred.
     */
    public function getTable(string|Column $column): ?Identifier
    {
        $columnName = is_string($column) ? $column : $column->name();
        $joinContext = $column instanceof Column ? $this->getColumnJoinContext($column) : null;
        $isSemiOrAntiJoin = $joinContext !== null && $joinContext->isSemiOrAntiJoin();

        // A SEMI / ANTI join's own table is excluded from the global lookup below, so skip it here
        $tableName = $isSemiOrAntiJoin ? null : $this->getTableNameFromSources($columnName);

        if (($tableName === null || $tableName === '') && $joinContext !== null) {
            // Fall back to disambiguating by the sources joined so far
            try {
                $tableName = $this->getTableNameFromSources($columnName, $this->getAvailableSourceColumns($joinContext));
            } catch (OptimizeError) {
                // Unknown sources
            }
        }

        if (($tableName === null || $tableName === '') && $this->inferSchema) {
            $sourcesWithoutSchema = [];

            foreach ($this->getAllSourceColumns() as $source => $columns) {
                if ($columns === [] || in_array('*', $columns, true)) {
                    $sourcesWithoutSchema[] = (string) $source;
                }
            }

            if (count($sourcesWithoutSchema) === 1) {
                $tableName = $sourcesWithoutSchema[0];
            }
        }

        $selected = $this->scope->selectedSources();

        if ($tableName === null || ! isset($selected[$tableName])) {
            return $tableName === null ? null : Builder::toIdentifier($tableName);
        }

        $node = $selected[$tableName][0];

        if ($node instanceof Query) {
            while ($node->alias() !== $tableName && $node->parent !== null) {
                $node = $node->parent;
            }
        }

        $nodeAlias = $node->argExpr('alias');

        if ($nodeAlias !== null) {
            $aliasThis = $nodeAlias->args['this'] ?? null;

            if ($aliasThis instanceof Identifier) {
                return $aliasThis->copy();
            }

            if (is_string($aliasThis)) {
                return Builder::toIdentifier($aliasThis);
            }
        }

        return Builder::toIdentifier($tableName);
    }

    /**
     * Resolvers for the outer scopes a correlated subquery can reference, innermost first.
     *
     * @return Generator<int, Resolver>
     */
    public function outerResolvers(): Generator
    {
        $scope = $this->scope;

        while ($scope->canBeCorrelated && $scope->parent !== null) {
            $scope = $scope->parent;

            yield new self($scope, $this->schema, $this->inferSchema);
        }
    }

    /**
     * Whether the columns of some source can't be determined, e.g. a table missing from the schema.
     */
    public function hasUnknownSources(): bool
    {
        foreach ($this->getAllSourceColumns() as $columns) {
            if ($columns === [] || in_array('*', $columns, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * All the columns of all the sources of the scope.
     *
     * @return array<string, true>
     */
    public function allColumns(): array
    {
        if ($this->allColumns === null) {
            $this->allColumns = [];

            foreach ($this->getAllSourceColumns() as $columns) {
                foreach ($columns as $column) {
                    $this->allColumns[$column] = true;
                }
            }
        }

        return $this->allColumns;
    }

    /**
     * @return list<string>
     */
    public function getSourceColumnsFromSetOp(Expression $expression): array
    {
        if ($expression instanceof Select) {
            return $expression->namedSelects();
        }

        if ($expression instanceof Subquery) {
            return $this->getSourceColumnsFromSetOp($expression->unnest());
        }

        if (! $expression instanceof SetOperation) {
            throw new OptimizeError("Unknown set operation: {$expression->sql()}");
        }

        // BigQuery set operation modifiers, e.g. INNER UNION ALL BY NAME
        $on = $expression->argList('on');

        if ($on !== []) {
            // {INNER | LEFT | FULL} UNION ALL BY NAME ON (col1, col2, ...)
            return array_map(static fn (Expression $col): string => $col->name(), $on);
        }

        $side = strtoupper($expression->text('side'));
        $kind = strtoupper($expression->text('kind'));

        if ($side !== '' || $kind !== '') {
            $left = $this->getSourceColumnsFromSetOp(self::exprArg($expression, 'this'));
            $right = $this->getSourceColumnsFromSetOp(self::exprArg($expression, 'expression'));

            if ($side === 'LEFT') {
                return $left;
            }

            if ($side === 'FULL') {
                return array_values(array_unique([...$left, ...$right]));
            }

            if ($kind === 'INNER') {
                return array_values(array_intersect(array_values(array_unique($left)), $right));
            }

            return [];
        }

        return $expression->namedSelects();
    }

    /**
     * The columns of a source of the scope.
     *
     * @return list<string>
     */
    public function getSourceColumns(string $name, bool $onlyVisible = false): array
    {
        $cacheKey = $name."\0".($onlyVisible ? '1' : '0');

        if (isset($this->sourceColumnsCache[$cacheKey])) {
            return $this->sourceColumnsCache[$cacheKey];
        }

        if (! array_key_exists($name, $this->scope->sources)) {
            throw new OptimizeError("Unknown table: {$name}");
        }

        $source = $this->scope->sources[$name];

        // A pivoted CTE reference is a Table in the scope sources, but the CTE's scope still
        // holds the pre-pivot columns
        if (
            $source instanceof Table
            && $source->text('db') === ''
            && $source->argList('pivots') !== []
            && isset($this->scope->cteSources[$source->name()])
        ) {
            $source = $this->scope->cteSources[$source->name()];
        }

        if ($source instanceof Table) {
            $columns = $this->schema->columnNames($source, $onlyVisible);
        } elseif ($source->expression instanceof Values || $source->expression instanceof Unnest || $source->expression instanceof Lateral) {
            $sourceExpr = $source->expression;
            $columns = self::namedSelectsOf($sourceExpr);

            // In BigQuery, UNNEST structs are scoped as tables, so struct fields can be selected
            // directly; this handles statically known types
            if ($this->dialect->settings['UNNEST_COLUMN_ONLY'] === true && $sourceExpr instanceof Unnest) {
                $type = $sourceExpr->type();

                if ($type === null || $type->isType(DType::UNKNOWN)) {
                    $unnestExpr = $sourceExpr->argList('expressions')[0] ?? null;

                    if ($unnestExpr instanceof Column && $this->scope->parent !== null) {
                        $colType = $this->getUnnestColumnType($unnestExpr, $this->scope->parent);

                        if ($colType !== null && $colType->isType(DType::ARRAY)) {
                            $elementTypes = $colType->argList('expressions');

                            if ($elementTypes !== [] && $elementTypes[0] instanceof DataType) {
                                $sourceExpr->setType($elementTypes[0]->copy());
                            }
                        } elseif ($colType !== null) {
                            $sourceExpr->setType($colType->copy());
                        }
                    }
                }

                array_push($columns, ...self::structFieldNames($sourceExpr->type()));
            } elseif ($sourceExpr instanceof Lateral && ($sourceExpr->args['this'] ?? null) instanceof Explode) {
                $explodeCol = $sourceExpr->args['this']->args['this'] ?? null;

                // An unqualified column couldn't be resolved when this scope's children were
                // qualified; disambiguating it here would recurse without bound
                if ($explodeCol instanceof Column && $explodeCol->text('table') !== '' && $source->parent !== null) {
                    array_push($columns, ...self::structFieldNames($this->getUnnestColumnType($explodeCol, $source->parent)));
                }
            } elseif ($sourceExpr instanceof Lateral && ($sourceExpr->args['this'] ?? null) instanceof Query) {
                $columns = $sourceExpr->args['this']->namedSelects();
            }
        } elseif ($source->expression instanceof SetOperation) {
            $columns = $this->getSourceColumnsFromSetOp($source->expression);
        } else {
            $selects = self::selectsOf($source->expression);
            $select = $selects[0] ?? null;

            if ($select instanceof QueryTransform) {
                // https://spark.apache.org/docs/3.5.1/sql-ref-syntax-qry-select-transform.html
                $schema = $select->argExpr('schema');
                $columns = $schema !== null
                    ? array_map(static fn (Expression $c): string => $c->name(), $schema->argList('expressions'))
                    : ['key', 'value'];
            } else {
                $columns = self::namedSelectsOf($source->expression);
            }
        }

        $node = $this->scope->selectedSources()[$name][0] ?? null;
        $columnAliases = $node !== null ? $node->aliasColumnNames() : [];

        if ($columnAliases !== []) {
            // The aliases of a source's columns shadow their names
            $count = max(count($columns), count($columnAliases));
            $aliased = [];

            for ($i = 0; $i < $count; $i++) {
                $alias = $columnAliases[$i] ?? '';
                $aliased[] = $alias !== '' ? $alias : ($columns[$i] ?? '');
            }

            $columns = $aliased;
        }

        return $this->sourceColumnsCache[$cacheKey] = $columns;
    }

    /**
     * The projections of a selectable expression (sqlglot Selectable.selects).
     *
     * @return list<Expression>
     */
    public static function selectsOf(Expression $expression): array
    {
        if ($expression instanceof Query || $expression instanceof Table) {
            return $expression->selects();
        }

        if ($expression instanceof UDTF) {
            $columns = $expression->argExpr('alias')?->argList('columns') ?? [];

            if ($expression instanceof Unnest) {
                $offset = $expression->args['offset'] ?? null;

                if ($offset === true) {
                    $columns[] = new Identifier(['this' => 'offset', 'quoted' => false]);
                } elseif ($offset instanceof Expression) {
                    $columns[] = $offset;
                }
            } elseif ($expression instanceof Lateral) {
                // UNNEST ... WITH ORDINALITY stores the ordinality column's name in Unnest.offset
                $inner = $expression->args['this'] ?? null;
                $offset = $inner instanceof Unnest ? ($inner->args['offset'] ?? null) : null;

                if ($offset instanceof Identifier) {
                    $columns[] = $offset;
                }
            }

            return $columns;
        }

        return [];
    }

    /**
     * The output names of the projections of a selectable expression (sqlglot Selectable.named_selects).
     *
     * @return list<string>
     */
    public static function namedSelectsOf(Expression $expression): array
    {
        if ($expression instanceof Query || $expression instanceof Table) {
            return $expression->namedSelects();
        }

        return array_map(static fn (Expression $e): string => $e->outputName(), self::selectsOf($expression));
    }

    /**
     * @return array<string, list<string>>
     */
    private function getAllSourceColumns(): array
    {
        if ($this->sourceColumns === null) {
            $this->sourceColumns = [];

            foreach ([...array_keys($this->scope->selectedSources()), ...array_keys($this->scope->lateralSources)] as $sourceName) {
                $sourceName = (string) $sourceName;
                $this->sourceColumns[$sourceName] = $this->getSourceColumns($sourceName);
            }
        }

        return $this->sourceColumns;
    }

    /**
     * @param  array<string, list<string>>|null  $sourceColumns
     */
    private function getTableNameFromSources(string $columnName, ?array $sourceColumns = null): ?string
    {
        if ($sourceColumns === null || $sourceColumns === []) {
            $this->unambiguousColumns ??= $this->getUnambiguousColumns($this->getAllSourceColumns());
            $unambiguous = $this->unambiguousColumns;
        } else {
            $unambiguous = $this->getUnambiguousColumns($sourceColumns);
        }

        return $unambiguous[$columnName] ?? null;
    }

    /**
     * The join a column is part of, when it can be disambiguated by the order of the sources.
     */
    private function getColumnJoinContext(Column $column): ?Join
    {
        $expression = $this->scope->expression;

        if ($expression->argList('joins') === [] || $expression->argList('laterals') !== [] || $expression->argList('pivots') !== []) {
            // Columns aren't disambiguated when other sources (laterals, pivots) exist alongside joins
            return null;
        }

        $joinAncestor = $column->findAncestor(Join::class, Select::class);

        if ($joinAncestor instanceof Join) {
            $joinName = $joinAncestor->aliasOrName();

            // Make sure the join contains an actual source, e.g. in ClickHouse `b` is an array
            // expression in `a ARRAY JOIN b`
            if (isset($this->scope->selectedSources()[$joinName]) || isset($this->scope->semiOrAntiJoinTables()[$joinName])) {
                return $joinAncestor;
            }
        }

        return null;
    }

    /**
     * The columns of the sources joined up to (and including) a join.
     *
     * @return array<string, list<string>>
     */
    private function getAvailableSourceColumns(Join $joinAncestor): array
    {
        $expression = $this->scope->expression;
        $from = $expression->argExpr('from_');
        $fromName = $from !== null ? self::fromAliasOrName($from) : '';
        $available = [$fromName => $this->getSourceColumns($fromName)];

        foreach (array_slice($expression->argList('joins'), 0, ($joinAncestor->index ?? 0) + 1) as $join) {
            $available[$join->aliasOrName()] = $this->getSourceColumns($join->aliasOrName());
        }

        return $available;
    }

    private static function fromAliasOrName(Expression $from): string
    {
        return $from->aliasOrName();
    }

    /**
     * Columns that belong to a single source: column => source name.
     *
     * @param  array<string, list<string>>  $sourceColumns
     * @return array<string, string>
     */
    private function getUnambiguousColumns(array $sourceColumns): array
    {
        if ($sourceColumns === []) {
            return [];
        }

        $pairs = [];

        foreach ($sourceColumns as $table => $columns) {
            $pairs[] = [(string) $table, $columns];
        }

        [$firstTable, $firstColumns] = $pairs[0];

        if (count($pairs) === 1) {
            return array_fill_keys($firstColumns, $firstTable);
        }

        // With UNNEST_COLUMN_ONLY (BigQuery), an UNNEST alias may shadow a column of another table
        $unnestOriginalAliases = [];

        if ($this->dialect->settings['UNNEST_COLUMN_ONLY'] === true) {
            foreach ($this->scope->sources as $sourceName => $source) {
                if ($source instanceof Scope && $source->expression instanceof Unnest) {
                    $aliasColumns = $source->expression->argExpr('alias')?->argList('columns') ?? [];

                    if ($aliasColumns !== []) {
                        $unnestOriginalAliases[$aliasColumns[0]->name()] = (string) $sourceName;
                    }
                }
            }
        }

        $unambiguous = array_fill_keys($firstColumns, $firstTable);
        $all = $unambiguous;

        foreach (array_slice($pairs, 1) as [$table, $columns]) {
            $unique = array_fill_keys($columns, true);
            $ambiguous = array_intersect_key($all, $unique);

            foreach ($columns as $column) {
                $all[$column] = true;
            }

            foreach (array_keys($ambiguous) as $column) {
                $column = (string) $column;

                if (isset($unnestOriginalAliases[$column])) {
                    $unambiguous[$column] = $unnestOriginalAliases[$column];

                    continue;
                }

                unset($unambiguous[$column]);
            }

            foreach (array_keys(array_diff_key($unique, $ambiguous)) as $column) {
                $unambiguous[(string) $column] = $table;
            }
        }

        return array_map('strval', $unambiguous);
    }

    /**
     * @return list<string>
     */
    private static function structFieldNames(?DataType $type): array
    {
        if ($type !== null && $type->isType(DType::ARRAY)) {
            $element = $type->argList('expressions')[0] ?? null;
            $type = $element instanceof DataType ? $element : null;
        }

        if ($type === null || ! $type->isType(DType::STRUCT)) {
            return [];
        }

        return array_map(static fn (Expression $k): string => $k->name(), $type->argList('expressions'));
    }

    /**
     * The type of a column being unnested / exploded, traced through CTEs and subqueries to its table.
     */
    private function getUnnestColumnType(Column $column, Scope $scope): ?DataType
    {
        if ($column->text('table') !== '') {
            $tableName = $column->text('table');
        } else {
            $tableIdentifier = (new self($scope, $this->schema, $this->inferSchema))->getTable($column);

            if ($tableIdentifier === null) {
                return null;
            }

            $tableName = $tableIdentifier->name();
        }

        $source = $scope->sources[$tableName] ?? null;

        return $source !== null ? $this->getColumnTypeFromScope($source, $column) : null;
    }

    private function getColumnTypeFromScope(Scope|Table $source, Column $column): ?DataType
    {
        $cacheKey = spl_object_id($source)."\0".$column->name();

        if (array_key_exists($cacheKey, $this->columnTypeFromScopeCache)) {
            return $this->columnTypeFromScopeCache[$cacheKey];
        }

        $result = null;

        if ($source instanceof Table) {
            $type = $this->schema->getColumnType($source, $column);

            if (! $type->isType(DType::UNKNOWN)) {
                $result = $type;
            }
        } else {
            foreach ($source->sources as $nested) {
                $nestedType = $this->getColumnTypeFromScope($nested, $column);

                if ($nestedType !== null && ! $nestedType->isType(DType::UNKNOWN)) {
                    $result = $nestedType;

                    break;
                }
            }
        }

        return $this->columnTypeFromScopeCache[$cacheKey] = $result;
    }

    private static function exprArg(Expression $expression, string $key): Expression
    {
        $value = $expression->args[$key] ?? null;

        if (! $value instanceof Expression) {
            throw new OptimizeError("Missing {$key} of {$expression->sql()}");
        }

        return $value;
    }
}
