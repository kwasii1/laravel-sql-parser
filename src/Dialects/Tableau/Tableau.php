<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Tableau;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

/**
 * The Tableau dialect (sqlglot dialects/tableau.py).
 */
class Tableau extends Dialect
{
    public const string NAME = 'tableau';

    public const string SETTINGS = TableauDialectTables::class;

    public const string TOKENIZER_TABLES = TableauTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = TableauJsonPathTokenizerTables::class;

    public const string PARSER = TableauParser::class;

    public const string GENERATOR = TableauGenerator::class;

    public const string TYPE_TABLES = TableauTypeTables::class;
}
