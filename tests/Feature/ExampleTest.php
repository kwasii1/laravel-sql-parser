<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\LaravelSqlParser;

it('resolves the singleton', function () {
    expect(app(LaravelSqlParser::class))->toBeInstanceOf(LaravelSqlParser::class);
});

it('returns the same instance from the container', function () {
    expect(app(LaravelSqlParser::class))->toBe(app(LaravelSqlParser::class));
});

it('merges the package config', function () {
    expect(config('laravel-sql-parser.placeholder'))->toBe('default');
});

it('loads the package translations', function () {
    expect(trans('laravel-sql-parser::messages.placeholder'))->toBe('LaravelSqlParser placeholder translation.');
});

it('loads the package views', function () {
    expect(view()->exists('laravel-sql-parser::placeholder'))->toBeTrue();
});

it('registers the artisan command', function () {
    $this->artisan('laravel-sql-parser:placeholder')
        ->expectsOutputToContain('LaravelSqlParser placeholder command executed.')
        ->assertSuccessful();
});
