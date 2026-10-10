<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\TSql;

use Kwasii\LaravelSqlParser\Dialects\Dialect;

/**
 * The T-SQL dialect (sqlglot dialects/tsql.py).
 */
class TSql extends Dialect
{
    public const string NAME = 'tsql';

    public const string SETTINGS = TSqlDialectTables::class;

    public const string TOKENIZER_TABLES = TSqlTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = TSqlJsonPathTokenizerTables::class;

    public const string PARSER = TSqlParser::class;

    public const string GENERATOR = TSqlGenerator::class;

    public const string TYPE_TABLES = TSqlTypeTables::class;
}
