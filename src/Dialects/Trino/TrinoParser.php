<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Trino;

use LaravelSqlParser\LaravelSqlParser\Dialects\Presto\PrestoParser;
use LaravelSqlParser\LaravelSqlParser\Expressions\Block;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseStatement;
use LaravelSqlParser\LaravelSqlParser\Expressions\EndStatement;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\FunctionSpecification;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfBlock;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Iterate;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractQuote;
use LaravelSqlParser\LaravelSqlParser\Expressions\Leave;
use LaravelSqlParser\LaravelSqlParser\Expressions\LoopBlock;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\RepeatBlock;
use LaravelSqlParser\LaravelSqlParser\Expressions\ReturnExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\WhileBlock;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Trino parser (sqlglot parsers/trino.py).
 */
class TrinoParser extends PrestoParser
{
    public const string TABLES = TrinoParserTables::class;

    protected function parseJsonQueryQuote(): ?JSONExtractQuote
    {
        if (! ($this->matchTextSeq('KEEP', 'QUOTES') || $this->matchTextSeq('OMIT', 'QUOTES'))) {
            return null;
        }

        $option = strtoupper($this->texts[$this->index - 2]);

        /** @var JSONExtractQuote */
        return $this->expression(new JSONExtractQuote([
            'option' => $option,
            'scalar' => $this->matchTextSeq('ON', 'SCALAR', 'STRING') ? true : null,
        ]));
    }

    protected function parseJsonQuery(): Expression
    {
        $this_ = $this->parseBitwise();
        $expression = $this->match(TokenType::COMMA) ? $this->parseBitwise() : null;

        return $this->expression(new JSONExtract([
            'this' => $this_,
            'expression' => $expression,
            'option' => $this->parseVarFromOptions($this->t['JSON_QUERY_OPTIONS'], raiseUnmatched: false),
            'json_query' => true,
            'quote' => $this->parseJsonQueryQuote(),
            'on_condition' => $this->parseOnCondition(),
        ]));
    }

    protected function parseCte(): ?Expression
    {
        // A `WITH` clause entry that starts with `FUNCTION <name>` is an inline SQL UDF
        // specification, as opposed to a CTE that happens to be named "function"
        if ($this->curr === TokenType::FUNCTION && $this->next !== 0 && isset($this->idVarTokens[$this->next])) {
            $this->advance();

            return $this->parseFunctionSpecification();
        }

        return parent::parseCte();
    }

    protected function parseFunctionSpecification(): FunctionSpecification
    {
        $this_ = $this->parseUserDefinedFunction(kind: TokenType::FUNCTION);

        // Collected separately so the generator can place `WITH (...)` in its own bracketed
        // clause at the end, instead of it blending into the bare characteristics list.
        $characteristics = [];
        $properties = [];

        while (true) {
            if ($this->match(TokenType::WITH)) {
                array_push($properties, ...$this->parseWrappedCsv(fn (): ?Expression => $this->parseKeyValueProperty()));

                continue;
            }

            $characteristic = $this->parseProperty();

            if ($characteristic === null || $characteristic === []) {
                break;
            }

            array_push($characteristics, ...(is_array($characteristic) ? $characteristic : [$characteristic]));
        }

        /** @var FunctionSpecification */
        return $this->expression(new FunctionSpecification([
            'this' => $this_,
            'characteristics' => $characteristics !== [] ? $this->expression(new Properties(['expressions' => $characteristics])) : null,
            'properties' => $properties !== [] ? $this->expression(new Properties(['expressions' => $properties])) : null,
            'expression' => $this->parseRoutineStatement(),
        ]));
    }

    /**
     * Statements up to (and including) any of the terminators, matched as text.
     *
     * @return list<Expression>
     */
    protected function parseRoutineStatements(string ...$terminators): array
    {
        $set = array_fill_keys($terminators, true);
        $statements = [];

        while (! $this->matchTexts($set)) {
            if ($this->curr === 0) {
                if ($this->chunkIndex >= count($this->chunks)) {
                    $this->raiseError('Unexpected end of routine body');

                    break;
                }

                $this->advanceChunk();
            } elseif (! $this->match(TokenType::SEMICOLON)) {
                $statement = $this->parseRoutineStatement();

                if ($statement === null) {
                    break;
                }

                $statements[] = $statement;
            }
        }

        return $statements;
    }

    protected function parseRoutineBlock(): Block
    {
        $this->match(TokenType::BEGIN);
        $statements = $this->parseRoutineStatements('END');
        $statements[] = new EndStatement;

        /** @var Block */
        return $this->expression(new Block(['expressions' => $statements, 'begin' => true]));
    }

    protected function parseRoutineIfBranch(): IfBlock
    {
        $condition = $this->parseDisjunction();
        $this->matchTextSeq('THEN');
        $true = $this->expression(new Block(['expressions' => $this->parseRoutineStatements('ELSEIF', 'ELSE', 'END')]));

        /** @var IfBlock */
        return $this->expression(new IfBlock(['this' => $condition, 'true' => $true]));
    }

    protected function parseRoutineIf(): IfBlock
    {
        // ELSEIF chains nest into `false` rather than a flat list
        $this_ = $tail = $this->parseRoutineIfBranch();

        while (strtoupper($this->prevText()) === 'ELSEIF') {
            $node = $this->parseRoutineIfBranch();
            $tail->set('false', $node);
            $tail = $node;
        }

        if (strtoupper($this->prevText()) === 'ELSE') {
            $tail->set('false', $this->expression(new Block(['expressions' => $this->parseRoutineStatements('END')])));
        }

        $this->matchTextSeq('IF');

        return $this_;
    }

    protected function parseRoutineCase(): CaseStatement
    {
        $this_ = $this->parseDisjunction();

        $ifs = [];
        $this->matchTextSeq('WHEN');
        while (strtoupper($this->prevText()) === 'WHEN') {
            $condition = $this->parseDisjunction();
            $this->matchTextSeq('THEN');
            $true = $this->expression(new Block(['expressions' => $this->parseRoutineStatements('WHEN', 'ELSE', 'END')]));
            $ifs[] = $this->expression(new IfExpr(['this' => $condition, 'true' => $true]));
        }

        $default = null;

        if (strtoupper($this->prevText()) === 'ELSE') {
            $default = $this->expression(new Block(['expressions' => $this->parseRoutineStatements('END')]));
        }

        $this->matchTextSeq('CASE');

        /** @var CaseStatement */
        return $this->expression(new CaseStatement(['this' => $this_, 'ifs' => $ifs, 'default' => $default]));
    }

    protected function parseRoutineWhile(?Expression $label = null): WhileBlock
    {
        $condition = $this->parseDisjunction();
        $this->matchTextSeq('DO');
        $body = $this->expression(new Block(['expressions' => $this->parseRoutineStatements('END')]));
        $this->matchTextSeq('WHILE');

        /** @var WhileBlock */
        return $this->expression(new WhileBlock(['this' => $condition, 'body' => $body, 'label' => $label]));
    }

    protected function parseRoutineLoop(?Expression $label = null): LoopBlock
    {
        $body = $this->expression(new Block(['expressions' => $this->parseRoutineStatements('END')]));
        $this->matchTextSeq('LOOP');

        /** @var LoopBlock */
        return $this->expression(new LoopBlock(['body' => $body, 'label' => $label]));
    }

    protected function parseRoutineRepeat(?Expression $label = null): RepeatBlock
    {
        // unlike WHILE/LOOP, the body is evaluated at least once, with UNTIL <condition> tested after
        $body = $this->expression(new Block(['expressions' => $this->parseRoutineStatements('UNTIL')]));
        $until = $this->parseDisjunction();
        $this->matchTextSeq('END', 'REPEAT');

        /** @var RepeatBlock */
        return $this->expression(new RepeatBlock(['body' => $body, 'until' => $until, 'label' => $label]));
    }

    protected function parseRoutineStatement(): ?Expression
    {
        // An optional `label :` can precede WHILE, LOOP, or REPEAT; the colon lookahead has to
        // run first, before the statement keywords are matched.
        $label = null;

        if ($this->next === TokenType::COLON) {
            $label = $this->parseIdVar();
            $this->match(TokenType::COLON);
        }

        if ($this->curr === TokenType::BEGIN) {
            return $this->parseRoutineBlock();
        }

        if ($this->matchTextSeq('RETURN')) {
            return $this->expression(new ReturnExpr(['this' => $this->parseDisjunction()]));
        }

        if ($this->matchTextSeq('IF')) {
            return $this->parseRoutineIf();
        }

        if ($this->matchTextSeq('CASE')) {
            return $this->parseRoutineCase();
        }

        if ($this->match(TokenType::DECLARE)) {
            return $this->parseDeclare();
        }

        if ($this->match(TokenType::SET)) {
            return $this->parseSet();
        }

        if ($this->matchTextSeq('ITERATE')) {
            return $this->expression(new Iterate(['this' => $this->parseIdVar()]));
        }

        if ($this->matchTextSeq('LEAVE')) {
            return $this->expression(new Leave(['this' => $this->parseIdVar()]));
        }

        if ($this->matchTextSeq('WHILE')) {
            return $this->parseRoutineWhile($label);
        }

        if ($this->matchTextSeq('LOOP')) {
            return $this->parseRoutineLoop($label);
        }

        if ($this->matchTextSeq('REPEAT')) {
            return $this->parseRoutineRepeat($label);
        }

        $this->raiseError('Expected routine statement');

        return null;
    }
}
