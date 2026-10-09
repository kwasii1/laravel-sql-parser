<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Tests;

use LaravelSqlParser\LaravelSqlParser\LaravelSqlParserServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaravelSqlParserServiceProvider::class,
        ];
    }
}
