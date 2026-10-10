<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Drill;

use Kwasii\LaravelSqlParser\Expressions\Escape;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\ILike;
use Kwasii\LaravelSqlParser\Parser\Parser;

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
