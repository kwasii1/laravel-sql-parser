<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Tests\Support;

use Closure;
use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Schema\MappingSchema;
use RuntimeException;

/**
 * Replays the optimizer cases recorded from sqlglot's tests/test_optimizer.py
 * (tests/Fixtures/sqlglot/optimizer.json, see tools/sqlglot-reference/record_optimizer.py).
 *
 * Each sqlglot function maps to a static method of the PHP class porting its module:
 * sqlglot.optimizer.qualify_tables.qualify_tables -> Optimizer\QualifyTables::qualifyTables().
 * Python keyword arguments become PHP named arguments in camelCase.
 */
final class OptimizerReference
{
    private const string OPTIMIZER = 'LaravelSqlParser\\LaravelSqlParser\\Optimizer\\';

    /** @var array<string, mixed>|null */
    private static ?array $fixture = null;

    /**
     * @return list<array{file: string, function: mixed, pretty: bool, kwargs: array<string, mixed>, cases: list<array{title: string, sql: string, expected: string, actual: string, dialect: string|null, kwargs: array<string, mixed>}>}>
     */
    public static function files(): array
    {
        if (self::$fixture === null) {
            $contents = file_get_contents(dirname(__DIR__).'/Fixtures/sqlglot/optimizer.json');

            if ($contents === false) {
                throw new RuntimeException('Missing fixture optimizer.json');
            }

            self::$fixture = json_decode(str_replace("\r\n", "\n", $contents), true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$fixture['files'];
    }

    /**
     * The PHP callable for a recorded sqlglot function, or null while it isn't ported yet.
     */
    public static function function(mixed $function): ?Closure
    {
        // functools.partial(optimize, rules=[...])
        if (is_array($function) && isset($function['@partial'])) {
            $inner = self::function($function['@partial']);
            $bound = self::arguments($function['kwargs']);

            if ($inner === null || in_array(null, $bound['rules'] ?? [], true)) {
                return null;
            }

            return static fn (Expression $expression, mixed ...$kwargs): Expression => $inner($expression, ...[...$bound, ...$kwargs]);
        }

        if (! is_string($function)) {
            return null;
        }

        // The wrappers tests/test_optimizer.py defines around the rules
        $wrapper = match ($function) {
            'tests.test_optimizer.qualify_columns' => static fn (Expression $e, mixed ...$kwargs): Expression => self::call('qualify.qualify', $e, ...[
                'inferSchema' => true,
                'validateQualifyColumns' => $kwargs['validateQualifyColumns'] ?? true,
                'identify' => false,
                ...$kwargs,
            ]),
            'tests.test_optimizer.pushdown_projections' => static fn (Expression $e, mixed ...$kwargs): Expression => self::call(
                'pushdown_projections.pushdown_projections',
                self::call('qualify_columns.qualify_columns', self::call('qualify_tables.qualify_tables', $e), ...['inferSchema' => true, ...$kwargs]),
            ),
            'tests.test_optimizer.normalize' => static fn (Expression $e, mixed ...$kwargs): Expression => self::call(
                'simplify.simplify',
                self::call('annotate_types.annotate_types', self::call('normalize.normalize', $e, dnf: false), schema: $kwargs['schema'] ?? null),
            ),
            'tests.test_optimizer.simplify' => static fn (Expression $e, mixed ...$kwargs): Expression => self::call(
                'simplify.simplify',
                self::call('annotate_types.annotate_types', $e, schema: $kwargs['schema'] ?? null, dialect: $kwargs['dialect'] ?? null),
                constantPropagation: true,
                coalesceSimplification: true,
                dialect: $kwargs['dialect'] ?? null,
            ),
            'tests.test_optimizer.pushdown_ctes' => static fn (Expression $e): Expression => self::call('qualify_columns.pushdown_cte_alias_columns_for_scope', $e),
            'tests.test_optimizer.qualify_then_canonicalize' => static fn (Expression $e, mixed ...$kwargs): Expression => self::call(
                'canonicalize_internal_names.canonicalize_internal_names',
                self::call('qualify.qualify', $e, ...$kwargs),
            ),
            default => null,
        };

        if ($wrapper !== null) {
            return self::ported($function) ? $wrapper : null;
        }

        if (! str_starts_with($function, 'sqlglot.optimizer.') || self::target(substr($function, 18)) === null) {
            return null;
        }

        return static fn (Expression $e, mixed ...$kwargs): Expression => self::call(substr($function, 18), $e, ...$kwargs);
    }

    /**
     * Recorded Python keyword arguments as PHP named arguments.
     *
     * @param  array<string, mixed>  $kwargs
     * @return array<string, mixed>
     */
    public static function arguments(array $kwargs): array
    {
        $out = [];

        foreach ($kwargs as $key => $value) {
            $name = lcfirst(str_replace('_', '', ucwords($key, '_')));

            $out[$name] = match ($key) {
                'schema' => self::schema($value),
                'rules' => array_map(static fn (string $rule): ?Closure => self::function($rule), $value),
                default => $value,
            };
        }

        return $out;
    }

    /**
     * Whether every optimizer function a test wrapper calls has been ported.
     */
    private static function ported(string $wrapper): bool
    {
        $needs = match ($wrapper) {
            'tests.test_optimizer.qualify_columns' => ['qualify.qualify'],
            'tests.test_optimizer.pushdown_projections' => ['qualify_tables.qualify_tables', 'qualify_columns.qualify_columns', 'pushdown_projections.pushdown_projections'],
            'tests.test_optimizer.normalize' => ['normalize.normalize', 'annotate_types.annotate_types', 'simplify.simplify'],
            'tests.test_optimizer.simplify' => ['annotate_types.annotate_types', 'simplify.simplify'],
            'tests.test_optimizer.pushdown_ctes' => ['qualify_columns.pushdown_cte_alias_columns_for_scope'],
            'tests.test_optimizer.qualify_then_canonicalize' => ['qualify.qualify', 'canonicalize_internal_names.canonicalize_internal_names'],
            default => [],
        };

        foreach ($needs as $name) {
            if (self::target($name) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * module.function (relative to sqlglot.optimizer) -> [PHP class, method], when ported.
     *
     * @return array{0: class-string, 1: string}|null
     */
    private static function target(string $name): ?array
    {
        [$module, $function] = explode('.', $name, 2);
        $class = self::OPTIMIZER.str_replace('_', '', ucwords($module, '_'));
        $method = lcfirst(str_replace('_', '', ucwords($function, '_')));

        return class_exists($class) && method_exists($class, $method) ? [$class, $method] : null;
    }

    private static function call(string $name, Expression $expression, mixed ...$kwargs): Expression
    {
        $target = self::target($name) ?? throw new RuntimeException("Optimizer function {$name} isn't ported");

        $result = ($target[0])::{$target[1]}($expression, ...$kwargs);

        return $result instanceof Expression ? $result : $expression;
    }

    /**
     * A recorded schema: nested arrays of column => type SQL, with non-nullable types recorded as
     * {"@type": ..., "nullable": false}; a MappingSchema as {"@schema": mapping, "visible": ..., "dialect": ...}.
     */
    private static function schema(mixed $schema): mixed
    {
        if (is_array($schema) && array_key_exists('@schema', $schema)) {
            $mapping = self::schema($schema['@schema']);

            // A MappingSchema with visible columns can't be passed on as a plain array
            if (($schema['visible'] ?? []) !== [] && is_array($mapping)) {
                return new MappingSchema($mapping, visible: $schema['visible']);
            }

            return $mapping;
        }

        if (is_array($schema) && array_key_exists('@type', $schema)) {
            $type = DataType::build((string) $schema['@type']);

            if ($schema['nullable'] === false) {
                $type->set('nullable', false);
            }

            return $type;
        }

        if (is_array($schema)) {
            return array_map(self::schema(...), $schema);
        }

        return $schema;
    }

    /**
     * The dialect a case's SQL is read and written in.
     */
    public static function dialect(?string $name): Dialect
    {
        return Dialect::get($name === null || $name === '' ? null : $name);
    }
}
