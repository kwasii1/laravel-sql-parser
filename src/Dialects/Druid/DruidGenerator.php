<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Druid;

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;

/**
 * Apache Druid generator (sqlglot generators/druid.py).
 */
class DruidGenerator extends Generator
{
    public const string TABLES = DruidGeneratorTables::class;

    protected function transformCurrentTimestamp(Expression $e): string
    {
        return 'CURRENT_TIMESTAMP';
    }

    protected function transformArray(Expression $e): string
    {
        return 'ARRAY['.$this->expressions($e).']';
    }
}
