<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Drill;

use LaravelSqlParser\LaravelSqlParser\Expressions\Escape;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\ILike;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;

/**
 * Apache Drill parser (sqlglot parsers/drill.py).
 */
class DrillParser extends Parser
{
    public const string TABLES = DrillParserTables::class;

    /**
     * @param  list<Expression>  $args
     */
    protected function buildIlike(array $args): Expression
    {
        $ilike = new ILike(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null]);
        $escape = $args[2] ?? null;

        return $escape !== null ? new Escape(['this' => $ilike, 'expression' => $escape]) : $ilike;
    }
}
