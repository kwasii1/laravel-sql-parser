<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Parser\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Errors\ParseError;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONBContainsTopKey;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONBExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONBExtractScalar;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPath;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPathKey;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPathRoot;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPathSubscript;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampTrunc;

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

        if (! $fmt instanceof Expression) {
            $fmt = $default === true ? $targetDialect->settings['TIME_FORMAT'] : ($default !== false && $default !== '' ? $default : null);
        }

        return new $expClass(['this' => $args[0] ?? null, 'format' => $targetDialect->formatTime($fmt)]);
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
}
