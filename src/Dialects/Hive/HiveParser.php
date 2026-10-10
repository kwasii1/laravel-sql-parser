<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Hive;

use LaravelSqlParser\LaravelSqlParser\Expressions\AlterColumn;
use LaravelSqlParser\LaravelSqlParser\Expressions\ApproxQuantile;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ConcatWs;
use LaravelSqlParser\LaravelSqlParser\Expressions\CurrentTimestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataTypeParam;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateDiff;
use LaravelSqlParser\LaravelSqlParser\Expressions\Day;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\FileFormatProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\IgnoreNulls;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Month;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\Parameter;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Quantile;
use LaravelSqlParser\LaravelSqlParser\Expressions\QueryTransform;
use LaravelSqlParser\LaravelSqlParser\Expressions\SerdeProperties;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToMap;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToUnix;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeStrToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeToStr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Transform;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\UsingProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Year;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Apache Hive parser (sqlglot parsers/hive.py).
 */
class HiveParser extends Parser
{
    public const string TABLES = HiveParserTables::class;

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS builders
    // ---------------------------------------------------------------------------------------

    /**
     * sqlglot build_with_ignore_nulls(exp_class).
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $expClass
     */
    protected function buildWithIgnoreNulls(array $args, string $expClass): Expression
    {
        $this_ = new $expClass(['this' => $args[0] ?? null]);
        $second = $args[1] ?? null;

        if ($second instanceof Expression && $second->equals(Builder::true())) {
            return new IgnoreNulls(['this' => $this_]);
        }

        return $this_;
    }

    /** @param list<mixed> $args */
    protected function buildCollectList(array $args): Expression
    {
        return new ArrayAgg(['this' => $args[0] ?? null, 'nulls_excluded' => true]);
    }

    /**
     * DATE_ADD (TsOrDsAdd in days) and DATE_SUB (sqlglot _build_date_add, negated increment).
     *
     * @param  list<mixed>  $args
     */
    protected function buildDateAdd(array $args): Expression
    {
        $expression = $args[1] ?? null;

        if ($this->functionBuilderName === 'DATE_SUB' && $expression instanceof Expression) {
            $expression = Builder::binop(Mul::class, $expression, -1);
        }

        return new TsOrDsAdd(['this' => $args[0] ?? null, 'expression' => $expression, 'unit' => Literal::string('DAY')]);
    }

    /** @param list<mixed> $args */
    protected function buildConcatWs(array $args): Expression
    {
        return new ConcatWs([
            'expressions' => $args,
            'safe' => $this->dialect->settings['STRICT_STRING_CONCAT'] !== true,
            'coalesce' => $this->dialect->settings['CONCAT_WS_COALESCE'],
            'flatten' => true,
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildDateFormat(array $args): Expression
    {
        return $this->buildFormattedTime([new TimeStrToTime(['this' => $args[0] ?? null]), $args[1] ?? null], TimeToStr::class);
    }

    /** @param list<mixed> $args */
    protected function buildDatediff(array $args): Expression
    {
        return new DateDiff([
            'this' => new TsOrDsToDate(['this' => $args[0] ?? null]),
            'expression' => new TsOrDsToDate(['this' => $args[1] ?? null]),
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildDay(array $args): Expression
    {
        return new Day(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /** @param list<mixed> $args */
    protected function buildGetJsonObject(array $args): Expression
    {
        return new JSONExtractScalar(['this' => $args[0] ?? null, 'expression' => $this->dialect->toJsonPath($args[1] ?? null)]);
    }

    /** @param list<mixed> $args */
    protected function buildMonth(array $args): Expression
    {
        return new Month(['this' => TsOrDsToDate::fromArgList($args)]);
    }

    /** @param list<mixed> $args */
    protected function buildYear(array $args): Expression
    {
        return new Year(['this' => TsOrDsToDate::fromArgList($args)]);
    }

    /**
     * Map named_struct('k', v, ...) to exp.Struct.
     *
     * @param  list<mixed>  $args
     */
    protected function buildNamedStruct(array $args): Expression
    {
        $expressions = [];

        for ($i = 0; $i + 1 < count($args); $i += 2) {
            $key = $args[$i];
            $expressions[] = new PropertyEQ([
                'this' => Builder::toIdentifier($key instanceof Expression ? $key->name() : ''),
                'expression' => $args[$i + 1],
            ]);
        }

        return new Struct(['expressions' => $expressions]);
    }

    /** @param list<mixed> $args */
    protected function buildStrToMap(array $args): Expression
    {
        return new StrToMap([
            'this' => $args[0] ?? null,
            'pair_delim' => $args[1] ?? Literal::string(','),
            'key_value_delim' => $args[2] ?? Literal::string(':'),
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildToDate(array $args): Expression
    {
        $expr = $this->buildFormattedTime($args, TsOrDsToDate::class);
        $expr->set('safe', true);

        return $expr;
    }

    /** @param list<mixed> $args */
    protected function buildUnixTimestamp(array $args): Expression
    {
        return $this->buildFormattedTime($args !== [] ? $args : [new CurrentTimestamp], StrToUnix::class, default: true);
    }

    protected function funcPercentile(): Expression
    {
        return $this->parseDistinctArgFunction(Quantile::class);
    }

    protected function funcPercentileApprox(): Expression
    {
        return $this->parseDistinctArgFunction(ApproxQuantile::class);
    }

    protected function propertySerdeproperties(): Expression
    {
        return new SerdeProperties(['expressions' => $this->parseWrappedCsv(fn (): Expression|array|null => $this->parseProperty())]);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $args
     */
    public function buildCast(bool $strict, array $args): Expression
    {
        if (strtoupper($this->prevText()) === 'BD') {
            $this_ = $args['this'] ?? null;
            $name = $this_ instanceof Expression ? $this_->name() : '';
            [$digits, $exponent] = self::decimalTuple($name);
            $precision = strlen($digits);
            $scale = -$exponent;

            if ($this->t['NORMALIZE_DECIMAL_LITERALS'] === true) {
                if (trim($digits, '0') === '') {
                    [$precision, $scale] = [1, 0];
                } else {
                    while ($scale > 0 && $digits[$precision - 1] === '0') {
                        $precision--;
                        $scale--;
                    }
                }
            }

            if ($scale < 0 && $this->t['DECIMAL_LITERALS_ALLOW_NEGATIVE_SCALE'] !== true) {
                $precision -= $scale;
                $scale = 0;
            }

            // Unsuffixed exponent literals can be DOUBLE, so cast from text to avoid rounding.
            $args['this'] = $scale < 0 ? $this_ : Literal::string($name);
            $args['to'] = new DataType([
                'this' => DType::DECIMAL,
                'expressions' => [
                    new DataTypeParam(['this' => Literal::number(max($precision, $scale))]),
                    new DataTypeParam(['this' => Literal::number($scale)]),
                ],
            ]);
        }

        return parent::buildCast($strict, $args);
    }

    /**
     * Python's Decimal(text).as_tuple() digits (as a string) and exponent.
     *
     * @return array{0: string, 1: int}
     */
    protected static function decimalTuple(string $text): array
    {
        $parts = preg_split('/[eE]/', $text, 2);
        $mantissa = ltrim($parts[0] ?? '', '+-');
        $exponent = isset($parts[1]) ? (int) $parts[1] : 0;
        [$int, $frac] = str_contains($mantissa, '.') ? explode('.', $mantissa, 2) : [$mantissa, ''];
        $digits = ltrim($int.$frac, '0');

        return [$digits === '' ? '0' : $digits, $exponent - strlen($frac)];
    }

    protected function parseTransform(): ?Expression
    {
        if (! $this->match(TokenType::L_PAREN, false)) {
            $this->retreat($this->index - 1);

            return null;
        }

        $args = $this->parseWrappedCsv(fn (): ?Expression => $this->parseLambda());
        $rowFormatBefore = $this->parseRowFormat(matchRow: true);

        $recordWriter = null;

        if ($this->matchTextSeq('RECORDWRITER')) {
            $recordWriter = $this->parseString();
        }

        if (! $this->match(TokenType::USING)) {
            return Transform::fromArgList($args);
        }

        $commandScript = $this->parseString();

        $this->match(TokenType::ALIAS);
        $schema = $this->parseSchema();

        $rowFormatAfter = $this->parseRowFormat(matchRow: true);
        $recordReader = null;

        if ($this->matchTextSeq('RECORDREADER')) {
            $recordReader = $this->parseString();
        }

        return $this->expression(new QueryTransform([
            'expressions' => $args,
            'command_script' => $commandScript,
            'schema' => $schema,
            'row_format_before' => $rowFormatBefore,
            'record_writer' => $recordWriter,
            'row_format_after' => $rowFormatAfter,
            'record_reader' => $recordReader,
        ]));
    }

    /**
     * Spark (and most likely Hive) treats casts to CHAR(length) and VARCHAR(length) as casts to
     * STRING in all contexts except for schema definitions.
     */
    protected function parseTypes(
        bool $checkFunc = false,
        bool $schema = false,
        bool $allowIdentifiers = true,
        bool $withCollation = false,
    ): ?Expression {
        $this_ = parent::parseTypes($checkFunc, $schema, $allowIdentifiers, $withCollation);

        if ($this_ !== null && ! $schema) {
            return $this_->transform(static function (Expression $node): Expression {
                if ($node instanceof DataType && $node->isType('char', 'varchar')) {
                    $node->set('this', DType::TEXT);
                    $node->set('expressions', null);
                }

                return $node;
            }, false);
        }

        return $this_;
    }

    protected function parseAlterTableChange(): ?Expression
    {
        $this->match(TokenType::COLUMN);
        $this_ = $this->parseField(anyToken: true);

        if ($this->t['CHANGE_COLUMN_ALTER_SYNTAX'] === true && $this->matchTextSeq('TYPE')) {
            return $this->expression(new AlterColumn(['this' => $this_, 'dtype' => $this->parseTypes(schema: true)]));
        }

        $columnNew = $this->parseField(anyToken: true);
        $dtype = $this->parseTypes(schema: true);

        $comment = $this->match(TokenType::COMMENT) ? $this->parseString() : null;

        if ($this_ === null || $columnNew === null || $dtype === null) {
            $this->raiseError("Expected 'CHANGE COLUMN' to be followed by 'column_name' 'column_name' 'data_type'");
        }

        return $this->expression(new AlterColumn(['this' => $this_, 'rename_to' => $columnNew, 'dtype' => $dtype, 'comment' => $comment]));
    }

    protected function parseUsingProperty(): Expression
    {
        if ($this->matchTexts(['JAR' => true, 'FILE' => true, 'ARCHIVE' => true])) {
            $kind = strtoupper($this->prevText());

            return new UsingProperty(['this' => $this->parseString(), 'kind' => $kind]);
        }

        return $this->parsePropertyAssignment(FileFormatProperty::class);
    }

    /**
     * @return array{0: list<Expression>, 1: ?Expression}
     */
    protected function parsePartitionAndOrder(): array
    {
        $partition = $this->matchSet([TokenType::PARTITION_BY => true, TokenType::DISTRIBUTE_BY => true])
            ? $this->parseCsv(fn (): ?Expression => $this->parseAssignment())
            : [];

        return [$partition, $this->parseOrder(skipOrderToken: $this->match(TokenType::SORT_BY))];
    }

    protected function parseParameter(): Parameter
    {
        $this->match(TokenType::L_BRACE);
        $this_ = $this->parseIdentifier() ?? $this->parsePrimaryOrVar();
        $expression = $this->match(TokenType::COLON) ? ($this->parseIdentifier() ?? $this->parsePrimaryOrVar()) : null;
        $this->match(TokenType::R_BRACE);

        return $this->expression(new Parameter(['this' => $this_, 'expression' => $expression]));
    }

    protected function toPropEq(Expression $expression, int $index): Expression
    {
        if ($expression->isStar()) {
            return $expression;
        }

        $key = $expression instanceof Column ? ($expression->args['this'] ?? null) : Builder::toIdentifier('col'.($index + 1));

        return $this->expression(new PropertyEQ(['this' => $key, 'expression' => $expression]));
    }
}
