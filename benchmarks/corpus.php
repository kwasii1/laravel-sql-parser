<?php

declare(strict_types=1);

/*
 * Benchmark queries, ported from sqlglot's benchmarks/parse.py so results are comparable.
 *
 * @return array<string, string>
 */

$range = static fn (int $count): array => range(0, $count - 1);

return [
    'tpch' => trim((string) file_get_contents(__DIR__.'/corpus/tpch.sql')),

    'short' => 'SELECT 1 AS a, CASE WHEN 1 THEN 1 WHEN 2 THEN 2 ELSE 3 END AS b, c FROM x',

    'deep_arithmetic' => 'SELECT 1+'.implode('+', $range(500)).' AS a, 2*'.implode('*', $range(500)).' AS b FROM x',

    'large_in' => 'SELECT * FROM t WHERE x IN ('.implode(', ', array_map(static fn (int $i): string => "'s{$i}'", $range(20000))).')'
        .' OR y IN ('.implode(', ', $range(20000)).')',

    'values' => 'INSERT INTO t VALUES '.implode(', ', array_map(
        static fn (int $i): string => '('.implode(', ', array_map(
            static fn (int $j): string => $j % 2 === 1 ? "'s{$i}_{$j}'" : (string) ($i * 20 + $j),
            $range(20),
        )).')',
        $range(2000),
    )),

    'many_joins' => 'SELECT * FROM t0'.implode('', array_map(
        static fn (int $i): string => "\nJOIN t{$i} ON t{$i}.id = t".($i - 1).'.id',
        range(1, 199),
    )),

    'many_unions' => implode("\nUNION ALL\n", array_map(static fn (int $i): string => "SELECT {$i} AS a, 's{$i}' AS b FROM t{$i}", $range(500))),

    'nested_subqueries' => 'SELECT * FROM '.str_repeat('(SELECT * FROM ', 20).'t'.str_repeat(')', 20),

    'many_columns' => 'SELECT '.implode(', ', array_map(static fn (int $i): string => "c{$i}", $range(1000))).' FROM t',

    'large_case' => 'SELECT CASE '.implode(' ', array_map(static fn (int $i): string => "WHEN x = {$i} THEN {$i}", $range(1000))).' ELSE -1 END FROM t',

    'complex_where' => 'SELECT * FROM t WHERE '.implode(' AND ', array_map(
        static fn (int $i): string => "(c{$i} > {$i} OR c{$i} LIKE '%s{$i}%' OR c{$i} BETWEEN {$i} AND ".($i + 10)." OR c{$i} IS NULL)",
        $range(200),
    )),

    'many_ctes' => 'WITH '.implode(', ', array_map(
        static fn (int $i): string => "t{$i} AS (SELECT {$i} AS a FROM t".($i > 0 ? (string) ($i - 1) : 'base').')',
        $range(200),
    )).' SELECT * FROM t199',

    'many_windows' => 'SELECT '.implode(', ', array_map(
        static fn (int $i): string => "SUM(c{$i}) OVER (PARTITION BY p".($i % 10).' ORDER BY o'.($i % 5).") AS w{$i}",
        $range(200),
    )).' FROM t',

    'nested_functions' => 'SELECT '.str_repeat('COALESCE(', 20).'x'.str_repeat(', NULL)', 20).' FROM t',

    'large_strings' => 'SELECT '.implode(', ', array_map(static fn (int $i): string => "'".str_repeat('x', 100)."'", $range(500))).' FROM t',

    'many_numbers' => 'SELECT '.implode(', ', $range(10000)).' FROM t',
];
