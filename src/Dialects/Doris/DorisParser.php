<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Doris;

use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySqlParser;
use LaravelSqlParser\LaravelSqlParser\Expressions\BuildProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Partition;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionByRangeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionByRangePropertyDynamic;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionRange;
use LaravelSqlParser\LaravelSqlParser\Expressions\Property;
use LaravelSqlParser\LaravelSqlParser\Expressions\RefreshTriggerProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\UniqueKeyProperty;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Apache Doris parser (sqlglot parsers/doris.py).
 */
class DorisParser extends MySqlParser
{
    public const string TABLES = DorisParserTables::class;

    /**
     * Accept both DATE_TRUNC(datetime, unit) and DATE_TRUNC(unit, datetime).
     *
     * @param  list<Expression>  $args
     */
    protected function buildDateTrunc(array $args): Expression
    {
        $a0 = $args[0] ?? null;
        $a1 = $args[1] ?? null;

        $isUnitLike = static fn (?Expression $e): bool => $e instanceof Literal
            && $e->isString()
            && preg_match('/\d/', (string) $e->args['this']) !== 1;

        [$unit, $this_] = $isUnitLike($a0) ? [$a0, $a1] : [$a1, $a0];

        return new TimestampTrunc(['this' => $this_, 'unit' => $unit]);
    }

    protected function propertyUnique(): Expression
    {
        return $this->parseCompositeKeyProperty(UniqueKeyProperty::class);
    }

    protected function propertyKey(): Expression
    {
        return $this->parseCompositeKeyProperty(UniqueKeyProperty::class);
    }

    /**
     * @return Expression|list<Expression>|null
     */
    protected function parsePartitionProperty(): Expression|array|null
    {
        $expr = parent::parsePartitionProperty();

        if ($expr === null || $expr === []) {
            return $this->parsePartitionedBy();
        }

        if ($expr instanceof Property) {
            return $expr;
        }

        $this->matchLParen();

        $createExpressions = $this->peekTextSeq('FROM')
            ? $this->parseCsv(fn (): Expression => $this->parsePartitioningGranularityDynamic())
            : null;

        $this->matchRParen();

        return $this->expression(new PartitionByRangeProperty(['partition_expressions' => $expr, 'create_expressions' => $createExpressions]));
    }

    protected function parsePartitioningGranularityDynamic(): PartitionByRangePropertyDynamic
    {
        $this->matchTextSeq('FROM');
        $start = $this->parseWrapped(fn (): ?Expression => $this->parseString());
        $this->matchTextSeq('TO');
        $end = $this->parseWrapped(fn (): ?Expression => $this->parseString());
        $this->matchTextSeq('INTERVAL');
        $number = $this->parseNumber();
        $unit = $this->parseVar(anyToken: true);
        $every = $this->expression(new Interval(['this' => $number, 'unit' => $unit]));

        return $this->expression(new PartitionByRangePropertyDynamic(['start' => $start, 'end' => $end, 'every' => $every]));
    }

    protected function parsePartitionRangeValue(): ?Expression
    {
        $expr = parent::parsePartitionRangeValue();

        if ($expr instanceof Partition) {
            return $expr;
        }

        $this->matchTextSeq('VALUES');
        $name = $expr;

        $this->match(TokenType::L_BRACKET);
        $values = $this->parseCsv(fn (): array => $this->parseWrappedCsv(fn (): ?Expression => $this->parseExpression()));

        $this->match(TokenType::R_BRACKET);
        $this->match(TokenType::R_PAREN);

        $partRange = $this->expression(new PartitionRange(['this' => $name, 'expressions' => $values]));

        return $this->expression(new Partition(['expressions' => [$partRange]]));
    }

    protected function parseBuildProperty(): Expression
    {
        return $this->expression(new BuildProperty(['this' => $this->parseVar(upper: true)]));
    }

    protected function parseRefreshProperty(): Expression
    {
        $method = $this->parseVar(upper: true);

        $this->match(TokenType::ON);

        $kind = $this->matchTexts(['MANUAL' => true, 'COMMIT' => true, 'SCHEDULE' => true]) ? strtoupper($this->prevText()) : null;
        $every = $this->matchTextSeq('EVERY') ? $this->parseNumber() : null;
        $unit = $every !== null ? $this->parseVar(anyToken: true) : null;
        $starts = $this->matchTextSeq('STARTS') ? $this->parseString() : null;

        return $this->expression(new RefreshTriggerProperty([
            'method' => $method,
            'kind' => $kind,
            'every' => $every,
            'unit' => $unit,
            'starts' => $starts,
        ]));
    }
}
