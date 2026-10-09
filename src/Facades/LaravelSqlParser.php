<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \LaravelSqlParser\LaravelSqlParser\LaravelSqlParser
 */
class LaravelSqlParser extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaravelSqlParser\LaravelSqlParser\LaravelSqlParser::class;
    }
}
