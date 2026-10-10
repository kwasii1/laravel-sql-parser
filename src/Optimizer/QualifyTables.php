<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Closure;
use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\ColumnDef;
use Kwasii\LaravelSqlParser\Expressions\Create;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\Func;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Join;
use Kwasii\LaravelSqlParser\Expressions\Pivot;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Subquery;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\TableAlias;
use Kwasii\LaravelSqlParser\Expressions\TableFromRows;
use Kwasii\LaravelSqlParser\Expressions\Values;
use Kwasii\LaravelSqlParser\Schema\MappingSchema;
use Kwasii\LaravelSqlParser\Support\Scope;

/**
 * Rewrite the AST to have fully qualified tables (sqlglot optimizer/qualify_tables.py). Join
 * constructs such as (t1 JOIN t2) AS t are expanded into (SELECT * FROM t1 AS t1, t2 AS t2) AS t.
 */
final class QualifyTables
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @param  (Closure(Table): void)|null  $onQualify  called after a table has been qualified
     * @param  bool  $canonicalizeTableAliases  use canonical aliases (_0, _1, ...) for all sources
     * @return T
     */
    public static function qualifyTables(
        Expression $expression,
        Identifier|string|null $db = null,
        Identifier|string|null $catalog = null,
        ?Closure $onQualify = null,
        string|Dialect|null $dialect = null,
        bool $canonicalizeTableAliases = false,
    ): Expression {
        $dialect = Dialect::get($dialect);
        $sequence = 0;
        $nextAliasName = static function () use (&$sequence): string {
            return '_'.$sequence++;
        };

        $db = self::defaultPart($db, $dialect);
        $catalog = self::defaultPart($catalog, $dialect);

        $qualify = static function (Table $table) use ($db, $catalog): void {
            if (($table->args['this'] ?? null) instanceof Identifier) {
                if ($db !== null && ! ($table->args['db'] ?? null) instanceof Expression) {
                    $table->set('db', $db->copy());
                }

                if ($catalog !== null && ! ($table->args['catalog'] ?? null) instanceof Expression && ($table->args['db'] ?? null) instanceof Expression) {
                    $table->set('catalog', $catalog->copy());
                }
            }
        };

        if (($db !== null || $catalog !== null) && ! $expression instanceof Query) {
            $with = $expression->argExpr('with_');
            $cteNames = [];

            foreach ($with?->argList('expressions') ?? [] as $cte) {
                $cteNames[$cte->aliasOrName()] = true;
            }

            foreach ($expression->walk(prune: static fn (Expression $n): bool => $n instanceof Query) as $node) {
                if ($node instanceof Table && ! isset($cteNames[$node->name()])) {
                    $qualify($node);
                }
            }
        }

        /**
         * @param  array<string, string>  $canonicalAliases
         * @param  list<string|Identifier|ColumnDef|Expression>|null  $columns
         */
        $setAlias = static function (
            Expression $expression,
            array &$canonicalAliases,
            ?string $targetAlias = null,
            ?Scope $scope = null,
            bool $normalize = false,
            ?array $columns = null,
        ) use ($canonicalizeTableAliases, $nextAliasName, $dialect): void {
            $alias = $expression->argExpr('alias') ?? new TableAlias;

            if ($canonicalizeTableAliases) {
                $newAliasName = $nextAliasName();
                $key = $alias->name() !== '' ? $alias->name() : ($targetAlias ?? '');
                $canonicalAliases[$key] = $newAliasName;
            } elseif ($alias->name() === '') {
                $newAliasName = $targetAlias !== null && $targetAlias !== '' ? $targetAlias : $nextAliasName();

                if ($normalize && $targetAlias !== null && $targetAlias !== '') {
                    $newAliasName = NormalizeIdentifiers::normalizeIdentifiers($newAliasName, $dialect)->name();
                }
            } else {
                return;
            }

            $alias->set('this', Builder::toIdentifier($newAliasName));

            if ($columns !== null && $columns !== []) {
                $alias->set('columns', array_map(
                    static fn (string|Expression $c): ?Expression => is_string($c) ? Builder::toIdentifier($c) : $c->copy(),
                    $columns,
                ));
            }

            $expression->set('alias', $alias);
            $scope?->renameSource(null, $newAliasName);
        };

        $functionColumnNames = $dialect->settings['DEFAULT_FUNCTIONS_COLUMN_NAMES'] ?? [];
        $functionColumnNames = is_array($functionColumnNames) ? $functionColumnNames : [];

        foreach (Scope::traverse($expression) as $scope) {
            $parent = $scope->parent;
            $localColumns = $scope->localColumns();
            $canonicalAliases = [];

            $queries = $scope->subqueries();

            // Subquery wrappers around a DML / DDL query fragment, e.g. a CREATE FUNCTION body or
            // an UPDATE's SET subquery, don't belong to any scope, so they aren't collected above
            if ($scope->isRoot() && $scope->expression instanceof Subquery) {
                $queries[] = $scope->expression->unnest();
            } elseif ($scope->isSubquery()) {
                $queries[] = $scope->expression;
            }

            foreach ($queries as $query) {
                $subquery = $query->parent;

                if ($subquery instanceof Subquery) {
                    $unwrapped = $subquery->unwrap();

                    if ($unwrapped->parent instanceof From || $unwrapped->parent instanceof Join) {
                        // A wrapped derived table must keep its alias
                        continue;
                    }

                    if ($unwrapped->parent instanceof Create && $unwrapped !== $subquery) {
                        // Function bodies may require wrapping parentheses, e.g. in BigQuery
                        // `... AS ((SELECT 1))` the outer parens delimit the body itself
                        $unwrapped->set('this', $subquery);
                    } else {
                        $unwrapped->replace($subquery);
                    }
                }
            }

            foreach ($scope->derivedTables() as $derivedTable) {
                $unnested = $derivedTable->unnest();

                if ($unnested instanceof Table) {
                    $joins = $unnested->args['joins'] ?? null;
                    $unnested->set('joins', null);
                    $select = Builder::selectStarFrom($unnested->copy());
                    $inner = $derivedTable->args['this'];

                    if ($inner instanceof Expression) {
                        $inner->replace($select);
                    }

                    $select->set('joins', $joins);
                }

                $setAlias($derivedTable, $canonicalAliases, scope: $scope);
                $pivots = $derivedTable->argList('pivots');

                if ($pivots !== []) {
                    $setAlias($pivots[count($pivots) - 1], $canonicalAliases);
                }
            }

            $tableAliases = [];

            foreach ($scope->sources as $name => $source) {
                $name = (string) $name;

                // A source can appear in many scopes, e.g. as a lateral source of a UDTF scope or as a
                // CTE propagated to inner scopes. Deferring to the parent scope when it contains the same
                // source ensures each source is processed once, in the outermost scope that contains it
                if ($parent !== null && ($parent->sources[$name] ?? null) === $source) {
                    continue;
                }

                if ($source instanceof Table) {
                    // An empty name means a non-table source, e.g. a pivoted CTE
                    $isRealTableSource = $name !== '';
                    $sourcePivots = $source->argList('pivots');
                    $pivot = $sourcePivots === [] ? null : $sourcePivots[count($sourcePivots) - 1];

                    if ($pivot !== null) {
                        $name = $source->name();
                    }

                    $tableThis = $source->args['this'] ?? null;
                    $tableAlias = $source->argExpr('alias');
                    $functionColumns = null;

                    if ($tableThis instanceof Func) {
                        $default = $functionColumnNames[$tableThis::class] ?? null;

                        if ($tableAlias === null) {
                            $functionColumns = $default === null ? [] : array_values((array) $default);
                        } elseif ($tableAlias->argList('columns') !== []) {
                            $functionColumns = $tableAlias->argList('columns');
                        } elseif ($default !== null) {
                            $functionColumns = [$source->aliasOrName()];
                            $source->set('alias', null);
                            $name = '';
                        }
                    }

                    $setAlias(
                        $source,
                        $canonicalAliases,
                        targetAlias: $name !== '' ? $name : ($source->name() !== '' ? $source->name() : null),
                        normalize: true,
                        columns: $functionColumns,
                    );

                    $sourceFqn = implode('.', array_map(static fn (Expression $p): string => $p->name(), $source->parts()));
                    $hadExplicitAlias = $tableAlias !== null && $tableAlias->name() !== '';
                    $newAlias = $source->argExpr('alias')?->argExpr('this');

                    if ((! $hadExplicitAlias || ! isset($tableAliases[$sourceFqn])) && $newAlias !== null) {
                        $tableAliases[$sourceFqn] = $newAlias->copy();
                    }

                    if ($pivot instanceof Pivot) {
                        $targetAlias = $pivot->flag('unpivot') ? $source->alias() : null;
                        $setAlias($pivot, $canonicalAliases, targetAlias: $targetAlias, normalize: true);

                        // A pivoted CTE isn't qualified
                        if (($scope->sources[$source->aliasOrName()] ?? null) instanceof Scope) {
                            continue;
                        }
                    }

                    if ($isRealTableSource) {
                        $qualify($source);

                        if ($onQualify !== null) {
                            $onQualify($source);
                        }
                    }
                } elseif ($source->isUdtf()) {
                    $udtf = $source->expression;
                    $setAlias($udtf, $canonicalAliases);
                    $tableAlias = $udtf->argExpr('alias');

                    if ($tableAlias === null) {
                        continue;
                    }

                    if ($udtf instanceof Values && $tableAlias->argList('columns') === []) {
                        $tableAlias->set('columns', array_map(
                            static fn (Identifier $i): Identifier => NormalizeIdentifiers::normalizeIdentifiers($i, $dialect),
                            $dialect->generateValuesAliases($udtf),
                        ));
                    } elseif ($udtf instanceof TableFromRows && $tableAlias->argList('columns') === []) {
                        $udtfThis = $udtf->args['this'] ?? null;
                        $default = is_object($udtfThis) ? ($functionColumnNames[$udtfThis::class] ?? null) : null;

                        if ($default !== null && $default !== [] && $default !== '') {
                            $tableAlias->set('columns', array_map(
                                static fn (mixed $c): Identifier => NormalizeIdentifiers::normalizeIdentifiers(Builder::toIdentifier((string) $c) ?? new Identifier(['this' => '']), $dialect),
                                array_values((array) $default),
                            ));
                        }
                    }
                }
            }

            foreach ($scope->tables() as $table) {
                if ($table->alias() === '' && ($table->parent instanceof From || $table->parent instanceof Join)) {
                    $setAlias($table, $canonicalAliases, targetAlias: $table->name());
                }
            }

            foreach ($localColumns as $column) {
                $columnTable = $column->text('table');

                if ($column->text('db') !== '') {
                    $parts = $column->parts();
                    $key = implode('.', array_map(static fn (Expression $p): string => $p->name(), array_slice($parts, 0, -1)));
                    $tableAlias = $tableAliases[$key] ?? null;

                    if ($tableAlias !== null) {
                        foreach (['table', 'db', 'catalog'] as $part) {
                            $column->set($part, null);
                        }

                        $column->set('table', $tableAlias->copy());
                    }
                } elseif ($canonicalAliases !== [] && $columnTable !== '') {
                    $canonicalTable = $canonicalAliases[$columnTable] ?? null;

                    if ($canonicalTable !== null && $canonicalTable !== '' && $canonicalTable !== $columnTable) {
                        // Amend existing aliases, e.g. t.c -> _0.c if t is aliased to _0
                        $column->set('table', Builder::toIdentifier($canonicalTable));
                    }
                }
            }
        }

        return $expression;
    }

    private static function defaultPart(Identifier|string|null $part, Dialect $dialect): ?Identifier
    {
        if ($part === null || $part === '') {
            return null;
        }

        $identifier = MappingSchema::parseIdentifier($part instanceof Identifier ? $part->copy() : $part, $dialect);
        $identifier->setMeta('is_table', true);

        return NormalizeIdentifiers::normalizeIdentifiers($identifier, $dialect);
    }
}
