<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\DuckDB;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\AggFunc;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\FirstValue;
use Kwasii\LaravelSqlParser\Expressions\Having;
use Kwasii\LaravelSqlParser\Expressions\Lag;
use Kwasii\LaravelSqlParser\Expressions\LastValue;
use Kwasii\LaravelSqlParser\Expressions\Lead;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Neg;
use Kwasii\LaravelSqlParser\Expressions\NthValue;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\Seq1;
use Kwasii\LaravelSqlParser\Expressions\Seq2;
use Kwasii\LaravelSqlParser\Expressions\Seq4;
use Kwasii\LaravelSqlParser\Expressions\Seq8;
use Kwasii\LaravelSqlParser\Expressions\Where;

/**
 * Module-level constants and SQL templates of sqlglot generators/duckdb.py, shared by the
 * DuckDB generator's traits.
 */
final class DuckDBGeneratorSupport
{
    /** _CONNECT_BY_ARGS_TO_SKIP */
    public const array CONNECT_BY_ARGS_TO_SKIP = ['connect' => true, 'where' => true, 'from_' => true, 'with_' => true, 'expressions' => true];

    /** Time zones in timestamps of the form [+|-]TT[:tt], after the time part. */
    public const string TIMEZONE_PATTERN = '/:\d{2}.*?[+\-]\d{2}(?::\d{2})?/';

    /** Characters escaped when building regular expressions for INITCAP. */
    public const array REGEX_ESCAPE_REPLACEMENTS = [
        '\\' => '\\\\',
        '-' => '\-',
        '^' => '\^',
        '[' => '\[',
        ']' => '\]',
    ];

    /** Used in RANDSTR transpilation. */
    public const string RANDSTR_CHAR_POOL = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public const int RANDSTR_SEED = 123456;

    /** Whitespace control characters that DuckDB must process with CHR(<val>) calls. */
    public const array WS_CONTROL_CHARS_TO_DUCK = [
        "\u{000b}" => 11,
        "\u{001c}" => 28,
        "\u{001d}" => 29,
        "\u{001e}" => 30,
        "\u{001f}" => 31,
    ];

    /**
     * Snowflake collation defaults: DuckDB behaves the same way, so they're safe to drop ("as" is
     * also a reserved keyword in DuckDB, so it couldn't be passed through).
     */
    public const array SNOWFLAKE_COLLATION_DEFAULTS = ['cs' => true, 'as' => true, 'ps' => true];

    public const array SNOWFLAKE_COLLATION_UNSUPPORTED = [
        'ci' => true, 'ai' => true, 'upper' => true, 'lower' => true, 'utf8' => true, 'bin' => true,
        'pi' => true, 'fl' => true, 'fu' => true, 'trim' => true, 'ltrim' => true, 'rtrim' => true,
    ];

    /** Window functions that support IGNORE / RESPECT NULLS in DuckDB. */
    public const array IGNORE_RESPECT_NULLS_WINDOW_FUNCTIONS = [FirstValue::class, Lag::class, LastValue::class, Lead::class, NthValue::class];

    /** Where SEQ functions can't be turned into a ROW_NUMBER based expression. */
    public const array SEQ_RESTRICTED = [Where::class, Having::class, AggFunc::class, Order::class, Select::class];

    /** The byte width of each SEQ function (SEQ1 = 1 byte, SEQ2 = 2, ...). */
    public const array SEQ_BYTE_WIDTH = [Seq1::class => 1, Seq2::class => 2, Seq4::class => 4, Seq8::class => 8];

    public static function maxBitPosition(): Literal|Neg
    {
        return Literal::number(32768);
    }

    /** _SEQ_BASE: a fresh copy of (ROW_NUMBER() OVER (ORDER BY 1) - 1). */
    public static function seqBase(): Expression
    {
        return Dialect::get()->parseOne('(ROW_NUMBER() OVER (ORDER BY 1) - 1)');
    }

    /** _SEQ_UNSIGNED: a fresh copy of :base % :max_val. */
    public static function seqUnsigned(): Expression
    {
        return Dialect::get()->parseOne(':base % :max_val');
    }

    /** _SEQ_SIGNED: a fresh copy of the signed wrap-around template. */
    public static function seqSigned(): Expression
    {
        return Dialect::get()->parseOne('(CASE WHEN :base % :max_val >= :half THEN :base % :max_val - :max_val ELSE :base % :max_val END)');
    }
}
