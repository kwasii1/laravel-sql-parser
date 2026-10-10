<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Presto;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

/**
 * The Presto dialect (sqlglot dialects/presto.py).
 */
class Presto extends Dialect
{
    public const string NAME = 'presto';

    public const string SETTINGS = PrestoDialectTables::class;

    public const string TOKENIZER_TABLES = PrestoTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = PrestoJsonPathTokenizerTables::class;

    public const string PARSER = PrestoParser::class;

    public const string GENERATOR = PrestoGenerator::class;

    public const string TYPE_TABLES = PrestoTypeTables::class;

    public const string TYPE_ANNOTATOR = PrestoTypeAnnotator::class;
}
