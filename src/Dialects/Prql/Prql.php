<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Prql;

use Kwasii\LaravelSqlParser\Dialects\Dialect;

/**
 * The PRQL dialect (sqlglot dialects/prql.py).
 */
class Prql extends Dialect
{
    public const string NAME = 'prql';

    public const string SETTINGS = PrqlDialectTables::class;

    public const string TOKENIZER_TABLES = PrqlTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = PrqlJsonPathTokenizerTables::class;

    public const string PARSER = PrqlParser::class;

    public const string GENERATOR = PrqlGenerator::class;

    public const string TYPE_TABLES = PrqlTypeTables::class;
}
