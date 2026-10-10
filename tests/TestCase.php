<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Tests;

use Kwasii\LaravelSqlParser\LaravelSqlParserServiceProvider;
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
