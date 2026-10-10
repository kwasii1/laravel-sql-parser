<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Doris;

use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySql;

/**
 * The Apache Doris dialect (sqlglot dialects/doris.py).
 */
class Doris extends MySql
{
    public const string NAME = 'doris';

    public const string SETTINGS = DorisDialectTables::class;

    public const string TOKENIZER_TABLES = DorisTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = DorisJsonPathTokenizerTables::class;

    public const string PARSER = DorisParser::class;

    public const string GENERATOR = DorisGenerator::class;

    public const string TYPE_TABLES = DorisTypeTables::class;
}
