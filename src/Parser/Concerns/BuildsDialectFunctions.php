<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Parser\Concerns;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Errors\ParseError;
use Kwasii\LaravelSqlParser\Expressions\Anonymous;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DateTrunc;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\Is;
use Kwasii\LaravelSqlParser\Expressions\JSONBContainsTopKey;
use Kwasii\LaravelSqlParser\Expressions\JSONBExtract;
use Kwasii\LaravelSqlParser\Expressions\JSONBExtractScalar;
use Kwasii\LaravelSqlParser\Expressions\JSONExtract;
use Kwasii\LaravelSqlParser\Expressions\JSONExtractScalar;
use Kwasii\LaravelSqlParser\Expressions\JSONPath;
use Kwasii\LaravelSqlParser\Expressions\JSONPathKey;
use Kwasii\LaravelSqlParser\Expressions\JSONPathRoot;
use Kwasii\LaravelSqlParser\Expressions\JSONPathSubscript;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\NullExpr;
use Kwasii\LaravelSqlParser\Expressions\Paren;
use Kwasii\LaravelSqlParser\Expressions\RegexpExtract;
use Kwasii\LaravelSqlParser\Expressions\Replace;
use Kwasii\LaravelSqlParser\Expressions\TimestampTrunc;
use Kwasii\LaravelSqlParser\Expressions\TimeToStr;
use Kwasii\LaravelSqlParser\Expressions\ToChar;
use Kwasii\LaravelSqlParser\Expressions\Trunc;

/**
 * Function builders shared by dialects (sqlglot dialects/dialect.py build_* helpers). FUNCTIONS
 * entries reference them as [method, ...closure variables]; they receive the argument list.
 */
trait BuildsDialectFunctions
{
    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $expClass
     * @param  array<string, string>|null  $unitMapping
     */
    protected function buildDateDelta(array $args, string $expClass, ?array $unitMapping = null, ?string $defaultUnit = 'DAY', bool $supportsTimezone = false): Expression
    {
        $unitBased = count($args) >= 3;
        $hasTimezone = count($args) === 4;
        $this_ = $unitBased ? $args[2] : ($args[0] ?? null);
        $unit = null;

        if ($unitBased || ($defaultUnit !== null && $defaultUnit !== '')) {
            $unit = $unitBased ? $args[0] : Literal::string((string) $defaultUnit);

            if ($unitMapping !== null && $unitMapping !== [] && $unit instanceof Expression) {
                $unit = Builder::var($unitMapping[strtolower($unit->name())] ?? $unit->name());
            }
        }

        $expression = new $expClass(['this' => $this_, 'expression' => $args[1] ?? null, 'unit' => $unit]);

        if ($supportsTimezone && $hasTimezone) {
            $expression->set('zone', $args[count($args) - 1]);
        }

        return $expression;
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $expressionClass
     */
    protected function buildDateDeltaWithInterval(array $args, string $expressionClass, ?string $defaultUnit = null): ?Expression
    {
        if (count($args) < 2) {
            return null;
        }

        $interval = $args[1];

        if (! $interval instanceof Interval) {
            if ($defaultUnit === null) {
                throw new ParseError("INTERVAL expression expected but got '".(is_scalar($interval) || $interval instanceof Expression ? (string) $interval : '')."'");
            }

            return new $expressionClass(['this' => $args[0], 'expression' => $interval, 'unit' => Literal::string($defaultUnit)]);
        }

        return new $expressionClass(['this' => $args[0], 'expression' => $interval->args['this'] ?? null, 'unit' => Builder::unitToStr($interval)]);
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $expClass
     */
    protected function buildFormattedTime(array $args, string $expClass, ?string $dialectOverride = null, bool|string|null $default = null): Expression
    {
        $targetDialect = $dialectOverride !== null ? Dialect::get($dialectOverride) : $this->dialect;
        $fmt = $args[1] ?? null;

        // A quoted format string, e.g. Oracle's TIMESTAMP literal default
        if (is_string($fmt)) {
            return new $expClass(['this' => $args[0] ?? null, 'format' => $targetDialect->formatTime($fmt)]);
        }

        if (! $fmt instanceof Expression) {
            $fmt = $default === true ? $targetDialect->settings['TIME_FORMAT'] : ($default !== false && $default !== '' ? $default : null);
        }

        return new $expClass(['this' => $args[0] ?? null, 'format' => $targetDialect->formatTime($fmt)]);
    }

    /**
     * sqlglot build_timetostr_or_tochar: TO_CHAR(<temporal>, fmt) formats a time, any other TO_CHAR
     * converts to text.
     *
     * @param  list<mixed>  $args
     */
    protected function buildTimetostrOrTochar(array $args): Expression
    {
        if (count($args) === 2 && $args[0] instanceof Expression) {
            if ($args[0]->type() === null) {
                $this->dialect->annotateTypes($args[0]);
            }

            if ($args[0]->isType(...DataType::TEMPORAL_TYPES)) {
                return $this->buildFormattedTime($args, TimeToStr::class, default: true);
            }
        }

        return ToChar::fromArgList($args);
    }

    /**
     * sqlglot build_trunc, for dialects with an overloaded TRUNC (Oracle, Snowflake, ...): the
     * argument types tell date truncation from numeric truncation; TRUNC stays an anonymous
     * function when they can't.
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
        $this_ = $args[0] ?? null;
        $second = $args[1] ?? null;

        foreach ([$this_, $second] as $arg) {
            if ($arg instanceof Expression && $arg->type() === null) {
                $this->dialect->annotateTypes($arg);
            }
        }

        $thisExpr = $this_ instanceof Expression ? $this_ : null;
        $secondExpr = $second instanceof Expression ? $second : null;

        // Date truncation
        if (
            ($thisExpr !== null && $thisExpr->isType(...DataType::TEMPORAL_TYPES) && ($secondExpr !== null || $defaultDateTruncUnit !== null))
            || ($secondExpr !== null && $secondExpr->isType(...DataType::TEXT_TYPES))
        ) {
            return new DateTrunc([
                'this' => $thisExpr,
                'unit' => $secondExpr ?? Literal::string((string) $defaultDateTruncUnit),
                'unabbreviate' => $dateTruncUnabbreviate,
            ]);
        }

        // Numeric truncation
        if (
            ($thisExpr !== null && $thisExpr->isType(...DataType::NUMERIC_TYPES))
            || ($secondExpr !== null && $secondExpr->isType(...DataType::NUMERIC_TYPES))
            || (! $dateTruncRequiresPart && $secondExpr === null)
        ) {
            return new Trunc(['this' => $thisExpr, 'decimals' => $secondExpr, 'fractions_supported' => $fractionsSupported]);
        }

        return new Anonymous(['this' => 'TRUNC', 'expressions' => $args]);
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildJsonExtractPath(array $args, string $exprType, bool $zeroBasedIndexing = true, bool $arrowReqJsonType = false, ?string $jsonType = null): Expression
    {
        $segments = [new JSONPathRoot];

        foreach (array_slice($args, 1) as $arg) {
            if (! $arg instanceof Literal) {
                $rest = array_slice($args, 2);

                return new $exprType(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'expressions' => $rest !== [] ? $rest : null]);
            }

            $text = $arg->name();

            if (preg_match('/^[+-]?\d+$/', trim($text)) === 1 && (! $arrowReqJsonType || ! $arg->isString())) {
                $index = (int) $text;
                $segments[] = new JSONPathSubscript(['this' => $zeroBasedIndexing ? $index : $index - 1]);
            } else {
                $segments[] = new JSONPathKey(['this' => $text]);
            }
        }

        $kwargs = ['this' => $args[0] ?? null, 'expression' => new JSONPath(['expressions' => $segments])];

        if (! is_a($exprType, JSONBExtract::class, true) && ! is_a($exprType, JSONBExtractScalar::class, true)) {
            $kwargs['only_json_types'] = $arrowReqJsonType;
        }

        if ($jsonType !== null) {
            $kwargs['json_type'] = $jsonType;
        }

        return new $exprType($kwargs);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTimestampTrunc(array $args): Expression
    {
        return new TimestampTrunc(['this' => $args[1] ?? null, 'unit' => $args[0] ?? null, 'zone' => $args[2] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function isnullToIsNull(array $args): Expression
    {
        return new Paren(['this' => new Is(['this' => $args[0] ?? null, 'expression' => new NullExpr])]);
    }

    // sqlglot parser.py module-level builders for the JSON `->` / `->>` / `#>` / `#>>` / `?`
    // operators, used by dialects' JSON_OPERATORS and CONCAT_OPERATORS

    protected function buildJsonExtractHandler(?Expression $this_, ?Expression $path): Expression
    {
        return $this->expression(new JSONExtract([
            'this' => $this_,
            'expression' => $this->dialect->toJsonPath($path),
            'only_json_types' => $this->t['JSON_ARROWS_REQUIRE_JSON_TYPE'],
        ]));
    }

    protected function buildJsonExtractScalar(?Expression $this_, ?Expression $path): Expression
    {
        return $this->expression(new JSONExtractScalar([
            'this' => $this_,
            'expression' => $this->dialect->toJsonPath($path),
            'only_json_types' => $this->t['JSON_ARROWS_REQUIRE_JSON_TYPE'],
            'scalar_only' => $this->dialect->settings['JSON_EXTRACT_SCALAR_SCALAR_ONLY'],
        ]));
    }

    protected function buildJsonbExtract(?Expression $this_, ?Expression $path): Expression
    {
        return $this->expression(new JSONBExtract(['this' => $this_, 'expression' => $path]));
    }

    protected function buildJsonbExtractScalar(?Expression $this_, ?Expression $path): Expression
    {
        return $this->expression(new JSONBExtractScalar(['this' => $this_, 'expression' => $path]));
    }

    protected function buildJsonbContainsTopKey(?Expression $this_, ?Expression $key): Expression
    {
        return $this->expression(new JSONBContainsTopKey(['this' => $this_, 'expression' => $key]));
    }

    /**
     * sqlglot map_date_part for the parser's dialect.
     */
    protected function mapDatePart(?Expression $part): ?Expression
    {
        if ($part === null || ($part instanceof Column && count($part->parts()) !== 1)) {
            return $part;
        }

        $mapped = $this->dialect->settings['DATE_PART_MAPPING'][strtoupper($part->name())] ?? null;

        if (is_string($mapped) && $mapped !== '') {
            return $part->isString() ? Literal::string($mapped) : Builder::var($mapped);
        }

        return $part;
    }

    /**
     * sqlglot binary_from_function(expr_type): f(a, b) as a binary node.
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function binaryFromFunction(array $args, string $exprType): Expression
    {
        return new $exprType(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null]);
    }

    /**
     * sqlglot build_default_decimal_type(precision, scale), a TYPE_CONVERTERS entry: a bare DECIMAL
     * gets the dialect's default precision and scale.
     */
    protected function buildDefaultDecimalType(DataType $dtype, ?int $precision = null, ?int $scale = null): DataType
    {
        if ($dtype->argList('expressions') !== [] || $precision === null) {
            return $dtype;
        }

        $params = $scale !== null ? "{$precision}, {$scale}" : (string) $precision;

        return DataType::fromStr("DECIMAL({$params})");
    }

    /**
     * sqlglot build_replace_with_optional_replacement: REPLACE(s, from [, to = '']).
     *
     * @param  list<mixed>  $args
     */
    protected function buildReplaceWithOptionalReplacement(array $args): Expression
    {
        return new Replace(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'replacement' => $args[2] ?? Literal::string('')]);
    }

    /**
     * sqlglot date_trunc_to_time: DATE_TRUNC(unit, x), a DateTrunc for a DATE cast and a
     * TimestampTrunc otherwise.
     *
     * @param  list<mixed>  $args
     */
    protected function dateTruncToTime(array $args): Expression
    {
        $unit = $args[0] ?? null;
        $this_ = $args[1] ?? null;

        if ($this_ instanceof Cast && $this_->isType(DType::DATE)) {
            return new DateTrunc(['unit' => $unit, 'this' => $this_]);
        }

        return new TimestampTrunc(['this' => $this_, 'unit' => $unit]);
    }

    /**
     * sqlglot build_regexp_extract(expr_type). null_if_pos_overflow says whether a position past
     * the string's end returns NULL (or an empty string); REGEXP_EXTRACT_ALL always returns an
     * empty array then.
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>|null  $exprType
     */
    protected function buildRegexpExtract(array $args, ?string $exprType = null): Expression
    {
        $exprType ??= RegexpExtract::class;
        $kwargs = [
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'group' => $args[2] ?? Literal::number((int) $this->dialect->settings['REGEXP_EXTRACT_DEFAULT_GROUP']),
            'parameters' => $args[3] ?? null,
        ];

        if ($exprType === RegexpExtract::class) {
            $kwargs['null_if_pos_overflow'] = $this->dialect->settings['REGEXP_EXTRACT_POSITION_OVERFLOW_RETURNS_NULL'];
        }

        return new $exprType($kwargs);
    }
}
