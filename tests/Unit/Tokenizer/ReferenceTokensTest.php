<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Tests\Support\Reference;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * @return array<int, array{0: string, 1: list<array{0: string, 1: string}>}>
 */
function referenceTokenCases(string $dialect): array
{
    $cases = $dialect === 'identity'
        ? Reference::identity()
        : array_filter(Reference::dialectCases($dialect), static fn (array $case): bool => isset($case['tokens']));

    $out = [];
    foreach ($cases as $case) {
        if (is_array($case['tokens'] ?? null)) {
            $out[] = [$case['sql'], $case['tokens']];
        }
    }

    return $out;
}

/**
 * @return list<array{0: string, 1: string}>
 */
function tokenPairs(string $sql, string $dialect): array
{
    $stream = Dialect::get($dialect)->tokenizer()->tokenize($sql);
    $pairs = [];

    foreach ($stream->types as $i => $type) {
        $pairs[] = [TokenType::name($type), $stream->texts[$i]];
    }

    return $pairs;
}

it('tokenizes identity.sql like sqlglot', function (string $sql, array $expected): void {
    expect(tokenPairs($sql, 'base'))->toBe($expected);
})->with(referenceTokenCases('identity'));

foreach (Reference::DIALECTS as $dialect) {
    it("tokenizes {$dialect} inputs like sqlglot", function (string $sql, array $expected) use ($dialect): void {
        expect(tokenPairs($sql, $dialect))->toBe($expected);
    })->with(referenceTokenCases($dialect));
}
