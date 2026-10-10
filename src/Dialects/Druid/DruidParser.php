<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Druid;

use Kwasii\LaravelSqlParser\Parser\Parser;

/**
 * Apache Druid parser (sqlglot parsers/druid.py).
 */
class DruidParser extends Parser
{
    public const string TABLES = DruidParserTables::class;
}
