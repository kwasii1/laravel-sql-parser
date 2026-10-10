<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Postgres;

use Kwasii\LaravelSqlParser\Dialects\Dialect;

/**
 * The PostgreSQL dialect (sqlglot dialects/postgres.py).
 */
class Postgres extends Dialect
{
    public const string NAME = 'postgres';

    public const string SETTINGS = PostgresDialectTables::class;

    public const string TOKENIZER_TABLES = PostgresTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = PostgresJsonPathTokenizerTables::class;

    public const string PARSER = PostgresParser::class;

    public const string GENERATOR = PostgresGenerator::class;

    public const string TYPE_TABLES = PostgresTypeTables::class;
}
