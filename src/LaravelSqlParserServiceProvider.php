<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\ServiceProvider;
use LaravelSqlParser\LaravelSqlParser\Console\Commands\TranspileCommand;

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
        if ($this->app->make('config')->get('laravel-sql-parser.macros', true) === true) {
            $this->registerMacros();
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([TranspileCommand::class]);

        $this->publishes([
            __DIR__.'/../config/laravel-sql-parser.php' => config_path('laravel-sql-parser.php'),
        ], ['laravel-sql-parser', 'laravel-sql-parser-config']);
    }

    /**
     * toSqlFor(), toRawSqlFor() and bindingsFor() transpile a query from its connection's dialect.
     * Eloquent gets its own copies because it doesn't return what forwarded calls return.
     */
    private function registerMacros(): void
    {
        $transpile = static function (QueryBuilder $query, string $dialect, bool $pretty): array {
            $connection = $query->getConnection();

            return app(LaravelSqlParser::class)->transpileQuery(
                $query->toSql(),
                $query->getBindings(),
                $connection instanceof Connection ? $connection->getDriverName() : null,
                $dialect,
                $pretty,
            );
        };

        QueryBuilder::macro('toSqlFor', function (string $dialect, bool $pretty = false) use ($transpile): string {
            /** @var QueryBuilder $this */
            return $transpile($this, $dialect, $pretty)['sql'];
        });

        QueryBuilder::macro('bindingsFor', function (string $dialect) use ($transpile): array {
            /** @var QueryBuilder $this */
            return $transpile($this, $dialect, false)['bindings'];
        });

        QueryBuilder::macro('toRawSqlFor', function (string $dialect, bool $pretty = false) use ($transpile): string {
            /** @var QueryBuilder $this */
            ['sql' => $sql, 'bindings' => $bindings] = $transpile($this, $dialect, $pretty);

            return $this->grammar->substituteBindingsIntoRawSql($sql, $this->connection->prepareBindings($bindings));
        });

        foreach (['toSqlFor', 'bindingsFor', 'toRawSqlFor'] as $method) {
            EloquentBuilder::macro($method, function (mixed ...$arguments) use ($method): mixed {
                /** @var EloquentBuilder<Model> $this */
                return $this->toBase()->{$method}(...$arguments);
            });
        }
    }
}
