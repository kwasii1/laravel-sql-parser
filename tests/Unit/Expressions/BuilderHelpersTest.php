<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;

/**
 * The table and placeholder helpers of sqlglot's expressions/builders.py, checked against
 * the examples in sqlglot's docstrings.
 */
function helperParse(string $sql, ?string $dialect = null): Expression
{
    return Dialect::get($dialect)->parseOne($sql);
}

it('finds the tables a query reads, without CTE references', function (): void {
    $tables = Builder::findTables(helperParse('WITH c AS (SELECT * FROM a) SELECT * FROM c JOIN b ON TRUE WHERE x IN (SELECT y FROM d)'));
    $names = array_map(fn (Table $table): string => $table->name(), $tables);
    sort($names);

    expect($names)->toBe(['a', 'b', 'd']);
});

it('names tables', function (): void {
    expect(Builder::tableName(helperParse('select * from a.b.c')->find(Table::class)))->toBe('a.b.c')
        ->and(Builder::tableName('a.`b-c`', 'mysql'))->toBe('a.`b-c`')
        ->and(Builder::tableName('a.b', identify: true))->toBe('"a"."b"');
});

it('normalizes table names', function (): void {
    expect(Builder::normalizeTableName('`A-B`.c', 'bigquery'))->toBe('A-B.c')
        ->and(Builder::normalizeTableName('A.B'))->toBe('a.b');
});

it('replaces tables', function (): void {
    expect(Builder::replaceTables(helperParse('select * from a.b'), ['a.b' => 'c'])->sql())->toBe('SELECT * FROM c /* a.b */');
});

it('replaces placeholders', function (): void {
    $expression = Builder::replacePlaceholders(
        helperParse('select * from :tbl where ? = ?'),
        [Builder::toIdentifier('str_col'), 'b'],
        ['tbl' => Builder::toIdentifier('foo')],
    );

    expect($expression->sql())->toBe("SELECT * FROM foo WHERE str_col = 'b'");
});

it('expands sources into subqueries', function (): void {
    expect(Builder::expand(helperParse('select * from x AS z'), ['x' => helperParse('select * from y')])->sql())
        ->toBe('SELECT * FROM (SELECT * FROM y) AS z /* source: x */')
        ->and(Builder::expand(helperParse('select * from x AS z'), ['x' => helperParse('select * from y'), 'y' => fn () => helperParse('select * from z')])->sql())
        ->toBe('SELECT * FROM (SELECT * FROM (SELECT * FROM z) AS y /* source: y */) AS z /* source: x */');
});
