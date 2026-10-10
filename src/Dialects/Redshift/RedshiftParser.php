<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Redshift;

use Kwasii\LaravelSqlParser\Dialects\Postgres\PostgresParser;
use Kwasii\LaravelSqlParser\Expressions\Alias;
use Kwasii\LaravelSqlParser\Expressions\ApproxDistinct;
use Kwasii\LaravelSqlParser\Expressions\ApproxQuantile;
use Kwasii\LaravelSqlParser\Expressions\ArrayContains;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Count;
use Kwasii\LaravelSqlParser\Expressions\CurrentTimestamp;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\Distinct;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\ObjectTransform;
use Kwasii\LaravelSqlParser\Expressions\PercentileDisc;
use Kwasii\LaravelSqlParser\Expressions\Pivot;
use Kwasii\LaravelSqlParser\Expressions\RegexpExtract;
use Kwasii\LaravelSqlParser\Expressions\StringToArray;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsAdd;
use Kwasii\LaravelSqlParser\Expressions\WithinGroup;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Amazon Redshift parser (sqlglot parsers/redshift.py).
 */
class RedshiftParser extends PostgresParser
{
    public const string TABLES = RedshiftParserTables::class;

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildDateDeltaRedshift(array $args, string $exprType): Expression
    {
        $expression = new $exprType([
            'this' => $args[2] ?? null,
            'expression' => $args[1] ?? null,
            'unit' => $this->mapDatePart($args[0] ?? null),
        ]);

        if ($expression instanceof TsOrDsAdd) {
            $expression->set('return_type', new DataType(['this' => DType::TIMESTAMP]));
        }

        return $expression;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildAddMonths(array $args): TsOrDsAdd
    {
        return new TsOrDsAdd([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'unit' => Builder::var('month'),
            'return_type' => new DataType(['this' => DType::TIMESTAMP]),
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildConvertTimezone(array $args, ?string $defaultSourceTz = 'UTC'): Expression
    {
        return parent::buildConvertTimezone($args, $defaultSourceTz);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildRegexpSubstr(array $args): RegexpExtract
    {
        return new RegexpExtract([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'position' => $args[2] ?? null,
            'occurrence' => $args[3] ?? null,
            'parameters' => $args[4] ?? null,
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSplitToArray(array $args): StringToArray
    {
        return new StringToArray(['this' => $args[0] ?? null, 'expression' => $args[1] ?? Literal::string(',')]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildArrayContains(array $args): ArrayContains
    {
        return new ArrayContains([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'check_null' => $args[2] ?? null,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // NO_PAREN_FUNCTION_PARSERS / FUNCTION_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function noParenFunctionSysdate(): Expression
    {
        return $this->expression(new CurrentTimestamp(['sysdate' => true]));
    }

    protected function parseObjectTransform(): ObjectTransform
    {
        $this_ = $this->parseColumn();
        $keep = $this->match(TokenType::KEEP) ? $this->parseCsv(fn (): ?Expression => $this->parsePrimary()) : [];
        $set = $this->match(TokenType::SET) ? $this->parseCsv(fn (): ?Expression => $this->parseExpression()) : [];

        return $this->expression(new ObjectTransform(['this' => $this_, 'keep' => $keep, 'set_' => $set]));
    }

    protected function parseApproximateCount(): ?Expression
    {
        $index = $this->index - 1;
        $func = $this->parseFunction();

        if ($func instanceof Count && ($func->args['this'] ?? null) instanceof Distinct) {
            return $this->expression(new ApproxDistinct(['this' => $func->args['this']->argList('expressions')[0] ?? null]));
        }

        if ($func instanceof WithinGroup && ($func->args['this'] ?? null) instanceof PercentileDisc) {
            $order = $func->args['expression'] ?? null;
            $ordered = $order instanceof Expression ? ($order->argList('expressions')[0] ?? null) : null;

            return $this->expression(new ApproxQuantile([
                'this' => $ordered?->args['this'] ?? null,
                'quantile' => $func->args['this']->args['this'] ?? null,
            ]));
        }

        $this->retreat($index);

        return null;
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseTable(
        bool $schema = false,
        bool $joins = false,
        ?array $aliasTokens = null,
        bool $parseBracket = false,
        bool $isDbReference = false,
        bool $parsePartition = false,
        bool $consumePipe = false,
    ): ?Expression {
        // Redshift supports UNPIVOTing SUPER objects, e.g. `UNPIVOT foo.obj[0] AS val AT attr`
        $unpivot = $this->match(TokenType::UNPIVOT);
        $table = parent::parseTable(
            schema: $schema,
            joins: $joins,
            aliasTokens: $aliasTokens,
            parseBracket: $parseBracket,
            isDbReference: $isDbReference,
        );

        return $unpivot ? $this->expression(new Pivot(['this' => $table, 'unpivot' => true])) : $table;
    }

    protected function parseConvert(bool $strict, ?bool $safe = null): ?Expression
    {
        $to = $this->parseTypes();
        $this->match(TokenType::COMMA);
        $this_ = $this->parseBitwise();

        return $this->expression(new Cast(['this' => $this_, 'to' => $to, 'safe' => $safe]));
    }

    protected function parseProjections(): array
    {
        [$projections] = parent::parseProjections();

        if ($this->prev !== 0 && strtoupper($this->prevText()) === 'EXCLUDE' && $this->curr !== 0) {
            $this->retreat($this->index - 1);
        }

        // EXCLUDE clause always comes at the end of the projection list and applies to it as a whole
        $exclude = $this->matchTextSeq('EXCLUDE')
            ? $this->parseWrappedCsv(fn (): ?Expression => $this->parseExpression(), optional: true)
            : [];

        $last = $projections === [] ? null : $projections[count($projections) - 1];

        if ($exclude !== [] && $last instanceof Alias && strtoupper($last->alias()) === 'EXCLUDE') {
            $inner = $last->args['this'] ?? null;

            if ($inner instanceof Expression) {
                $projections[count($projections) - 1] = $inner->pop();
            }
        }

        return [$projections, $exclude];
    }
}
