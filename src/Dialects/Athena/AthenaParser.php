<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Athena;

use LaravelSqlParser\LaravelSqlParser\Dialects\Trino\TrinoParser;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * Athena parser for the Trino engine (sqlglot parsers/athena.py AthenaTrinoParser); statements
 * routed to the Hive engine are parsed by the Hive dialect (see Athena::parse()).
 */
class AthenaParser extends TrinoParser
{
    public const string TABLES = AthenaParserTables::class;

    protected function statementUsing(): Expression
    {
        return $this->parseAsCommand($this->index - 1);
    }
}
