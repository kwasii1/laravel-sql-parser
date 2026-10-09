<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \LaravelSqlParser\LaravelSqlParser\Dialects\Dialect dialect(string|\LaravelSqlParser\LaravelSqlParser\Dialects\Dialect|null $dialect = null)
 * @method static list<\LaravelSqlParser\LaravelSqlParser\Tokenizer\Token> tokenize(string $sql, string|\LaravelSqlParser\LaravelSqlParser\Dialects\Dialect|null $dialect = null)
 * @method static list<\LaravelSqlParser\LaravelSqlParser\Expressions\Expression|null> parse(string $sql, string|\LaravelSqlParser\LaravelSqlParser\Dialects\Dialect|null $dialect = null, \LaravelSqlParser\LaravelSqlParser\Errors\ErrorLevel|null $errorLevel = null)
 * @method static \LaravelSqlParser\LaravelSqlParser\Expressions\Expression parseOne(string $sql, string|\LaravelSqlParser\LaravelSqlParser\Dialects\Dialect|null $dialect = null, \LaravelSqlParser\LaravelSqlParser\Errors\ErrorLevel|null $errorLevel = null)
 * @method static list<string> transpile(string $sql, string|\LaravelSqlParser\LaravelSqlParser\Dialects\Dialect|null $from = null, string|\LaravelSqlParser\LaravelSqlParser\Dialects\Dialect|null $to = null, bool|array<string, mixed> $options = [])
 * @method static array{sql: string, bindings: list<mixed>} transpileQuery(string $sql, array<array-key, mixed> $bindings, string|\LaravelSqlParser\LaravelSqlParser\Dialects\Dialect|null $from = null, string|\LaravelSqlParser\LaravelSqlParser\Dialects\Dialect|null $to = null, bool|array<string, mixed> $options = [])
 * @method static \LaravelSqlParser\LaravelSqlParser\Dialects\Dialect dialectForDriver(string $driver)
 *
 * @see \LaravelSqlParser\LaravelSqlParser\LaravelSqlParser
 */
class LaravelSqlParser extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaravelSqlParser\LaravelSqlParser\LaravelSqlParser::class;
    }
}
