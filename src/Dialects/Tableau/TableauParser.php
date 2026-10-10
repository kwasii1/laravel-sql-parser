<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Tableau;

use LaravelSqlParser\LaravelSqlParser\Expressions\Count;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrPosition;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;

/**
 * Tableau parser (sqlglot parsers/tableau.py).
 */
class TableauParser extends Parser
{
    public const string TABLES = TableauParserTables::class;

    /**
     * @param  list<Expression>  $args
     */
    protected function buildCountd(array $args): Expression
    {
        return new Count(['this' => new Distinct(['expressions' => $args])]);
    }

    /**
     * @param  list<Expression>  $args
     */
    protected function buildFindnth(array $args): Expression
    {
        return new StrPosition(['this' => $args[0] ?? null, 'substr' => $args[1] ?? null, 'occurrence' => $args[2] ?? null]);
    }
}
