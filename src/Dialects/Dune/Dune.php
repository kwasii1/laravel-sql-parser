<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Dune;

use LaravelSqlParser\LaravelSqlParser\Dialects\Trino\Trino;

/**
 * The Dune dialect (sqlglot dialects/dune.py).
 */
class Dune extends Trino
{
    public const string NAME = 'dune';

    public const string SETTINGS = DuneDialectTables::class;

    public const string TOKENIZER_TABLES = DuneTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = DuneJsonPathTokenizerTables::class;

    public const string PARSER = DuneParser::class;

    public const string GENERATOR = DuneGenerator::class;

    public const string TYPE_TABLES = DuneTypeTables::class;
}
