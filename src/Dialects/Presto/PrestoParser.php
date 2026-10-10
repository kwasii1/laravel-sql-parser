<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Presto;

use Kwasii\LaravelSqlParser\Expressions\ApproxQuantile;
use Kwasii\LaravelSqlParser\Expressions\BitwiseNot;
use Kwasii\LaravelSqlParser\Expressions\Bracket;
use Kwasii\LaravelSqlParser\Expressions\DateAdd;
use Kwasii\LaravelSqlParser\Expressions\DateDiff;
use Kwasii\LaravelSqlParser\Expressions\Decode;
use Kwasii\LaravelSqlParser\Expressions\Encode;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\JSONFormat;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\RegexpReplace;
use Kwasii\LaravelSqlParser\Expressions\SHA2Digest;
use Kwasii\LaravelSqlParser\Expressions\StrPosition;
use Kwasii\LaravelSqlParser\Expressions\TimeToStr;
use Kwasii\LaravelSqlParser\Expressions\UnixToTime;
use Kwasii\LaravelSqlParser\Parser\Parser;

/**
 * Presto parser (sqlglot parsers/presto.py).
 */
class PrestoParser extends Parser
{
    public const string TABLES = PrestoParserTables::class;

    /**
     * @param  list<mixed>  $args
     */
    protected function buildApproxPercentile(array $args): Expression
    {
        if (count($args) === 4) {
            return new ApproxQuantile(['this' => $args[0], 'weight' => $args[1], 'quantile' => $args[2], 'accuracy' => $args[3]]);
        }

        if (count($args) === 3) {
            return new ApproxQuantile(['this' => $args[0], 'quantile' => $args[1], 'accuracy' => $args[2]]);
        }

        return ApproxQuantile::fromArgList($args);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildFromUnixtime(array $args): Expression
    {
        if (count($args) === 3) {
            return new UnixToTime(['this' => $args[0], 'hours' => $args[1], 'minutes' => $args[2]]);
        }

        if (count($args) === 2) {
            return new UnixToTime(['this' => $args[0], 'zone' => $args[1]]);
        }

        return UnixToTime::fromArgList($args);
    }

    /**
     * Teradata-style TO_CHAR format (sqlglot _build_to_char).
     *
     * @param  list<mixed>  $args
     */
    protected function buildToChar(array $args): Expression
    {
        $fmt = $args[1] ?? null;

        if ($fmt instanceof Literal) {
            // We uppercase this to match Teradata's format mapping keys
            $fmt->set('this', strtoupper($fmt->name()));
        }

        // We use "teradata" on purpose here, because the time formats are different in Presto
        return $this->buildFormattedTime($args, TimeToStr::class, 'teradata');
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildBitwiseNot(array $args): Expression
    {
        return new BitwiseNot(['this' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDateAdd(array $args): Expression
    {
        return new DateAdd(['this' => $args[2] ?? null, 'expression' => $args[1] ?? null, 'unit' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDateDiff(array $args): Expression
    {
        return new DateDiff(['this' => $args[2] ?? null, 'expression' => $args[1] ?? null, 'unit' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildElementAt(array $args): Expression
    {
        return new Bracket(['this' => $args[0] ?? null, 'expressions' => [$args[1] ?? null], 'offset' => 1, 'safe' => true]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildFromUtf8(array $args): Expression
    {
        return new Decode(['this' => $args[0] ?? null, 'replace' => $args[1] ?? null, 'charset' => Literal::string('utf-8')]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildJsonFormat(array $args): Expression
    {
        return new JSONFormat(['this' => $args[0] ?? null, 'options' => $args[1] ?? null, 'is_json' => true]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildRegexpReplace(array $args): Expression
    {
        $replacement = $args[2] ?? null;

        return new RegexpReplace([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'replacement' => $replacement instanceof Expression ? $replacement : Literal::string(''),
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildStrpos(array $args): Expression
    {
        return new StrPosition(['this' => $args[0] ?? null, 'substr' => $args[1] ?? null, 'occurrence' => $args[2] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildToUtf8(array $args): Expression
    {
        return new Encode(['this' => $args[0] ?? null, 'charset' => Literal::string('utf-8')]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSha256(array $args): Expression
    {
        return new SHA2Digest(['this' => $args[0] ?? null, 'length' => Literal::number(256)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSha512(array $args): Expression
    {
        return new SHA2Digest(['this' => $args[0] ?? null, 'length' => Literal::number(512)]);
    }
}
