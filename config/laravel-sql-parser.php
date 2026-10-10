<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default dialect
    |--------------------------------------------------------------------------
    |
    | The dialect used when none is given: a dialect name (mysql, mariadb, postgres, sqlite, tsql)
    | or a Laravel driver name (pgsql, sqlsrv). Null uses the driver of the default connection.
    |
    */

    'default_dialect' => env('SQL_PARSER_DIALECT'),

    /*
    |--------------------------------------------------------------------------
    | Errors and output
    |--------------------------------------------------------------------------
    |
    | error_level: immediate (throw on the first error), raise (throw once with every error),
    | warn (log and carry on) or ignore. pretty: format transpiled SQL over several lines.
    |
    */

    'error_level' => env('SQL_PARSER_ERROR_LEVEL', 'immediate'),

    'pretty' => false,

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | memo keeps recent transpile results in memory for the life of the process, which helps
    | long-running workers (Octane, queues). cache stores results in a Laravel cache store; it
    | only pays off for long SQL, since a cache round trip can cost more than transpiling a
    | short query, so statements shorter than min_length bytes skip it.
    |
    */

    'memo' => [
        'enabled' => env('SQL_PARSER_MEMO', false),
        'size' => 1000,
    ],

    'cache' => [
        'enabled' => env('SQL_PARSER_CACHE', false),
        'store' => env('SQL_PARSER_CACHE_STORE'),
        'ttl' => 86400,
        'prefix' => 'sql-parser:',
        'min_length' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom dialects
    |--------------------------------------------------------------------------
    |
    | Extra dialects by name, each a class extending
    | Kwasii\LaravelSqlParser\Dialects\Dialect.
    |
    */

    'dialects' => [],

    /*
    |--------------------------------------------------------------------------
    | Query builder macros
    |--------------------------------------------------------------------------
    |
    | Registers toSqlFor(), toRawSqlFor() and bindingsFor() on the query and Eloquent builders.
    |
    */

    'macros' => true,

];
