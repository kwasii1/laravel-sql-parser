<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

it('skips a leading UTF-8 byte order mark, keeping byte offsets', function (string $dialect): void {
    $stream = Dialect::get($dialect)->tokenizer()->tokenize("\u{FEFF}DROP TABLE IF EXISTS t");

    expect($stream->texts)->toBe(['DROP', 'TABLE', 'IF', 'EXISTS', 't'])
        ->and($stream->starts[0])->toBe(3);
})->with(['base', 'mysql', 'postgres', 'sqlite', 'tsql']);

it('parses dumps that start with a byte order mark', function (): void {
    $statements = Dialect::get('mysql')->parse("\u{FEFF}/*!40101 SET NAMES utf8mb4 */;\nCREATE TABLE t (id INT)");

    expect(Dialect::get('mysql')->generate($statements[count($statements) - 1]))->toBe('CREATE TABLE t (id INT)');
});
