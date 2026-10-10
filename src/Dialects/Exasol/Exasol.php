<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Exasol;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

/**
 * The Exasol dialect (sqlglot dialects/exasol.py).
 */
class Exasol extends Dialect
{
    public const string NAME = 'exasol';

    public const string SETTINGS = ExasolDialectTables::class;

    public const string TOKENIZER_TABLES = ExasolTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = ExasolJsonPathTokenizerTables::class;

    public const string PARSER = ExasolParser::class;

    public const string GENERATOR = ExasolGenerator::class;

    public const string TYPE_TABLES = ExasolTypeTables::class;
}
