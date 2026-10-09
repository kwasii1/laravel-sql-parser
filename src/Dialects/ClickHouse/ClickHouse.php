<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\ClickHouse;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

/**
 * The ClickHouse dialect (sqlglot dialects/clickhouse.py).
 */
class ClickHouse extends Dialect
{
    public const string NAME = 'clickhouse';

    public const string SETTINGS = ClickHouseDialectTables::class;

    public const string TOKENIZER_TABLES = ClickHouseTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = ClickHouseJsonPathTokenizerTables::class;

    public const string PARSER = ClickHouseParser::class;

    public const string GENERATOR = ClickHouseGenerator::class;

    public const string TYPE_TABLES = ClickHouseTypeTables::class;
}
