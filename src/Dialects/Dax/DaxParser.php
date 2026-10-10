<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Dax;

use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * DAX parser (sqlglot parsers/dax.py).
 */
class DaxParser extends Parser
{
    public const string TABLES = DaxParserTables::class;

    protected function parseStatement(): ?Expression
    {
        if (! $this->matchTextSeq('EVALUATE')) {
            return null;
        }

        $query = $this->parseDaxTableExpression();

        if ($query !== null && $this->match(TokenType::ORDER_BY)) {
            $order = new Order(['expressions' => $this->parseCsv(fn (): ?Expression => $this->parseOrdered())]);
            $query->set('order', $this->expression($order));
        }

        return $query;
    }

    protected function parseDaxTableExpression(): ?Select
    {
        if ($this->matchPair(TokenType::FILTER, TokenType::L_PAREN)) {
            $query = $this->parseDaxTableExpression();
            $this->match(TokenType::COMMA);
            $where = $this->parseDisjunction();
            $this->matchRParen();

            if ($query === null) {
                return null;
            }

            if ($where !== null) {
                $existing = $query->args['where'] ?? null;

                if ($existing instanceof Where && ($existing->args['this'] ?? null) instanceof Expression) {
                    $where = Builder::and([$existing->args['this'], $where], copy: false);
                }

                $query->set('where', new Where(['this' => $where]));
            }

            return $query;
        }

        $table = $this->parseTableParts();

        return $table !== null ? Builder::selectStarFrom($table) : null;
    }

    protected function parseColumn(): ?Expression
    {
        $this_ = parent::parseColumn();

        // Combine adjacent identifiers into a column reference: Table[Column]
        if ($this_ instanceof Column) {
            $column = $this->parseIdentifier();

            if ($column !== null) {
                $this_ = $this->expression(new Column(['this' => $column, 'table' => $this_->args['this'] ?? null]));
            }
        }

        return $this_;
    }
}
