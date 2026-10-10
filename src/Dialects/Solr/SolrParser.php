<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Solr;

use LaravelSqlParser\LaravelSqlParser\Parser\Parser;

/**
 * Apache Solr parser (sqlglot parsers/solr.py).
 */
class SolrParser extends Parser
{
    public const string TABLES = SolrParserTables::class;
}
