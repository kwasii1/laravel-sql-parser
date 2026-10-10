<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\BigQuery;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Dot;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\UserDefinedFunction;

/**
 * The Google BigQuery dialect (sqlglot dialects/bigquery.py).
 */
class BigQuery extends Dialect
{
    public const string NAME = 'bigquery';

    public const string SETTINGS = BigQueryDialectTables::class;

    public const string TOKENIZER_TABLES = BigQueryTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = BigQueryJsonPathTokenizerTables::class;

    public const string PARSER = BigQueryParser::class;

    public const string GENERATOR = BigQueryGenerator::class;

    public const string TYPE_TABLES = BigQueryTypeTables::class;

    public const string TYPE_ANNOTATOR = BigQueryTypeAnnotator::class;

    /**
     * CTEs are case-insensitive in BigQuery, but UDF and table names are case-sensitive by default.
     * Tables are told apart heuristically by being qualified: they must be qualified with at
     * least a dataset, unless @@dataset_id is set.
     *
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public function normalizeIdentifier(Expression $expression): Expression
    {
        if ($expression instanceof Identifier && $this->normalizationStrategy() === 'CASE_INSENSITIVE') {
            $parent = $expression->parent;

            while ($parent instanceof Dot) {
                $parent = $parent->parent;
            }

            $caseSensitive = $parent instanceof UserDefinedFunction
                || (
                    $parent instanceof Table
                    && $parent->text('db') !== ''
                    && ($parent->metaGet('quoted_table') === true || $parent->metaGet('maybe_column') !== true)
                )
                || $expression->metaGet('is_table') === true;

            if (! $caseSensitive) {
                $expression->set('this', strtolower((string) ($expression->args['this'] ?? '')));
            }

            return $expression;
        }

        return parent::normalizeIdentifier($expression);
    }
}
