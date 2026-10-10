<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\RisingWave;

use Kwasii\LaravelSqlParser\Dialects\Postgres\PostgresGenerator;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Generator\Generator;

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
