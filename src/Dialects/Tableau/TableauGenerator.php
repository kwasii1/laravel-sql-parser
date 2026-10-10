<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Tableau;

use Kwasii\LaravelSqlParser\Expressions\Distinct;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * Tableau generator (sqlglot generators/tableau.py).
 */
class TableauGenerator extends Generator
{
    public const string TABLES = TableauGeneratorTables::class;

    protected function ifSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $true = $this->sql($expression, 'true');
        $false = $this->sql($expression, 'false');

        return "IF {$this_} THEN {$true} ELSE {$false} END";
    }

    protected function countSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Distinct) {
            return $this->funcWith('COUNTD', $this_->expressions());
        }

        return $this->func('COUNT', $this_);
    }

    protected function strpositionSql(Expression $expression): string
    {
        $hasOccurrence = array_key_exists('occurrence', $expression->args);

        return $this->strpositionSqlHandler(
            $expression,
            funcName: $hasOccurrence ? 'FINDNTH' : 'FIND',
            supportsOccurrence: $hasOccurrence,
        );
    }
}
