<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\StarRocks;

use Kwasii\LaravelSqlParser\Dialects\MySql\MySql;

/**
 * The StarRocks dialect (sqlglot dialects/starrocks.py).
 */
class StarRocks extends MySql
{
    public const string NAME = 'starrocks';

    public const string SETTINGS = StarRocksDialectTables::class;

    public const string TOKENIZER_TABLES = StarRocksTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = StarRocksJsonPathTokenizerTables::class;

    public const string PARSER = StarRocksParser::class;

    public const string GENERATOR = StarRocksGenerator::class;

    public const string TYPE_TABLES = StarRocksTypeTables::class;
}
