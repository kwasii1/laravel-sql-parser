<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Tests\Support\Reference;

/**
 * Normalise a tree the way the recorder serialised sqlglot's (JSON numbers, string keys).
 *
 * @param  array<string, mixed>  $tree
 * @return array<string, mixed>
 */
function jsonTree(array $tree): array
{
    return json_decode(json_encode($tree, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function referenceAstCases(string $dialect): array
{
    $cases = $dialect === 'identity' ? Reference::identity() : Reference::dialectCases($dialect);
    $out = [];

    foreach ($cases as $case) {
        if (is_array($case['ast'] ?? null)) {
            $out[] = [$case['sql'], $case['ast']];
        }
    }

    return $out;
}

it('parses identity.sql into the same trees as sqlglot', function (string $sql, array $ast): void {
    expect(jsonTree(Dialect::get()->parseOne($sql)->toTree()))->toEqual(jsonTree($ast));
})->with(referenceAstCases('identity'));

foreach (Reference::GENERATOR_DIALECTS as $dialect) {
    it("parses {$dialect} cases into the same trees as sqlglot", function (string $sql, array $ast) use ($dialect): void {
        expect(jsonTree(Dialect::get($dialect)->parseOne($sql)->toTree()))->toEqual(jsonTree($ast));
    })->with(referenceAstCases($dialect));
}
