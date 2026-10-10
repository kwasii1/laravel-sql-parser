<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Optimizer\MergeSubqueries;
use LaravelSqlParser\LaravelSqlParser\Optimizer\Optimizer;
use LaravelSqlParser\LaravelSqlParser\Optimizer\QualifyColumns;
use LaravelSqlParser\LaravelSqlParser\Optimizer\QualifyTables;

it('optimizes a SQL string with a schema', function (): void {
    $optimized = Optimizer::optimize('SELECT a FROM (SELECT x.a FROM x) AS y', schema: ['x' => ['a' => 'INT']]);

    expect($optimized->sql())->toBe('SELECT "x"."a" AS "a" FROM "x" AS "x"');
});

it('passes each rule only the options it accepts', function (): void {
    $optimized = Optimizer::optimize(
        'SELECT a FROM (SELECT x.a FROM x) CROSS JOIN y',
        schema: ['x' => ['a' => 'INT'], 'y' => ['b' => 'INT']],
        rules: [QualifyTables::qualifyTables(...), QualifyColumns::qualifyColumns(...), MergeSubqueries::mergeSubqueries(...)],
        leaveTablesIsolated: true,
    );

    expect($optimized)->toBeInstanceOf(Expression::class)
        ->and($optimized->sql())->toContain('FROM (SELECT');
});

it('matches the README example', function (): void {
    $schema = ['x' => ['a' => 'INT', 'b' => 'INT']];

    expect(Optimizer::optimize('SELECT a FROM (SELECT x.a FROM x) AS s WHERE 1 + 1 = 2 AND a > 1', schema: $schema)->sql())
        ->toBe('SELECT "x"."a" AS "a" FROM "x" AS "x" WHERE "x"."a" > 1');
});
