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
 * Drop recorder artifacts: a Python object sqlglot keeps in an arg (e.g. the dialect a DataType
 * was built with) was recorded as its repr, '<sqlglot.dialects.clickhouse.ClickHouse object ...>'.
 *
 * @param  array<array-key, mixed>  $tree
 * @return array<array-key, mixed>
 */
function withoutObjectReprs(array $tree): array
{
    foreach ($tree as $key => $value) {
        if (is_string($value) && str_starts_with($value, '<sqlglot.')) {
            unset($tree[$key]);
        } elseif (is_array($value)) {
            $tree[$key] = withoutObjectReprs($value);
        }
    }

    return $tree;
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
            $out[] = [$case['sql'], withoutObjectReprs($case['ast'])];
        }
    }

    return $out;
}

it('parses identity.sql into the same trees as sqlglot', function (string $sql, array $ast): void {
    expect(jsonTree(Dialect::get()->parseOne($sql)->toTree()))->toEqual(jsonTree($ast));
})->with(referenceAstCases('identity'));

foreach (array_intersect(Reference::GENERATOR_DIALECTS, Reference::DIALECTS) as $dialect) {
    // Some dialects' tests record no trees (e.g. DAX's are all validate_transpile cases)
    if (($cases = referenceAstCases($dialect)) === []) {
        continue;
    }

    it("parses {$dialect} cases into the same trees as sqlglot", function (string $sql, array $ast) use ($dialect): void {
        expect(jsonTree(Dialect::get($dialect)->parseOne($sql)->toTree()))->toEqual(jsonTree($ast));
    })->with($cases);
}
