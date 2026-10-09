<?php

declare(strict_types=1);

/*
 * Dependency-free benchmark runner.
 *
 *     php benchmarks/run.php [--subject=tokenize|parse|transpile] [--iterations=5] [--json=out.json] [--baseline=base.json] [--filter=name]
 *
 * Reports the best of N runs per query (like sqlglot's benchmarks/parse.py), plus the
 * median. With --baseline, prints the ratio against a previous --json run.
 */

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\Tokenizer;

require dirname(__DIR__).'/vendor/autoload.php';

$options = getopt('', ['subject::', 'iterations::', 'json::', 'baseline::', 'filter::']);
$subject = is_string($options['subject'] ?? null) ? $options['subject'] : 'parse';
$iterations = max(1, (int) ($options['iterations'] ?? 5));
$filter = is_string($options['filter'] ?? null) ? $options['filter'] : null;

/** @var array<string, string> $queries */
$queries = require __DIR__.'/corpus.php';

$run = match ($subject) {
    'tokenize' => (static function (): Closure {
        $tokenizer = new Tokenizer;

        return static fn (string $sql): mixed => $tokenizer->tokenize($sql);
    })(),
    'parse' => static fn (string $sql): mixed => Dialect::get()->parse($sql),
    'transpile' => static fn (string $sql): mixed => Dialect::transpile($sql),
    default => throw new InvalidArgumentException("Unknown subject {$subject}"),
};

$baseline = [];

if (is_string($options['baseline'] ?? null)) {
    $baseline = json_decode((string) file_get_contents($options['baseline']), true)['results'] ?? [];
}

$results = [];
$width = max(array_map('strlen', array_keys($queries)));

printf("%s (%d iterations, PHP %s, opcache %s, JIT %s)\n\n", $subject, $iterations, PHP_VERSION,
    function_exists('opcache_get_status') && is_array(@opcache_get_status(false)) ? 'on' : 'off',
    ini_get('opcache.jit') ?: 'off');
printf("%-{$width}s  %12s  %12s%s\n", 'query', 'best', 'median', $baseline !== [] ? '      vs base' : '');

foreach ($queries as $name => $sql) {
    if ($filter !== null && ! str_contains($name, $filter)) {
        continue;
    }

    $run($sql); // warm up

    $times = [];
    for ($i = 0; $i < $iterations; $i++) {
        gc_collect_cycles();
        $start = hrtime(true);
        $run($sql);
        $times[] = (hrtime(true) - $start) / 1e9;
    }

    sort($times);
    $best = $times[0];
    $median = $times[intdiv(count($times), 2)];
    $results[$name] = ['best' => $best, 'median' => $median];

    $ratio = isset($baseline[$name]['best']) ? sprintf('  %10.2fx', $best / $baseline[$name]['best']) : '';
    printf("%-{$width}s  %12s  %12s%s\n", $name, format_time($best), format_time($median), $ratio);
}

if (is_string($options['json'] ?? null)) {
    file_put_contents($options['json'], json_encode(['subject' => $subject, 'php' => PHP_VERSION, 'results' => $results], JSON_PRETTY_PRINT)."\n");
}

function format_time(float $seconds): string
{
    return match (true) {
        $seconds < 1e-3 => sprintf('%.1f µs', $seconds * 1e6),
        $seconds < 1 => sprintf('%.2f ms', $seconds * 1e3),
        default => sprintf('%.2f s', $seconds),
    };
}
