<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Databricks;

use LaravelSqlParser\LaravelSqlParser\Dialects\Spark\SparkParser;
use LaravelSqlParser\LaravelSqlParser\Expressions\ClusterProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\CurrentDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegrAvgx;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegrAvgy;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegrSxx;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegrSxy;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegrSyy;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeseriesKey;
use LaravelSqlParser\LaravelSqlParser\Expressions\Uniform;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Databricks parser (sqlglot parsers/databricks.py).
 */
class DatabricksParser extends SparkParser
{
    public const string TABLES = DatabricksParserTables::class;

    /** @param list<mixed> $args */
    protected function buildUniform(array $args): Expression
    {
        return new Uniform(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'seed' => $args[2] ?? null]);
    }

    protected function funcRegrAvgx(): Expression
    {
        return $this->parseDistinctArgFunction(RegrAvgx::class, distinctIndex: 1);
    }

    protected function funcRegrAvgy(): Expression
    {
        return $this->parseDistinctArgFunction(RegrAvgy::class);
    }

    protected function funcRegrSxx(): Expression
    {
        return $this->parseDistinctArgFunction(RegrSxx::class, distinctIndex: 1);
    }

    protected function funcRegrSxy(): Expression
    {
        return $this->parseDistinctArgFunction(RegrSxy::class);
    }

    protected function funcRegrSyy(): Expression
    {
        return $this->parseDistinctArgFunction(RegrSyy::class, distinctIndex: 1);
    }

    protected function columnOpQdcolon(?Expression $this_, ?Expression $to): Expression
    {
        return $this->buildCast(false, ['this' => $this_, 'to' => $to]);
    }

    protected function parseCurdate(): Expression
    {
        // CURDATE, an alias for CURRENT_DATE, has optional parentheses
        if ($this->match(TokenType::L_PAREN)) {
            $this->matchRParen();
        }

        return $this->expression(new CurrentDate);
    }

    protected function parsePrimaryKeyPart(): ?Expression
    {
        $this_ = parent::parsePrimaryKeyPart();

        if ($this_ !== null && $this->matchTextSeq('TIMESERIES')) {
            return $this->expression(new TimeseriesKey(['this' => $this_]));
        }

        return $this_;
    }

    protected function parseClusterProperty(): ClusterProperty
    {
        if ($this->matchTexts(['AUTO' => true, 'NONE' => true])) {
            return $this->expression(new ClusterProperty(['this' => strtoupper($this->prevText())]));
        }

        return parent::parseClusterProperty();
    }
}
