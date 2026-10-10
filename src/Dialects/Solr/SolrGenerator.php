<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Solr;

use LaravelSqlParser\LaravelSqlParser\Generator\Generator;

/**
 * Apache Solr generator (sqlglot generators/solr.py).
 */
class SolrGenerator extends Generator
{
    public const string TABLES = SolrGeneratorTables::class;
}
