<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Kwasii\LaravelSqlParser\Expressions\Alias;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\CTE;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Lateral;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\SetOperation;
use Kwasii\LaravelSqlParser\Expressions\Subquery;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\TableColumn;
use Kwasii\LaravelSqlParser\Expressions\Unnest;
use Kwasii\LaravelSqlParser\Expressions\With;
use Kwasii\LaravelSqlParser\Support\Scope;

/**
 * Rewrite a qualified query to a canonical structural form (sqlglot
 * optimizer/canonicalize_internal_names.py): internal names (table aliases, CTE /
 * subquery names, internal column aliases) become sequential `_tN` / `_cN`, while
 * base-table names, their columns and the top-level output aliases are preserved.
 */
final class CanonicalizeInternalNames
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public static function canonicalizeInternalNames(Expression $expression): Expression
    {
        if (! $expression instanceof Query) {
            return $expression;
        }

        $outputScopeExprs = [];
        $stack = [$expression];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node instanceof SetOperation) {
                $stack[] = self::unnested($node->args['this'] ?? null);

                if ($node->flag('by_name')) {
                    $stack[] = self::unnested($node->args['expression'] ?? null);
                }
            } elseif ($node !== null) {
                $outputScopeExprs[spl_object_id($node)] = true;
            }
        }

        $tableCounter = 0;
        $columnCounter = 0;
        $nextTable = static function () use (&$tableCounter): string {
            return '_t'.$tableCounter++;
        };
        $nextColumn = static function () use (&$columnCounter): string {
            return '_c'.$columnCounter++;
        };

        /** @var array<int, string> $scopeTable */
        $scopeTable = [];
        /** @var array<int, array<string, string>> $scopeOutputs */
        $scopeOutputs = [];
        /** @var array<int, array<string, string>> $tableColumns */
        $tableColumns = [];
        /** @var array<int, array<string, string>> $udtfColumns */
        $udtfColumns = [];

        foreach (Scope::traverse($expression) as $scope) {
            $scopeExpr = $scope->expression;
            $isOutputScope = isset($outputScopeExprs[spl_object_id($scopeExpr)]);

            /** @var array<string, list<Expression>> $columnsBySource */
            $columnsBySource = [];

            foreach ($scope->columns() as $col) {
                $columnsBySource[$col->text('table')][] = $col;
            }

            foreach ($scope->tableColumns() as $tableCol) {
                $columnsBySource[$tableCol->name()][] = $tableCol;
            }

            foreach ($scope->stars() as $star) {
                if ($star instanceof Column && $star->text('table') !== '') {
                    $columnsBySource[$star->text('table')][] = $star;
                }
            }

            /** @var array<string, array{0: string, 1: string}> $tableMap */
            $tableMap = [];
            $refCanonTaken = [];

            foreach ($scope->sources as $sourceName => $source) {
                $sourceName = (string) $sourceName;
                $sourceCols = $columnsBySource[$sourceName] ?? [];
                $aliasHolder = null;
                $isBaseSource = $source instanceof Table;

                if ($source instanceof Table) {
                    $canonT = $scopeTable[spl_object_id($source)] ?? '';

                    if ($canonT === '') {
                        $canonT = $nextTable();
                        $scopeTable[spl_object_id($source)] = $canonT;
                    }

                    $childOutput = [];
                    $tableColumns[spl_object_id($source)] ??= [];
                    $nameMap = &$tableColumns[spl_object_id($source)];
                } else {
                    $srcExpr = $source->expression;
                    $childOutput = $scopeOutputs[spl_object_id($srcExpr)] ?? [];
                    $parent = $srcExpr->parent;

                    if ($parent instanceof CTE || $parent instanceof Subquery) {
                        $aliasHolder = $parent;
                    } elseif (
                        $parent instanceof SetOperation
                        && $parent->parent instanceof CTE
                        && $parent->parent->parent instanceof With
                        && $parent->parent->parent->flag('recursive')
                    ) {
                        $aliasHolder = $parent->parent;
                    } elseif ($source->isUdtf()) {
                        $aliasHolder = $srcExpr;
                    }

                    $isUdtfSource = $aliasHolder === $srcExpr;
                    $tableKey = $aliasHolder !== null ? spl_object_id($aliasHolder) : spl_object_id($source);
                    $canonT = $scopeTable[$tableKey] ?? '';

                    if ($canonT === '') {
                        $canonT = $nextTable();
                        $scopeTable[$tableKey] = $canonT;
                    } elseif (! $isUdtfSource) {
                        $aliasHolder = null;
                    }

                    if ($isUdtfSource) {
                        $udtfColumns[spl_object_id($srcExpr)] ??= [];
                        $nameMap = &$udtfColumns[spl_object_id($srcExpr)];
                    } else {
                        $nameMap = [];
                    }
                }

                if (isset($refCanonTaken[$canonT])) {
                    $refAlias = $nextTable();
                } else {
                    $refAlias = $canonT;
                    $refCanonTaken[$canonT] = true;
                }

                $tableMap[$sourceName] = [$canonT, $refAlias];

                $structFieldNames = [];
                $src = $source instanceof Table ? ($source->args['expression'] ?? null) : $source->expression;

                if ($src instanceof Unnest) {
                    $first = $src->expressions()[0] ?? null;
                    $elementType = $first?->type()?->expressions()[0] ?? null;

                    if ($first !== null && $elementType instanceof Expression && $elementType->isType(DType::STRUCT)) {
                        foreach ($elementType->expressions() as $cd) {
                            $structFieldNames[$cd->name()] = true;
                        }
                    }
                }

                foreach ($sourceCols as $srcCol) {
                    if ($srcCol instanceof TableColumn) {
                        $ident = $srcCol->args['this'] ?? null;

                        if ($ident instanceof Identifier) {
                            self::canon($ident, $refAlias);
                        }

                        continue;
                    }

                    $oldName = $srcCol->name();
                    $preserveCol = $isBaseSource || isset($structFieldNames[$oldName]);
                    $canonCol = $nameMap[$oldName] ?? null;

                    if ($canonCol === null) {
                        if ($preserveCol) {
                            $canonCol = $oldName;
                        } else {
                            $child = $childOutput[$oldName] ?? '';
                            $canonCol = $child !== '' ? $child : $nextColumn();
                        }

                        $nameMap[$oldName] = $canonCol;
                    }

                    $ident = $srcCol->args['this'] ?? null;

                    if (! $preserveCol && $ident instanceof Identifier) {
                        self::canon($ident, $canonCol);
                    }

                    $tableId = $srcCol->args['table'] ?? null;

                    if ($tableId instanceof Identifier) {
                        self::canon($tableId, $refAlias);
                    }
                }

                if ($aliasHolder !== null) {
                    $alias = $aliasHolder->args['alias'] ?? null;

                    if ($alias instanceof Expression) {
                        $aliasThis = $alias->args['this'] ?? null;

                        if ($aliasThis instanceof Identifier) {
                            self::canon($aliasThis, $canonT);
                        }

                        $aliasColumns = $alias->argList('columns');

                        if ($aliasColumns !== []) {
                            $alias->set('columns', array_map(
                                static fn (Expression $c): ?Identifier => Builder::toIdentifier($nameMap[$c->name()] ?? $c->name(), quoted: $c->flag('quoted')),
                                $aliasColumns,
                            ));
                        }
                    }

                    $unnest = $aliasHolder instanceof Lateral ? ($aliasHolder->args['this'] ?? null) : $aliasHolder;

                    if ($unnest instanceof Unnest) {
                        $offsetId = $unnest->args['offset'] ?? null;

                        if ($offsetId instanceof Identifier && isset($nameMap[$offsetId->name()])) {
                            self::canon($offsetId, $nameMap[$offsetId->name()]);
                        }
                    }
                }

                unset($nameMap);
            }

            foreach ($scope->pivots() as $pivot) {
                $pivotAlias = $pivot->args['alias'] ?? null;
                $pivotAliasThis = $pivotAlias instanceof Expression ? ($pivotAlias->args['this'] ?? null) : null;

                if (! $pivotAliasThis instanceof Identifier) {
                    continue;
                }

                $pivotCols = $columnsBySource[$pivotAliasThis->name()] ?? [];

                if ($pivotCols === []) {
                    continue;
                }

                $canonT = $nextTable();
                self::canon($pivotAliasThis, $canonT);

                foreach ($pivotCols as $pivotCol) {
                    $tableId = $pivotCol->args['table'] ?? null;

                    if ($tableId instanceof Identifier) {
                        self::canon($tableId, $canonT);
                    }
                }
            }

            foreach ($scope->tables() as $table) {
                $entry = $tableMap[$table->aliasOrName()] ?? null;

                if ($entry === null) {
                    continue;
                }

                [$sourceCanon, $refAlias] = $entry;
                $tableThis = $table->args['this'] ?? null;

                if ($tableThis instanceof Identifier && ! ($table->args['db'] ?? null) instanceof Expression) {
                    self::canon($tableThis, $sourceCanon);
                }

                $alias = $table->args['alias'] ?? null;

                if ($alias instanceof Expression) {
                    $aliasThis = $alias->args['this'] ?? null;

                    if ($aliasThis instanceof Identifier) {
                        self::canon($aliasThis, $refAlias);
                    }

                    $aliasColumns = $alias->argList('columns');

                    if ($aliasColumns !== []) {
                        $tc = $tableColumns[spl_object_id($table)] ?? [];
                        $alias->set('columns', array_map(
                            static fn (Expression $c): ?Identifier => Builder::toIdentifier($tc[$c->name()] ?? $c->name(), quoted: $c->flag('quoted')),
                            $aliasColumns,
                        ));
                    }
                }
            }

            /** @var array<string, string> $outputMap */
            $outputMap = [];

            if ($scopeExpr instanceof Select) {
                foreach ($scopeExpr->selects() as $sel) {
                    if (($sel instanceof Alias || $sel instanceof Subquery) && $sel->alias() !== '') {
                        $oldAlias = $sel->alias();

                        if ($isOutputScope) {
                            $newName = $oldAlias;
                        } else {
                            $newName = $nextColumn();
                            $sel->set('alias', Builder::toIdentifier($newName, quoted: true));
                        }

                        $outputMap[$oldAlias] = $newName;
                    }
                }
            } elseif ($scopeExpr instanceof SetOperation && $scope->setOperationScopes !== []) {
                $outputMap = $scopeOutputs[spl_object_id($scope->setOperationScopes[0]->expression)] ?? [];

                if ($scopeExpr->flag('by_name')) {
                    $rightOut = $scopeOutputs[spl_object_id($scope->setOperationScopes[1]->expression)] ?? [];

                    foreach ($rightOut as $k => $v) {
                        $outputMap[$k] ??= $v;
                    }
                }
            } elseif ($scope->isUdtf() && $scope->subqueryScopes !== []) {
                $outputMap = $scopeOutputs[spl_object_id($scope->subqueryScopes[0]->expression)] ?? [];
            }

            $scopeOutputs[spl_object_id($scopeExpr)] = $outputMap;

            foreach (Scope::findAllInScope($scopeExpr, Column::class) as $col) {
                $ident = $col->args['this'] ?? null;

                if ($col->text('table') === '' && isset($outputMap[$col->name()]) && $ident instanceof Identifier) {
                    self::canon($ident, $outputMap[$col->name()]);
                }
            }

            if ($scopeExpr instanceof SetOperation && $scopeExpr->flag('by_name') && count($scope->setOperationScopes) === 2) {
                [$leftScope, $rightScope] = $scope->setOperationScopes;
                $leftOut = $scopeOutputs[spl_object_id($leftScope->expression)] ?? [];
                $rightOut = $scopeOutputs[spl_object_id($rightScope->expression)] ?? [];

                $rename = [];

                foreach ($leftOut as $origName => $leftCanon) {
                    $rightCanon = $rightOut[$origName] ?? '';

                    if ($rightCanon !== '' && $rightCanon !== $leftCanon) {
                        $rename[$rightCanon] = $leftCanon;
                    }
                }

                if ($rename !== []) {
                    $renameStack = [$rightScope->expression];

                    while ($renameStack !== []) {
                        $node = array_pop($renameStack);

                        if ($node instanceof SetOperation) {
                            foreach (['this', 'expression'] as $key) {
                                $child = $node->args[$key] ?? null;

                                if ($child instanceof Expression) {
                                    $renameStack[] = $child;
                                }
                            }

                            continue;
                        }

                        if (! $node instanceof Select) {
                            continue;
                        }

                        foreach ($node->selects() as $sel) {
                            if ($sel instanceof Alias) {
                                $aid = $sel->args['alias'] ?? null;

                                if ($aid instanceof Identifier && isset($rename[$aid->name()])) {
                                    self::canon($aid, $rename[$aid->name()]);
                                }
                            }
                        }

                        foreach (Scope::findAllInScope($node, Column::class) as $col) {
                            $ident = $col->args['this'] ?? null;

                            if ($col->text('table') === '' && isset($rename[$col->name()]) && $ident instanceof Identifier) {
                                self::canon($ident, $rename[$col->name()]);
                            }
                        }
                    }

                    $scopeOutputs[spl_object_id($rightScope->expression)] = array_map(
                        static fn (string $v): string => $rename[$v] ?? $v,
                        $rightOut,
                    );
                }
            }
        }

        return $expression;
    }

    private static function unnested(mixed $node): ?Expression
    {
        return $node instanceof Expression ? $node->unnest() : null;
    }

    private static function canon(Identifier $ident, string $name): void
    {
        $ident->set('this', $name);
        $ident->set('quoted', true);
    }
}
