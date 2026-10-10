<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use InvalidArgumentException;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\ExpressionRegistry;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeUnitExpression;

/**
 * JSON-serializable dumps of expression trees (sqlglot serde.py).
 *
 * The format is sqlglot's: a flat list of payloads in depth-first order, each with its parent's
 * index (i), argument key (k), whether it's a list item (a), and either a class name (c, plus
 * type t, comments o and meta m) or a plain value (v). Class names are sqlglot's, so dumps can be
 * exchanged with sqlglot's Expression.dump()/load().
 *
 * @phpstan-require-extends Expression
 */
trait SerializesExpressions
{
    /**
     * Dump the tree into a JSON-serializable list (sqlglot Expression.dump).
     *
     * @return list<array<string, mixed>>
     */
    public function dump(): array
    {
        $i = 0;
        $payloads = [];
        $stack = [[$this, null, null, false]];

        while ($stack !== []) {
            [$node, $index, $argKey, $isArray] = array_pop($stack);
            $payload = [];

            if ($index !== null) {
                $payload['i'] = $index;
            }

            if ($argKey !== null) {
                $payload['k'] = $argKey;
            }

            if ($isArray) {
                $payload['a'] = true;
            }

            if ($node instanceof Expression) {
                $payload['c'] = self::serdeClassName($node);
                $type = $node->inferredType;

                if ($type !== null && $type !== $node) {
                    $payload['t'] = $type->dump();
                }

                if ($node->comments !== null && $node->comments !== []) {
                    $payload['o'] = $node->comments;
                }

                if ($node->meta !== null) {
                    $payload['m'] = $node->meta;
                }

                foreach (array_reverse($node->args, true) as $key => $value) {
                    if (is_array($value) && array_is_list($value)) {
                        foreach (array_reverse($value) as $item) {
                            $stack[] = [$item, $i, $key, true];
                        }
                    } elseif ($value !== null) {
                        $stack[] = [$value, $i, $key, false];
                    }
                }
            } elseif ($argKey === 'this' && is_string($node) && $index !== null && ($payloads[$index]['c'] ?? null) === 'DataType') {
                // DataType's `this` is a DType member in sqlglot
                $payload['c'] = 'DataType.Type';
                $payload['v'] = $node;
            } else {
                $payload['v'] = $node;
            }

            $payloads[] = $payload;
            $i++;
        }

        return $payloads;
    }

    /**
     * Rebuild a tree from dump() output, or from sqlglot's (sqlglot Expression.load).
     *
     * @param  list<array<string, mixed>>|null  $payloads
     */
    public static function load(?array $payloads): ?Expression
    {
        if ($payloads === null || $payloads === []) {
            return null;
        }

        $root = self::loadPayload($payloads[0]);

        if (! $root instanceof Expression) {
            throw new InvalidArgumentException('The first payload must be an expression.');
        }

        $nodes = [$root];

        foreach (array_slice($payloads, 1) as $payload) {
            $node = isset($payload['c']) ? self::loadPayload($payload) : ($payload['v'] ?? null);
            $nodes[] = $node;
            $parent = $nodes[$payload['i'] ?? -1] ?? null;
            $argKey = $payload['k'] ?? null;

            if (! $parent instanceof Expression || ! is_string($argKey)) {
                throw new InvalidArgumentException('Malformed payload: missing parent or argument key.');
            }

            if (($payload['a'] ?? false) === true) {
                $parent->append($argKey, $node);
            } else {
                $parent->set($argKey, $node);
            }
        }

        return $root;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function loadPayload(array $payload): mixed
    {
        $className = $payload['c'] ?? null;

        if (! is_string($className)) {
            throw new InvalidArgumentException('Malformed payload: missing class name.');
        }

        if ($className === 'DataType.Type') {
            return $payload['v'] ?? null;
        }

        // sqlglot writes module-qualified names (sqlglot.expressions.query.Select) for its classes
        $short = str_contains($className, '.') ? substr($className, strrpos($className, '.') + 1) : $className;
        $class = ExpressionRegistry::BY_KEY[strtolower($short)] ?? $className;

        if (! is_a($class, Expression::class, true)) {
            throw new InvalidArgumentException("Unknown expression class '{$className}'.");
        }

        $expression = new $class;
        $type = isset($payload['t']) && is_array($payload['t']) ? self::load(array_values($payload['t'])) : null;
        $expression->setType($type instanceof DataType ? $type : null);
        $comments = $payload['o'] ?? null;
        $expression->comments = is_array($comments) ? array_values(array_map(strval(...), $comments)) : null;
        $meta = $payload['m'] ?? null;
        $expression->meta = is_array($meta) ? $meta : null;

        return $expression;
    }

    /**
     * sqlglot's class name for a node (PHP-reserved names drop their Expr suffix); classes outside
     * the expression registry keep their fully qualified PHP name.
     */
    private static function serdeClassName(Expression $node): string
    {
        $class = $node::class;

        if ((ExpressionRegistry::BY_KEY[$node::KEY] ?? null) !== $class) {
            return $class;
        }

        if ($class === TimeUnitExpression::class) {
            return '_TimeUnit';
        }

        $short = substr($class, strrpos($class, '\\') + 1);

        return strtolower($short) !== $node::KEY && str_ends_with($short, 'Expr') ? substr($short, 0, -4) : $short;
    }
}
