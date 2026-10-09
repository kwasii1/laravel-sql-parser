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

**Supported dialects:** MySQL, MariaDB, PostgreSQL, SQLite and SQL Server (T-SQL), plus a generic ANSI dialect. Laravel's driver names (`mysql`, `mariadb`, `pgsql`, `sqlite`, `sqlsrv`) work everywhere a dialect is expected.

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
