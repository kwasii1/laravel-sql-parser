<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser;

use Composer\InstalledVersions;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as Config;
use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Errors\ErrorLevel;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Tokenizer\Token;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

class LaravelSqlParser
{
    /** Named placeholder that stands in for a "?" binding while a query is transpiled. */
    private const string BINDING_MARKER = '__lsp';

    /** @var array<string, list<string>> per-process transpile results, oldest first */
    private array $memo = [];

    private ?string $version = null;

    public function __construct(
        private readonly Config $config,
        private readonly CacheFactory $cache,
    ) {
        foreach ($this->configArray('dialects') as $name => $class) {
            if (is_string($name) && is_string($class) && is_subclass_of($class, Dialect::class)) {
                Dialect::register($name, $class);
            }
        }
    }

    /**
     * Resolve a dialect by name, alias, Laravel driver name or instance. Null means the configured
     * default dialect, or the driver of the default database connection.
     */
    public function dialect(string|Dialect|null $dialect = null): Dialect
    {
        return Dialect::get($dialect ?? $this->defaultDialect());
    }

    /**
     * @return list<Token>
     */
    public function tokenize(string $sql, string|Dialect|null $dialect = null): array
    {
        return $this->dialect($dialect)->tokenize($sql);
    }

    /**
     * Parse SQL into one expression tree per statement.
     *
     * @return list<Expression|null>
     */
    public function parse(string $sql, string|Dialect|null $dialect = null, ?ErrorLevel $errorLevel = null): array
    {
        return $this->dialect($dialect)->parse($sql, $errorLevel ?? $this->errorLevel());
    }

    /**
     * Parse a single statement; several statements are wrapped in a Block.
     */
    public function parseOne(string $sql, string|Dialect|null $dialect = null, ?ErrorLevel $errorLevel = null): Expression
    {
        return $this->dialect($dialect)->parseOne($sql, $errorLevel ?? $this->errorLevel());
    }

    /**
     * Transpile SQL from one dialect to another; returns one string per statement.
     *
     * @param  bool|array<string, mixed>  $options  generator options, or just the pretty flag
     * @return list<string>
     */
    public function transpile(string $sql, string|Dialect|null $from = null, string|Dialect|null $to = null, bool|array $options = []): array
    {
        $read = $this->dialect($from);
        $write = $to === null ? $read : $this->dialect($to);
        $options = $this->options($options);
        $key = null;

        if ($this->memoSize() > 0 || $this->shouldUseCache($sql)) {
            $key = hash('xxh128', (string) json_encode([$sql, $read::class, $write::class, $options, $this->version()]));

            if (isset($this->memo[$key])) {
                return $this->memo[$key];
            }

            $cached = $this->shouldUseCache($sql) ? $this->cacheStore()->get($this->cachePrefix().$key) : null;

            if (is_array($cached) && array_is_list($cached)) {
                /** @var list<string> $cached */
                return $this->remember($key, $cached);
            }
        }

        $statements = Dialect::transpile($sql, $read, $write, $options, $this->errorLevel());

        if ($key !== null && $this->shouldUseCache($sql)) {
            $this->cacheStore()->put($this->cachePrefix().$key, $statements, $this->cacheTtl());
        }

        return $key === null ? $statements : $this->remember($key, $statements);
    }

    /**
     * Transpile one statement with "?" placeholders and return the bindings in the order the new
     * SQL uses them. Rewrites can move placeholders (T-SQL puts LIMIT first as TOP) or repeat them,
     * so each "?" is tracked through the transpile.
     *
     * @param  array<array-key, mixed>  $bindings
     * @param  bool|array<string, mixed>  $options
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function transpileQuery(string $sql, array $bindings, string|Dialect|null $from = null, string|Dialect|null $to = null, bool|array $options = []): array
    {
        $read = $this->dialect($from);
        $bindings = array_values($bindings);
        $marked = '';
        $offset = 0;
        $count = 0;

        // Only real placeholder tokens: a "?" inside a string, identifier or comment stays as it is
        foreach ($read->tokenize($sql) as $token) {
            if ($token->type === TokenType::PLACEHOLDER && $token->text === '?') {
                $marked .= substr($sql, $offset, $token->start - $offset).':'.self::BINDING_MARKER.$count++;
                $offset = $token->start + 1;
            }
        }

        $marked .= substr($sql, $offset);
        $generated = implode(";\n", $this->transpile($marked, $read, $to, $options));
        $order = [];

        // Named placeholders come out as :name, or %(name)s in Postgres
        $transpiled = (string) preg_replace_callback(
            '/(?::|%\()'.self::BINDING_MARKER.'(\d+)(?:\)s)?/',
            static function (array $match) use (&$order): string {
                $order[] = (int) $match[1];

                return '?';
            },
            $generated,
        );

        return [
            'sql' => $transpiled,
            'bindings' => array_map(static fn (int $index): mixed => $bindings[$index] ?? null, $order),
        ];
    }

    /**
     * The dialect for a Laravel database driver name (mysql, mariadb, pgsql, sqlite, sqlsrv).
     */
    public function dialectForDriver(string $driver): Dialect
    {
        return Dialect::get($driver);
    }

    private function defaultDialect(): string
    {
        $configured = $this->config->get('laravel-sql-parser.default_dialect');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $connection = $this->config->get('database.default');
        $driver = is_string($connection) ? $this->config->get("database.connections.{$connection}.driver") : null;

        return is_string($driver) ? $driver : '';
    }

    private function errorLevel(): ErrorLevel
    {
        $level = $this->config->get('laravel-sql-parser.error_level');

        return match (true) {
            $level instanceof ErrorLevel => $level,
            is_string($level) => ErrorLevel::tryFrom(strtolower($level)) ?? ErrorLevel::Immediate,
            default => ErrorLevel::Immediate,
        };
    }

    /**
     * @param  bool|array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function options(bool|array $options): array
    {
        if (is_bool($options)) {
            $options = ['pretty' => $options];
        }

        return $options + ['pretty' => (bool) $this->config->get('laravel-sql-parser.pretty', false)];
    }

    /**
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function remember(string $key, array $statements): array
    {
        $size = $this->memoSize();

        if ($size <= 0) {
            return $statements;
        }

        unset($this->memo[$key]);
        $this->memo[$key] = $statements;

        if (count($this->memo) > $size) {
            unset($this->memo[array_key_first($this->memo)]);
        }

        return $statements;
    }

    private function memoSize(): int
    {
        return $this->config->get('laravel-sql-parser.memo.enabled') === true
            ? max(0, $this->configInt('memo.size', 1000))
            : 0;
    }

    private function shouldUseCache(string $sql): bool
    {
        return $this->config->get('laravel-sql-parser.cache.enabled') === true
            && strlen($sql) >= $this->configInt('cache.min_length', 0);
    }

    private function cacheStore(): Repository
    {
        $store = $this->config->get('laravel-sql-parser.cache.store');

        return $this->cache->store(is_string($store) && $store !== '' ? $store : null);
    }

    private function cachePrefix(): string
    {
        $prefix = $this->config->get('laravel-sql-parser.cache.prefix');

        return is_string($prefix) ? $prefix : 'sql-parser:';
    }

    private function cacheTtl(): ?int
    {
        $ttl = $this->config->get('laravel-sql-parser.cache.ttl');

        return is_int($ttl) ? $ttl : null;
    }

    private function configInt(string $key, int $default): int
    {
        $value = $this->config->get("laravel-sql-parser.{$key}");

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function configArray(string $key): array
    {
        $value = $this->config->get("laravel-sql-parser.{$key}");

        return is_array($value) ? $value : [];
    }

    /**
     * Part of every cache key, so an upgrade never serves output from an older generator.
     */
    private function version(): string
    {
        return $this->version ??= class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('kwasii/laravel-sql-parser')
            ? (string) InstalledVersions::getReference('kwasii/laravel-sql-parser')
            : 'dev';
    }
}
