<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Dremio;

use Kwasii\LaravelSqlParser\Expressions\AtTimeZone;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Concat;
use Kwasii\LaravelSqlParser\Expressions\CurrentTimestamp;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\Date;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\RegexpSplit;
use Kwasii\LaravelSqlParser\Expressions\ToChar;
use Kwasii\LaravelSqlParser\Parser\Parser;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Dremio parser (sqlglot parsers/dremio.py).
 */
class DremioParser extends Parser
{
    public const string TABLES = DremioParserTables::class;

    protected const array VERSION_PHRASES = [
        ...parent::VERSION_PHRASES,
        [['AT', 'TIMESTAMP'], 'TIMESTAMP'],
        [['AT', 'SNAPSHOT'], 'VERSION'],
    ];

    /**
     * sqlglot to_char_is_numeric_handler.
     *
     * @param  list<mixed>  $args
     */
    protected function toCharIsNumericHandler(array $args): Expression
    {
        $expression = $this->buildTimetostrOrTochar($args);
        $fmt = $args[1] ?? null;

        if ($fmt instanceof Expression && $expression instanceof ToChar && $fmt->isString() && str_contains($fmt->name(), '#')) {
            // Only mark as numeric if format is a literal containing #
            $expression->set('is_numeric', true);
        }

        return $expression;
    }

    /**
     * sqlglot build_date_delta_with_cast_interval.
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $expressionClass
     * @param  string|array<int|string, mixed>  $fallbackBuilder
     */
    protected function buildDateDeltaWithCastInterval(array $args, string $expressionClass, string|array $fallbackBuilder): Expression
    {
        if (count($args) === 2) {
            [$dateArg, $intervalArg] = $args;

            if ($intervalArg instanceof Cast) {
                $to = $intervalArg->args['to'] ?? null;

                if ($to instanceof DataType && ($to->args['this'] ?? null) instanceof Interval) {
                    return new $expressionClass([
                        'this' => $dateArg,
                        'expression' => $intervalArg->args['this'] ?? null,
                        'unit' => $to->args['this']->args['unit'] ?? null,
                    ]);
                }
            }

            return new $expressionClass(['this' => $dateArg, 'expression' => $intervalArg]);
        }

        $result = $this->callHandler($fallbackBuilder, $args);

        return $result instanceof Expression ? $result : new $expressionClass([]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildRegexpSplit(array $args): Expression
    {
        return new RegexpSplit([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'mode' => $args[2] ?? null,
            'limit' => $args[3] ?? null,
        ]);
    }

    /**
     * sqlglot datetype_handler.
     *
     * @param  list<mixed>  $args
     */
    protected function datetypeHandler(array $args): Expression
    {
        [$year, $month, $day] = $args + [null, null, null];

        $allInts = true;

        foreach ([$year, $month, $day] as $arg) {
            if (! ($arg instanceof Literal && $arg->isInt())) {
                $allInts = false;
            }
        }

        if ($allInts && $year instanceof Literal && $month instanceof Literal && $day instanceof Literal) {
            $dateStr = sprintf('%04d-%02d-%02d', (int) $year->name(), (int) $month->name(), (int) $day->name());

            return new Date(['this' => Literal::string($dateStr)]);
        }

        return new Cast([
            'this' => new Concat([
                'expressions' => [$year, Literal::string('-'), $month, Literal::string('-'), $day],
                'coalesce' => $this->dialect->settings['CONCAT_COALESCE'] ?? false,
            ]),
            'to' => DataType::build(DType::DATE),
        ]);
    }

    protected function parseCurrentDateUtc(): Expression
    {
        if ($this->match(TokenType::L_PAREN)) {
            $this->matchRParen();
        }

        return new Cast([
            'this' => new AtTimeZone(['this' => new CurrentTimestamp([]), 'zone' => Literal::string('UTC')]),
            'to' => DataType::build(DType::DATE),
        ]);
    }
}
