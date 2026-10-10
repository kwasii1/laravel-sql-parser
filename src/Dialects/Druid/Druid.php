<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Druid;

use Kwasii\LaravelSqlParser\Dialects\Dialect;

/**
 * The Apache Druid dialect (sqlglot dialects/druid.py).
 */
class Druid extends Dialect
{
    public const string NAME = 'druid';

    public const string SETTINGS = DruidDialectTables::class;

    public const string TOKENIZER_TABLES = DruidTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = DruidJsonPathTokenizerTables::class;

    public const string PARSER = DruidParser::class;

    public const string GENERATOR = DruidGenerator::class;

    public const string TYPE_TABLES = DruidTypeTables::class;
}
