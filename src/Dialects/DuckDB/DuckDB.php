<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;

/**
 * The DuckDB dialect (sqlglot dialects/duckdb.py).
 */
class DuckDB extends Dialect
{
    public const string NAME = 'duckdb';

    public const string SETTINGS = DuckDBDialectTables::class;

    public const string TOKENIZER_TABLES = DuckDBTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = DuckDBJsonPathTokenizerTables::class;

    public const string PARSER = DuckDBParser::class;

    public const string GENERATOR = DuckDBGenerator::class;

    public const string TYPE_TABLES = DuckDBTypeTables::class;

    public const string TYPE_ANNOTATOR = DuckDBTypeAnnotator::class;

    /**
     * DuckDB also supports JSON pointers, which start with `/`, and `[#-i]` to index from the back
     * of a list; neither is parsed as a JSON path.
     */
    public function toJsonPath(?Expression $path): ?Expression
    {
        if ($path instanceof Literal && (str_starts_with($path->name(), '/') || str_contains($path->name(), '[#'))) {
            return $path;
        }

        return parent::toJsonPath($path);
    }
}
