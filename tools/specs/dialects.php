<?php

declare(strict_types=1);

/*
 * Dialects that tools/generate.php compiles tables for: sqlglot dialect name => PHP class name.
 *
 * A dialect's generated tables live next to its hand-written classes in
 * src/Dialects/<Class>/<Class><Component>Tables.php. The base dialect's tables live next
 * to the base classes instead, e.g. src/Tokenizer/TokenizerTables.php.
 */

return [
    'base' => 'Dialect',
    'mysql' => 'MySql',
    'postgres' => 'Postgres',
    'sqlite' => 'Sqlite',
    'tsql' => 'TSql',
    'redshift' => 'Redshift',
    'oracle' => 'Oracle',
    'clickhouse' => 'ClickHouse',
    'bigquery' => 'BigQuery',
    'snowflake' => 'Snowflake',
    'duckdb' => 'DuckDB',
];
