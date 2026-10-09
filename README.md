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

Fast SQL Parser for PHP Laravel

## Installation

You can install the package via Composer:

```bash
composer require kwasii1/laravel-sql-parser
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="laravel-sql-parser"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="laravel-sql-parser-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="laravel-sql-parser-migrations"
php artisan migrate
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="laravel-sql-parser-views"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="laravel-sql-parser-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="laravel-sql-parser-assets"
```

## Usage

<!-- Add a basic usage example here. -->

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
