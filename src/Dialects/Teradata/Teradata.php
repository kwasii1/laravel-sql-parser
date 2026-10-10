<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Teradata;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

/**
 * The Teradata dialect (sqlglot dialects/teradata.py).
 */
class Teradata extends Dialect
{
    public const string NAME = 'teradata';

    public const string SETTINGS = TeradataDialectTables::class;

    public const string TOKENIZER_TABLES = TeradataTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = TeradataJsonPathTokenizerTables::class;

    public const string PARSER = TeradataParser::class;

    public const string GENERATOR = TeradataGenerator::class;

    public const string TYPE_TABLES = TeradataTypeTables::class;
}
