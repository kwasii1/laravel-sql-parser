<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Druid;

use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Generator\Generator;

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
