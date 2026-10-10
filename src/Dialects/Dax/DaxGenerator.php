<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Dax;

use LaravelSqlParser\LaravelSqlParser\Generator\Generator;

/**
 * DAX generator (sqlglot generators/dax.py).
 */
class DaxGenerator extends Generator
{
    public const string TABLES = DaxGeneratorTables::class;
}
