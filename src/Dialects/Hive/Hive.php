<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Hive;

use Kwasii\LaravelSqlParser\Dialects\Dialect;

/**
 * The Apache Hive dialect (sqlglot dialects/hive.py).
 */
class Hive extends Dialect
{
    public const string NAME = 'hive';

    public const string SETTINGS = HiveDialectTables::class;

    public const string TOKENIZER_TABLES = HiveTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = HiveJsonPathTokenizerTables::class;

    public const string PARSER = HiveParser::class;

    public const string GENERATOR = HiveGenerator::class;

    public const string TYPE_TABLES = HiveTypeTables::class;
}
