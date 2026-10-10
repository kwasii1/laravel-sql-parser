<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Kwasii\LaravelSqlParser\Dialects\Dialect dialect(string|\Kwasii\LaravelSqlParser\Dialects\Dialect|null $dialect = null)
 * @method static list<\Kwasii\LaravelSqlParser\Tokenizer\Token> tokenize(string $sql, string|\Kwasii\LaravelSqlParser\Dialects\Dialect|null $dialect = null)
 * @method static list<\Kwasii\LaravelSqlParser\Expressions\Expression|null> parse(string $sql, string|\Kwasii\LaravelSqlParser\Dialects\Dialect|null $dialect = null, \Kwasii\LaravelSqlParser\Errors\ErrorLevel|null $errorLevel = null)
 * @method static \Kwasii\LaravelSqlParser\Expressions\Expression parseOne(string $sql, string|\Kwasii\LaravelSqlParser\Dialects\Dialect|null $dialect = null, \Kwasii\LaravelSqlParser\Errors\ErrorLevel|null $errorLevel = null)
 * @method static list<string> transpile(string $sql, string|\Kwasii\LaravelSqlParser\Dialects\Dialect|null $from = null, string|\Kwasii\LaravelSqlParser\Dialects\Dialect|null $to = null, bool|array<string, mixed> $options = [])
 * @method static array{sql: string, bindings: list<mixed>} transpileQuery(string $sql, array<array-key, mixed> $bindings, string|\Kwasii\LaravelSqlParser\Dialects\Dialect|null $from = null, string|\Kwasii\LaravelSqlParser\Dialects\Dialect|null $to = null, bool|array<string, mixed> $options = [])
 * @method static \Kwasii\LaravelSqlParser\Dialects\Dialect dialectForDriver(string $driver)
 *
 * @see \Kwasii\LaravelSqlParser\LaravelSqlParser
 */
class LaravelSqlParser extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Kwasii\LaravelSqlParser\LaravelSqlParser::class;
    }
}
