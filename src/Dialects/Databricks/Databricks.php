<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Databricks;

use Kwasii\LaravelSqlParser\Dialects\Spark\Spark;

/**
 * The Databricks dialect (sqlglot dialects/databricks.py).
 */
class Databricks extends Spark
{
    public const string NAME = 'databricks';

    public const string SETTINGS = DatabricksDialectTables::class;

    public const string TOKENIZER_TABLES = DatabricksTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = DatabricksJsonPathTokenizerTables::class;

    public const string PARSER = DatabricksParser::class;

    public const string GENERATOR = DatabricksGenerator::class;

    public const string TYPE_TABLES = DatabricksTypeTables::class;
}
