<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Sqlite;

use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\Attach;
use LaravelSqlParser\LaravelSqlParser\Expressions\Collate;
use LaravelSqlParser\LaravelSqlParser\Expressions\ComputedColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\CurrentTimestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\Detach;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DPipe;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\GeneratedAsIdentityColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONObjectAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\ModuleProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pragma;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeToStr;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToTimestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\UniqueColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\VirtualProperty;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * SQLite parser (sqlglot parsers/sqlite.py).
 */
class SqliteParser extends Parser
{
    public const string TABLES = SqliteParserTables::class;

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDatetime(array $args): Expression
    {
        return new Anonymous(['this' => 'DATETIME', 'expressions' => $args]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTime(array $args): Expression
    {
        return new Anonymous(['this' => 'TIME', 'expressions' => $args]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildJsonGroupObject(array $args): Expression
    {
        return new JSONObjectAgg(['expressions' => $args]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildStrftime(array &$args): Expression
    {
        if (count($args) === 1) {
            $args[] = new CurrentTimestamp;
        }

        if (count($args) === 2) {
            return new TimeToStr(['this' => new TsOrDsToTimestamp(['this' => $args[1]]), 'format' => $args[0]]);
        }

        return new Anonymous(['this' => 'STRFTIME', 'expressions' => $args]);
    }

    /**
     * Single-path json_extract() returns an SQL representation like ->>, except that object /
     * array results keep the JSON subtype; the multi-path form returns a JSON array.
     *
     * @param  list<mixed>  $args
     */
    protected function buildJsonExtractSqlite(array $args): Expression
    {
        if (count($args) === 2) {
            $extract = $this->buildExtractJsonWithPath($args, JSONExtractScalar::class);
            $extract->set('json_subtype', true);

            return $extract;
        }

        return $this->buildExtractJsonWithPath($args, JSONExtract::class);
    }

    // ---------------------------------------------------------------------------------------
    // Dispatch table handlers
    // ---------------------------------------------------------------------------------------

    protected function buildDpipe(?Expression $this_, ?Expression $expression): Expression
    {
        return $this->expression(new DPipe([
            'this' => $this_,
            'expression' => $expression,
            'safe' => $this->dialect->settings['STRICT_STRING_CONCAT'] !== true,
        ]));
    }

    protected function propertyVirtual(): Expression
    {
        return $this->expression(new VirtualProperty);
    }

    protected function statementDetach(): Expression
    {
        return $this->parseAttachDetach(isAttach: false);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    /**
     * ||, -> and ->> form a single left-associative precedence tier that binds tighter than
     * multiplication: https://sqlite.org/lang_expr.html
     */
    protected function parseFactorOperand(): ?Expression
    {
        $arithmetic = $this->t['ARITHMETIC_TOKENS'];
        $concatOperators = $this->t['CONCAT_OPERATORS'];

        $inArithmeticOperand = isset($arithmetic[$this->prev]);
        $this_ = $this->parseConcatOperand();
        $parsedOp = false;

        while ($this->curr !== 0 && isset($concatOperators[$this->curr])) {
            if ($this->curr !== TokenType::DPIPE && $this_ instanceof DPipe) {
                // Parenthesize so that dialects where the arrows bind tighter than || still
                // produce the same, left-associative grouping
                $this_ = $this->expression(new Paren(['this' => $this_]));
            }

            $build = $concatOperators[$this->curr];
            $this->advance();
            $this_ = $this->callHandler($build, $this_, $this->parseConcatOperand());
            $parsedOp = true;
        }

        if ($parsedOp && ($inArithmeticOperand || ($this->curr !== 0 && isset($arithmetic[$this->curr])))) {
            // This tier binds tighter than arithmetic in SQLite but looser elsewhere, so
            // parenthesize it to preserve the grouping in other dialects
            $this_ = $this->expression(new Paren(['this' => $this_]));
        }

        return $this_;
    }

    protected function parseConcatOperand(): ?Expression
    {
        $this_ = $this->parseUnary();

        // COLLATE binds tighter than any binary operator, including this tier
        while ($this->match(TokenType::COLLATE)) {
            $collate = $this->expression(new Collate(['this' => $this_, 'expression' => $this->parseUnary()]));
            $this->normalizeCollate($collate);
            $this_ = $collate;
        }

        return $this_;
    }

    protected function parseUnique(): UniqueColumnConstraint
    {
        // Do not consume more tokens if UNIQUE is used as a standalone constraint, e.g:
        // CREATE TABLE foo (bar TEXT UNIQUE REFERENCES baz ...)
        if ($this->curr !== 0 && isset($this->t['CONSTRAINT_PARSERS'][strtoupper($this->currText())])) {
            return $this->expression(new UniqueColumnConstraint);
        }

        return parent::parseUnique();
    }

    protected function parseModuleProperty(mixed ...$kwargs): ModuleProperty
    {
        $name = $this->parseIdVar();
        $expressions = $this->match(TokenType::L_PAREN, false)
            ? $this->parseWrappedCsv(fn (): ?Expression => $this->parseIdVar())
            : null;

        return $this->expression(new ModuleProperty(['this' => $name, 'expressions' => $expressions]));
    }

    protected function parsePragma(): Pragma
    {
        $name = $this->parseVar(anyToken: true);

        if ($this->match(TokenType::DOT)) {
            $name = new Dot(['this' => $name, 'expression' => $this->parseVar(anyToken: true)]);
        }

        $this->match(TokenType::EQ);

        $value = $this->parseWrapped(fn (): ?Expression => $this->parseUnary() ?? $this->parseVar(anyToken: true), optional: true);

        if ($value instanceof Expression) {
            return $this->expression(new Pragma(['this' => new EQ(['this' => $name, 'expression' => $value])]));
        }

        return $this->expression(new Pragma(['this' => $name]));
    }

    protected function parseAttachDetach(bool $isAttach = true): Expression
    {
        $this->match(TokenType::DATABASE);
        $this_ = $this->parseExpression();

        return $isAttach
            ? $this->expression(new Attach(['this' => $this_]))
            : $this->expression(new Detach(['this' => $this_]));
    }

    /**
     * Only expression-bearing GENERATED ALWAYS AS (expr) forms are computed columns
     * (https://www.sqlite.org/gencol.html), so STORED / VIRTUAL is only matched there and a true
     * identity (AS IDENTITY) doesn't consume a trailing storage keyword.
     */
    protected function parseGeneratedAsIdentity(): Expression
    {
        $this_ = parent::parseGeneratedAsIdentity();

        if ($this_ instanceof GeneratedAsIdentityColumnConstraint && ($this_->args['expression'] ?? null) !== null) {
            $persisted = $this->matchTexts(['STORED' => true, 'VIRTUAL' => true]) && strtoupper($this->prevText()) === 'STORED';

            return $this->expression(new ComputedColumnConstraint(['this' => $this_->args['expression'], 'persisted' => $persisted]));
        }

        return $this_;
    }
}
