<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Materialize;

use LaravelSqlParser\LaravelSqlParser\Dialects\Postgres\PostgresGenerator;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;

/**
 * Materialize generator (sqlglot generators/materialize.py).
 */
class MaterializeGenerator extends PostgresGenerator
{
    public const string TABLES = MaterializeGeneratorTables::class;

    protected function transformAutoIncrementColumnConstraint(Expression $e): string
    {
        return '';
    }

    protected function transformGeneratedAsIdentityColumnConstraint(Expression $e): string
    {
        return '';
    }

    protected function transformOnConflict(Expression $e): string
    {
        return '';
    }

    protected function transformPrimaryKeyColumnConstraint(Expression $e): string
    {
        return '';
    }

    protected function propertyeqSql(Expression $expression): string
    {
        return $this->binary($expression, '=>');
    }

    protected function datatypeSql(DataType $expression): string
    {
        $expressions = $expression->argList('expressions');

        if ($expression->isType(DType::LIST)) {
            return $expressions !== [] ? $this->expressions($expression, flat: true).' LIST' : 'LIST';
        }

        if ($expression->isType(DType::MAP) && count($expressions) === 2) {
            return 'MAP['.$this->sql($expressions[0]).' => '.$this->sql($expressions[1]).']';
        }

        return parent::datatypeSql($expression);
    }

    protected function listSql(Expression $expression): string
    {
        $first = $expression->argList('expressions')[0] ?? null;

        if ($first instanceof Select) {
            return $this->func('LIST', $first);
        }

        return $this->normalizeFunc('LIST').'['.$this->expressions($expression, flat: true).']';
    }

    protected function tomapSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ instanceof Select) {
            return $this->func('MAP', $this_);
        }

        return $this->normalizeFunc('MAP').'['.$this->expressions($this_).']';
    }
}
