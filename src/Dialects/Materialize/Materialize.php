<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Materialize;

use Kwasii\LaravelSqlParser\Dialects\Postgres\Postgres;

/**
 * The Materialize dialect (sqlglot dialects/materialize.py).
 */
class Materialize extends Postgres
{
    public const string NAME = 'materialize';

    public const string SETTINGS = MaterializeDialectTables::class;

    public const string TOKENIZER_TABLES = MaterializeTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = MaterializeJsonPathTokenizerTables::class;

    public const string PARSER = MaterializeParser::class;

    public const string GENERATOR = MaterializeGenerator::class;

    public const string TYPE_TABLES = MaterializeTypeTables::class;
}
