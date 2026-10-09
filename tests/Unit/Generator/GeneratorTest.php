<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Errors\ErrorLevel;
use LaravelSqlParser\LaravelSqlParser\Errors\UnsupportedError;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;

/**
 * @param  array<string, mixed>  $options
 */
function generateBase(string $sql, array $options = []): string
{
    $dialect = Dialect::get();

    return $dialect->generate($dialect->parseOne($sql), $options);
}

it('applies generator options like sqlglot', function (string $sql, array $options, string $expected): void {
    expect(generateBase($sql, $options))->toBe($expected);
})->with([
    'identify' => ['SELECT a FROM t', ['identify' => true], 'SELECT "a" FROM "t"'],
    'normalize' => ['SELECT A, B AS c FROM T', ['normalize' => true], 'SELECT a, b AS c FROM t'],
    'pretty' => [
        'SELECT a, b FROM t WHERE x = 1 AND y = 2 ORDER BY a',
        ['pretty' => true],
        "SELECT\n  a,\n  b\nFROM t\nWHERE\n  x = 1 AND y = 2\nORDER BY\n  a",
    ],
    'leading comma' => ['SELECT a, b FROM t', ['pretty' => true, 'leading_comma' => true], "SELECT\n  a\n  , b\nFROM t"],
    'camelCase option names' => ['SELECT a, b FROM t', ['pretty' => true, 'leadingComma' => true], "SELECT\n  a\n  , b\nFROM t"],
    'comments kept' => ['SELECT a /* comment */ FROM t', [], 'SELECT a /* comment */ FROM t'],
    'comments dropped' => ['SELECT a /* comment */ FROM t', ['comments' => false], 'SELECT a FROM t'],
    'wrapped connectors' => ['SELECT a FROM x WHERE (a AND b) OR c', ['pretty' => true], "SELECT\n  a\nFROM x\nWHERE\n  (\n    a AND b\n  ) OR c"],
    'pretty set operation with CTE' => [
        'WITH a AS (SELECT 1 AS x) SELECT x FROM a UNION ALL SELECT 2',
        ['pretty' => true],
        "WITH a AS (\n  SELECT\n    1 AS x\n)\nSELECT\n  x\nFROM a\nUNION ALL\nSELECT\n  2",
    ],
]);

it('generates literals, identifiers and operators like sqlglot', function (string $sql, string $expected): void {
    expect(generateBase($sql))->toBe($expected);
})->with([
    'escaped quote' => ["SELECT 'it''s'", "SELECT 'it''s'"],
    'escaped identifier' => ['SELECT "weird""name" FROM t', 'SELECT "weird""name" FROM t'],
    'casts' => ['SELECT TRY_CAST(x AS INT), x::text, CAST(y AS DECIMAL(10, 2))', 'SELECT TRY_CAST(x AS INT), CAST(x AS TEXT), CAST(y AS DECIMAL(10, 2))'],
    'aggregate filter' => ['SELECT ARRAY_AGG(DISTINCT a ORDER BY b) FILTER(WHERE c > 0) FROM t', 'SELECT ARRAY_AGG(DISTINCT a ORDER BY b) FILTER(WHERE c > 0) FROM t'],
    'double negation is not a comment' => ['SELECT - -5', 'SELECT - -5'],
    'like quantifier' => ["SELECT a FROM t WHERE b LIKE ANY ('%a', '%b')", "SELECT a FROM t WHERE b LIKE ANY ('%a', '%b')"],
]);

it('raises unsupported features at the RAISE level', function (): void {
    generateBase('SELECT a FROM t FOR UPDATE', ['unsupported_level' => ErrorLevel::Raise]);
})->throws(UnsupportedError::class, "Locking reads using 'FOR UPDATE/SHARE' are not supported");

it('drops unsupported features silently at the IGNORE level', function (): void {
    expect(generateBase('SELECT a FROM t FOR UPDATE', ['unsupported_level' => ErrorLevel::Ignore]))->toBe('SELECT a FROM t');
});

it('reports unsupported features to the warning handler at the WARN level', function (): void {
    $warnings = [];
    Parser::$warningHandler = static function (string $message) use (&$warnings): void {
        $warnings[] = $message;
    };

    try {
        expect(generateBase('SELECT a FROM t FOR UPDATE'))->toBe('SELECT a FROM t')
            ->and($warnings)->toBe(["Locking reads using 'FOR UPDATE/SHARE' are not supported"]);
    } finally {
        Parser::$warningHandler = null;
    }
});

it('does not mutate the tree it generates from', function (): void {
    $dialect = Dialect::get();
    $expression = $dialect->parseOne('SELECT a FROM t WHERE b = 1');
    $before = $expression->toTree();

    $dialect->generate($expression, ['identify' => true, 'pretty' => true]);

    expect($expression->toTree())->toBe($before);
});

it('generates very long AND chains without recursing', function (): void {
    $sql = 'SELECT '.implode(' AND ', array_map(static fn (int $i): string => "a{$i} = {$i}", range(0, 2999)));

    expect(generateBase($sql))->toBe($sql);
});

it('generates any node, not only statements', function (): void {
    $column = Dialect::get()->parseOne('SELECT t.a FROM t')->find(Column::class);

    expect($column?->sql())->toBe('t.a')
        ->and($column?->sql('base', ['identify' => true]))->toBe('"t"."a"');
});

it('rejects unknown generator options', function (): void {
    generateBase('SELECT 1', ['prety' => true]);
})->throws(InvalidArgumentException::class, "Unknown generator option 'prety'.");
