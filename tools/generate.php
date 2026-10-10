<?php

declare(strict_types=1);

/*
 * Generates literal-only PHP sources from the sqlglot specs in tools/specs/sqlglot.
 *
 *     php tools/generate.php           write generated files
 *     php tools/generate.php --check   exit 1 if any generated file is missing, stale or orphaned
 *
 * Generated files carry the GENERATED_MARKER so they can be found, compared and cleaned up.
 * Literal-only tables matter for performance: class constants that reference other classes are
 * evaluated on first access in every request, while literal constants live in opcache memory.
 */

require __DIR__.'/generator/Support.php';
require __DIR__.'/generator/TokenTypes.php';
require __DIR__.'/generator/Expressions.php';
require __DIR__.'/generator/Dialects.php';

use Tools\Generator\Dialects;
use Tools\Generator\Expressions;
use Tools\Generator\Support;
use Tools\Generator\TokenTypes;

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

$specs = Support::loadSpecs($root.'/tools/specs/sqlglot');
$tokenTypes = new TokenTypes($specs['token_types']);

/** @var array<string, string> $files relative path => contents */
$files = [
    ...$tokenTypes->files(),
    ...(new Expressions($specs['expressions']))->files(),
    ...(new Dialects($specs['dialects'], $tokenTypes, require $root.'/tools/specs/dialects.php', $specs['methods'], array_column($specs['expressions']['expressions'], 'key')))->files(),
];

ksort($files);

$problems = [];
$written = 0;

foreach ($files as $path => $contents) {
    $absolute = $root.'/'.$path;
    $current = is_file($absolute) ? file_get_contents($absolute) : null;

    if ($current === $contents) {
        continue;
    }

    if ($check) {
        $problems[] = ($current === null ? 'missing: ' : 'stale:   ').$path;

        continue;
    }

    if (! is_dir(dirname($absolute))) {
        mkdir(dirname($absolute), 0777, true);
    }

    file_put_contents($absolute, $contents);
    $written++;
}

foreach (Support::generatedFiles($root.'/src') as $path) {
    $relative = substr($path, strlen($root) + 1);

    if (isset($files[$relative])) {
        continue;
    }

    if ($check) {
        $problems[] = 'orphan:  '.$relative;
    } else {
        unlink($path);
        $written++;
    }
}

if ($check) {
    if ($problems !== []) {
        fwrite(STDERR, "Generated files are out of date. Run `composer generate`.\n  ".implode("\n  ", $problems)."\n");
        exit(1);
    }

    fwrite(STDOUT, count($files)." generated files are up to date.\n");
    exit(0);
}

fwrite(STDOUT, 'Generated '.count($files)." files ({$written} changed).\n");
