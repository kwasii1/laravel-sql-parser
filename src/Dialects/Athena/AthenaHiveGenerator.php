<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Athena;

use LaravelSqlParser\LaravelSqlParser\Dialects\Hive\HiveGenerator;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alter;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;

/**
 * Athena generator for the Hive engine (sqlglot generators/athena.py _HiveGenerator).
 */
class AthenaHiveGenerator extends HiveGenerator
{
    protected function alterSql(Expression $expression): string
    {
        if ($expression instanceof Alter && $expression->text('kind') === 'TABLE') {
            $actions = $expression->argList('actions');

            if ($actions !== [] && $actions[0] instanceof ColumnDef) {
                $expression->set('actions', [new Schema(['expressions' => $actions])]);
            }
        }

        return parent::alterSql($expression);
    }
}
