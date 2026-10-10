<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\DuckDB;

use Kwasii\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBPart1;
use Kwasii\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBPart2;
use Kwasii\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBPart3;
use Kwasii\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBPart4;
use Kwasii\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBTransforms;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * DuckDB generator (sqlglot generators/duckdb.py).
 */
class DuckDBGenerator extends Generator
{
    use GeneratesDuckDBPart1;
    use GeneratesDuckDBPart2;
    use GeneratesDuckDBPart3;
    use GeneratesDuckDBPart4;
    use GeneratesDuckDBTransforms;

    public const string TABLES = DuckDBGeneratorTables::class;
}
