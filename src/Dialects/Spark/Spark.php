<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Spark;

use LaravelSqlParser\LaravelSqlParser\Dialects\Spark2\Spark2;

/**
 * The Apache Spark dialect (sqlglot dialects/spark.py).
 */
class Spark extends Spark2
{
    public const string NAME = 'spark';

    public const string SETTINGS = SparkDialectTables::class;

    public const string TOKENIZER_TABLES = SparkTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = SparkJsonPathTokenizerTables::class;

    public const string PARSER = SparkParser::class;

    public const string GENERATOR = SparkGenerator::class;

    public const string TYPE_TABLES = SparkTypeTables::class;
}
