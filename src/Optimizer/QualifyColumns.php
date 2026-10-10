<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Errors\OptimizeError;
use LaravelSqlParser\LaravelSqlParser\Expressions\AggFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Aliases;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Explode;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Group;
use LaravelSqlParser\LaravelSqlParser\Expressions\Having;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Parameter;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pivot;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pseudocolumn;
use LaravelSqlParser\LaravelSqlParser\Expressions\Qualify;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\QueryTransform;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Selectable;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableColumn;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Union;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;
use LaravelSqlParser\LaravelSqlParser\Expressions\With;
use LaravelSqlParser\LaravelSqlParser\Schema\Schema;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;

/**
 * Rewrite the AST to have fully qualified columns (sqlglot optimizer/qualify_columns.py).
 *
 * A source may carry a chain of (UN)PIVOT operators; each one is resolved against the output
 * of the one before it, and the last one's alias names the result.
 */
final class QualifyColumns
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @param  array<array-key, mixed>|Schema|null  $schema
     * @return T
     */
    public static function qualifyColumns(
        Expression $expression,
        array|Schema|null $schema = null,
        bool $expandAliasRefs = true,
        bool $expandStars = true,
        ?bool $inferSchema = null,
        bool $allowPartialQualification = false,
        string|Dialect|null $dialect = null,
    ): Expression {
        $schema = Schema::ensure($schema, $dialect);
        $dialect = $schema->dialect() ?? Dialect::get();
        $annotator = new ($dialect::TYPE_ANNOTATOR)($dialect);
        $annotator->schema = $schema;
        $inferSchema ??= $schema->isEmpty();
        $pseudocolumns = self::pseudocolumns($dialect);
        $settings = $dialect->settings;

        foreach (Scope::traverse($expression) as $scope) {
            if ($settings['PREFER_CTE_ALIAS_COLUMN'] === true) {
                self::pushdownCteAliasColumns($scope);
            }

            $isSelect = $scope->expression instanceof Select;

            self::separatePseudocolumns($scope, $pseudocolumns);

            $resolver = new Resolver($scope, $schema, $inferSchema);
            self::popTableColumnAliases($scope->ctes());
            self::popTableColumnAliases($scope->derivedTables());
            $usingColumnTables = self::expandUsing($scope, $resolver);

            if (($schema->isEmpty() || $settings['FORCE_EARLY_ALIAS_REF_EXPANSION'] === true) && $expandAliasRefs) {
                self::expandAliasRefs($scope, $resolver, $dialect, $settings['EXPAND_ONLY_GROUP_ALIAS_REF'] === true);
            }

            self::convertColumnsToDots($scope, $resolver);
            self::qualifyScopeColumns($scope, $resolver, $allowPartialQualification);

            // A column just qualified in place may have been cached as external
            $scope->clearColumnCache();

            if (! $schema->isEmpty() && $expandAliasRefs) {
                self::expandAliasRefs($scope, $resolver, $dialect);
            }

            if ($isSelect) {
                if ($expandStars) {
                    self::expandStars($scope, $resolver, $usingColumnTables, $pseudocolumns, $annotator);
                }

                self::qualifyOutputs($scope, $dialect);
            }

            self::expandGroupBy($scope, $dialect);

            // DISTINCT ON and ORDER BY follow the same rules
            self::expandOrderByAndDistinctOn($scope, $resolver);

            if ($settings['ANNOTATE_ALL_SCOPES'] === true) {
                $annotator->annotateScope($scope);
            }
        }

        return $expression;
    }

    /**
     * Raise an OptimizeError if any column isn't qualified.
     *
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function validateQualifyColumns(Expression $expression, ?string $sql = null): Expression
    {
        $allUnqualified = [];

        foreach (Scope::traverse($expression) as $scope) {
            if (! $scope->expression instanceof Select) {
                continue;
            }

            $unqualified = $scope->unqualifiedColumns();
            $external = $scope->externalColumns();

            if ($external !== [] && ! $scope->isCorrelatedSubquery() && $scope->pivots() === []) {
                $column = $external[0];
                $forTable = $column->text('table') !== '' ? " for table: '{$column->text('table')}'" : '';
                $message = "Column '{$column->name()}' could not be resolved{$forTable}.".self::position($column, ' Line: %s, Col: %s');

                throw new OptimizeError($message);
            }

            array_push($allUnqualified, ...$unqualified);
        }

        if ($allUnqualified !== []) {
            $first = $allUnqualified[0];

            throw new OptimizeError("Ambiguous column '{$first->name()}'".self::position($first, ' (Line: %s, Col: %s)'));
        }

        return $expression;
    }

    /**
     * Ensure all output columns are aliased.
     */
    public static function qualifyOutputs(Scope|Expression $scopeOrExpression, string|Dialect|null $dialect = null): void
    {
        $dialect = Dialect::get($dialect);

        if ($scopeOrExpression instanceof Expression) {
            $scope = Scope::build($scopeOrExpression);

            if ($scope === null) {
                return;
            }
        } else {
            $scope = $scopeOrExpression;
        }

        $expression = $scope->expression;

        if (! $expression instanceof Selectable) {
            return;
        }

        $selects = Resolver::selectsOf($expression);
        $newSelections = [];
        $count = max(count($selects), count($scope->outerColumns));

        for ($i = 0; $i < $count; $i++) {
            $selection = $selects[$i] ?? null;
            $aliasedColumn = $scope->outerColumns[$i] ?? null;

            if ($selection === null || $selection instanceof QueryTransform) {
                break;
            }

            if ($selection instanceof Subquery) {
                if ($selection->outputName() === '') {
                    $identifier = new Identifier(['this' => "_col_{$i}", 'quoted' => false]);
                    $dialect->normalizeIdentifier($identifier);
                    $selection->set('alias', new TableAlias(['this' => $identifier]));
                }
            } elseif (! $selection instanceof Alias && ! $selection instanceof Aliases && ! $selection->isStar()) {
                $unwrapped = $selection->unnest();

                if ($unwrapped instanceof Column) {
                    $sourceIdentifier = $unwrapped->args['this'] ?? null;
                } elseif ($unwrapped instanceof Dot) {
                    $sourceIdentifier = $unwrapped->args['expression'] ?? null;
                } else {
                    $sourceIdentifier = null;
                }

                $outputName = $selection->outputName();
                $selection = Builder::alias($selection, $outputName !== '' ? $outputName : "_col_{$i}", copy: false);
                $aliasIdentifier = $selection->argExpr('alias');

                if ($sourceIdentifier instanceof Identifier) {
                    // The alias copies the spelling of an existing identifier, so it's not normalized
                    if ($sourceIdentifier->quoted()) {
                        $aliasIdentifier?->set('quoted', true);
                    }
                } elseif ($aliasIdentifier !== null) {
                    $dialect->normalizeIdentifier($aliasIdentifier);
                }
            }

            if ($aliasedColumn !== null && $aliasedColumn !== '') {
                $selection->set('alias', Builder::toIdentifier($aliasedColumn));
            }

            $newSelections[] = $selection;
        }

        if ($newSelections !== [] && $expression instanceof Select) {
            $expression->set('expressions', $newSelections);
        }
    }

    /**
     * Make sure all identifiers that need to be quoted are quoted.
     *
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function quoteIdentifiers(Expression $expression, string|Dialect|null $dialect = null, bool $identify = true): Expression
    {
        $dialect = Dialect::get($dialect);

        foreach ($expression->walk() as $node) {
            if ($node instanceof Identifier) {
                $dialect->quoteIdentifier($node, $identify);
            }
        }

        return $expression;
    }

    /**
     * Push the CTE alias columns down into the CTE's projections (Snowflake can reference them in HAVING).
     */
    public static function pushdownCteAliasColumns(Scope $scope): void
    {
        foreach ($scope->ctes() as $cte) {
            $body = $cte->args['this'] ?? null;

            if ($cte->aliasColumnNames() === [] || ! $body instanceof Select) {
                continue;
            }

            $aliases = $cte->argExpr('alias')?->argList('columns') ?? [];
            $projections = $body->argList('expressions');
            $newExpressions = [];

            foreach (array_slice($aliases, 0, min(count($aliases), count($projections))) as $i => $alias) {
                $projection = $projections[$i];

                if ($projection instanceof Alias) {
                    $projection->set('alias', $alias->copy());
                } else {
                    $projection = Builder::alias($projection, $alias instanceof Identifier ? $alias : $alias->name());
                }

                $newExpressions[] = $projection;
            }

            $body->set('expressions', $newExpressions);
        }
    }

    /**
     * Build the scope of an expression and push its CTE alias columns down (the test entry point).
     *
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function pushdownCteAliasColumnsForScope(Expression $expression): Expression
    {
        $scope = Scope::build($expression);

        if ($scope !== null) {
            self::pushdownCteAliasColumns($scope);
        }

        return $expression;
    }

    /**
     * Ordered map of output column name => pre-rename source name of a (UN)PIVOT (sqlglot
     * Pivot.output_columns), as a list of the output names.
     *
     * @param  iterable<string>  $prePivotColumns
     * @return list<string>
     */
    public static function pivotOutputColumns(Pivot $pivot, iterable $prePivotColumns): array
    {
        $excluded = [];

        if ($pivot->flag('unpivot')) {
            $nameColumns = [];

            foreach ($pivot->argList('fields') as $field) {
                if (! $field instanceof In) {
                    continue;
                }

                if (($field->args['this'] ?? null) instanceof Identifier) {
                    $nameColumns[] = $field->args['this'];
                }

                foreach ($field->argList('expressions') as $e) {
                    foreach ($e->findAll(Column::class) as $c) {
                        $excluded[$c->outputName()] = true;
                    }
                }
            }

            $valueColumns = [];

            foreach ($pivot->argList('expressions') as $e) {
                foreach ($e instanceof Tuple ? $e->argList('expressions') : [$e] as $identifier) {
                    if ($identifier instanceof Identifier) {
                        $valueColumns[] = $identifier;
                    }
                }
            }

            // T-SQL emits the value column(s) ahead of the name column, everyone else after it
            $ordered = $pivot->flag('value_columns_first') ? [...$valueColumns, ...$nameColumns] : [...$nameColumns, ...$valueColumns];
            $outputs = array_map(static fn (Expression $i): string => $i->name(), $ordered);
        } else {
            foreach ($pivot->findAll(Column::class) as $c) {
                $excluded[$c->outputName()] = true;
            }

            $outputs = array_map(static fn (Expression $c): string => $c->outputName(), $pivot->argList('columns'));

            if ($outputs === []) {
                $outputs = array_map(static fn (Expression $c): string => $c->aliasOrName(), $pivot->argList('expressions'));
            }
        }

        if ($excluded === [] || $outputs === []) {
            return [];
        }

        $preRename = [];

        foreach ($prePivotColumns as $c) {
            if (! isset($excluded[$c])) {
                $preRename[] = $c;
            }
        }

        array_push($preRename, ...$outputs);
        $renames = $pivot->argExpr('alias')?->argList('columns') ?? [];

        // PIVOT(...) AS alias(c1, c2, ...) renames the leading output columns positionally
        if ($renames !== []) {
            $renameNames = array_map(static fn (Expression $r): string => $r->name(), $renames);
            $postRename = [...$renameNames, ...array_slice($preRename, count($renameNames))];
        } else {
            $postRename = $preRename;
        }

        // dict(zip(post, pre)): ordered, deduplicated keys
        $seen = [];
        $count = min(count($postRename), count($preRename));

        for ($i = 0; $i < $count; $i++) {
            $seen[$postRename[$i]] = true;
        }

        return array_map('strval', array_keys($seen));
    }

    /**
     * @return array<string, true>
     */
    private static function pseudocolumns(Dialect $dialect): array
    {
        $value = $dialect->settings['PSEUDOCOLUMNS'] ?? [];
        $out = [];

        foreach (is_array($value) ? $value : [] as $key => $flag) {
            $out[is_string($flag) ? $flag : (string) $key] = true;
        }

        return $out;
    }

    private static function position(Column $column, string $format): string
    {
        $this_ = $column->args['this'] ?? null;
        $line = $this_ instanceof Expression ? $this_->metaGet('line') : null;
        $col = $this_ instanceof Expression ? $this_->metaGet('col') : null;

        if (is_scalar($line) && is_scalar($col) && (bool) $line && (bool) $col) {
            return sprintf($format, (string) $line, (string) $col);
        }

        return '';
    }

    /**
     * @param  array<string, true>  $pseudocolumns
     */
    private static function separatePseudocolumns(Scope $scope, array $pseudocolumns): void
    {
        if ($pseudocolumns === []) {
            return;
        }

        $hasPseudocolumns = false;
        $scopeExpression = $scope->expression;

        foreach ($scope->columns() as $column) {
            $name = strtoupper($column->name());

            if (! isset($pseudocolumns[$name])) {
                continue;
            }

            if ($name !== 'LEVEL' || ($scopeExpression instanceof Select && $scopeExpression->flag('connect'))) {
                $column->replace(new Pseudocolumn($column->args));
                $hasPseudocolumns = true;
            }
        }

        if ($hasPseudocolumns) {
            $scope->clearCache();
        }
    }

    /**
     * Remove table column aliases, e.g. col1 and col2 of SELECT ... FROM (SELECT ...) AS foo(col1, col2).
     *
     * @param  list<Expression>  $derivedTables
     */
    private static function popTableColumnAliases(array $derivedTables): void
    {
        foreach ($derivedTables as $derivedTable) {
            if ($derivedTable->parent instanceof With && $derivedTable->parent->flag('recursive')) {
                continue;
            }

            $derivedTable->argExpr('alias')?->set('columns', null);
        }
    }

    /**
     * Expand JOIN ... USING / NATURAL JOIN into ON conditions.
     *
     * @return array<string, array<string, true>> column name => ordered set of source names
     */
    private static function expandUsing(Scope $scope, Resolver $resolver): array
    {
        $columns = [];

        $updateSourceColumns = static function (string $sourceName) use (&$columns, $resolver): void {
            foreach ($resolver->getSourceColumns($sourceName) as $columnName) {
                if (! array_key_exists($columnName, $columns)) {
                    $columns[$columnName] = $sourceName;
                }
            }
        };

        $joins = Scope::findAllInScope($scope->expression, Join::class);

        if ($joins === []) {
            return [];
        }

        $names = [];

        foreach ($joins as $join) {
            $names[$join->aliasOrName()] = true;
        }

        $ordered = [];

        foreach (array_keys($scope->selectedSources()) as $key) {
            if (! isset($names[(string) $key])) {
                $ordered[] = (string) $key;
            }
        }

        if ($ordered === []) {
            throw new OptimizeError('Joins '.implode(', ', array_keys($names))." missing source table {$scope->expression->sql()}");
        }

        $columnTables = [];
        $hasUsing = false;

        foreach ($joins as $join) {
            if ($join->flag('using') || $join->method() === 'NATURAL') {
                $hasUsing = true;
            }
        }

        if (! $hasUsing) {
            return $columnTables;
        }

        foreach ($ordered as $sourceName) {
            $updateSourceColumns($sourceName);
        }

        foreach ($joins as $i => $join) {
            $sourceTable = $ordered[count($ordered) - 1];

            if ($sourceTable !== '') {
                $updateSourceColumns($sourceTable);
            }

            $joinTable = $join->aliasOrName();
            $ordered[] = $joinTable;
            $joinColumns = $resolver->getSourceColumns($joinTable);

            $using = $join->args['using'] ?? null;

            if ($using === null && $join->method() === 'NATURAL') {
                // A NATURAL JOIN is a USING join over the columns common to both sides; when those
                // can't be determined (unknown schema, no common columns), NATURAL stays
                if ($columns !== [] && ! array_key_exists('*', $columns) && $joinColumns !== [] && ! in_array('*', $joinColumns, true)) {
                    $using = [];

                    foreach (array_keys($columns) as $columnName) {
                        if (in_array((string) $columnName, $joinColumns, true)) {
                            $using[] = Builder::toIdentifier((string) $columnName);
                        }
                    }

                    if ($using !== []) {
                        $join->set('method', null);
                    }
                }
            }

            if (! is_array($using) || $using === []) {
                continue;
            }

            $conditions = [];
            $usingCount = count($using);
            $isSemiOrAntiJoin = $join->isSemiOrAntiJoin();

            foreach ($using as $identifier) {
                if (! $identifier instanceof Expression) {
                    continue;
                }

                $identifier = $identifier->name();
                $table = $columns[$identifier] ?? null;

                if (($table === null || $table === '' || ! in_array($identifier, $joinColumns, true)) && $columns !== [] && ! array_key_exists('*', $columns) && $joinColumns !== []) {
                    throw new OptimizeError("Cannot automatically join: {$identifier}");
                }

                $table = $table !== null && $table !== '' ? $table : $sourceTable;

                if ($i === 0 || $usingCount === 1) {
                    $lhs = Builder::column($identifier, $table);
                } else {
                    $coalesceColumns = [];

                    foreach (array_slice($ordered, 0, -1) as $t) {
                        if (in_array($identifier, $resolver->getSourceColumns($t), true)) {
                            $coalesceColumns[] = Builder::column($identifier, $t);
                        }
                    }

                    $lhs = count($coalesceColumns) > 1 ? Builder::func('coalesce', $coalesceColumns) : Builder::column($identifier, $table);
                }

                $conditions[] = Builder::binop(EQ::class, $lhs, Builder::column($identifier, $joinTable));

                // Only the key order matters. A SEMI / ANTI join doesn't add COALESCE columns
                $columnTables[$identifier] ??= [];

                if (! $isSemiOrAntiJoin) {
                    $columnTables[$identifier][$table] = true;
                    $columnTables[$identifier][$joinTable] = true;
                }
            }

            $join->set('using', null);
            $join->set('on', Builder::and($conditions, copy: false));
        }

        if ($columnTables !== []) {
            foreach ($scope->columns() as $column) {
                if ($column->text('table') !== '' || ! isset($columnTables[$column->name()])) {
                    continue;
                }

                $coalesceArgs = [];

                foreach (array_keys($columnTables[$column->name()]) as $table) {
                    $coalesceArgs[] = Builder::column($column->name(), (string) $table);
                }

                $replacement = Builder::func('coalesce', $coalesceArgs);

                if ($column->parent instanceof Select) {
                    // The USING column keeps its name if it's projected
                    $replacement = Builder::alias($replacement, $column->name(), copy: false);
                } elseif ($column->parent instanceof Struct) {
                    // The USING column keeps its name if it's an anonymous STRUCT field
                    $replacement = new PropertyEQ(['this' => Builder::toIdentifier($column->name()), 'expression' => $replacement]);
                }

                $scope->replace($column, $replacement);
            }
        }

        return $columnTables;
    }

    /**
     * Expand references to aliases: SELECT y.foo AS bar, bar * 2 AS baz FROM y
     * => SELECT y.foo AS bar, y.foo * 2 AS baz FROM y.
     */
    private static function expandAliasRefs(Scope $scope, Resolver $resolver, Dialect $dialect, bool $expandOnlyGroupBy = false): void
    {
        $expression = $scope->expression;

        if (! $expression instanceof Select || $dialect->settings['DISABLES_ALIAS_REF_EXPANSION'] === true) {
            return;
        }

        $shadowSources = $dialect->settings['PROJECTION_ALIASES_SHADOW_SOURCE_NAMES'] === true;

        /** @var array<string, array{0: Expression, 1: int}> $aliasToExpression */
        $aliasToExpression = [];
        $projections = [];

        foreach ($expression->selects() as $s) {
            $projections[$s->aliasOrName()] = true;
        }

        $replaced = false;

        $replaceColumns = static function (?Expression $node, bool $resolveTable = false, bool $literalIndex = false) use (&$aliasToExpression, &$replaced, $projections, $expandOnlyGroupBy, $resolver, $dialect, $shadowSources): void {
            $isGroupBy = $node instanceof Group;
            $isHaving = $node instanceof Having;
            $isQualify = $node instanceof Qualify;

            if ($node === null || ($expandOnlyGroupBy && ! $isGroupBy)) {
                return;
            }

            foreach (Scope::walkInScope($node, static fn (Expression $n): bool => $n->isStar()) as $column) {
                if (! $column instanceof Column) {
                    continue;
                }

                // BigQuery's GROUP BY only expands standalone alias names
                if ($expandOnlyGroupBy && $column->parent !== $node) {
                    continue;
                }

                $skipReplace = false;
                $table = $resolveTable && $column->text('table') === '' ? $resolver->getTable($column->name()) : null;
                [$aliasExpr, $i] = $aliasToExpression[$column->name()] ?? [null, 1];

                if ($aliasExpr !== null) {
                    // An aggregate alias isn't expanded into a GROUP BY or another (non-window) aggregate
                    $skipReplace = self::containsAggregate($aliasExpr)
                        && (
                            $isGroupBy
                            || (
                                $column->findAncestor(AggFunc::class) !== null
                                && ! $column->findAncestor(Window::class, Select::class) instanceof Window
                            )
                        );

                    // BigQuery's HAVING gets confused if an alias matches a source
                    if (($isHaving || $isQualify) && $shadowSources && ! $skipReplace) {
                        foreach ($aliasExpr->findAll(Column::class) as $inner) {
                            $first = $inner->parts()[0] ?? null;

                            if ($first !== null && isset($projections[$first->name()])) {
                                $skipReplace = true;

                                break;
                            }
                        }
                    }
                }

                if ($table !== null && ($aliasExpr === null || $skipReplace)) {
                    $column->set('table', $table);
                } elseif ($column->text('table') === '' && $aliasExpr !== null && ! $skipReplace) {
                    if (($aliasExpr instanceof Literal || $aliasExpr->isNumber()) && ($literalIndex || $resolveTable)) {
                        if ($literalIndex) {
                            $column->replace(Literal::number($i));
                            $replaced = true;
                        }
                    } else {
                        $replaced = true;
                        $new = Builder::paren($aliasExpr);
                        $column->replace($new);
                        $simplified = Simplify::simplifyParens($new, $dialect);

                        if ($simplified !== $new) {
                            $new->replace($simplified);
                            $new = $simplified;
                        }

                        if ($resolveTable && $resolver->schema->isEmpty()) {
                            // Resolve an alias spliced into QUALIFY / HAVING with unqualified columns
                            foreach (Scope::walkInScope($new) as $inner) {
                                if ($inner instanceof Column && $inner->text('table') === '') {
                                    $innerTable = $resolver->getTable($inner);

                                    if ($innerTable !== null) {
                                        $inner->set('table', $innerTable);
                                    }
                                }
                            }
                        }
                    }
                }
            }
        };

        foreach ($expression->selects() as $i => $projection) {
            $replaceColumns($projection);

            if ($projection instanceof Alias && ($projection->args['this'] ?? null) instanceof Expression) {
                $aliasToExpression[$projection->alias()] = [$projection->args['this'], $i + 1];
            }
        }

        $childScope = $scope;
        $parentScope = $scope;
        $onRightSubTree = false;

        while ($parentScope !== null && ! $parentScope->isCte()) {
            $childScope = $parentScope;
            $parentScope = $parentScope->parent;

            if ($parentScope !== null && $parentScope->expression instanceof Union) {
                // Set operation operands aren't guaranteed to be queries, e.g. SELECT 1 UNION ALL VALUES (2)
                $right = $parentScope->expression->args['expression'] ?? null;
                $onRightSubTree = $right instanceof Expression && $right->unnest() === $childScope->expression;
            }
        }

        // Aliases matching the recursive CTE's columns aren't expanded in its recursive part
        if ($parentScope !== null && $onRightSubTree) {
            $cte = $parentScope->expression->parent;

            if ($cte !== null) {
                $with = $cte->findAncestor(With::class);

                if ($with !== null && $with->flag('recursive')) {
                    $recursiveColumns = $cte->argExpr('alias')?->argList('columns') ?? [];

                    if ($recursiveColumns === []) {
                        $body = $cte->argExpr('this');
                        $recursiveColumns = $body !== null ? Resolver::selectsOf($body) : [];
                    }

                    foreach ($recursiveColumns as $recursiveColumn) {
                        unset($aliasToExpression[$recursiveColumn->outputName()]);
                    }
                }
            }
        }

        $replaceColumns($expression->argExpr('where'));
        $replaceColumns($expression->argExpr('group'), literalIndex: true);
        $replaceColumns($expression->argExpr('having'), resolveTable: true);
        $replaceColumns($expression->argExpr('qualify'), resolveTable: true);

        if ($dialect->settings['SUPPORTS_ALIAS_REFS_IN_JOIN_CONDITIONS'] === true) {
            foreach ($expression->argList('joins') as $join) {
                $replaceColumns($join);
            }
        }

        if ($shadowSources) {
            // In BigQuery's GROUP BY, HAVING and QUALIFY, a qualifier that collides with a projection
            // alias resolves to the projection, so such columns are marked to be rendered bare
            foreach (['group', 'having', 'qualify'] as $key) {
                $clause = $expression->argExpr($key);

                if ($clause === null) {
                    continue;
                }

                foreach (Scope::findAllInScope($clause, Column::class) as $column) {
                    if ($column->text('table') !== '' && $column->text('db') === '') {
                        $column->set('shadow', isset($projections[$column->text('table')]) ? true : null);
                    }
                }
            }
        }

        if ($replaced) {
            $scope->clearCache();
        }
    }

    private static function containsAggregate(Expression $expression): bool
    {
        foreach (Scope::walkInScope($expression) as $node) {
            if ($node instanceof AggFunc) {
                return true;
            }
        }

        return false;
    }

    private static function expandGroupBy(Scope $scope, Dialect $dialect): void
    {
        $expression = $scope->expression;
        $group = $expression->argExpr('group');

        if ($group === null) {
            return;
        }

        $group->set('expressions', self::expandPositionalReferences($scope, $group->argList('expressions'), $dialect));
        $expression->set('group', $group);
    }

    private static function expandOrderByAndDistinctOn(Scope $scope, Resolver $resolver): void
    {
        $expression = $scope->expression;

        if (! $expression instanceof Selectable) {
            return;
        }

        $counts = array_count_values(array_map('strval', Resolver::namedSelectsOf($expression)));
        $aliasNames = [];

        foreach ($counts as $name => $count) {
            if ($count === 1) {
                $aliasNames[(string) $name] = true;
            }
        }

        foreach (['order', 'distinct'] as $modifierKey) {
            $modifier = $expression->argExpr($modifierKey);

            if ($modifier instanceof Distinct) {
                $modifier = $modifier->argExpr('on');
            }

            if ($modifier === null) {
                continue;
            }

            $modifierExpressions = $modifier->argList('expressions');

            if ($modifierKey === 'order') {
                $modifierExpressions = array_values(array_filter(array_map(
                    static fn (Expression $ordered): ?Expression => $ordered->argExpr('this'),
                    $modifierExpressions,
                )));
            }

            $expanded = self::expandPositionalReferences($scope, $modifierExpressions, $resolver->dialect, $aliasNames);

            foreach ($modifierExpressions as $i => $original) {
                if (! isset($expanded[$i])) {
                    break;
                }

                foreach ($original->findAll(AggFunc::class) as $agg) {
                    foreach ($agg->findAll(Column::class) as $col) {
                        if ($col->text('table') === '') {
                            $col->set('table', $resolver->getTable($col->name()));
                        }
                    }
                }

                $original->replace($expanded[$i]);
            }

            if ($expression->argExpr('group') !== null) {
                $selects = [];

                foreach (Resolver::selectsOf($expression) as $s) {
                    $sThis = $s->args['this'] ?? null;

                    if (isset($aliasNames[$s->aliasOrName()]) && $sThis instanceof Expression) {
                        $selects[$sThis->hashKey()] ??= $s->aliasOrName();
                    }
                }

                foreach ($modifierExpressions as $node) {
                    // Remaining ordinals reference duplicate aliases and must stay positional
                    if (! $node->isInt()) {
                        $name = $selects[$node->hashKey()] ?? null;

                        if ($name !== null) {
                            $node->replace(Builder::column($name));
                        }
                    }
                }
            }
        }
    }

    /**
     * @param  list<Expression>  $expressions
     * @param  array<string, true>|null  $aliasNames
     * @return list<Expression>
     */
    private static function expandPositionalReferences(Scope $scope, array $expressions, Dialect $dialect, ?array $aliasNames = null): array
    {
        $newNodes = [];
        $ambiguousProjections = null;
        $expression = $scope->expression;

        if (! $expression instanceof Selectable) {
            return $newNodes;
        }

        foreach ($expressions as $node) {
            if (! ($node->isInt() && $node instanceof Literal)) {
                $newNodes[] = $node;

                continue;
            }

            $select = self::selectByPos($expression, $node);

            if ($aliasNames !== null) {
                $alias = $select->argExpr('alias');
                $newNodes[] = isset($aliasNames[$select->alias()]) && $alias instanceof Identifier ? Builder::column($alias->copy()) : $node;

                continue;
            }

            $selectExpr = $select->args['this'] ?? null;

            if (! $selectExpr instanceof Expression) {
                $newNodes[] = $node;

                continue;
            }

            $ambiguous = false;

            if ($dialect->settings['PROJECTION_ALIASES_SHADOW_SOURCE_NAMES'] === true) {
                if ($ambiguousProjections === null) {
                    // A projection named like a source can't be told apart in BigQuery's GROUP BY
                    $ambiguousProjections = [];
                    $selected = $scope->selectedSources();

                    foreach (Resolver::selectsOf($expression) as $s) {
                        if (isset($selected[$s->aliasOrName()])) {
                            $ambiguousProjections[$s->aliasOrName()] = true;
                        }
                    }
                }

                foreach ($selectExpr->findAll(Column::class) as $column) {
                    $first = $column->parts()[0] ?? null;

                    if ($first !== null && isset($ambiguousProjections[$first->name()])) {
                        $ambiguous = true;

                        break;
                    }
                }
            }

            if (
                $selectExpr instanceof Literal || $selectExpr instanceof Boolean || $selectExpr instanceof NullExpr
                || $selectExpr->isNumber()
                || $selectExpr->find(Explode::class, Unnest::class) !== null
                || $ambiguous
            ) {
                $newNodes[] = $node;
            } else {
                $newNodes[] = $selectExpr->copy();
            }
        }

        return $newNodes;
    }

    private static function selectByPos(Expression $expression, Literal $node): Expression
    {
        $selects = Resolver::selectsOf($expression);
        $index = (int) $node->name() - 1;

        if ($index < 0) {
            $index += count($selects);
        }

        $select = $selects[$index] ?? null;

        if ($select === null) {
            throw new OptimizeError("Unknown output column: {$node->name()}");
        }

        if (! $select instanceof Alias) {
            throw new OptimizeError("Expected an aliased projection at position {$node->name()}");
        }

        return $select;
    }

    /**
     * Convert columns that are STRUCT or JSON field lookups into chained Dots, e.g. "col"."field"
     * becomes Dot(<table>.<col>, field), so they're qualified properly.
     */
    private static function convertColumnsToDots(Scope $scope, Resolver $resolver): void
    {
        $converted = false;

        foreach ([...$scope->columns(), ...$scope->stars()] as $column) {
            if (! $column instanceof Column) {
                continue;
            }

            $isStar = ($column->args['this'] ?? null) instanceof Star;
            $columnTable = $column->text('table');
            $dotParts = $column->metaGet('dot_parts', []);
            $dotParts = is_array($dotParts) ? array_values($dotParts) : [];
            $column->setMeta('dot_parts', null);
            $rootResolver = null;
            $selected = $scope->selectedSources();

            if (! $isStar && $column->text('db') !== '' && isset($selected[$columnTable])) {
                [$root, $field] = $column->parts();

                foreach ([$resolver, ...$resolver->outerResolvers()] as $candidate) {
                    if (! isset($candidate->scope->selectedSources()[$root->name()])) {
                        continue;
                    }

                    if (in_array($field->name(), $candidate->getSourceColumns($root->name()), true)) {
                        $rootResolver = $candidate;
                    }

                    break;
                }
            }

            if (
                $columnTable !== ''
                && (! isset($selected[$columnTable]) || ($isStar && $column->text('db') !== '') || $rootResolver !== null)
                && (
                    $rootResolver !== null
                    || $isStar
                    || $scope->parent === null
                    || ! array_key_exists($columnTable, $scope->parent->sources)
                    || ! $scope->isCorrelatedSubquery()
                )
            ) {
                $parts = $column->parts();
                $root = array_shift($parts);
                $wasQualified = false;
                $newTable = $columnTable;

                // Correlated stars can't be deferred; qualified paths use their root source's resolver
                $resolvers = $isStar ? [$resolver, ...$resolver->outerResolvers()] : [$rootResolver ?? $resolver];

                foreach ($resolvers as $sourceResolver) {
                    $selectedSources = $sourceResolver->scope->selectedSources();

                    if ($column->text('db') === '' && isset($selectedSources[$column->text('table')])) {
                        // The star is over a table, so it's expanded as is
                        $newTable = null;

                        break;
                    }

                    if ($root instanceof Identifier && isset($selectedSources[$root->name()])) {
                        // The struct is already qualified, but the AST still needs to change
                        $newTable = $root;
                        $root = array_shift($parts);
                        $wasQualified = true;

                        break;
                    }

                    if (
                        $isStar
                        && ! isset($sourceResolver->allColumns()[$root->name()])
                        && $sourceResolver->hasUnknownSources()
                    ) {
                        // An unknown source may own the star, so it's preserved instead of inferred
                        $newTable = null;

                        break;
                    }

                    $newTable = $sourceResolver->getTable($root->name());

                    if ($newTable !== null) {
                        break;
                    }
                }

                if ($newTable !== null && $root instanceof Expression) {
                    $converted = true;
                    $newColumn = Builder::column($root instanceof Identifier || $root instanceof Star ? $root : $root->name(), $newTable);

                    if ($dotParts !== []) {
                        // Remove the actual column parts from the rest of the dot parts
                        $newColumn->setMeta('dot_parts', array_slice($dotParts, $wasQualified ? 2 : 1));
                    }

                    $column->replace($parts === [] ? $newColumn : Dot::build([$newColumn, ...$parts]));
                }
            }
        }

        if ($converted) {
            // Re-aggregate the converted columns so they aren't skipped
            $scope->clearCache();
        }
    }

    /**
     * Resolve a positional column ($1), returning whether to skip further qualification.
     *
     * @param  list<string>  $sourceColumns
     * @param  list<Pivot>  $pivots
     */
    private static function qualifyPositionalColumn(
        Scope $scope,
        Resolver $resolver,
        Column $column,
        string $columnTable,
        Expression|Scope $columnSource,
        array $sourceColumns,
        array $pivots,
        bool $allowPartialQualification,
    ): bool {
        $parameter = $column->args['this'] ?? null;
        $position = $parameter instanceof Parameter ? ($parameter->args['this'] ?? null) : null;

        if (
            $resolver->dialect->settings['SUPPORTS_POSITIONAL_COLUMN_REFS'] !== true
            || ! $position instanceof Literal
            || ! $position->isInt()
        ) {
            return false;
        }

        // Pivots from unrelated sources may share this scope, so prefer an exact output-alias
        // match; for an aliasless chain, fall back to the last operator on the referenced source
        $scopePivot = null;

        foreach ($scope->pivots() as $pivot) {
            if ($pivot->alias() === $columnTable) {
                $scopePivot = $pivot;

                break;
            }
        }

        if ($scopePivot === null) {
            foreach (array_reverse($scope->pivots()) as $pivot) {
                if ($pivot->parent !== null && $pivot->parent->aliasOrName() === $columnTable) {
                    $scopePivot = $pivot;

                    break;
                }
            }
        }

        if ($pivots !== [] || $scopePivot !== null || $sourceColumns === [] || in_array('*', $sourceColumns, true)) {
            if ($scopePivot !== null) {
                $column->set('table', Builder::toIdentifier($scopePivot->alias()));
            }

            return true;
        }

        $positionValue = (int) $position->name();

        if ($columnSource instanceof Table) {
            $aliasColumns = $columnSource->aliasColumnNames();
            $withoutAliases = $resolver->schema->columnNames($columnSource, onlyVisible: true);
            $incomplete = $withoutAliases === [] || in_array('*', $withoutAliases, true);
        } else {
            $aliasColumns = [];
            $incomplete = false;
        }

        if ($aliasColumns !== [] && $positionValue > count($aliasColumns) && $incomplete) {
            return true;
        }

        $positionalColumns = $resolver->getSourceColumns($columnTable, onlyVisible: true);

        if ($positionValue < 1 || $positionValue > count($positionalColumns)) {
            if ($allowPartialQualification) {
                return true;
            }

            throw new OptimizeError("Positional reference \${$positionValue} is out of range for source '{$columnTable}'");
        }

        $positionalName = $positionalColumns[$positionValue - 1];

        if (count(array_keys($positionalColumns, $positionalName, true)) > 1) {
            return true;
        }

        if ($columnSource instanceof Scope) {
            $sourceExpression = $columnSource->expression;

            if (! $sourceExpression instanceof Query) {
                return true;
            }

            $sourceSelects = $sourceExpression->selects();
            $selection = $sourceSelects[$positionValue - 1] ?? null;
            $sourceIdentifier = self::outputIdentifier($selection);

            if ($sourceIdentifier === null || $sourceIdentifier->name() !== $positionalName) {
                return true;
            }

            $positionalIdentifier = $sourceIdentifier->copy();
        } else {
            $positionalIdentifier = Builder::toIdentifier($positionalName) ?? new Identifier(['this' => $positionalName]);
            $resolver->dialect->quoteIdentifier($positionalIdentifier, identify: false);
        }

        $column->set('this', $positionalIdentifier);

        return false;
    }

    /**
     * Disambiguate columns, making sure each column names its source.
     */
    private static function qualifyScopeColumns(Scope $scope, Resolver $resolver, bool $allowPartialQualification): void
    {
        foreach ($scope->columns() as $column) {
            $columnTable = $column->text('table');
            $columnName = $column->name();

            if ($columnTable !== '' && array_key_exists($columnTable, $scope->sources)) {
                $columnSource = $scope->sources[$columnTable];
                $sourceColumns = $resolver->getSourceColumns($columnTable);
                $pivots = $columnSource instanceof Table ? array_values(array_filter($columnSource->argList('pivots'), static fn (Expression $p): bool => $p instanceof Pivot)) : [];

                if ($pivots !== [] && $sourceColumns !== [] && ! in_array('*', $sourceColumns, true)) {
                    // Each operator's input is the previous one's output
                    foreach ($pivots as $pivot) {
                        $sourceColumns = self::pivotOutputColumns($pivot, $sourceColumns);
                    }
                }

                /** @var list<Pivot> $pivots */
                if (self::qualifyPositionalColumn($scope, $resolver, $column, $columnTable, $columnSource, $sourceColumns, $pivots, $allowPartialQualification)) {
                    continue;
                }

                $columnName = $column->name();

                if (
                    ! $allowPartialQualification
                    && $sourceColumns !== []
                    && ! in_array($columnName, $sourceColumns, true)
                    && ! in_array('*', $sourceColumns, true)
                ) {
                    throw new OptimizeError("Unknown column: {$columnName}");
                }
            }

            if ($columnTable === '') {
                $scopePivots = $scope->pivots();

                if ($scopePivots !== [] && $column->findAncestor(Pivot::class) === null) {
                    // Columns outside the pivot are qualified with the pivot's alias
                    $column->set('table', Builder::toIdentifier($scopePivots[count($scopePivots) - 1]->alias()));

                    continue;
                }

                // The table can be '' because a BigQuery UNNEST has no table alias
                $table = $resolver->getTable($column);

                if ($table !== null) {
                    $source = $scope->sources[$table->name()] ?? null;

                    if ($source instanceof Scope && isset($source->columnIndex()[spl_object_id($column)])) {
                        continue;
                    }

                    $column->set('table', $table);
                } elseif (
                    $resolver->dialect->settings['TABLES_REFERENCEABLE_AS_COLUMNS'] === true
                    && count($column->parts()) === 1
                    && isset($scope->selectedSources()[$columnName])
                ) {
                    // BigQuery and Postgres allow tables to be referenced as columns (structs / records)
                    $scope->replace($column, new TableColumn(['this' => $column->args['this'] ?? null]));
                }
            }
        }

        $pivots = $scope->pivots();

        // A chained operator's IN-list may name columns produced by earlier operators, which no
        // source exposes; the chain's accumulated output resolves them. Attribution is only
        // unambiguous when all pivots share one parent, i.e. there's a single pivoted source
        $singleChain = $pivots !== [] && $pivots[0]->parent === $pivots[count($pivots) - 1]->parent;
        $produced = [];
        $pivotedSource = $singleChain ? $pivots[count($pivots) - 1]->alias() : '';
        $available = array_key_exists($pivotedSource, $scope->sources) ? $resolver->getSourceColumns($pivotedSource) : [];

        foreach ($pivots as $pivot) {
            foreach ($pivot->findAll(Column::class) as $column) {
                if ($column->text('table') !== '') {
                    continue;
                }

                if (isset($resolver->allColumns()[$column->name()])) {
                    $table = $resolver->getTable($column->name());

                    if ($table !== null) {
                        $column->set('table', $table);
                    }
                } elseif ($singleChain && isset($produced[$column->name()])) {
                    $column->set('table', Builder::toIdentifier($pivotedSource));
                }
            }

            if ($singleChain) {
                $available = self::pivotOutputColumns($pivot, $available);

                foreach ($available as $name) {
                    $produced[$name] = true;
                }
            }
        }
    }

    /**
     * [BigQuery] Expand foo.bar.* where bar is a struct column.
     *
     * @return list<Expression>
     */
    private static function expandStructStarsNoParens(Dot $expression): array
    {
        $dotColumn = $expression->find(Column::class);

        if (! $dotColumn instanceof Column || ! $dotColumn->isType(DType::STRUCT)) {
            return [];
        }

        // All nested struct values are ColumnDefs, so normalize the first column in one
        $dotColumn = $dotColumn->copy();
        $startingStruct = new ColumnDef(['this' => $dotColumn->args['this'] ?? null, 'kind' => $dotColumn->type()]);

        // The first part is the table name and the last part is the star
        $parts = $expression->parts();
        $dotParts = array_slice($parts, 1, -1);

        // Expanding a nested struct, e.g. t.c.f1.f2.*, finds the last struct (f2)
        foreach (array_slice($dotParts, 1) as $part) {
            $kind = $startingStruct->argExpr('kind');
            $found = false;

            foreach ($kind?->argList('expressions') ?? [] as $field) {
                // Stars can only be expanded if all fields are named
                if (! ($field->args['this'] ?? null) instanceof Identifier) {
                    return [];
                }

                $fieldKind = $field->argExpr('kind');

                if ($field->name() === $part->name() && $fieldKind !== null && $fieldKind->isType(DType::STRUCT)) {
                    $startingStruct = $field;
                    $found = true;

                    break;
                }
            }

            if (! $found) {
                return [];
            }
        }

        $takenNames = [];
        $newSelections = [];

        foreach ($startingStruct->argExpr('kind')?->argList('expressions') ?? [] as $field) {
            $name = $field->name();
            $fieldThis = $field->args['this'] ?? null;

            // Ambiguous or anonymous fields can't be expanded
            if (isset($takenNames[$name]) || ! $fieldThis instanceof Identifier) {
                return [];
            }

            $takenNames[$name] = true;
            $this_ = $fieldThis->copy();
            $all = array_map(static fn (Expression $p): Expression => $p->copy(), [...$dotParts, $this_]);
            $root = array_shift($all);
            $table = $dotColumn->args['table'] ?? null;

            $newColumn = Builder::column(
                $root instanceof Identifier ? $root : $root->name(),
                $table instanceof Identifier ? $table : null,
                fields: array_values(array_filter($all, static fn (Expression $p): bool => $p instanceof Identifier)),
            );
            $newSelections[] = Builder::alias($newColumn, $this_, copy: false);
        }

        return $newSelections;
    }

    /**
     * [RisingWave] Expand (<exp>.bar).*, where bar is a struct column.
     *
     * @return list<Expression>
     */
    private static function expandStructStarsWithParens(Dot $expression): array
    {
        $outerParen = $expression->args['this'] ?? null;

        if (! $outerParen instanceof Paren) {
            return [];
        }

        $dotColumn = $expression->find(Column::class);

        if (! $dotColumn instanceof Column || ! $dotColumn->isType(DType::STRUCT)) {
            return [];
        }

        $parent = $dotColumn->parent;
        $startingStruct = $dotColumn->type();

        // Walk up the AST and down into the struct definition in sync
        while ($parent !== null) {
            if ($parent instanceof Paren) {
                $parent = $parent->parent;

                continue;
            }

            if (! $parent instanceof Dot) {
                return [];
            }

            $rhs = $parent->args['expression'] ?? null;

            if ($rhs instanceof Star) {
                break;
            }

            if (! $rhs instanceof Identifier) {
                return [];
            }

            $matched = false;

            foreach ($startingStruct?->argList('expressions') ?? [] as $fieldDef) {
                if ($fieldDef->name() === $rhs->name()) {
                    $matched = true;
                    $kind = $fieldDef->argExpr('kind');
                    $startingStruct = $kind instanceof DataType ? $kind : null;

                    break;
                }
            }

            if (! $matched) {
                return [];
            }

            $parent = $parent->parent;
        }

        $newSelections = [];

        foreach ($startingStruct?->argList('expressions') ?? [] as $fieldDef) {
            $fieldThis = $fieldDef->args['this'] ?? null;

            if (! $fieldThis instanceof Identifier) {
                continue;
            }

            $newIdentifier = $fieldThis->copy();
            $newDot = Dot::build([$outerParen->copy(), $newIdentifier]);
            $newSelections[] = Builder::alias($newDot, $newIdentifier, copy: false);
        }

        return $newSelections;
    }

    /**
     * Expand stars to lists of column selections.
     *
     * @param  array<string, array<string, true>>  $usingColumnTables
     * @param  array<string, true>  $pseudocolumns
     */
    private static function expandStars(Scope $scope, Resolver $resolver, array $usingColumnTables, array $pseudocolumns, TypeAnnotator $annotator): void
    {
        $newSelections = [];
        $exceptColumns = [];
        $replaceColumns = [];
        $renameColumns = [];
        $ilikePattern = null;
        $coalescedColumns = [];
        $dialect = $resolver->dialect;

        $annotatedAhead = false;

        if ($dialect->settings['SUPPORTS_STRUCT_STAR_EXPANSION'] === true) {
            foreach ($scope->stars() as $star) {
                if ($star instanceof Dot) {
                    $annotatedAhead = true;

                    break;
                }
            }
        }

        if ($annotatedAhead) {
            // Struct expansion needs the types of the scope
            $annotator->annotateScope($scope);
        }

        $scopeExpression = $scope->expression;

        if (! $scopeExpression instanceof Selectable) {
            return;
        }

        foreach (Resolver::selectsOf($scopeExpression) as $expression) {
            $tables = [];

            if ($expression instanceof Star) {
                // Only a string literal ILIKE pattern can filter the expansion at optimization time
                $ilike = $expression->argExpr('ilike');

                if ($ilike !== null && ! $ilike->isString()) {
                    $newSelections[] = $expression;

                    continue;
                }

                $tables = array_map('strval', array_keys($scope->selectedSources()));
                self::addExceptColumns($expression, $tables, $exceptColumns);
                self::addReplaceColumns($expression, $tables, $replaceColumns);
                self::addRenameColumns($expression, $tables, $renameColumns);
                $ilikePattern = self::addIlikeColumns($expression, $dialect);
            } elseif ($expression->isStar()) {
                if ($expression instanceof Column) {
                    $star = $expression->args['this'];
                    $ilike = $star instanceof Expression ? $star->argExpr('ilike') : null;

                    if ($ilike !== null && ! $ilike->isString()) {
                        $newSelections[] = $expression;

                        continue;
                    }

                    $tables[] = $expression->text('table');

                    if ($star instanceof Expression) {
                        self::addExceptColumns($star, $tables, $exceptColumns);
                        self::addReplaceColumns($star, $tables, $replaceColumns);
                        self::addRenameColumns($star, $tables, $renameColumns);
                        $ilikePattern = self::addIlikeColumns($star, $dialect);
                    }
                } elseif ($expression instanceof Dot) {
                    if ($dialect->settings['REQUIRES_PARENTHESIZED_STRUCT_ACCESS'] === true) {
                        $structFields = self::expandStructStarsWithParens($expression);
                    } elseif ($dialect->settings['SUPPORTS_STRUCT_STAR_EXPANSION'] === true) {
                        $structFields = self::expandStructStarsNoParens($expression);
                    } else {
                        $structFields = [];
                    }

                    if ($structFields !== []) {
                        if ($annotatedAhead) {
                            $annotator->uncache($expression);
                        }

                        $star = $expression->args['expression'] ?? null;
                        $excluded = [];
                        $replaced = [];

                        if ($star instanceof Expression) {
                            foreach ($star->argList('except_') as $e) {
                                $excluded[$e->name()] = true;
                            }

                            foreach ($star->argList('replace') as $e) {
                                $replaced[$e->alias()] = $e;
                            }
                        }

                        foreach ($structFields as $f) {
                            if (! isset($excluded[$f->alias()])) {
                                $newSelections[] = $replaced[$f->alias()] ?? $f;
                            }
                        }

                        continue;
                    }
                }
            }

            if ($tables === []) {
                $newSelections[] = $expression;

                continue;
            }

            foreach ($tables as $table) {
                $source = $scope->sources[$table] ?? null;
                $sourceResolver = $resolver;
                $pivots = null;
                $sourceTable = $table;

                if ($source === null) {
                    // The chain's final alias names the resulting source, but only the underlying
                    // source is in the scope sources, so resolve through the chain's parent
                    $chain = $scope->pivots();
                    $parent = $chain !== [] && $chain[0]->parent === $chain[count($chain) - 1]->parent && $chain[count($chain) - 1]->alias() === $table
                        ? $chain[count($chain) - 1]->parent
                        : null;

                    if ($parent !== null) {
                        $pivots = $chain;
                        $sourceTable = $parent->aliasOrName();
                        $source = $scope->sources[$sourceTable] ?? null;
                    }

                    if ($source === null) {
                        // Correlated stars, e.g. (SELECT AS STRUCT x.* EXCEPT (a)), expand an outer source
                        $found = false;

                        foreach ([$resolver, ...$resolver->outerResolvers()] as $sourceResolver) {
                            $source = $sourceResolver->scope->sources[$table] ?? null;

                            if ($source !== null || $sourceResolver->hasUnknownSources()) {
                                $found = true;

                                break;
                            }
                        }

                        if (! $found) {
                            throw new OptimizeError("Unknown table: {$table}");
                        }
                    }

                    if ($source === null) {
                        // An unknown source may own the star, so it's kept instead of expanded
                        $newSelections[] = $expression;

                        break;
                    }
                }

                $columns = $sourceResolver->getSourceColumns($sourceTable, onlyVisible: true);

                if ($columns === []) {
                    $columns = $scope->outerColumns;
                }

                if ($pseudocolumns !== [] && $dialect->settings['EXCLUDES_PSEUDOCOLUMNS_FROM_STAR'] === true) {
                    $columns = array_values(array_filter($columns, static fn (string $name): bool => ! isset($pseudocolumns[strtoupper($name)])));
                }

                // A source exposing duplicate output names would produce ambiguous projections
                if ($columns === [] || in_array('*', $columns, true) || count($columns) !== count(array_unique($columns))) {
                    return;
                }

                $columnsToExclude = $exceptColumns[$table] ?? [];
                $renamedColumns = $renameColumns[$table] ?? [];
                $replacedColumns = $replaceColumns[$table] ?? [];

                // Preserve the case-sensitivity of quoted source columns, so the generated alias
                // isn't folded by normalization
                $sourceExpression = $source instanceof Scope ? $source->expression : null;
                $quotedColumns = [];

                if ($sourceExpression instanceof Query) {
                    foreach ($sourceExpression->selects() as $s) {
                        if (self::outputIdentifier($s)?->quoted() === true) {
                            $quotedColumns[$s->outputName()] = true;
                        }
                    }
                }

                // The operators belong to a specific source, so a star over a source joined
                // alongside it expands from that source's own columns
                if ($pivots === null) {
                    $selectedNode = $scope->selectedSources()[$table][0] ?? null;

                    if ($selectedNode === null && $source instanceof Table) {
                        // A pivoted CTE reference is registered under the pivot's alias
                        $selectedNode = $source;
                    }

                    $pivots = $selectedNode !== null ? array_values(array_filter($selectedNode->argList('pivots'), static fn (Expression $p): bool => $p instanceof Pivot)) : [];
                }

                if ($pivots !== []) {
                    // Each operator consumes the previous one's output
                    $pivotColumns = $columns;

                    foreach ($pivots as $pivot) {
                        /** @var Pivot $pivot */
                        $output = self::pivotOutputColumns($pivot, $pivotColumns);
                        $pivotColumns = $output !== [] ? $output : $pivot->aliasColumnNames();
                    }

                    if ($pivotColumns !== []) {
                        $lastAlias = $pivots[count($pivots) - 1]->alias();

                        foreach ($pivotColumns as $name) {
                            if (! isset($columnsToExclude[$name])) {
                                $newSelections[] = Builder::alias(Builder::column($name, $lastAlias !== '' ? $lastAlias : null), $name, copy: false);
                            }
                        }

                        continue;
                    }
                }

                foreach ($columns as $name) {
                    if (isset($columnsToExclude[$name]) || isset($coalescedColumns[$name])) {
                        continue;
                    }

                    if ($ilikePattern !== null && $ilikePattern !== '' && preg_match('/\A(?:'.$ilikePattern.')\z/iu', $name) !== 1) {
                        continue;
                    }

                    if (isset($usingColumnTables[$name][$table])) {
                        $coalescedColumns[$name] = true;
                        $coalesceArgs = array_map(static fn (string|int $t): Expression => Builder::column($name, (string) $t), array_keys($usingColumnTables[$name]));
                        $newSelections[] = Builder::alias(Builder::func('coalesce', $coalesceArgs), $name, copy: false);
                    } else {
                        $alias = $renamedColumns[$name] ?? $name;
                        // A name with characters the dialect would have changed was quoted
                        $quoted = isset($quotedColumns[$name]) || ($source instanceof Table && $dialect->caseSensitive($name));
                        $selection = $replacedColumns[$name] ?? Builder::column($name, $table, quoted: $quoted);
                        $newSelections[] = $alias !== $name ? Builder::alias($selection, $alias, copy: false) : $selection;
                    }
                }
            }

            if ($annotatedAhead) {
                // The star projection was replaced by the expansions above
                $annotator->uncache($expression);
            }
        }

        // Don't overwrite the initial selections with an empty list
        if ($newSelections !== [] && $scopeExpression instanceof Select) {
            if ($annotatedAhead) {
                $annotator->uncache($scopeExpression, deep: false);
            }

            $scopeExpression->set('expressions', $newSelections);
            // The parent scope sees this scope's columns, so they must reflect the expansions
            $scope->clearCache();
        }
    }

    private static function outputIdentifier(?Expression $selection): ?Identifier
    {
        if ($selection instanceof Alias) {
            $identifier = $selection->args['alias'] ?? null;
        } elseif ($selection instanceof Column) {
            $identifier = $selection->args['this'] ?? null;
        } else {
            $identifier = null;
        }

        return $identifier instanceof Identifier ? $identifier : null;
    }

    private static function addIlikeColumns(Expression $expression, Dialect $dialect): ?string
    {
        $ilike = $expression->argExpr('ilike');

        if ($ilike === null) {
            return null;
        }

        $chars = [];
        $pattern = $ilike->name();
        $characters = mb_str_split($pattern);
        $length = count($characters);
        $backslashEscape = $dialect->settings['STAR_ILIKE_BACKSLASH_ESCAPE'] === true;

        for ($i = 0; $i < $length; $i++) {
            $c = $characters[$i];

            if ($c === '\\' && $backslashEscape && $i + 1 < $length) {
                $i++;
                $chars[] = preg_quote($characters[$i], '/');
            } elseif ($c === '%') {
                $chars[] = '.*';
            } elseif ($c === '_') {
                $chars[] = '.';
            } else {
                $chars[] = preg_quote($c, '/');
            }
        }

        return implode('', $chars);
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, array<string, true>>  $exceptColumns
     */
    private static function addExceptColumns(Expression $expression, array $tables, array &$exceptColumns): void
    {
        $except = $expression->argList('except_');

        if ($except === []) {
            return;
        }

        $columns = [];
        $qualifiedColumns = [];

        foreach ($except as $e) {
            // A qualified exclusion only applies to the source it references
            if ($e instanceof Column && $e->text('table') !== '') {
                $qualifiedColumns[$e->text('table')][$e->name()] = true;
            } else {
                $columns[$e->name()] = true;
            }
        }

        foreach ($tables as $table) {
            $tableColumns = isset($qualifiedColumns[$table]) ? $columns + $qualifiedColumns[$table] : $columns;

            if ($tableColumns !== []) {
                $exceptColumns[$table] = $tableColumns;
            }
        }
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, array<string, string>>  $renameColumns
     */
    private static function addRenameColumns(Expression $expression, array $tables, array &$renameColumns): void
    {
        $rename = $expression->argList('rename');

        if ($rename === []) {
            return;
        }

        $columns = [];

        foreach ($rename as $e) {
            $eThis = $e->argExpr('this');
            $columns[$eThis !== null ? $eThis->name() : ''] = $e->alias();
        }

        foreach ($tables as $table) {
            $renameColumns[$table] = $columns;
        }
    }

    /**
     * @param  list<string>  $tables
     * @param  array<string, array<string, Expression>>  $replaceColumns
     */
    private static function addReplaceColumns(Expression $expression, array $tables, array &$replaceColumns): void
    {
        $replace = $expression->argList('replace');

        if ($replace === []) {
            return;
        }

        $columns = [];

        foreach ($replace as $e) {
            $columns[$e->alias()] = $e;
        }

        foreach ($tables as $table) {
            $replaceColumns[$table] = $columns;
        }
    }
}
