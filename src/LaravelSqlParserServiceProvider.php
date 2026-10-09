<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser;

use Illuminate\Support\ServiceProvider;

class LaravelSqlParserServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-sql-parser.php', 'laravel-sql-parser');

        $this->app->singleton(LaravelSqlParser::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-sql-parser.php' => config_path('laravel-sql-parser.php'),
        ], ['laravel-sql-parser', 'laravel-sql-parser-config']);
    }
}
