<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\LaravelSqlParser;

it('registers the parser service as a singleton', function (): void {
    expect(app(LaravelSqlParser::class))->toBe(app(LaravelSqlParser::class));
});

it('merges the package configuration', function (): void {
    expect(config('laravel-sql-parser'))->toBeArray();
});
