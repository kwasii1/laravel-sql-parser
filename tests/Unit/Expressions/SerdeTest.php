<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Tests\Support\Reference;

/*
 * Port of sqlglot's tests/test_serde.py: dump() / load() round-trip trees through plain JSON.
 */

final class SerdeCustomExpression extends Expression
{
    public const string KEY = 'serdecustomexpression';

    public const array ARG_TYPES = ['this' => false];

    public const array REQUIRED_ARGS = [];
}

function dumpLoad(Expression $expression): ?Expression
{
    $json = json_encode($expression->dump(), JSON_THROW_ON_ERROR);

    return Expression::load(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
}

it('round-trips every identity fixture', function (): void {
    $dialect = Dialect::get(null);
    $checked = 0;

    foreach (Reference::identity() as $case) {
        try {
            $before = $dialect->parseOne($case['sql']);
        } catch (Throwable) {
            continue;
        }

        $after = dumpLoad($before);

        expect($after)->not->toBeNull()
            ->and($after?->toTree())->toBe($before->toTree(), $case['sql'])
            ->and($after?->sql())->toBe($before->sql(), $case['sql'])
            ->and($after?->equals($before))->toBeTrue();
        $checked++;
    }

    expect($checked)->toBeGreaterThan(900);
});

it('round-trips custom expression classes', function (): void {
    $before = new SerdeCustomExpression;
    $after = dumpLoad($before);

    expect($after)->toBeInstanceOf(SerdeCustomExpression::class)
        ->and($after?->equals($before))->toBeTrue();
});

it('round-trips type annotations', function (): void {
    $dialect = Dialect::get(null);
    $before = $dialect->annotateTypes($dialect->parseOne("CAST('1' AS STRUCT<x ARRAY<INT>>)"));
    $after = dumpLoad($before);

    expect($after?->type()?->equals($before->type()))->toBeTrue()
        ->and($after?->args['this']->type()?->equals($before->args['this']->type()))->toBeTrue();
});

it('round-trips meta', function (): void {
    $before = Dialect::get(null)->parseOne('SELECT * FROM X');
    $before->setMeta('x', 1);

    expect(dumpLoad($before)?->meta)->toBe($before->meta);
});

it('round-trips very deep trees', function (): void {
    $expression = Dialect::get(null)->parseOne('SELECT 1'.str_repeat(' UNION ALL SELECT 1', 5000));

    expect(dumpLoad($expression)?->sql())->toBe($expression->sql());
});

it('uses sqlglot class names', function (): void {
    $payloads = Dialect::get(null)->parseOne('SELECT CASE WHEN x IS NULL THEN CAST(y AS INT) END')->dump();

    expect(array_values(array_filter(array_column($payloads, 'c'))))
        ->toBe(['Select', 'Case', 'If', 'Is', 'Column', 'Identifier', 'Null', 'Cast', 'Column', 'Identifier', 'DataType', 'DataType.Type']);
});

it('loads dumps written by sqlglot', function (): void {
    $payloads = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/sqlglot/serde.json'), true, 512, JSON_THROW_ON_ERROR);
    $expression = Expression::load($payloads);

    expect($expression?->sql())->toBe("SELECT CAST(a AS STRUCT<x ARRAY<INT>>) AS x, CASE WHEN b IS NULL THEN 'n' END /* c */ FROM t WHERE t.y IN (1, 2) ORDER BY 1 DESC")
        ->and(Expression::load([]))->toBeNull();
});
