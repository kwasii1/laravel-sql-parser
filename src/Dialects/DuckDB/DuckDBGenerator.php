<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB;

use LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBPart1;
use LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBPart2;
use LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBPart3;
use LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBPart4;
use LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\Concerns\GeneratesDuckDBTransforms;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;

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
