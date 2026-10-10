<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Errors\OptimizeError;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Subquery;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Schema\Schema;
use Kwasii\LaravelSqlParser\Support\Scope;

/**
 * Wrap the tables of multi-source queries in SELECT * subqueries (sqlglot
 * optimizer/isolate_table_selects.py).
 */
final class IsolateTableSelects
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @param  array<array-key, mixed>|Schema|null  $schema
     * @return T
     */
    public static function isolateTableSelects(Expression $expression, array|Schema|null $schema = null, string|Dialect|null $dialect = null): Expression
    {
        $schema = Schema::ensure($schema, $dialect);

        foreach (Scope::traverse($expression) as $scope) {
            if (count($scope->selectedSources()) === 1) {
                continue;
            }

            foreach ($scope->selectedSources() as [, $source]) {
                if (
                    ! $source instanceof Table
                    || $schema->columnNames($source) === []
                    || $source->parent instanceof Subquery
                    || $source->parent?->parent instanceof Table
                ) {
                    continue;
                }

                if ($source->alias() === '') {
                    throw new OptimizeError('Tables require an alias. Run qualify_tables optimization.');
                }

                $alias = $source->alias();
                $placeholder = new Table;
                $source->replace($placeholder);
                $select = Builder::selectStarFrom(Builder::alias($source, $source->aliasOrName(), table: true, copy: false));
                $placeholder->replace($select->subquery($alias, copy: false));
            }
        }

        return $expression;
    }
}
