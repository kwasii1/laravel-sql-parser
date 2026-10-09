<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Sqlite;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

/**
 * The SQLite dialect (sqlglot dialects/sqlite.py).
 */
class Sqlite extends Dialect
{
    public const string NAME = 'sqlite';

    public const string SETTINGS = SqliteDialectTables::class;

    public const string TOKENIZER_TABLES = SqliteTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = SqliteJsonPathTokenizerTables::class;

    public const string PARSER = SqliteParser::class;

    public const string GENERATOR = SqliteGenerator::class;

    public const string TYPE_TABLES = SqliteTypeTables::class;
}
