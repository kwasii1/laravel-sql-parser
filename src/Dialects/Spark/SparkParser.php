<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Spark;

use Kwasii\LaravelSqlParser\Dialects\Spark2\Spark2Parser;
use Kwasii\LaravelSqlParser\Expressions\ArrayInsert;
use Kwasii\LaravelSqlParser\Expressions\Bracket;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\ComputedColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\DateDiff;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Placeholder;
use Kwasii\LaravelSqlParser\Expressions\TimestampAdd;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsAdd;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsToDate;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Spark 3 parser (sqlglot parsers/spark.py).
 */
class SparkParser extends Spark2Parser
{
    public const string TABLES = SparkParserTables::class;

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS builders
    // ---------------------------------------------------------------------------------------

    /**
     * sqlglot _build_datediff: DATEDIFF(end, start) or DATEDIFF(unit, start, end).
     *
     * @param  list<mixed>  $args
     */
    protected function buildDatediff(array $args): Expression
    {
        $unit = null;
        $this_ = $args[0] ?? null;
        $expression = $args[1] ?? null;

        if (count($args) === 3) {
            $unit = Builder::var($this_ instanceof Expression ? $this_->name() : '');
            $this_ = $args[2];
        }

        return new DateDiff([
            'this' => new TsOrDsToDate(['this' => $this_]),
            'expression' => new TsOrDsToDate(['this' => $expression]),
            'unit' => $unit,
        ]);
    }

    /**
     * sqlglot _build_dateadd (DATE_ADD, DATEADD, TIMESTAMPADD); DATE_SUB keeps Hive's builder.
     *
     * @param  list<mixed>  $args
     */
    protected function buildDateAdd(array $args): Expression
    {
        if ($this->functionBuilderName === 'DATE_SUB') {
            return parent::buildDateAdd($args);
        }

        $expression = $args[1] ?? null;

        if (count($args) === 2) {
            // DATE_ADD(startDate, numDays INTEGER)
            return new TsOrDsAdd(['this' => $args[0] ?? null, 'expression' => $expression, 'unit' => Literal::string('DAY')]);
        }

        // DATE_ADD / DATEADD / TIMESTAMPADD(unit, value integer, expr)
        return new TimestampAdd(['this' => $args[2] ?? null, 'expression' => $expression, 'unit' => $args[0] ?? null]);
    }

    /** @param list<mixed> $args */
    protected function buildArrayInsert(array $args): Expression
    {
        return new ArrayInsert([
            'this' => $args[0] ?? null,
            'position' => $args[1] ?? null,
            'expression' => $args[2] ?? null,
            'offset' => 1,
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildTryElementAt(array $args): Expression
    {
        $index = $args[1] ?? null;

        return new Bracket([
            'this' => $args[0] ?? null,
            'expressions' => $index === null ? [] : [$index],
            'offset' => 1,
            'safe' => true,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // SET_PARSERS / PLACEHOLDER_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function setVar(): ?Expression
    {
        return $this->parseSetItemAssignment('VARIABLE');
    }

    protected function setVariable(): ?Expression
    {
        return $this->parseSetItemAssignment('VARIABLE');
    }

    protected function parseQueryParameter(): ?Expression
    {
        $this_ = $this->parseIdVar();
        $this->match(TokenType::R_BRACE);

        return $this->expression(new Placeholder(['this' => $this_, 'widget' => true]));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseGeneratedAsIdentity(): Expression
    {
        $this_ = parent::parseGeneratedAsIdentity();
        $expression = $this_->argExpr('expression');

        if ($expression !== null) {
            return $this->expression(new ComputedColumnConstraint(['this' => $expression]));
        }

        return $this_;
    }

    protected function parsePivotAggregation(): ?Expression
    {
        // Spark 3+ and Databricks support non aggregate functions in PIVOT too
        $aggregateExpr = $this->parseFunction() ?? $this->parseDisjunction();

        return $this->parseAlias($aggregateExpr);
    }
}
