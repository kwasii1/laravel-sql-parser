<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\MySql;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

/**
 * The MySQL dialect (sqlglot dialects/mysql.py).
 */
class MySql extends Dialect
{
    public const string NAME = 'mysql';

    public const string SETTINGS = MySqlDialectTables::class;

    public const string TOKENIZER_TABLES = MySqlTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = MySqlJsonPathTokenizerTables::class;

    public const string PARSER = MySqlParser::class;

    public const string GENERATOR = MySqlGenerator::class;
}
