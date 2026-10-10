<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Trino;

use LaravelSqlParser\LaravelSqlParser\Dialects\Presto\Presto;
use LaravelSqlParser\LaravelSqlParser\Dialects\Presto\PrestoTypeAnnotator;

/**
 * The Trino dialect (sqlglot dialects/trino.py).
 */
class Trino extends Presto
{
    public const string NAME = 'trino';

    public const string SETTINGS = TrinoDialectTables::class;

    public const string TOKENIZER_TABLES = TrinoTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = TrinoJsonPathTokenizerTables::class;

    public const string PARSER = TrinoParser::class;

    public const string GENERATOR = TrinoGenerator::class;

    public const string TYPE_TABLES = TrinoTypeTables::class;

    public const string TYPE_ANNOTATOR = PrestoTypeAnnotator::class;
}
