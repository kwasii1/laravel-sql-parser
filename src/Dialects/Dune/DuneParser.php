<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Dune;

use Kwasii\LaravelSqlParser\Dialects\Trino\TrinoParser;

/**
 * Dune parser (sqlglot parsers/dune.py).
 */
class DuneParser extends TrinoParser
{
    public const string TABLES = DuneParserTables::class;
}
