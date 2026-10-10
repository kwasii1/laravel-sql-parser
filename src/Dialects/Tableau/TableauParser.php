<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Tableau;

use Kwasii\LaravelSqlParser\Expressions\Count;
use Kwasii\LaravelSqlParser\Expressions\Distinct;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\StrPosition;
use Kwasii\LaravelSqlParser\Parser\Parser;

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
