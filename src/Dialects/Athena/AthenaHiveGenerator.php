<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Athena;

use Kwasii\LaravelSqlParser\Dialects\Hive\HiveGenerator;
use Kwasii\LaravelSqlParser\Expressions\Alter;
use Kwasii\LaravelSqlParser\Expressions\ColumnDef;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Schema;

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
