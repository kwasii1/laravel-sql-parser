<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Solr;

use Kwasii\LaravelSqlParser\Dialects\Dialect;

/**
 * The Apache Solr dialect (sqlglot dialects/solr.py).
 */
class Solr extends Dialect
{
    public const string NAME = 'solr';

    public const string SETTINGS = SolrDialectTables::class;

    public const string TOKENIZER_TABLES = SolrTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = SolrJsonPathTokenizerTables::class;

    public const string PARSER = SolrParser::class;

    public const string GENERATOR = SolrGenerator::class;

    public const string TYPE_TABLES = SolrTypeTables::class;
}
