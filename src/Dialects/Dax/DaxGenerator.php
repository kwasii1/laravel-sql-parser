<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Dax;

use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * DAX generator (sqlglot generators/dax.py).
 */
class DaxGenerator extends Generator
{
    public const string TABLES = DaxGeneratorTables::class;
}
