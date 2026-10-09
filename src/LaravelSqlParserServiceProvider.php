<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser;

use Illuminate\Support\ServiceProvider;
use LaravelSqlParser\LaravelSqlParser\Console\Commands\LaravelSqlParserCommand;

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
        $this->loadRoutesFrom(__DIR__.'/../routes/laravel-sql-parser.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'laravel-sql-parser');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'laravel-sql-parser');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/laravel-sql-parser.php' => config_path('laravel-sql-parser.php'),
        ], ['laravel-sql-parser', 'laravel-sql-parser-config']);

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/laravel-sql-parser'),
        ], ['laravel-sql-parser', 'laravel-sql-parser-views']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/laravel-sql-parser'),
        ], ['laravel-sql-parser', 'laravel-sql-parser-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/laravel-sql-parser'),
        ], ['laravel-sql-parser', 'laravel-sql-parser-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['laravel-sql-parser', 'laravel-sql-parser-migrations']);

        $this->commands([
            LaravelSqlParserCommand::class,
        ]);
    }
}
