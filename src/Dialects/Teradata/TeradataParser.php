<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Teradata;

use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\FormatPhrase;
use Kwasii\LaravelSqlParser\Expressions\IndexParameters;
use Kwasii\LaravelSqlParser\Expressions\LockingStatement;
use Kwasii\LaravelSqlParser\Expressions\QueryBand;
use Kwasii\LaravelSqlParser\Expressions\Rand;
use Kwasii\LaravelSqlParser\Expressions\RangeN;
use Kwasii\LaravelSqlParser\Expressions\TranslateCharacters;
use Kwasii\LaravelSqlParser\Expressions\Update;
use Kwasii\LaravelSqlParser\Expressions\UseExpr;
use Kwasii\LaravelSqlParser\Expressions\VarExpr;
use Kwasii\LaravelSqlParser\Parser\Parser;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Teradata parser (sqlglot parsers/teradata.py).
 */
class TeradataParser extends Parser
{
    public const string TABLES = TeradataParserTables::class;

    /**
     * @param  list<mixed>  $args
     */
    protected function buildRandom(array $args): Expression
    {
        return new Rand(['lower' => $args[0] ?? null, 'upper' => $args[1] ?? null]);
    }

    protected function statementDatabase(): Expression
    {
        return $this->expression(new UseExpr(['this' => $this->parseTable(schema: false)]));
    }

    protected function parseLockingStatement(): LockingStatement
    {
        // Reuse exp.LockingProperty parsing for the lock kind, type etc
        $lockingProperty = $this->parseLocking();
        $wrappedQuery = $this->parseSelect();

        if ($wrappedQuery === null) {
            $this->raiseError('Expected SELECT statement after LOCKING clause');
        }

        return $this->expression(new LockingStatement(['this' => $lockingProperty, 'expression' => $wrappedQuery]));
    }

    protected function parseTranslate(): Expression
    {
        $this_ = $this->parseAssignment();
        $this->match(TokenType::USING);
        $this->matchTexts($this->t['CHARSET_TRANSLATORS']);

        return $this->expression(new TranslateCharacters([
            'this' => $this_,
            'expression' => strtoupper($this->prevText()),
            'with_error' => $this->matchTextSeq('WITH', 'ERROR'),
        ]));
    }

    // FROM before SET in Teradata UPDATE syntax
    protected function parseUpdate(): Update
    {
        $this_ = $this->parseTable(aliasTokens: $this->t['UPDATE_ALIAS_TOKENS']);
        $from = $this->parseFrom(joins: true);
        $expressions = $this->match(TokenType::SET)
            ? $this->parseCsv(fn (): ?Expression => $this->parseUpdateAssignment())
            : null;

        return $this->expression(new Update([
            'this' => $this_,
            'from_' => $from,
            'expressions' => $expressions,
            'where' => $this->parseWhere(),
        ]));
    }

    protected function parseRangen(): Expression
    {
        $this_ = $this->parseIdVar();
        $this->match(TokenType::BETWEEN);

        $expressions = $this->parseCsv(fn (): ?Expression => $this->parseAssignment());
        $each = $this->matchTextSeq('EACH') ? $this->parseAssignment() : null;

        return $this->expression(new RangeN(['this' => $this_, 'expressions' => $expressions, 'each' => $each]));
    }

    protected function parseQueryBand(): Expression
    {
        $this->match(TokenType::EQ);

        // Handle both string literals and NONE keyword
        if ($this->matchTextSeq('NONE')) {
            $queryBandString = new VarExpr(['this' => 'NONE']);
        } else {
            $queryBandString = $this->parseString();
        }

        $update = $this->matchTextSeq('UPDATE');
        $this->matchTextSeq('FOR');

        if ($this->matchTextSeq('SESSION', 'VOLATILE')) {
            $scope = 'SESSION VOLATILE';
        } elseif ($this->matchTexts(['SESSION' => true, 'TRANSACTION' => true])) {
            $scope = strtoupper($this->prevText());
        } else {
            $scope = null;
        }

        return $this->expression(new QueryBand(['this' => $queryBandString, 'scope' => $scope, 'update' => $update]));
    }

    protected function parseIndexParams(): IndexParameters
    {
        $this_ = parent::parseIndexParams();

        if ($this_->flag('on')) {
            $this_->set('on', null);
            $this->retreat($this->index - 2);
        }

        return $this_;
    }

    /**
     * @param  array<string, mixed>|null  $functions
     */
    protected function parseFunction(?array $functions = null, bool $anonymous = false, bool $optionalParens = true, bool $anyToken = false): ?Expression
    {
        // Teradata's `(FORMAT <format_string>)` clause after a column reference is not a call
        if (
            $this->next === TokenType::L_PAREN
            && $this->index + 2 < $this->chunkEnd
            && $this->types[$this->index + 2] === TokenType::FORMAT
        ) {
            return null;
        }

        return parent::parseFunction($functions, $anonymous, $optionalParens, $anyToken);
    }

    protected function parseColumnOps(?Expression $this_): ?Expression
    {
        $this_ = parent::parseColumnOps($this_);

        if ($this->matchPair(TokenType::L_PAREN, TokenType::FORMAT)) {
            $fmtString = $this->parseString();
            $this->matchRParen();

            $this_ = $this->expression(new FormatPhrase(['this' => $this_, 'format' => $fmtString]));
        }

        return $this_;
    }
}
