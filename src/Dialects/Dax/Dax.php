<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Dax;

use Kwasii\LaravelSqlParser\Dialects\Dialect;

/**
 * The DAX dialect (sqlglot dialects/dax.py).
 */
class Dax extends Dialect
{
    public const string NAME = 'dax';

    public const string SETTINGS = DaxDialectTables::class;

    public const string TOKENIZER_TABLES = DaxTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = DaxJsonPathTokenizerTables::class;

    public const string PARSER = DaxParser::class;

    public const string GENERATOR = DaxGenerator::class;

    public const string TYPE_TABLES = DaxTypeTables::class;
}
