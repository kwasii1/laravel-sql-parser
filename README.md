<div align="center">
    <h1>Laravel Sql Parser</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/kwasii1/laravel-sql-parser"><img src="https://img.shields.io/packagist/v/kwasii1/laravel-sql-parser.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/kwasii1/laravel-sql-parser"><img src="https://img.shields.io/packagist/php-v/kwasii1/laravel-sql-parser.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/kwasii1/laravel-sql-parser"><img src="https://badge.laravel.cloud/badge/kwasii1/laravel-sql-parser?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/kwasii1/laravel-sql-parser/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/kwasii1/laravel-sql-parser/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/kwasii1/laravel-sql-parser"><img src="https://img.shields.io/packagist/dt/kwasii1/laravel-sql-parser.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A fast SQL parser and transpiler for Laravel, written in pure PHP. It parses SQL into a syntax tree and writes it back out in another dialect: MySQL to SQL Server, Postgres to SQLite, and so on. It is a port of [sqlglot](https://github.com/tobymao/sqlglot) (v30.21.0) and passes sqlglot's own test cases for every supported dialect.

**Supported dialects:** every dialect sqlglot ships, plus MariaDB and a generic ANSI dialect. Laravel's driver names (`mysql`, `mariadb`, `pgsql`, `sqlite`, `sqlsrv`) work everywhere a dialect is expected.

| Family | Dialects (name to pass) |
|---|---|
| Laravel drivers | MySQL (`mysql`), MariaDB (`mariadb`), PostgreSQL (`postgres`, `pgsql`), SQLite (`sqlite`), SQL Server (`tsql`, `sqlsrv`, `mssql`) |
| Warehouses | BigQuery (`bigquery`), Snowflake (`snowflake`), Redshift (`redshift`), Databricks (`databricks`), ClickHouse (`clickhouse`), Exasol (`exasol`), Teradata (`teradata`), Microsoft Fabric (`fabric`) |
| Query engines | Hive (`hive`), Spark (`spark`, `spark2`), Presto (`presto`), Trino (`trino`), Athena (`athena`), Drill (`drill`), Druid (`druid`), Dremio (`dremio`), Dune (`dune`) |
| Other databases | Oracle (`oracle`), DuckDB (`duckdb`), Doris (`doris`), StarRocks (`starrocks`), SingleStore (`singlestore`), Materialize (`materialize`), RisingWave (`risingwave`), Solr (`solr`) |
| Other languages | Tableau (`tableau`), DAX (`dax`), PRQL (`prql`) |

## Installation

```bash
composer require kwasii1/laravel-sql-parser
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="laravel-sql-parser-config"
```

## Usage

### Transpiling SQL

```php
use LaravelSqlParser\LaravelSqlParser\Facades\LaravelSqlParser as Sql;

Sql::transpile('SELECT IFNULL(a, 1) FROM t LIMIT 5', from: 'mysql', to: 'tsql');
// ['SELECT TOP 5 COALESCE(a, 1) FROM t']

Sql::transpile('SELECT a, b FROM t', 'mysql', 'postgres', true); // or an options array: ['pretty' => true]
// ["SELECT\n  a,\n  b\nFROM t"]
```

`transpile()` returns one string per statement. When `from` is omitted, the configured `default_dialect` is used, or else the driver of your default database connection; when `to` is omitted, the SQL is written back in the same dialect.

### Query builder

The `toSqlFor()`, `bindingsFor()` and `toRawSqlFor()` macros transpile a query from its connection's dialect, on both the query builder and Eloquent:

```php
$query = User::where('email', 'like', '%@example.com')->orderBy('id');

$query->toSqlFor('sqlsrv');      // 'SELECT * FROM [users] WHERE [email] LIKE ? ORDER BY [id] ASC'
$query->bindingsFor('sqlsrv');   // ['%@example.com']
$query->toRawSqlFor('mysql');    // "SELECT * FROM `users` WHERE `email` LIKE '%@example.com' ORDER BY `id` ASC"
```

Some rewrites move or repeat placeholders: SQL Server puts the limit first as `TOP (?)`, and `ILIKE` becomes `LOWER(a) LIKE LOWER(?)` in MySQL. Each `?` is tracked through the transpile, so `bindingsFor()` returns the bindings in the order the new SQL uses them. For raw SQL with bindings, use `Sql::transpileQuery($sql, $bindings, $from, $to)`, which returns `['sql' => ..., 'bindings' => [...]]`.

### Parsing

```php
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;

$select = Sql::parseOne('SELECT u.id, u.name FROM users AS u JOIN posts ON posts.user_id = u.id', 'mysql');

array_map(fn ($table) => $table->name(), $select->findAll(Table::class));   // ['users', 'posts']
array_map(fn ($column) => $column->name(), $select->findAll(Column::class)); // ['id', 'name', 'user_id', 'id']

$select->sql('postgres'); // generate SQL for any dialect from the tree
```

`Sql::parse()` returns one tree per statement, and `Sql::tokenize()` returns the tokens. Invalid SQL throws a `LaravelSqlParser\LaravelSqlParser\Errors\ParseError` with the line, column and surrounding text.

### Building queries

`Builder` ports sqlglot's fluent builders. Strings are parsed as SQL (pass `dialect:` to choose the dialect), every call returns a copy unless you pass `copy: false`, and list methods append unless you pass `append: false`:

```php
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;

$query = Builder::select('id', 'name')
    ->from('users')
    ->where('active = 1')
    ->where(Builder::column('age')->gte(18))
    ->orderBy('name')
    ->limit(10);

$query->sql('tsql'); // 'SELECT TOP 10 id, name FROM users WHERE active = 1 AND age >= 18 ORDER BY name'

Builder::condition('x = 1')->and('y = 2')->or('z = 3')->sql();       // '(x = 1 AND y = 2) OR z = 3'
Builder::update('users', ['active' => false], where: 'id = 5')->sql(); // 'UPDATE users SET active = FALSE WHERE id = 5'
```

Also available: `Builder::from()`, `insert()`, `delete()`, `merge()`, `union()`, `intersect()`, `except()`, `subquery()`, `values()`, `case()`, `cast()`, `alias()`, `func()`, and on queries `join()`, `groupBy()`, `having()`, `with()`, `window()`, `qualify()`, `distinct()`, `ctas()`. Any expression has `and()`, `or()`, `not()`, `as()`, `eq()`, `neq()`, `gt()`, `isin()`, `between()`, `like()`, `is()` and friends. Python names ending in `_` drop it (`from_` is `from()`), and `Update::set_()` is `assign()`.

### Optimizing queries

`Optimizer::optimize()` ports sqlglot's optimizer: it qualifies tables and columns (expanding `*` from the schema), simplifies expressions, pushes predicates and projections down, merges and unnests subqueries and removes unused joins and CTEs. It takes a SQL string or a tree and returns a new tree:

```php
use LaravelSqlParser\LaravelSqlParser\Optimizer\Optimizer;

$schema = ['x' => ['a' => 'INT', 'b' => 'INT']];

Optimizer::optimize('SELECT a FROM (SELECT x.a FROM x) AS s WHERE 1 + 1 = 2 AND a > 1', schema: $schema)->sql();
// 'SELECT "x"."a" AS "a" FROM "x" AS "x" WHERE "x"."a" > 1'
```

Pass `dialect:` for dialect-specific SQL, and `rules:` to run only some steps. Each rule is also usable on its own: for example `Qualify::qualify()` resolves every column to its table without rewriting the query, and `Simplify::simplify()` only folds expressions. The schema is a nested array of `table => column => type` (optionally `db => table => ...` or `catalog => db => table => ...`), or a `Schema\MappingSchema`.

`Builder::findTables()` lists the tables a query reads (CTE names excluded), `Builder::tableName()` formats one, and `Builder::replaceTables()`, `Builder::expand()` and `Builder::replacePlaceholders()` rewrite them.

### Serializing trees

`dump()` turns a tree into a JSON-serializable array and `Expression::load()` rebuilds it, in the same format as sqlglot's `dump()`/`load()`:

```php
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

$json = json_encode($query->dump());
$copy = Expression::load(json_decode($json, true));

$copy->equals($query); // true
```

### Artisan command

```bash
php artisan sql:transpile "SELECT IFNULL(a, 1) FROM t LIMIT 5" --from=mysql --to=sqlsrv --pretty
php artisan sql:transpile --file=dump.sql --from=pgsql --to=mysql
cat query.sql | php artisan sql:transpile --from=mysql --to=postgres
php artisan sql:transpile "SELECT 1" --ast
```

### Without Laravel

The parser core has no Laravel dependency:

```php
use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;

Dialect::transpile('SELECT IFNULL(a, 1)', read: 'mysql', write: 'postgres');
Dialect::get('mysql')->parseOne('SELECT 1');
```

## Configuration

| Key | Default | |
|---|---|---|
| `default_dialect` | `null` | Dialect used when none is given; `null` uses the default connection's driver (`SQL_PARSER_DIALECT`) |
| `error_level` | `immediate` | `immediate`, `raise`, `warn` or `ignore` (`SQL_PARSER_ERROR_LEVEL`) |
| `pretty` | `false` | Format transpiled SQL over several lines by default |
| `memo.enabled`, `memo.size` | `false`, `1000` | Keep recent results in memory for the life of the process, useful under Octane or in queue workers |
| `cache.enabled` | `false` | Store results in a Laravel cache store |
| `cache.store`, `cache.ttl`, `cache.prefix` | `null`, `86400`, `sql-parser:` | Which store, for how long, and under which key prefix |
| `cache.min_length` | `2000` | Shorter statements skip the cache: a cache round trip can cost more than transpiling them |
| `dialects` | `[]` | Custom dialects, `name => class` extending `Dialects\Dialect` |
| `macros` | `true` | Register the query builder macros |

## Performance

The parser is a pure-PHP port with a flat token store, cached dispatch and a fast path through the operator levels. Times below are the best of three rounds on sqlglot's own benchmark queries (PHP 8.4, opcache on; sqlglot 30.21.0 on Python 3.12, pure Python and the compiled `sqlglot[c]` build; same machine, WSL2). Bold marks this package's faster setting.

**Parse**

| Query | sqlglot | sqlglot (compiled) | this package | this package + JIT |
|---|---:|---:|---:|---:|
| tpch | 2.5 ms | 676 µs | **793 µs** | 3.3 ms |
| short | 175 µs | 47 µs | **79 µs** | 112 µs |
| deep_arithmetic | 7.6 ms | 2.3 ms | 2.7 ms | **2.3 ms** |
| large_in | 488 ms | 116 ms | 156 ms | **90.8 ms** |
| values | 532 ms | 135 ms | 167 ms | **104 ms** |
| many_joins | 10.1 ms | 2.9 ms | 4.0 ms | **2.6 ms** |
| many_unions | 38.8 ms | 10.8 ms | 14.3 ms | **9.9 ms** |
| nested_subqueries | 1.3 ms | 255 µs | 347 µs | **223 µs** |
| many_columns | 12.0 ms | 3.3 ms | 4.1 ms | **2.5 ms** |
| large_case | 35.4 ms | 10.0 ms | 15.2 ms | **8.7 ms** |
| complex_where | 31.8 ms | 7.6 ms | 12.7 ms | **7.2 ms** |
| many_ctes | 19.6 ms | 4.0 ms | 6.3 ms | **3.4 ms** |
| many_windows | 21.6 ms | 5.2 ms | 7.3 ms | **4.6 ms** |
| nested_functions | 895 µs | 165 µs | 240 µs | **139 µs** |
| large_strings | 5.3 ms | 1.5 ms | 1.8 ms | **1.0 ms** |
| many_numbers | 103 ms | 28.9 ms | 38.6 ms | **22.2 ms** |

**Transpile** (parse and generate)

| Query | sqlglot | sqlglot (compiled) | this package | this package + JIT |
|---|---:|---:|---:|---:|
| tpch | 3.5 ms | 926 µs | **1.5 ms** | 2.3 ms |
| short | 248 µs | 75 µs | **134 µs** | 159 µs |
| deep_arithmetic | 9.0 ms | 2.8 ms | 3.8 ms | **2.1 ms** |
| large_in | 512 ms | 128 ms | 161 ms | **97.8 ms** |
| values | 590 ms | 154 ms | 209 ms | **114 ms** |
| many_joins | 14.5 ms | 3.6 ms | 6.1 ms | **4.1 ms** |
| many_unions | 54.0 ms | 13.8 ms | 27.2 ms | **16.0 ms** |
| nested_subqueries | 1.8 ms | 378 µs | 651 µs | **442 µs** |
| many_columns | 15.3 ms | 3.8 ms | 5.8 ms | **3.4 ms** |
| large_case | 44.8 ms | 11.0 ms | 19.2 ms | **11.7 ms** |
| complex_where | 35.9 ms | 9.0 ms | 15.2 ms | **10.4 ms** |
| many_ctes | 22.0 ms | 5.0 ms | 9.1 ms | **5.8 ms** |
| many_windows | 23.5 ms | 6.4 ms | 9.6 ms | **6.2 ms** |
| nested_functions | 918 µs | 191 µs | 293 µs | **180 µs** |
| large_strings | 5.3 ms | 1.5 ms | 2.1 ms | **1.2 ms** |
| many_numbers | 98.0 ms | 29.4 ms | 44.9 ms | **27.8 ms** |

Against pure-Python sqlglot it is 2.5–4.4x faster on average (geometric mean) and faster on every query. With JIT it is level with the compiled build: about 1.1x faster at parsing and 0.97x at transpiling. Without JIT the compiled build is faster.

### Production tips

- **Turn on OPcache** (and `opcache.enable_cli=1` for queue workers and Octane).
- **JIT** (`opcache.jit=tracing`, `opcache.jit_buffer_size=128M`) roughly halves the time for large statements. Small statements gain nothing, so it only pays off for long SQL or long-running processes.
- **Preload the parser core** to skip loading about 1,100 classes per request. It cut the first transpile in a request from about 0.9 ms to about 0.45 ms:

  ```ini
  opcache.preload=/path/to/app/vendor/kwasii1/laravel-sql-parser/preload.php
  opcache.preload_user=www-data
  ```

  Preloading isn't available on Windows. The package works the same without it.
- **Optimize the autoloader:** `composer dump-autoload --classmap-authoritative`.
- **Cache repeated work:** turn on `memo` for Octane and queue workers, and `cache` for long statements you transpile often.

Run the benchmarks yourself with `composer bench` (add `-- --subject=transpile` for transpiling).

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Sql Parser! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [kwasii1](https://github.com/kwasii1)
- [All Contributors](../../contributors)

## License

Laravel Sql Parser is open-sourced software licensed under the [MIT license](LICENSE.md).
