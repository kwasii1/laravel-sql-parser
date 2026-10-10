<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Solr;

use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * Apache Solr generator (sqlglot generators/solr.py).
 */
class SolrGenerator extends Generator
{
    public const string TABLES = SolrGeneratorTables::class;
}
