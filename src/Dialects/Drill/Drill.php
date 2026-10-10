<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Drill;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

/**
 * The Apache Drill dialect (sqlglot dialects/drill.py).
 */
class Drill extends Dialect
{
    public const string NAME = 'drill';

    public const string SETTINGS = DrillDialectTables::class;

    public const string TOKENIZER_TABLES = DrillTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = DrillJsonPathTokenizerTables::class;

    public const string PARSER = DrillParser::class;

    public const string GENERATOR = DrillGenerator::class;

    public const string TYPE_TABLES = DrillTypeTables::class;
}
