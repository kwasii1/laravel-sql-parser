<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Exasol;

use LaravelSqlParser\LaravelSqlParser\Expressions\All;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseNot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\CommentColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\ConvertTimezone;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpLike;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpReplace;
use LaravelSqlParser\LaravelSqlParser\Expressions\SHA2;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\UseExpr;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Exasol parser (sqlglot parsers/exasol.py).
 */
class ExasolParser extends Parser
{
    public const string TABLES = ExasolParserTables::class;

    /** sqlglot parsers/exasol.py DATE_UNITS. */
    public const array DATE_UNITS = ['DAY' => true, 'WEEK' => true, 'MONTH' => true, 'YEAR' => true, 'HOUR' => true, 'MINUTE' => true, 'SECOND' => true];

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS builders
    // ---------------------------------------------------------------------------------------

    /**
     * sqlglot _build_zeroifnull.
     *
     * @param  list<mixed>  $args
     */
    protected function buildZeroifnull(array $args): Expression
    {
        $cond = new Is(['this' => $args[0] ?? null, 'expression' => new NullExpr]);

        return new IfExpr(['this' => $cond, 'true' => Literal::number(0), 'false' => $args[0] ?? null]);
    }

    /**
     * sqlglot _build_nullifzero.
     *
     * @param  list<mixed>  $args
     */
    protected function buildNullifzero(array $args): Expression
    {
        $cond = new EQ(['this' => $args[0] ?? null, 'expression' => Literal::number(0)]);

        return new IfExpr(['this' => $cond, 'true' => new NullExpr, 'false' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildBitNot(array $args): Expression
    {
        return new BitwiseNot(['this' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildConvertTz(array $args): Expression
    {
        return new ConvertTimezone([
            'source_tz' => $args[1] ?? null,
            'target_tz' => $args[2] ?? null,
            'timestamp' => $args[0] ?? null,
            'options' => $args[3] ?? null,
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDateTrunc(array $args): Expression
    {
        return new TimestampTrunc(['this' => $args[1] ?? null, 'unit' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildEvery(array $args): Expression
    {
        return new All(['this' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildHashSha256(array $args): Expression
    {
        return new SHA2(['this' => $args[0] ?? null, 'length' => Literal::number(256)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildHashSha512(array $args): Expression
    {
        return new SHA2(['this' => $args[0] ?? null, 'length' => Literal::number(512)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildRegexpLike(array $args): Expression
    {
        return new RegexpLike([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'flag' => $args[2] ?? null,
            'full_match' => true,
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildRegexpReplace(array $args): Expression
    {
        return new RegexpReplace([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'replacement' => $args[2] ?? null,
            'position' => $args[3] ?? null,
            'occurrence' => $args[4] ?? null,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // Other parser table entries
    // ---------------------------------------------------------------------------------------

    protected function constraintComment(): Expression
    {
        return $this->expression(new CommentColumnConstraint([
            'this' => $this->match(TokenType::IS) ? $this->parseString() : null,
        ]));
    }

    protected function rangeRlike(?Expression $this_): ?Expression
    {
        return $this->expression(new RegexpLike(['this' => $this_, 'expression' => $this->parseBitwise(), 'full_match' => true]));
    }

    protected function parseJsonExtract(): JSONExtract
    {
        $args = $this->parseExpressions();
        $this->matchRParen();

        $expression = new JSONExtract(['expressions' => $args]);

        if ($this->matchTextSeq('EMITS')) {
            $expression->set('emits', $this->parseSchema());
        }

        return $expression;
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseStatement(): ?Expression
    {
        // https://docs.exasol.com/db/latest/sql/open_schema.htm
        if ($this->matchTextSeq('OPEN', 'SCHEMA')) {
            return $this->expression(new UseExpr(['this' => $this->parseTable(schema: false)]));
        }

        return parent::parseStatement();
    }

    protected function parseColumn(): ?Expression
    {
        $column = parent::parseColumn();

        if (! $column instanceof Column) {
            return $column;
        }

        $tableIdent = $column->args['table'] ?? null;

        if ($tableIdent instanceof Identifier && strtoupper($tableIdent->name()) === 'LOCAL' && ! $tableIdent->flag('quoted')) {
            $column->set('table', null);
        }

        return $column;
    }
}
