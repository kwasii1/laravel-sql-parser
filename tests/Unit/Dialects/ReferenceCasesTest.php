<?php

declare(strict_types=1);

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Errors\ErrorLevel;
use Kwasii\LaravelSqlParser\Errors\UnsupportedError;
use Kwasii\LaravelSqlParser\Tests\Support\Reference;

/**
 * The recorded validate_identity / validate_all cases of a dialect, flattened to
 * [read dialect, write dialect, input, expected, options] checks. validate_all read and write
 * targets are limited to the implemented dialects.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: array<string, mixed>}>
 */
function referenceDialectChecks(string $dialect): array
{
    $self = $dialect === 'base' ? '' : $dialect;
    $implemented = ['' => true];

    foreach (Reference::GENERATOR_DIALECTS as $name) {
        $implemented[$name === 'base' ? '' : $name] = true;
    }

    $checks = [];

    foreach (Reference::dialectCases($dialect) as $i => $case) {
        // validate_transpile: transpile() from this dialect to write_dialect
        if ($case['type'] === 'transpile') {
            if ($case['ok'] && isset($implemented[$case['write_dialect']])) {
                $checks["#{$i} transpile {$case['write_dialect']}: {$case['sql']}"] = [$self, $case['write_dialect'], $case['sql'], $case['expected'], []];
            }

            continue;
        }

        $options = array_filter(['pretty' => $case['pretty'], 'identify' => $case['identify']]);

        if ($case['type'] === 'identity') {
            $checks["#{$i} identity: {$case['sql']}"] = [$self, $self, $case['sql'], $case['expected'], $options];

            continue;
        }

        foreach ($case['read'] as $read => $sql) {
            if (isset($implemented[$read])) {
                $checks["#{$i} read {$read}: {$sql}"] = [(string) $read, $self, $sql, $case['sql'], $options];
            }
        }

        foreach ($case['write'] as $write => $expected) {
            if (isset($implemented[$write])) {
                $checks["#{$i} write {$write}: {$case['sql']}"] = [$self, (string) $write, $case['sql'], $expected, $options];
            }
        }
    }

    return $checks;
}

// Dialects without a sqlglot test module of their own (e.g. spark2) are covered by others' cases
foreach (array_intersect(Reference::GENERATOR_DIALECTS, Reference::DIALECTS) as $dialect) {
    it("transpiles the recorded {$dialect} cases like sqlglot", function (string $read, string $write, string $sql, string $expected, array $options): void {
        $expression = Dialect::get($read === '' ? null : $read)->parseOne($sql);
        $target = Dialect::get($write === '' ? null : $write);

        // sqlglot's validate_all expects UnsupportedError for these writes at the RAISE level
        if ($expected === Reference::UNSUPPORTED) {
            expect(fn (): string => $target->generate($expression, $options + ['unsupported_level' => ErrorLevel::Raise]))
                ->toThrow(UnsupportedError::class);

            return;
        }

        expect($target->generate($expression, $options + ['unsupported_level' => ErrorLevel::Ignore]))->toBe($expected);
    })->with(referenceDialectChecks($dialect));
}
