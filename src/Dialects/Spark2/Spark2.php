<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Spark2;

use Kwasii\LaravelSqlParser\Dialects\Hive\Hive;

/**
 * The Spark 2 dialect (sqlglot dialects/spark2.py).
 */
class Spark2 extends Hive
{
    public const string NAME = 'spark2';

    public const string SETTINGS = Spark2DialectTables::class;

    public const string TOKENIZER_TABLES = Spark2TokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = Spark2JsonPathTokenizerTables::class;

    public const string PARSER = Spark2Parser::class;

    public const string GENERATOR = Spark2Generator::class;

    public const string TYPE_TABLES = Spark2TypeTables::class;

    public const string TYPE_ANNOTATOR = Spark2TypeAnnotator::class;
}
