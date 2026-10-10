<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Spark2;

use LaravelSqlParser\LaravelSqlParser\Dialects\Hive\HiveParser;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\ApproxQuantile;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\DayOfMonth;
use LaravelSqlParser\LaravelSqlParser\Expressions\DayOfWeek;
use LaravelSqlParser\LaravelSqlParser\Expressions\DayOfYear;
use LaravelSqlParser\LaravelSqlParser\Expressions\Drop;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\FromTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IntDiv;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\WeekOfYear;

/**
 * Spark 2 parser (sqlglot parsers/spark2.py).
 */
class Spark2Parser extends HiveParser
{
    public const string TABLES = Spark2ParserTables::class;

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS builders
    // ---------------------------------------------------------------------------------------

    /**
     * sqlglot build_as_cast(to_type).
     *
     * @param  list<mixed>  $args
     */
    protected function buildAsCast(array $args, string $toType): Expression
    {
        return new Cast(['this' => $args[0] ?? null, 'to' => DataType::build($toType)]);
    }

    /**
     * sqlglot build_int_div.
     *
     * @param  list<mixed>  $args
     */
    protected function buildIntDiv(array $args): Expression
    {
        $this_ = $args[0] ?? null;
        $expression = $args[1] ?? null;

        if (! $this_ instanceof Expression || ! $expression instanceof Expression) {
            return new IntDiv(['this' => $this_, 'expression' => $expression]);
        }

        $this_ = $this_ instanceof Binary ? new Paren(['this' => $this_]) : $this_;
        $expression = $expression instanceof Binary ? new Paren(['this' => $expression]) : $expression;

        return new Paren(['this' => new IntDiv(['this' => $this_, 'expression' => $expression])]);
    }

    /** @param list<mixed> $args */
    protected function buildDateTrunc(array $args): Expression
    {
        $unit = $args[0] ?? null;

        return new TimestampTrunc(['this' => $args[1] ?? null, 'unit' => $unit instanceof Expression || is_string($unit) ? Builder::var($unit) : null]);
    }

    /** @param list<mixed> $args */
    protected function buildDayofmonth(array $args): Expression
    {
        return new DayOfMonth(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /** @param list<mixed> $args */
    protected function buildDayofweek(array $args): Expression
    {
        return new DayOfWeek(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /** @param list<mixed> $args */
    protected function buildDayofyear(array $args): Expression
    {
        return new DayOfYear(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /** @param list<mixed> $args */
    protected function buildWeekofyear(array $args): Expression
    {
        return new WeekOfYear(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /** @param list<mixed> $args */
    protected function buildElementAt(array $args): Expression
    {
        $index = $args[1] ?? null;

        return new Bracket([
            'this' => $args[0] ?? null,
            'expressions' => $index === null ? [] : [$index],
            'offset' => 1,
            'safe' => false,
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildFromUtcTimestamp(array $args): Expression
    {
        return new AtTimeZone([
            'this' => Builder::cast($args[0] ?? new VarExpr(['this' => '']), DType::TIMESTAMP, dialect: $this->dialect),
            'zone' => $args[1] ?? null,
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildToUtcTimestamp(array $args): Expression
    {
        return new FromTimeZone([
            'this' => Builder::cast($args[0] ?? new VarExpr(['this' => '']), DType::TIMESTAMP, dialect: $this->dialect),
            'zone' => $args[1] ?? null,
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildToTimestamp(array $args): Expression
    {
        return count($args) === 1
            ? $this->buildAsCast($args, 'timestamp')
            : $this->buildFormattedTime($args, StrToTime::class);
    }

    /**
     * Spark's TRUNC(date, unit).
     *
     * @param  list<mixed>  $args
     */
    protected function buildTrunc(
        array $args,
        bool $dateTruncUnabbreviate = true,
        ?string $defaultDateTruncUnit = null,
        bool $dateTruncRequiresPart = true,
        bool $fractionsSupported = false,
    ): Expression {
        return new DateTrunc(['unit' => $args[1] ?? null, 'this' => $args[0] ?? null]);
    }

    /** @param list<mixed> $args */
    protected function buildLtrim(array $args): Expression
    {
        return $this->buildTrim($args, reverseArgs: true);
    }

    /** @param list<mixed> $args */
    protected function buildRtrim(array $args): Expression
    {
        return $this->buildTrim($args, isLeft: false, reverseArgs: true);
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTION_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function funcAnd(): Expression
    {
        return $this->parseConnectorFunction(static fn (array $args): Expression => Builder::and($args));
    }

    protected function funcOr(): Expression
    {
        return $this->parseConnectorFunction(static fn (array $args): Expression => Builder::or($args));
    }

    protected function funcApproxPercentile(): Expression
    {
        return $this->parseDistinctArgFunction(ApproxQuantile::class);
    }

    protected function funcBroadcast(): Expression
    {
        return $this->parseJoinHint('BROADCAST');
    }

    protected function funcBroadcastjoin(): Expression
    {
        return $this->parseJoinHint('BROADCASTJOIN');
    }

    protected function funcMapjoin(): Expression
    {
        return $this->parseJoinHint('MAPJOIN');
    }

    protected function funcMerge(): Expression
    {
        return $this->parseJoinHint('MERGE');
    }

    protected function funcShufflemerge(): Expression
    {
        return $this->parseJoinHint('SHUFFLEMERGE');
    }

    protected function funcMergejoin(): Expression
    {
        return $this->parseJoinHint('MERGEJOIN');
    }

    protected function funcShuffleHash(): Expression
    {
        return $this->parseJoinHint('SHUFFLE_HASH');
    }

    protected function funcShuffleReplicateNl(): Expression
    {
        return $this->parseJoinHint('SHUFFLE_REPLICATE_NL');
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseDropColumn(): ?Expression
    {
        return $this->matchTextSeq('DROP', 'COLUMNS')
            ? $this->expression(new Drop(['tables' => [$this->parseSchema()], 'kind' => 'COLUMNS']))
            : null;
    }

    /**
     * @param  list<Expression>  $aggregations
     * @return list<string>
     */
    protected function pivotColumnNames(array $aggregations): array
    {
        if (count($aggregations) === 1) {
            return [];
        }

        $names = [];

        foreach ($aggregations as $aggregation) {
            if ($aggregation instanceof Alias) {
                $names[] = $aggregation->alias();

                continue;
            }

            $unquoted = $aggregation->transform(static fn (Expression $node): Expression => $node instanceof Identifier ? new Identifier(['this' => $node->name(), 'quoted' => false]) : $node);
            $names[] = $unquoted?->sql('spark', ['normalize_functions' => 'lower']) ?? '';
        }

        return $names;
    }
}
