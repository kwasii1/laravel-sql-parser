<?php

declare(strict_types=1);

use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Tests\Support\OptimizerReference;

/**
 * sqlglot's optimizer fixtures (tests/fixtures/optimizer/*.sql), replayed through the PHP port.
 */
foreach (OptimizerReference::files() as $file) {
    $cases = [];

    foreach ($file['cases'] as $i => $case) {
        $cases["#{$i} {$case['title']}"] = [$case];
    }

    $function = OptimizerReference::function($file['function']);

    it("optimizes {$file['file']}.sql like sqlglot", function (array $case) use ($file, $function): void {
        expect($function)->not->toBeNull('The optimizer function this fixture needs is missing');

        if ($function === null) {
            return;
        }

        $dialect = OptimizerReference::dialect($case['dialect']);
        $kwargs = OptimizerReference::arguments([...$file['kwargs'], ...$case['kwargs']]);

        if (str_starts_with($case['actual'], '!')) {
            expect(fn () => $function($dialect->parseOne($case['sql']), ...$kwargs))->toThrow(Throwable::class);

            return;
        }

        $optimized = $function($dialect->parseOne($case['sql']), ...$kwargs);

        expect($dialect->generate($optimized, ['pretty' => $file['pretty']]))->toBe($case['expected']);

        // Like sqlglot's check_file: every child points back at its parent under the right key
        foreach ($optimized->walk() as $node) {
            foreach ($node->args as $key => $arg) {
                if ($arg instanceof Expression) {
                    expect($arg->argKey)->toBe($key)->and($arg->parent)->toBe($node);
                }
            }
        }
    })->with($cases);
}
