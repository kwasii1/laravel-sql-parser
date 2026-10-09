<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySql;
use LaravelSqlParser\LaravelSqlParser\Dialects\Postgres\Postgres;
use LaravelSqlParser\LaravelSqlParser\Dialects\Sqlite\Sqlite;
use LaravelSqlParser\LaravelSqlParser\Errors\ParseError;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Facades\LaravelSqlParser as Sql;

it('transpiles between dialects through the facade', function (): void {
    expect(Sql::transpile('SELECT IFNULL(a, 1) FROM t LIMIT 5', 'mysql', 'tsql'))
        ->toBe(['SELECT TOP 5 COALESCE(a, 1) FROM t']);
});

it('accepts Laravel driver names as dialects', function (string $driver, string $class): void {
    expect(Sql::dialect($driver))->toBeInstanceOf($class);
})->with([
    ['pgsql', Postgres::class],
    ['sqlite', Sqlite::class],
    ['mysql', MySql::class],
]);

it('defaults to the driver of the default database connection', function (): void {
    config(['database.default' => 'testing', 'database.connections.testing.driver' => 'sqlite']);

    expect(Sql::dialect())->toBeInstanceOf(Sqlite::class);
});

it('prefers the configured default dialect', function (): void {
    config(['laravel-sql-parser.default_dialect' => 'postgres']);

    expect(Sql::dialect())->toBeInstanceOf(Postgres::class)
        ->and(Sql::transpile('SELECT a::int'))->toBe(['SELECT CAST(a AS INT)']);
});

it('parses SQL into expression trees', function (): void {
    expect(Sql::parseOne('SELECT a FROM t', 'mysql'))->toBeInstanceOf(Select::class)
        ->and(Sql::parse('SELECT 1; SELECT 2', 'mysql'))->toHaveCount(2)
        ->and(Sql::tokenize('SELECT 1', 'mysql'))->toHaveCount(2);
});

it('formats output when pretty is passed or configured', function (): void {
    expect(Sql::transpile('SELECT a, b FROM t', 'mysql', 'mysql', true))->toBe(["SELECT\n  a,\n  b\nFROM t"]);

    config(['laravel-sql-parser.pretty' => true]);

    expect(Sql::transpile('SELECT a, b FROM t', 'mysql'))->toBe(["SELECT\n  a,\n  b\nFROM t"]);
});

it('throws on invalid SQL by default', function (): void {
    Sql::transpile('SELECT (', 'mysql');
})->throws(ParseError::class);

it('follows the configured error level', function (): void {
    config(['laravel-sql-parser.error_level' => 'ignore']);

    expect(fn () => Sql::parse('SELECT (', 'mysql'))->not->toThrow(ParseError::class);
});

it('keeps bindings in step when a rewrite moves placeholders', function (): void {
    $query = Sql::transpileQuery('SELECT * FROM users WHERE name = ? LIMIT ?', ['Taylor', 5], 'mysql', 'tsql');

    expect($query)->toBe([
        'sql' => 'SELECT TOP (?) * FROM users WHERE name = ?',
        'bindings' => [5, 'Taylor'],
    ]);
});

it('writes positional placeholders for Postgres', function (): void {
    expect(Sql::transpileQuery('SELECT * FROM users WHERE id = ?', [1], 'mysql', 'postgres')['sql'])
        ->toBe('SELECT * FROM users WHERE id = ?');
});

it('leaves question marks in strings and comments alone', function (): void {
    $query = Sql::transpileQuery("SELECT '?' AS q, a FROM t /* ? */ WHERE b = ?", ['x'], 'mysql', 'postgres');

    expect($query['sql'])->toContain("'?' AS q")
        ->and($query['bindings'])->toBe(['x']);
});

it('repeats a binding when a rewrite repeats its placeholder', function (): void {
    $query = Sql::transpileQuery('SELECT * FROM t WHERE a ILIKE ?', ['%x%'], 'postgres', 'mysql');

    expect($query)->toBe([
        'sql' => 'SELECT * FROM t WHERE LOWER(a) LIKE LOWER(?)',
        'bindings' => ['%x%'],
    ]);
});

it('memoizes transpile results in the process when enabled', function (): void {
    config(['laravel-sql-parser.memo.enabled' => true]);
    $parser = app(LaravelSqlParser\LaravelSqlParser\LaravelSqlParser::class);

    $first = $parser->transpile('SELECT IFNULL(a, 1)', 'mysql', 'postgres');

    expect($parser->transpile('SELECT IFNULL(a, 1)', 'mysql', 'postgres'))->toBe($first);
});

it('stores long statements in the cache when enabled', function (): void {
    config([
        'laravel-sql-parser.cache.enabled' => true,
        'laravel-sql-parser.cache.store' => 'array',
        'laravel-sql-parser.cache.min_length' => 10,
    ]);
    Cache::store('array')->flush();

    Sql::transpile('SELECT IFNULL(a, 1) FROM t', 'mysql', 'postgres');
    Sql::transpile('SELECT 1', 'mysql', 'postgres');

    $stored = (fn () => $this->storage)->call(Cache::store('array')->getStore());

    expect($stored)->toHaveCount(1)
        ->and(array_values($stored)[0]['value'])->toBe(['SELECT COALESCE(a, 1) FROM t']);
});

it('registers custom dialects from the config', function (): void {
    config(['laravel-sql-parser.dialects' => ['acme' => Postgres::class]]);
    app()->forgetInstance(LaravelSqlParser\LaravelSqlParser\LaravelSqlParser::class);

    expect(Sql::dialect('acme'))->toBeInstanceOf(Postgres::class);
});
