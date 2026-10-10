<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Prql;

use Kwasii\LaravelSqlParser\Expressions\Alias;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Except;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Intersect;
use Kwasii\LaravelSqlParser\Expressions\Is;
use Kwasii\LaravelSqlParser\Expressions\Limit;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\NEQ;
use Kwasii\LaravelSqlParser\Expressions\Not;
use Kwasii\LaravelSqlParser\Expressions\NullExpr;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\Ordered;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Sum;
use Kwasii\LaravelSqlParser\Expressions\Union;
use Kwasii\LaravelSqlParser\Expressions\Where;
use Kwasii\LaravelSqlParser\Parser\Parser;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * PRQL parser (sqlglot parsers/prql.py): a pipeline of transforms applied to `from <table>`.
 */
class PrqlParser extends Parser
{
    public const string TABLES = PrqlParserTables::class;

    // ---------------------------------------------------------------------------------------
    // Module helpers
    // ---------------------------------------------------------------------------------------

    /** sqlglot _select_all. */
    protected static function selectAll(?Expression $table): ?Expression
    {
        return $table !== null ? Builder::selectStarFrom($table) : null;
    }

    /**
     * sqlglot _resolve_projection.
     *
     * @param  array<string, Expression>  $projections
     */
    protected static function resolveProjection(Expression $s, array $projections): Expression
    {
        if ($s instanceof Column && isset($projections[$s->name()])) {
            return $projections[$s->name()]->copy();
        }

        return $s;
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS / TRANSFORM_PARSERS
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<Expression>  $args
     */
    protected function buildSum(array $args): Expression
    {
        return Builder::func('COALESCE', [new Sum(['this' => $args[0] ?? null]), Literal::number(0)]);
    }

    protected function transformParsersDerive(Expression $query): ?Expression
    {
        return $this->parseSelection($query);
    }

    protected function transformParsersSelect(Expression $query): ?Expression
    {
        return $this->parseSelection($query, append: false);
    }

    protected function transformParsersTake(Expression $query): ?Expression
    {
        return $this->parseTake($query);
    }

    protected function transformParsersFilter(Expression $query): ?Expression
    {
        $where = $this->parseDisjunction();

        if ($where === null) {
            return $query;
        }

        $query = $query->copy();
        $existing = $query->args['where'] ?? null;

        if ($existing instanceof Where && ($existing->args['this'] ?? null) instanceof Expression) {
            $where = Builder::and([$existing->args['this'], $where], copy: false);
        }

        $query->set('where', new Where(['this' => $where]));

        return $query;
    }

    protected function transformParsersAppend(Expression $query): ?Expression
    {
        return new Union(['this' => $query, 'expression' => self::selectAll($this->parseTable()), 'distinct' => false]);
    }

    protected function transformParsersRemove(Expression $query): ?Expression
    {
        return new Except(['this' => $query, 'expression' => self::selectAll($this->parseTable()), 'distinct' => false]);
    }

    protected function transformParsersIntersect(Expression $query): ?Expression
    {
        return new Intersect(['this' => $query, 'expression' => self::selectAll($this->parseTable()), 'distinct' => false]);
    }

    protected function transformParsersSort(Expression $query): ?Expression
    {
        return $this->parsePrqlOrderBy($query);
    }

    protected function transformParsersAggregate(Expression $query): ?Expression
    {
        return $this->parseSelection($query, fn (): ?Expression => $this->parsePrqlAggregate(), append: false);
    }

    // ---------------------------------------------------------------------------------------
    // Parsing
    // ---------------------------------------------------------------------------------------

    protected function parseEquality(?Expression $left = null): ?Expression
    {
        $eq = parent::parseEquality($left);

        if (! ($eq instanceof EQ || $eq instanceof NEQ)) {
            return $eq;
        }

        // https://prql-lang.org/book/reference/spec/null.html
        if (($eq->args['expression'] ?? null) instanceof NullExpr) {
            $isExp = new Is(['this' => $eq->args['this'] ?? null, 'expression' => $eq->args['expression']]);

            return $eq instanceof EQ ? $isExp : new Not(['this' => $isExp]);
        }

        if (($eq->args['this'] ?? null) instanceof NullExpr) {
            $isExp = new Is(['this' => $eq->args['expression'] ?? null, 'expression' => $eq->args['this']]);

            return $eq instanceof EQ ? $isExp : new Not(['this' => $isExp]);
        }

        return $eq;
    }

    protected function parseStatement(): ?Expression
    {
        return $this->parseExpression() ?? $this->parsePrqlQuery();
    }

    protected function parsePrqlQuery(): ?Expression
    {
        $from = $this->parseFrom();

        if ($from === null) {
            return null;
        }

        $query = Builder::selectStarFrom($from);
        $transformParsers = $this->t['TRANSFORM_PARSERS'];

        while ($query !== null && $this->matchTexts($transformParsers)) {
            $query = $this->{$transformParsers[strtoupper($this->prevText())]}($query);
        }

        return $query;
    }

    /**
     * @param  (callable(): ?Expression)|null  $parseMethod
     */
    protected function parseSelection(Expression $query, ?callable $parseMethod = null, bool $append = true): Expression
    {
        $parseMethod ??= fn (): ?Expression => $this->parseExpression();

        if ($this->match(TokenType::L_BRACE)) {
            $selects = $this->parseCsv($parseMethod(...));

            if (! $this->match(TokenType::R_BRACE, true, $query)) {
                $this->raiseError('Expecting }');
            }
        } else {
            $expression = $parseMethod();
            $selects = $expression !== null ? [$expression] : [];
        }

        $projections = [];

        if ($query instanceof Query) {
            foreach ($query->selects() as $select) {
                $projections[$select->aliasOrName()] = $select instanceof Alias && ($select->args['this'] ?? null) instanceof Expression
                    ? $select->args['this']
                    : $select;
            }
        }

        $resolved = [];

        foreach ($selects as $select) {
            $transformed = $select->transform(fn (Expression $node): Expression => self::resolveProjection($node, $projections), copy: false);

            if ($transformed !== null) {
                $resolved[] = $transformed;
            }
        }

        $query = $query->copy();
        $existing = $append && is_array($query->args['expressions'] ?? null) ? $query->args['expressions'] : [];
        $query->set('expressions', [...$existing, ...$resolved]);

        return $query;
    }

    protected function parseTake(Expression $query): ?Expression
    {
        $num = $this->parseNumber(); // TODO: TAKE for ranges a..b

        if ($num === null) {
            return null;
        }

        $query = $query->copy();
        $query->set('limit', new Limit(['expression' => $num]));

        return $query;
    }

    protected function parseOrdered(?callable $parseMethod = null): ?Ordered
    {
        $this->match(TokenType::PLUS);
        $desc = $this->match(TokenType::DASH);
        $term = parent::parseOrdered($parseMethod);

        if ($term !== null && $desc) {
            $term->set('desc', true);
            $term->set('nulls_first', false);
        }

        return $term;
    }

    protected function parsePrqlOrderBy(Expression $query): ?Expression
    {
        $lBrace = $this->match(TokenType::L_BRACE);
        $expressions = $this->parseCsv(fn (): ?Expression => $this->parseOrdered());

        if ($lBrace && ! $this->match(TokenType::R_BRACE)) {
            $this->raiseError('Expecting }');
        }

        $query->set('order', $this->expression(new Order(['expressions' => $expressions])));

        return $query;
    }

    protected function parsePrqlAggregate(): ?Expression
    {
        $alias = null;

        if ($this->next === TokenType::ALIAS) {
            $alias = $this->parseIdVar(anyToken: true);
            $this->match(TokenType::ALIAS);
        }

        $name = strtoupper($this->currText());
        $func = null;

        if (isset($this->t['FUNCTIONS'][$name])) {
            $this->advance();
            $args = $this->parseColumn();
            $this->functionBuilderName = $name;
            $func = $this->buildFunction($name, $args !== null ? [$args] : []);
        } else {
            $this->raiseError("Unsupported aggregation function {$name}");
        }

        if ($alias !== null) {
            return $this->expression(new Alias(['this' => $func, 'alias' => $alias]));
        }

        return $func;
    }

    protected function parseExpression(): ?Expression
    {
        if ($this->next === TokenType::ALIAS) {
            $alias = $this->parseIdVar(true);
            $this->match(TokenType::ALIAS);

            return $this->expression(new Alias(['this' => $this->parseAssignment(), 'alias' => $alias]));
        }

        return $this->parseAssignment();
    }

    protected function parseTable(
        bool $schema = false,
        bool $joins = false,
        ?array $aliasTokens = null,
        bool $parseBracket = false,
        bool $isDbReference = false,
        bool $parsePartition = false,
        bool $consumePipe = false,
    ): ?Expression {
        return $this->parseTableParts();
    }
}
