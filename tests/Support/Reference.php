<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Tests\Support;

use RuntimeException;

/**
 * Loads the sqlglot reference fixtures in tests/Fixtures/sqlglot.
 */
final class Reference
{
    /** Dialects whose recorded cases are exercised by the test suite. */
    public const array DIALECTS = ['base', 'mysql', 'postgres', 'sqlite', 'tsql', 'redshift', 'oracle', 'clickhouse', 'bigquery', 'snowflake', 'duckdb'];

    /**
     * Dialects whose parser and generator are implemented: their recorded cases are checked end to
     * end, and they're the only read / write targets exercised by validate_all cases.
     */
    public const array GENERATOR_DIALECTS = ['base', 'mysql', 'postgres', 'sqlite', 'tsql', 'redshift', 'oracle', 'clickhouse', 'bigquery', 'snowflake', 'duckdb'];

    /** Marks a validate_all write that sqlglot expects to raise UnsupportedError. */
    public const string UNSUPPORTED = '__UNSUPPORTED__';

    /** @var array<string, mixed> */
    private static array $cache = [];

    /**
     * @return list<array{sql: string, expected: string|null, ast: array<string, mixed>|null, tokens: list<array{0: string, 1: string}>|null}>
     */
    public static function identity(): array
    {
        return self::load('identity.json');
    }

    /**
     * @return list<array{sql: string, expected: string, ast: array<string, mixed>|null}>
     */
    public static function pretty(): array
    {
        return self::load('pretty.json');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function dialectCases(string $dialect): array
    {
        return self::load("dialects/{$dialect}.json")['cases'];
    }

    private static function load(string $file): mixed
    {
        if (! array_key_exists($file, self::$cache)) {
            $contents = file_get_contents(dirname(__DIR__).'/Fixtures/sqlglot/'.$file);

            if ($contents === false) {
                throw new RuntimeException("Missing fixture {$file}");
            }

            self::$cache[$file] = json_decode(str_replace("\r\n", "\n", $contents), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$cache[$file];
    }
}
