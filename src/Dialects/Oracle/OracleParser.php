<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Oracle;

use Kwasii\LaravelSqlParser\Expressions\Anonymous;
use Kwasii\LaravelSqlParser\Expressions\CurrentTimestamp;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DateStrToDate;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\ForceProperty;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\IntervalSpan;
use Kwasii\LaravelSqlParser\Expressions\Into;
use Kwasii\LaravelSqlParser\Expressions\JSONArray;
use Kwasii\LaravelSqlParser\Expressions\JSONArrayAgg;
use Kwasii\LaravelSqlParser\Expressions\JSONExists;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Pow;
use Kwasii\LaravelSqlParser\Expressions\Prior;
use Kwasii\LaravelSqlParser\Expressions\QueryOption;
use Kwasii\LaravelSqlParser\Expressions\Rand;
use Kwasii\LaravelSqlParser\Expressions\StrToTime;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\TableAlias;
use Kwasii\LaravelSqlParser\Expressions\TemporaryProperty;
use Kwasii\LaravelSqlParser\Expressions\ToNumber;
use Kwasii\LaravelSqlParser\Parser\Parser;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Oracle parser (sqlglot parsers/oracle.py).
 */
class OracleParser extends Parser
{
    public const string TABLES = OracleParserTables::class;

    /** sqlglot OracleParser.QUERY_RESTRICTIONS. */
    private const array QUERY_RESTRICTIONS = [
        'WITH' => [['READ', 'ONLY'], ['CHECK', 'OPTION']],
    ];

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $args
     */
    protected function buildToTimestamp(array $args): Expression
    {
        if (count($args) === 1) {
            return new Anonymous(['this' => 'TO_TIMESTAMP', 'expressions' => $args]);
        }

        return $this->buildFormattedTime($args, StrToTime::class);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildNvl(array $args): Expression
    {
        return $this->buildCoalesce($args, isNvl: true);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSquare(array $args): Pow
    {
        return new Pow(['this' => $args[0] ?? null, 'expression' => Literal::number(2)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTrunc(
        array $args,
        bool $dateTruncUnabbreviate = false,
        ?string $defaultDateTruncUnit = 'DD',
        bool $dateTruncRequiresPart = true,
        bool $fractionsSupported = false,
    ): Expression {
        return parent::buildTrunc($args, $dateTruncUnabbreviate, $defaultDateTruncUnit, $dateTruncRequiresPart, $fractionsSupported);
    }

    // ---------------------------------------------------------------------------------------
    // NO_PAREN_FUNCTION_PARSERS / FUNCTION_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function noParenFunctionPrior(): Prior
    {
        return $this->expression(new Prior(['this' => $this->parseBitwise()]));
    }

    protected function noParenFunctionSysdate(): Expression
    {
        return $this->expression(new CurrentTimestamp(['sysdate' => true]));
    }

    protected function parseDbmsRandom(): ?Expression
    {
        if ($this->matchTextSeq('.', 'VALUE')) {
            $lower = null;
            $upper = null;

            if ($this->match(TokenType::L_PAREN, false)) {
                $lowerUpper = $this->parseWrappedCsv(fn (): ?Expression => $this->parseBitwise());

                if (count($lowerUpper) === 2) {
                    [$lower, $upper] = $lowerUpper;
                }
            }

            return new Rand(['lower' => $lower, 'upper' => $upper]);
        }

        $this->retreat($this->index - 1);

        return null;
    }

    protected function parseToNumber(): ToNumber
    {
        // https://docs.oracle.com/en/database/oracle/oracle-database/19/sqlrf/TO_NUMBER.html
        $this_ = $this->parseBitwise();
        $default = null;

        if ($this->match(TokenType::DEFAULT)) {
            $default = $this->parseBitwise();
            $this->matchTextSeq('ON', 'CONVERSION', 'ERROR');
        }

        $fmt = null;
        $nlsparam = null;

        if ($this->match(TokenType::COMMA)) {
            $fmt = $this->parseBitwise();

            if ($this->match(TokenType::COMMA)) {
                $nlsparam = $this->parseBitwise();
            }
        }

        return $this->expression(new ToNumber(['this' => $this_, 'format' => $fmt, 'nlsparam' => $nlsparam, 'default' => $default]));
    }

    protected function parseOracleJsonArray(): Expression
    {
        return $this->parseJsonArrayOracle(JSONArray::class, [
            'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseFormatJson($this->parseBitwise())),
        ]);
    }

    protected function parseOracleJsonArrayagg(): Expression
    {
        return $this->parseJsonArrayOracle(JSONArrayAgg::class, [
            'this' => $this->parseFormatJson($this->parseBitwise()),
            'order' => $this->parseOrder(),
        ]);
    }

    /**
     * @param  class-string<Expression>  $exprType
     * @param  array<string, mixed>  $args
     */
    private function parseJsonArrayOracle(string $exprType, array $args): Expression
    {
        $nullHandling = $this->parseOnHandling('NULL', 'NULL', 'ABSENT');
        $returnType = $this->matchTextSeq('RETURNING') ? $this->parseType() : false;
        $strict = $this->matchTextSeq('STRICT');

        return $this->expression(new $exprType(['null_handling' => $nullHandling, 'return_type' => $returnType, 'strict' => $strict] + $args));
    }

    protected function parseJsonExists(): JSONExists
    {
        $this_ = $this->parseFormatJson($this->parseBitwise());
        $this->match(TokenType::COMMA);
        $path = $this->dialect->toJsonPath($this->parseBitwise());
        $passing = $this->matchTextSeq('PASSING')
            ? $this->parseCsv(fn (): ?Expression => $this->parseAlias($this->parseBitwise()))
            : false;

        return $this->expression(new JSONExists([
            'this' => $this_,
            'path' => $path,
            'passing' => $passing,
            'on_condition' => $this->parseOnCondition(),
        ]));
    }

    // ---------------------------------------------------------------------------------------
    // PROPERTY_PARSERS / QUERY_MODIFIER_PARSERS / TYPE_LITERAL_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function propertyGlobal(): ?Expression
    {
        return $this->matchTextSeq('TEMPORARY') ? $this->expression(new TemporaryProperty(['this' => 'GLOBAL'])) : null;
    }

    protected function propertyPrivate(): ?Expression
    {
        return $this->matchTextSeq('TEMPORARY') ? $this->expression(new TemporaryProperty(['this' => 'PRIVATE'])) : null;
    }

    protected function propertyForce(): Expression
    {
        return $this->expression(new ForceProperty);
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierOrderSiblingsBy(): array
    {
        return ['order', $this->parseOrder()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierWith(): array
    {
        $restriction = $this->parseQueryRestrictions();

        return ['options', $restriction === null ? [] : [$restriction]];
    }

    protected function typeLiteralDate(?Expression $this_, DataType $dataType): Expression
    {
        return $this->expression(new DateStrToDate(['this' => $this_]));
    }

    protected function typeLiteralTimestamp(?Expression $this_, DataType $dataType): Expression
    {
        // https://docs.oracle.com/en/database/oracle/oracle-database/19/refrn/NLS_TIMESTAMP_FORMAT.html
        return $this->buildToTimestamp([$this_, '"%Y-%m-%d %H:%M:%S.%f"']);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseHintFunctionCall(): ?Expression
    {
        if ($this->curr === 0 || $this->next !== TokenType::L_PAREN) {
            return null;
        }

        $name = $this->currText();
        $this->advance(2);
        $args = $this->parseHintArgs();
        $this_ = $this->expression(new Anonymous(['this' => $name, 'expressions' => $args]));
        $this->matchRParen($this_);

        return $this_;
    }

    /**
     * @return list<Expression>
     */
    private function parseHintArgs(): array
    {
        $args = [];

        while (($result = $this->parseVar()) !== null) {
            $args[] = $result;
        }

        return $args;
    }

    private function parseQueryRestrictions(): ?Expression
    {
        $kind = $this->parseVarFromOptions(self::QUERY_RESTRICTIONS, raiseUnmatched: false);

        if ($kind === null) {
            return null;
        }

        return $this->expression(new QueryOption([
            'this' => $kind,
            'expression' => $this->match(TokenType::CONSTRAINT) ? $this->parseField() : false,
        ]));
    }

    protected function parseIntoClause(): ?Into
    {
        // https://docs.oracle.com/en/database/oracle/oracle-database/19/lnpls/SELECT-INTO-statement.html
        $bulkCollect = $this->match(TokenType::BULK_COLLECT_INTO);

        if (! $bulkCollect && ! $this->match(TokenType::INTO)) {
            return null;
        }

        $index = $this->index;
        $expressions = $this->parseExpressions();

        if (count($expressions) === 1) {
            $this->retreat($index);
            $this->match(TokenType::TABLE);

            return $this->expression(new Into(['this' => $this->parseTable(schema: true), 'bulk_collect' => $bulkCollect]));
        }

        return $this->expression(new Into(['bulk_collect' => $bulkCollect, 'expressions' => $expressions]));
    }

    protected function parseConnectWithPrior(): ?Expression
    {
        return $this->parseAssignment();
    }

    protected function parseColumnOps(?Expression $this_): ?Expression
    {
        $this_ = parent::parseColumnOps($this_);

        if ($this_ === null) {
            return null;
        }

        $index = $this->index;

        // https://docs.oracle.com/en/database/oracle/oracle-database/26/sqlrf/Interval-Exprs.html
        $intervalSpan = $this->tryParse(fn (): Interval => $this->parseIntervalSpan($this_));

        if ($intervalSpan instanceof Interval && ($intervalSpan->args['unit'] ?? null) instanceof IntervalSpan) {
            return $intervalSpan;
        }

        $this->retreat($index);

        return $this_;
    }

    protected function parseInsertTable(): ?Expression
    {
        // Oracle doesn't use AS for an INSERT INTO alias; parse the table parts without the schema
        // so the alias isn't read as its column list
        $this_ = $this->parseTableParts(schema: true);

        if ($this_ instanceof Table) {
            $aliasName = $this->parseIdVar(anyToken: false);

            if ($aliasName !== null) {
                $this_->set('alias', new TableAlias(['this' => $aliasName]));
            }

            $this_->set('partition', $this->parsePartition());

            // Now parse the schema (column list) if present
            return $this->parseSchema($this_);
        }

        return $this_;
    }
}
