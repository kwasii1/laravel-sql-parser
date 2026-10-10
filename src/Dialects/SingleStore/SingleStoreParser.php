<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\SingleStore;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySqlParser;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\ApproxQuantile;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateBin;
use LaravelSqlParser\LaravelSqlParser\Expressions\DayOfWeek;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONArray;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONArrayAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONArrayContains;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExists;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONKeys;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONObject;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPath;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mod;
use LaravelSqlParser\LaravelSqlParser\Expressions\Reduce;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpExtractAll;
use LaravelSqlParser\LaravelSqlParser\Expressions\RenameColumn;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampDiff;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeToStr;
use LaravelSqlParser\LaravelSqlParser\Expressions\TryCast;

/**
 * SingleStore parser (sqlglot parsers/singlestore.py).
 */
class SingleStoreParser extends MySqlParser
{
    public const string TABLES = SingleStoreParserTables::class;

    private function mysqlFormatTime(mixed $format): ?Expression
    {
        return Dialect::get('mysql')->formatTime($format instanceof Expression || is_string($format) ? $format : null);
    }

    /**
     * TimeToStr(CAST(arg AS TIME(6)), fmt) cast to INT, for HOUR / MINUTE / SECOND / MICROSECOND.
     */
    private function timePart(mixed $arg, string $format): Expression
    {
        return new Cast([
            'this' => new TimeToStr(['this' => SingleStore::castToTime6($arg), 'format' => $this->mysqlFormatTime(Literal::string($format))]),
            'to' => new DataType(['this' => DType::INT]),
        ]);
    }

    /**
     * sqlglot's build_json_extract_path truncates the call's args (`del args[2:]`) once it has
     * folded the literal path segments into a JSONPath, so the arg-count validation passes.
     *
     * @param  string|list<mixed>  $function
     * @param  list<mixed>  $args
     */
    protected function callFunctionBuilder(string|array $function, array &$args): Expression
    {
        $result = parent::callFunctionBuilder($function, $args);

        if (is_array($function) && ($function[0] ?? null) === 'buildJsonExtractPath' && ($result->args['expression'] ?? null) instanceof JSONPath) {
            $args = array_slice($args, 0, 2);
        }

        return $result;
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS builders
    // ---------------------------------------------------------------------------------------

    /** @param list<mixed> $args */
    protected function buildTimeFormat(array $args): Expression
    {
        return new TimeToStr(['this' => SingleStore::castToTime6($args[0] ?? null), 'format' => $this->mysqlFormatTime($args[1] ?? null)]);
    }

    /** @param list<mixed> $args */
    protected function buildHour(array $args): Expression
    {
        return $this->timePart($args[0] ?? null, '%k');
    }

    /** @param list<mixed> $args */
    protected function buildMicrosecond(array $args): Expression
    {
        return $this->timePart($args[0] ?? null, '%f');
    }

    /** @param list<mixed> $args */
    protected function buildSecond(array $args): Expression
    {
        return $this->timePart($args[0] ?? null, '%s');
    }

    /** @param list<mixed> $args */
    protected function buildMinute(array $args): Expression
    {
        return $this->timePart($args[0] ?? null, '%i');
    }

    /** @param list<mixed> $args */
    protected function buildMonthname(array $args): Expression
    {
        return new TimeToStr(['this' => $args[0] ?? null, 'format' => $this->mysqlFormatTime(Literal::string('%M'))]);
    }

    /** @param list<mixed> $args */
    protected function buildDayname(array $args): Expression
    {
        return new TimeToStr(['this' => $args[0] ?? null, 'format' => $this->mysqlFormatTime(Literal::string('%W'))]);
    }

    /** @param list<mixed> $args */
    protected function buildWeekday(array $args): Expression
    {
        $sum = Builder::binop(Add::class, new DayOfWeek(['this' => $args[0] ?? null]), 5);

        return Builder::binop(Mod::class, Builder::paren($sum, copy: false), 7);
    }

    /** @param list<mixed> $args */
    protected function buildTimeBucket(array $args): Expression
    {
        return new DateBin(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'origin' => $args[2] ?? null]);
    }

    /**
     * JSON_ARRAY_CONTAINS_STRING / _DOUBLE / _JSON.
     *
     * @param  list<mixed>  $args
     */
    protected function buildJsonArrayContainsString(array $args, string $jsonType = 'STRING'): Expression
    {
        return new JSONArrayContains(['this' => $args[1] ?? null, 'expression' => $args[0] ?? null, 'json_type' => $jsonType]);
    }

    /** @param list<mixed> $args */
    protected function buildJsonArrayContainsDouble(array $args): Expression
    {
        return $this->buildJsonArrayContainsString($args, 'DOUBLE');
    }

    /** @param list<mixed> $args */
    protected function buildJsonArrayContainsJson(array $args): Expression
    {
        return $this->buildJsonArrayContainsString($args, 'JSON');
    }

    /** @param list<mixed> $args */
    protected function buildJsonKeys(array $args): Expression
    {
        return new JSONKeys(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1)]);
    }

    /** @param list<mixed> $args */
    protected function buildJsonBuildArray(array $args): Expression
    {
        return new JSONArray(['expressions' => $args]);
    }

    /** @param list<mixed> $args */
    protected function buildJsonBuildObject(array $args): Expression
    {
        return new JSONObject(['expressions' => $args]);
    }

    /** @param list<mixed> $args */
    protected function buildTimestampdiff(array $args): Expression
    {
        return new TimestampDiff(['this' => $args[2] ?? null, 'expression' => $args[1] ?? null, 'unit' => $args[0] ?? null]);
    }

    /** @param list<mixed> $args */
    protected function buildApproxPercentile(array $args): Expression
    {
        return new ApproxQuantile(['this' => $args[0] ?? null, 'quantile' => $args[1] ?? null, 'error_tolerance' => $args[2] ?? null]);
    }

    /** @param list<mixed> $args */
    protected function buildRegexpMatch(array $args): Expression
    {
        return new RegexpExtractAll(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'parameters' => $args[2] ?? null]);
    }

    /** @param list<mixed> $args */
    protected function buildRegexpSubstr(array $args): Expression
    {
        return new RegexpExtract([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'position' => $args[2] ?? null,
            'occurrence' => $args[3] ?? null,
            'parameters' => $args[4] ?? null,
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildReduce(array $args): Expression
    {
        return new Reduce(['initial' => $args[0] ?? null, 'this' => $args[1] ?? null, 'merge' => $args[2] ?? null]);
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTION_PARSERS / ALTER_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function funcJsonAgg(): Expression
    {
        $this_ = $this->parseTerm();

        return new JSONArrayAgg(['this' => $this_, 'order' => $this->parseOrder()]);
    }

    protected function alterChange(): ?Expression
    {
        $this_ = $this->parseColumn();

        return $this->expression(new RenameColumn(['this' => $this_, 'to' => $this->parseColumn()]));
    }

    // ---------------------------------------------------------------------------------------
    // COLUMN_OPERATORS
    // ---------------------------------------------------------------------------------------

    protected function columnOpColonGt(?Expression $this_, ?Expression $to): Expression
    {
        return $this->expression(new Cast(['this' => $this_, 'to' => $to]));
    }

    protected function columnOpNcolonGt(?Expression $this_, ?Expression $to): Expression
    {
        return $this->expression(new TryCast(['this' => $this_, 'to' => $to]));
    }

    protected function columnOpDcolon(?Expression $this_, ?Expression $to): Expression
    {
        return $this->buildJsonExtractPath([$this_, Literal::string($to?->name() ?? '')], JSONExtract::class);
    }

    protected function columnOpDcolondollar(?Expression $this_, ?Expression $path): Expression
    {
        return $this->buildJsonExtractPath([$this_, Literal::string($path?->name() ?? '')], JSONExtractScalar::class, jsonType: 'STRING');
    }

    protected function columnOpDcolonpercent(?Expression $this_, ?Expression $path): Expression
    {
        return $this->buildJsonExtractPath([$this_, Literal::string($path?->name() ?? '')], JSONExtractScalar::class, jsonType: 'DOUBLE');
    }

    protected function columnOpDcolonqmark(?Expression $this_, ?Expression $path): Expression
    {
        return $this->expression(new JSONExists(['this' => $this_, 'path' => $path?->name() ?? '', 'from_dcolonqmark' => true]));
    }

    /**
     * @param  list<Expression>  $expressions
     * @return list<Expression>
     */
    protected function parseVectorExpressions(array $expressions): array
    {
        $typeName = strtoupper($expressions[1]->name());
        $aliases = $this->dialect->settings['VECTOR_TYPE_ALIASES'];

        if (is_array($aliases) && isset($aliases[$typeName]) && is_string($aliases[$typeName])) {
            $typeName = $aliases[$typeName];
        }

        return [DataType::build($typeName, $this->dialect), $expressions[0]];
    }
}
