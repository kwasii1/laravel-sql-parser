<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Dialects\MariaDb\MariaDb;
use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySql;

it('resolves the mariadb dialect as a MySQL dialect', function (): void {
    $dialect = Dialect::get('mariadb');

    expect($dialect)->toBeInstanceOf(MariaDb::class)
        ->and($dialect)->toBeInstanceOf(MySql::class)
        ->and($dialect->name())->toBe('mariadb');
});

it('round-trips MySQL syntax', function (string $sql): void {
    $dialect = Dialect::get('mariadb');

    expect($dialect->generate($dialect->parseOne($sql)))->toBe($sql);
})->with([
    'SELECT `a`, b FROM t WHERE c = 1 LIMIT 10',
    'INSERT INTO t (a, b) VALUES (1, 2) ON DUPLICATE KEY UPDATE a = VALUES(a)',
    "SELECT DATE_FORMAT(created_at, '%Y-%m-%d') FROM users",
    'SELECT GROUP_CONCAT(name SEPARATOR \',\') FROM users GROUP BY team_id',
    'SELECT COALESCE(a, 0) FROM t',
]);

it('renders JSON arrow operators as functions, since MariaDB has no -> / ->>', function (string $sql, string $read, string $expected): void {
    expect(Dialect::transpile($sql, read: $read, write: 'mariadb')[0])->toBe($expected);
})->with([
    'MySQL ->>' => ["SELECT data->>'$.name' FROM users", 'mysql', "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.name')) FROM users"],
    'MySQL ->' => ["SELECT data->'$.name' FROM users", 'mysql', "SELECT JSON_EXTRACT(data, '$.name') FROM users"],
    'Postgres ->>' => ["SELECT data->>'name' FROM users", 'postgres', "SELECT JSON_UNQUOTE(JSON_EXTRACT(data, '$.name')) FROM users"],
]);

it('transpiles other dialects to MariaDB like MySQL', function (string $sql, string $read, string $expected): void {
    expect(Dialect::transpile($sql, read: $read, write: 'mariadb')[0])->toBe($expected);
})->with([
    'T-SQL TOP' => ['SELECT TOP 5 a FROM t', 'tsql', 'SELECT a FROM t LIMIT 5'],
    'Postgres ILIKE' => ["SELECT a FROM t WHERE b ILIKE '%x%'", 'postgres', "SELECT a FROM t WHERE LOWER(b) LIKE LOWER('%x%')"],
    'SQLite ||' => ['SELECT a || b FROM t', 'sqlite', 'SELECT CONCAT(a, b) FROM t'],
]);
