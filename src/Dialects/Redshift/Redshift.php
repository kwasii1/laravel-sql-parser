<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Redshift;

use Kwasii\LaravelSqlParser\Dialects\Postgres\Postgres;

/**
 * The Amazon Redshift dialect (sqlglot dialects/redshift.py).
 */
class Redshift extends Postgres
{
    public const string NAME = 'redshift';

    public const string SETTINGS = RedshiftDialectTables::class;

    public const string TOKENIZER_TABLES = RedshiftTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = RedshiftJsonPathTokenizerTables::class;

    public const string PARSER = RedshiftParser::class;

    public const string GENERATOR = RedshiftGenerator::class;

    public const string TYPE_TABLES = RedshiftTypeTables::class;
}
