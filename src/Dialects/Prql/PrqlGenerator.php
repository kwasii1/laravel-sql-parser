<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Prql;

use LaravelSqlParser\LaravelSqlParser\Generator\Generator;

/**
 * PRQL generator (sqlglot generators/prql.py).
 */
class PrqlGenerator extends Generator
{
    public const string TABLES = PrqlGeneratorTables::class;
}
