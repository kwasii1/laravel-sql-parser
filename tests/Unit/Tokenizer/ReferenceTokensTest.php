<?php

declare(strict_types=1);

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Tests\Support\Reference;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

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
    $pairs = [];

    // The dialect's public tokenize(), like sqlglot: Athena routes some statements to Hive's tokenizer
    foreach (Dialect::get($dialect)->tokenize($sql) as $token) {
        $pairs[] = [TokenType::name($token->type), $token->text];
    }

    return $pairs;
}

it('tokenizes identity.sql like sqlglot', function (string $sql, array $expected): void {
    expect(tokenPairs($sql, 'base'))->toBe($expected);
})->with(referenceTokenCases('identity'));

foreach (Reference::DIALECTS as $dialect) {
    // Some dialects' tests record no tokens (e.g. DAX's are all validate_transpile cases)
    if (($cases = referenceTokenCases($dialect)) === []) {
        continue;
    }

    it("tokenizes {$dialect} inputs like sqlglot", function (string $sql, array $expected) use ($dialect): void {
        expect(tokenPairs($sql, $dialect))->toBe($expected);
    })->with($cases);
}
