---
name: laravel-sql-parser-development
description: >
  Parse SQL into a syntax tree and transpile it between MySQL, MariaDB, PostgreSQL, SQLite,
  SQL Server, BigQuery, Snowflake, Oracle, Spark and 25 more dialects in Laravel applications with kwasii1/laravel-sql-parser: the LaravelSqlParser facade,
  the toSqlFor()/bindingsFor()/toRawSqlFor() query builder macros and the sql:transpile command.
license: MIT
metadata:
  author: kwasii1
---

# Laravel Sql Parser

Use this skill when a Laravel application needs to translate SQL from one database dialect to another, inspect SQL (tables, columns, statements), or show a query as another database would run it.

## Primary Goal

- apply the `kwasii1/laravel-sql-parser` public API in the smallest correct way

## Workflow

### 1. Inspect the Laravel app context

- confirm the package is installed (`composer require kwasii1/laravel-sql-parser`); the service provider and the `LaravelSqlParser` facade alias are auto-discovered
- check which database drivers the app uses (`config/database.php`); with no dialect given, the package reads SQL in the default connection's dialect
- supported dialects: `mysql`, `mariadb`, `postgres` (`pgsql`, `postgresql`), `sqlite`, `tsql` (`sqlsrv`, `mssql`), `bigquery`, `snowflake`, `redshift`, `databricks`, `clickhouse`, `exasol`, `teradata`, `fabric`, `hive`, `spark`, `spark2`, `presto`, `trino`, `athena`, `drill`, `druid`, `dremio`, `dune`, `oracle`, `duckdb`, `doris`, `starrocks`, `singlestore`, `materialize`, `risingwave`, `solr`, `tableau`, `dax`, `prql`; anything else throws `InvalidArgumentException`

### 2. Apply the package's public API

- transpile SQL: `Sql::transpile($sql, from: 'mysql', to: 'pgsql')` returns a `list<string>`, one per statement; pass `true` or `['pretty' => true]` as the fourth argument to format the output
- transpile a builder query: `$query->toSqlFor('sqlsrv')`, `$query->bindingsFor('sqlsrv')` and `$query->toRawSqlFor('mysql')` work on the query builder and on Eloquent builders, and read the query in its connection's dialect
- always pair `toSqlFor()` with `bindingsFor()`, never with `getBindings()`: rewrites can move or repeat placeholders (SQL Server's `TOP (?)`, `ILIKE` becoming `LOWER(a) LIKE LOWER(?)`)
- raw SQL with `?` bindings: `Sql::transpileQuery($sql, $bindings, $from, $to)` returns `['sql' => ..., 'bindings' => [...]]`
- optimize or qualify SQL: `Optimizer::optimize($sql, schema: ['users' => ['id' => 'INT']], dialect: 'mysql')` and `Qualify::qualify($tree, schema: ..., dialect: ...)` from `LaravelSqlParser\LaravelSqlParser\Optimizer` (sqlglot's optimizer: qualification, simplification, pushdowns, subquery merging); `Builder::findTables($tree)` lists the tables a query reads, CTEs excluded
- inspect SQL: `Sql::parseOne($sql, 'mysql')` returns an expression tree; use `findAll(Table::class)`, `findAll(Column::class)` and `->name()` from `LaravelSqlParser\LaravelSqlParser\Expressions`, and `->sql('postgres')` to write it back
- build SQL: `Builder::select('a')->from('t')->where('x > 1')->orderBy('a')->limit(10)->sql('mysql')` (`LaravelSqlParser\LaravelSqlParser\Expressions\Builder`); also `Builder::insert/update/delete/merge/union/condition/case`, and `and()`, `or()`, `not()`, `as()`, `eq()`, `isin()` on any expression; strings are parsed with `dialect:`, calls copy unless `copy: false`, list methods append unless `append: false`; `from_`/`and_`/`with_` are `from()`/`and()`/`with()`, `Update::set_()` is `assign()`
- serialize a tree: `$tree->dump()` gives a JSON-safe array (sqlglot's format); `Expression::load($array)` rebuilds it
- invalid SQL throws `LaravelSqlParser\LaravelSqlParser\Errors\ParseError` (a `SqlParserException`); catch it around user-supplied SQL
- from the terminal: `php artisan sql:transpile "<sql>" --from=mysql --to=sqlsrv [--pretty] [--file=path] [--ast]`, or pipe SQL on standard input

### 3. Configure only when needed

- publish with `php artisan vendor:publish --tag="laravel-sql-parser-config"`
- `SQL_PARSER_DIALECT` sets the default dialect; `SQL_PARSER_ERROR_LEVEL` is `immediate`, `raise`, `warn` or `ignore`
- `SQL_PARSER_MEMO=true` keeps results in memory for Octane and queue workers; `SQL_PARSER_CACHE=true` (with `SQL_PARSER_CACHE_STORE`) caches statements of at least `cache.min_length` bytes
- custom dialects go in the `dialects` config array as `name => class` extending `LaravelSqlParser\LaravelSqlParser\Dialects\Dialect`

## Rules, References, and Templates

Read before executing:

- no additional resource files for this skill

## Examples

```php
use LaravelSqlParser\LaravelSqlParser\Facades\LaravelSqlParser as Sql;

Sql::transpile('SELECT IFNULL(a, 1) FROM t LIMIT 5', 'mysql', 'tsql');
// ['SELECT TOP 5 COALESCE(a, 1) FROM t']

$query = User::where('email', 'like', '%@example.com')->orderBy('id');
DB::connection('sqlsrv')->select($query->toSqlFor('sqlsrv'), $query->bindingsFor('sqlsrv'));
```

```php
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;

$tables = array_map(fn ($t) => $t->name(), Sql::parseOne($sql, 'pgsql')->findAll(Table::class));
```

## Anti-patterns

- do not build dialect differences with string replacement or regex; transpile instead
- do not reuse `getBindings()` with `toSqlFor()` output; use `bindingsFor()` with the same dialect
- do not assume one statement: `transpile()` and `parse()` return lists
- do not enable `cache` for short queries; transpiling is faster than a cache round trip below `min_length`
