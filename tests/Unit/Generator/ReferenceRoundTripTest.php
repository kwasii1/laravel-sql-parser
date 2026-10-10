<?php

declare(strict_types=1);

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Tests\Support\Reference;

/**
 * Generate every statement in $sql with the base dialect, the way sqlglot's transpile() does.
 *
 * @param  array<string, mixed>  $options
 */
function roundTrip(string $sql, array $options = []): string
{
    $dialect = Dialect::get();

    return implode(";\n", array_map(
        static fn ($expression): string => $expression === null ? '' : $dialect->generate($expression, $options),
        $dialect->parse($sql),
    ));
}

it('round-trips identity.sql like sqlglot', function (string $sql, ?string $expected): void {
    expect(roundTrip($sql))->toBe($expected ?? $sql);
})->with(array_map(static fn (array $case): array => [$case['sql'], $case['expected']], Reference::identity()));

it('pretty prints pretty.sql like sqlglot', function (string $sql, string $expected): void {
    expect(roundTrip($sql, ['pretty' => true]))->toBe($expected);
})->with(array_map(static fn (array $case): array => [$case['sql'], $case['expected']], Reference::pretty()));
