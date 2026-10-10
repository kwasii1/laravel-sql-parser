<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Dremio;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

/**
 * The Dremio dialect (sqlglot dialects/dremio.py).
 */
class Dremio extends Dialect
{
    public const string NAME = 'dremio';

    public const string SETTINGS = DremioDialectTables::class;

    public const string TOKENIZER_TABLES = DremioTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = DremioJsonPathTokenizerTables::class;

    public const string PARSER = DremioParser::class;

    public const string GENERATOR = DremioGenerator::class;

    public const string TYPE_TABLES = DremioTypeTables::class;
}
