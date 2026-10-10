<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Parser\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Alias;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\CTE;
use Kwasii\LaravelSqlParser\Expressions\Distinct;
use Kwasii\LaravelSqlParser\Expressions\Except;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\Group;
use Kwasii\LaravelSqlParser\Expressions\Intersect;
use Kwasii\LaravelSqlParser\Expressions\Limit;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Offset;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\Ordered;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\SetOperation;
use Kwasii\LaravelSqlParser\Expressions\Star;
use Kwasii\LaravelSqlParser\Expressions\Subquery;
use Kwasii\LaravelSqlParser\Expressions\TableAlias;
use Kwasii\LaravelSqlParser\Expressions\Union;
use Kwasii\LaravelSqlParser\Expressions\Where;
use Kwasii\LaravelSqlParser\Expressions\With;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Pipe syntax (FROM t |> WHERE ... |> AGGREGATE ...): each operator wraps the query built so far
 * in a CTE (sqlglot parser.py, _parse_pipe_syntax_*).
 */
trait ParsesPipeSyntax
{
    protected function parsePipeSyntaxQuery(Expression $query): ?Expression
    {
        if ($query instanceof Subquery) {
            $query = self::pipeSelectFrom([new Star], $query);
        }

        if (! ($query->args['from_'] ?? null) instanceof Expression && $query instanceof Query) {
            $query = self::pipeSelectFrom([new Star], $query->subquery(copy: false));
        }

        while ($this->match(TokenType::PIPE_GT)) {
            $startIndex = $this->index;
            $startText = strtoupper($this->currText());
            $handler = $this->t['PIPE_SYNTAX_TRANSFORM_PARSERS'][$startText] ?? null;

            if ($handler !== null) {
                $query = $this->callHandler($handler, $query);

                continue;
            }

            // The set operators (UNION, etc) and the JOIN operator have a few common starting
            // keywords, making it tricky to disambiguate them without lookahead. The approach
            // here is to try and parse a set operation and if that fails, then try to parse a
            // join operator. If that fails as well, then the operator is not supported.
            $parsedQuery = $this->parsePipeSyntaxSetOperator($query) ?? $this->parsePipeSyntaxJoin($query);

            if ($parsedQuery === null) {
                $this->retreat($startIndex);
                $this->raiseError("Unsupported pipe syntax operator: '{$startText}'.");

                break;
            }

            $query = $parsedQuery;
        }

        return $query;
    }

    /**
     * SELECT <projections> FROM <source>; a string source is a table name.
     *
     * @param  list<Expression>  $projections
     */
    private static function pipeSelectFrom(array $projections, Expression|string $source): Select
    {
        $from = is_string($source) ? Builder::table($source) : $source;

        return new Select(['expressions' => $projections, 'from_' => new From(['this' => $from])]);
    }

    /**
     * @param  list<Expression>  $expressions
     */
    protected function buildPipeCte(Expression $query, array $expressions, ?TableAlias $aliasCte = null): Select
    {
        if ($aliasCte !== null) {
            $newCte = $aliasCte;
        } else {
            $this->pipeCteCounter++;
            $newCte = "__tmp{$this->pipeCteCounter}";
        }

        $with = $query->args['with_'] ?? null;
        $ctes = $with instanceof With ? $with->pop() : null;

        $newSelect = self::pipeSelectFrom($expressions, $newCte);

        $alias = $newCte instanceof TableAlias
            ? $newCte->copy()
            : new TableAlias(['this' => Builder::toIdentifier($newCte)]);

        $newSelect->set('with_', new With([
            'expressions' => [...($ctes?->argList('expressions') ?? []), new CTE(['this' => $query, 'alias' => $alias])],
        ]));

        return $newSelect;
    }

    protected function pipeAs(Expression $query): Expression
    {
        return $this->buildPipeCte($query, [new Star], $this->parseTableAlias());
    }

    protected function pipeDistinct(Expression $query): Expression
    {
        $this->advance();
        $query->set('distinct', new Distinct);

        return $query;
    }

    protected function pipeOrderBy(Expression $query): Expression
    {
        $order = $this->parseOrder();

        if ($order instanceof Expression) {
            $query->set('order', new Order(['expressions' => $order->argList('expressions')]));
        }

        return $query;
    }

    protected function pipeWhere(Expression $query): Expression
    {
        $where = $this->parseWhere();

        if ($where instanceof Where) {
            $existing = $query->args['where'] ?? null;
            $conditions = $existing instanceof Where ? [$existing->argExpr('this'), $where->argExpr('this')] : [$where->argExpr('this')];
            $query->set('where', new Where(['this' => Builder::and($conditions, copy: false)]));
        }

        return $query;
    }

    protected function pipeSelect(Expression $query): Expression
    {
        $select = $this->parseSelect(consumePipe: false);

        if ($select === null) {
            return $query;
        }

        $query->set('expressions', $select->argList('expressions'));

        return $this->buildPipeCte($query, [new Star]);
    }

    protected function pipeLimit(Expression $query): Expression
    {
        $limit = $this->parseLimit();
        $offset = $this->parseOffset();

        if ($limit instanceof Limit) {
            $currLimit = $query->args['limit'] ?? $limit;

            if ($currLimit->argExpr('expression')?->toPhp() >= $limit->argExpr('expression')?->toPhp()) {
                $query->set('limit', $limit);
            }
        }

        if ($offset instanceof Offset) {
            $currOffset = $query->args['offset'] ?? null;
            $currOffset = $currOffset instanceof Expression ? (int) $currOffset->argExpr('expression')?->toPhp() : 0;
            $query->set('offset', new Offset(['expression' => Literal::number($currOffset + (int) $offset->argExpr('expression')?->toPhp())]));
        }

        return $query;
    }

    protected function parsePipeSyntaxAggregateFields(): ?Expression
    {
        $this_ = $this->parseDisjunction();

        if ($this->peekTextSeq('GROUP', 'AND')) {
            return $this_;
        }

        $this_ = $this->parseAlias($this_);

        if ($this->matchSet([TokenType::ASC => true, TokenType::DESC => true], false)) {
            return $this->parseOrdered(static fn (): ?Expression => $this_);
        }

        return $this_;
    }

    protected function parsePipeSyntaxAggregateGroupOrderBy(Expression $query, bool $groupByExists = true): Expression
    {
        $aggregatesOrGroups = [];
        $orders = [];

        foreach ($this->parseCsv(fn (): ?Expression => $this->parsePipeSyntaxAggregateFields()) as $element) {
            if ($element instanceof Ordered) {
                $this_ = $element->args['this'] ?? null;

                if ($this_ instanceof Alias) {
                    $element->set('this', $this_->args['alias'] ?? null);
                }

                $orders[] = $element;
            } else {
                $this_ = $element;
            }

            if ($this_ instanceof Expression) {
                $aggregatesOrGroups[] = $this_;
            }
        }

        if ($groupByExists) {
            $query->set('expressions', [...$aggregatesOrGroups, ...$query->argList('expressions')]);

            $existing = $query->args['group'] ?? null;
            $groups = $existing instanceof Group ? $existing->argList('expressions') : [];

            foreach ($aggregatesOrGroups as $projection) {
                $groups[] = $projection->args['alias'] ?? $projection;
            }

            $query->set('group', new Group(['expressions' => $groups]));
        } else {
            $query->set('expressions', $aggregatesOrGroups);
        }

        if ($orders !== []) {
            $query->set('order', new Order(['expressions' => $orders]));
        }

        return $query;
    }

    protected function pipeAggregate(Expression $query): Expression
    {
        $this->matchTextSeq('AGGREGATE');
        $query = $this->parsePipeSyntaxAggregateGroupOrderBy($query, groupByExists: false);

        if ($this->match(TokenType::GROUP_BY) || ($this->matchTextSeq('GROUP', 'AND') && $this->match(TokenType::ORDER_BY))) {
            $query = $this->parsePipeSyntaxAggregateGroupOrderBy($query);
        }

        return $this->buildPipeCte($query, [new Star]);
    }

    protected function parsePipeSyntaxSetOperator(Expression $query): ?Expression
    {
        $firstSetop = $this->parseSetOperation($query);

        if (! $firstSetop instanceof SetOperation) {
            return null;
        }

        ($firstSetop->args['this'] ?? null)?->pop();
        $first = ($firstSetop->args['expression'] ?? null)?->pop();

        $setops = [$first instanceof Subquery ? $first->unnest() : $first];

        foreach ($this->parseCsv(function (): ?Expression {
            $expr = $this->parseParen();

            return $expr instanceof Subquery ? $expr->unnest() : $expr;
        }) as $setop) {
            $setops[] = $setop;
        }

        $query = $this->buildPipeCte($query, [new Star]);
        $with = $query->args['with_'] ?? null;
        $ctes = $with instanceof With ? $with->pop() : null;

        $operation = $firstSetop instanceof Union ? Union::class : ($firstSetop instanceof Except ? Except::class : Intersect::class);
        $options = $firstSetop->args;
        unset($options['this'], $options['expression']);

        $result = $query;
        foreach ($setops as $setop) {
            $result = new $operation(['this' => $result, 'expression' => $setop, ...$options]);
        }

        $result->set('with_', $ctes);

        return $this->buildPipeCte($result, [new Star]);
    }

    protected function parsePipeSyntaxJoin(Expression $query): ?Expression
    {
        $join = $this->parseJoin();

        if ($join === null) {
            return null;
        }

        if ($query instanceof Select) {
            if (($join->args['this'] ?? null) instanceof Select) {
                $join->args['this']->replace($join->args['this']->subquery());
            }

            $query->append('joins', $join);
        }

        return $query;
    }

    protected function pipePivot(Expression $query): Expression
    {
        $pivots = $this->parsePivots();

        if ($pivots === null || $pivots === []) {
            return $query;
        }

        $from = $query->args['from_'] ?? null;

        if ($from instanceof From) {
            $from->argExpr('this')?->set('pivots', $pivots);
        }

        return $this->buildPipeCte($query, [new Star]);
    }

    protected function pipeUnpivot(Expression $query): Expression
    {
        return $this->pipePivot($query);
    }

    protected function pipeExtend(Expression $query): Expression
    {
        $this->matchTextSeq('EXTEND');
        $query->set('expressions', [new Star, ...$this->parseExpressions()]);

        return $this->buildPipeCte($query, [new Star]);
    }

    protected function pipeTablesample(Expression $query): Expression
    {
        $sample = $this->parseTableSample();
        $with = $query->args['with_'] ?? null;

        if ($with instanceof With) {
            $ctes = $with->argList('expressions');
            $ctes[count($ctes) - 1]->argExpr('this')?->set('sample', $sample);
        } else {
            $query->set('sample', $sample);
        }

        return $query;
    }
}
