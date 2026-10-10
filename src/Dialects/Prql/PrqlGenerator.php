<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Prql;

use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * PRQL generator (sqlglot generators/prql.py).
 */
class PrqlGenerator extends Generator
{
    public const string TABLES = PrqlGeneratorTables::class;
}
