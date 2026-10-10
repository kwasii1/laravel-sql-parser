<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\RisingWave;

use LaravelSqlParser\LaravelSqlParser\Dialects\Postgres\PostgresGenerator;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;

/**
 * RisingWave generator (sqlglot generators/risingwave.py).
 */
class RisingWaveGenerator extends PostgresGenerator
{
    public const string TABLES = RisingWaveGeneratorTables::class;

    protected function transformFileFormatProperty(Expression $e): string
    {
        return 'FORMAT '.$this->sql($e, 'this');
    }

    protected function computedcolumnconstraintSql(Expression $expression): string
    {
        return Generator::computedcolumnconstraintSql($expression);
    }

    protected function datatypeSql(DataType $expression): string
    {
        $expressions = $expression->argList('expressions');

        if ($expression->isType(DType::MAP) && count($expressions) === 2) {
            return 'MAP('.$this->sql($expressions[0]).', '.$this->sql($expressions[1]).')';
        }

        return parent::datatypeSql($expression);
    }
}
