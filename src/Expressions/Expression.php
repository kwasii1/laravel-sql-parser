<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

use Generator;
use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LogicException;
use Stringable;

/**
 * Base class of every AST node (sqlglot: exp.Expression).
 *
 * Children live in `$args`, keyed by argument name. A value is an Expression, a list of
 * Expressions, a string, a bool, or null. Children point back to their parent through
 * `$parent`, `$argKey` and `$index`, which `set()`, `append()` and the constructor keep
 * up to date.
 *
 * Traversal, copying, hashing and equality are iterative so very deep trees (long AND/OR
 * chains, huge IN lists) never exhaust the stack.
 *
 * @phpstan-consistent-constructor
 */
abstract class Expression implements Stringable
{
    use Concerns\BuildsConditions;
    use Concerns\SerializesExpressions;

    public const string KEY = 'expression';

    /** @var array<string, bool> argument name => required */
    public const array ARG_TYPES = ['this' => true];

    /** @var list<string> */
    public const array REQUIRED_ARGS = ['this'];

    public const bool IS_VAR_LEN_ARGS = false;

    public const string VAR_LEN_ARG_KEY = 'expressions';

    /** Primitive nodes (literals, identifiers, ...) skip parent wiring and validation. */
    public const bool IS_PRIMITIVE = false;

    public const bool HASH_RAW_ARGS = false;

    public const bool IS_SUBQUERY = false;

    public const bool IS_CAST = false;

    public const bool IS_DATA_TYPE = false;

    /** @var list<string> SQL names of a function; the first one is used when generating SQL */
    public const array SQL_NAMES = [];

    /** @var array<string, mixed> */
    public array $args;

    public ?Expression $parent = null;

    public ?string $argKey = null;

    public ?int $index = null;

    /** @var list<string>|null */
    public ?array $comments = null;

    /** @var array<string, mixed>|null */
    public ?array $meta = null;

    protected ?DataType $inferredType = null;

    private ?string $hashCache = null;

    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(array $args = [])
    {
        $this->args = $args;

        if (static::IS_PRIMITIVE) {
            return;
        }

        foreach ($args as $key => $value) {
            if ($value instanceof self) {
                $value->parent = $this;
                $value->argKey = $key;
                $value->index = null;
            } elseif (is_array($value)) {
                foreach ($value as $i => $item) {
                    if ($item instanceof self) {
                        $item->parent = $this;
                        $item->argKey = $key;
                        $item->index = $i;
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------------------------------
    // Function metadata (sqlglot Func classmethods)
    // ---------------------------------------------------------------------------------------

    /**
     * Build a function node from positional arguments (sqlglot Func.from_arg_list).
     *
     * @param  list<mixed>  $args
     */
    public static function fromArgList(array $args): static
    {
        $keys = array_keys(static::ARG_TYPES);
        $named = [];

        if (static::IS_VAR_LEN_ARGS) {
            $varIndex = array_search(static::VAR_LEN_ARG_KEY, $keys, true);
            $varIndex = $varIndex === false ? count($keys) : $varIndex;

            foreach ($args as $i => $arg) {
                if ($i >= $varIndex) {
                    break;
                }
                $named[$keys[$i]] = $arg;
            }

            $named[static::VAR_LEN_ARG_KEY] = array_slice($args, $varIndex);
        } else {
            foreach ($args as $i => $arg) {
                if (! isset($keys[$i])) {
                    break;
                }
                $named[$keys[$i]] = $arg;
            }
        }

        return new static($named);
    }

    /**
     * @return list<string>
     */
    public static function sqlNames(): array
    {
        return static::SQL_NAMES;
    }

    public static function sqlName(): string
    {
        return static::SQL_NAMES[0] ?? strtoupper(static::KEY);
    }

    // ---------------------------------------------------------------------------------------
    // Argument accessors
    // ---------------------------------------------------------------------------------------

    public function this(): mixed
    {
        return $this->args['this'] ?? null;
    }

    public function expression(): mixed
    {
        return $this->args['expression'] ?? null;
    }

    /**
     * @return list<mixed>
     */
    public function expressions(): array
    {
        $value = $this->args['expressions'] ?? null;

        return is_array($value) ? array_values($value) : [];
    }

    public function arg(string $key): mixed
    {
        return $this->args[$key] ?? null;
    }

    /**
     * The argument as an Expression, or null when it's missing or not an Expression.
     */
    public function argExpr(string $key): ?self
    {
        $value = $this->args[$key] ?? null;

        return $value instanceof self ? $value : null;
    }

    /**
     * The argument as a list of Expressions (non-Expression items are skipped).
     *
     * @return list<Expression>
     */
    public function argList(string $key): array
    {
        $value = $this->args[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if ($item instanceof self) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Whether a flag-like argument is set (true, or any non-empty value).
     */
    public function flag(string $key): bool
    {
        $value = $this->args[$key] ?? null;

        return $value !== null && $value !== false && $value !== '' && $value !== [];
    }

    /**
     * The text of an argument: strings as-is, identifiers/literals/vars by their text.
     */
    public function text(string $key): string
    {
        $field = $this->args[$key] ?? null;

        if (is_string($field)) {
            return $field;
        }

        if ($field instanceof Identifier || $field instanceof Literal || $field instanceof VarExpr) {
            $text = $field->args['this'] ?? '';

            return is_string($text) ? $text : '';
        }

        if ($field instanceof Star || $field instanceof NullExpr) {
            return $field->name();
        }

        return '';
    }

    public function name(): string
    {
        return $this->text('this');
    }

    public function alias(): string
    {
        $alias = $this->args['alias'] ?? null;

        if ($alias instanceof self) {
            return $alias->name();
        }

        return $this->text('alias');
    }

    /**
     * @return list<string>
     */
    public function aliasColumnNames(): array
    {
        $alias = $this->args['alias'] ?? null;

        if (! $alias instanceof self) {
            return [];
        }

        return array_map(static fn (Expression $column): string => $column->name(), $alias->argList('columns'));
    }

    public function aliasOrName(): string
    {
        $alias = $this->alias();

        return $alias !== '' ? $alias : $this->name();
    }

    /**
     * The name of the column this expression produces when used as a projection.
     */
    public function outputName(): string
    {
        return '';
    }

    public function isString(): bool
    {
        return $this instanceof Literal && ($this->args['is_string'] ?? false) === true;
    }

    public function isNumber(): bool
    {
        if ($this instanceof Literal) {
            return ($this->args['is_string'] ?? false) !== true;
        }

        return $this instanceof Neg && $this->args['this'] instanceof self && $this->args['this']->isNumber();
    }

    public function isInt(): bool
    {
        // Python's int(): leading zeros and integers too big for PHP still count
        return $this->isNumber() && preg_match('/^\s*[+-]?\d+\s*$/', (string) ($this->args['this'] ?? '')) === 1;
    }

    public function isStar(): bool
    {
        return $this instanceof Star || ($this instanceof Column && ($this->args['this'] ?? null) instanceof Star);
    }

    /**
     * Convert a literal-like expression into a PHP value.
     */
    public function toPhp(): mixed
    {
        throw new LogicException(static::class.' cannot be converted to a PHP value.');
    }

    public function isLeaf(): bool
    {
        foreach ($this->args as $value) {
            if ($value instanceof self || (is_array($value) && $value !== [])) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------------------------------
    // Types and metadata
    // ---------------------------------------------------------------------------------------

    public function type(): ?DataType
    {
        if ($this instanceof DataType) {
            return $this;
        }

        if (static::IS_CAST) {
            if ($this->inferredType !== null) {
                return $this->inferredType;
            }

            $to = $this->args['to'] ?? null;

            return $to instanceof DataType ? $to : null;
        }

        return $this->inferredType;
    }

    public function setType(DataType|string|null $type): void
    {
        $this->inferredType = is_string($type) ? DataType::build($type) : $type;
    }

    public function isType(string ...$types): bool
    {
        // type() also covers casts, whose type is their target (sqlglot Expr.type)
        $type = $this->type();

        return $type !== null && $type->isType(...$types);
    }

    public function metaGet(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    public function setMeta(string $key, mixed $value): void
    {
        $this->meta ??= [];
        $this->meta[$key] = $value;
    }

    /**
     * @param  list<string>|null  $comments
     */
    public function addComments(?array $comments = null, bool $prepend = false): void
    {
        $this->comments ??= [];

        if ($comments === null || $comments === []) {
            return;
        }

        foreach ($comments as $comment) {
            $parts = explode(self::SQLGLOT_META, $comment, 2);

            if (count($parts) === 2) {
                foreach (explode(',', $parts[1]) as $pair) {
                    $kv = explode('=', $pair, 2);
                    $this->setMeta(trim($kv[0]), self::toBool(isset($kv[1]) ? trim($kv[1]) : true));
                }
            }
        }

        $existing = $this->comments ?? [];
        $this->comments = $prepend ? array_merge($comments, $existing) : array_merge($existing, $comments);
    }

    /**
     * @return list<string>
     */
    public function popComments(): array
    {
        $comments = $this->comments ?? [];
        $this->comments = null;

        return $comments;
    }

    public const string SQLGLOT_META = 'sqlglot.meta';

    private static function toBool(string|bool $value): string|bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '1' => true,
            'false', '0' => false,
            default => $value,
        };
    }

    // ---------------------------------------------------------------------------------------
    // Mutation
    // ---------------------------------------------------------------------------------------

    /**
     * Set an argument, keeping parent pointers up to date. A null value removes the argument;
     * with an index, a single list item is replaced, inserted or (null) removed.
     */
    public function set(string $argKey, mixed $value, ?int $index = null, bool $overwrite = true): void
    {
        $this->invalidateHashes();

        if ($index !== null) {
            $expressions = $this->args[$argKey] ?? [];

            if (! is_array($expressions) || ! isset($expressions[$index])) {
                return;
            }

            if ($value === null) {
                array_splice($expressions, $index, 1);
                $this->args[$argKey] = $expressions;
                $this->setParent($argKey, $expressions);

                return;
            }

            if (is_array($value)) {
                array_splice($expressions, $index, 1, $value);
            } elseif ($overwrite) {
                $expressions[$index] = $value;
            } else {
                array_splice($expressions, $index, 0, [$value]);
            }

            $value = $expressions;
        } elseif ($value === null) {
            unset($this->args[$argKey]);

            return;
        }

        $this->args[$argKey] = $value;
        $this->setParent($argKey, $value);
    }

    public function append(string $argKey, mixed $value): void
    {
        $this->invalidateHashes();

        $values = $this->args[$argKey] ?? null;

        if (! is_array($values)) {
            $values = [];
        }

        if ($value instanceof self) {
            $value->parent = $this;
            $value->argKey = $argKey;
            $value->index = count($values);
        }

        $values[] = $value;
        $this->args[$argKey] = $values;
    }

    private function setParent(string $argKey, mixed $value): void
    {
        if ($value instanceof self) {
            $value->parent = $this;
            $value->argKey = $argKey;
            $value->index = null;
        } elseif (is_array($value)) {
            foreach ($value as $i => $item) {
                if ($item instanceof self) {
                    $item->parent = $this;
                    $item->argKey = $argKey;
                    $item->index = $i;
                }
            }
        }
    }

    private function invalidateHashes(): void
    {
        $node = $this;

        while ($node !== null && $node->hashCache !== null) {
            $node->hashCache = null;
            $node = $node->parent;
        }
    }

    /**
     * Replace this node in its parent with another node (or null to remove it).
     *
     * @template T
     *
     * @param  T  $expression  an Expression, a list of them, or null
     * @return T
     */
    public function replace(mixed $expression): mixed
    {
        $parent = $this->parent;

        if ($parent === null || $parent === $expression) {
            return $expression;
        }

        $key = $this->argKey;

        if ($key !== null) {
            $value = $parent->args[$key] ?? null;

            if (is_array($expression) && $value instanceof self) {
                $value->parent?->replace($expression);
            } else {
                $parent->set($key, $expression, $this->index);
            }
        }

        if ($expression !== $this) {
            $this->parent = null;
            $this->argKey = null;
            $this->index = null;
        }

        return $expression;
    }

    /**
     * Remove this node from its parent.
     */
    public function pop(): static
    {
        $this->replace(null);

        return $this;
    }

    // ---------------------------------------------------------------------------------------
    // Traversal
    // ---------------------------------------------------------------------------------------

    /**
     * Direct child expressions, in argument order.
     *
     * @return list<Expression>
     */
    public function children(): array
    {
        $children = [];

        foreach ($this->args as $value) {
            if ($value instanceof self) {
                $children[] = $value;
            } elseif (is_array($value)) {
                foreach ($value as $item) {
                    if ($item instanceof self) {
                        $children[] = $item;
                    }
                }
            }
        }

        return $children;
    }

    /**
     * Walk the tree, breadth-first by default.
     *
     * @param  (callable(Expression): bool)|null  $prune  return true to skip a node's children
     * @return Generator<int, Expression>
     */
    public function walk(bool $bfs = true, ?callable $prune = null): Generator
    {
        return $bfs ? $this->bfs($prune) : $this->dfs($prune);
    }

    /**
     * @param  (callable(Expression): bool)|null  $prune
     * @return Generator<int, Expression>
     */
    public function dfs(?callable $prune = null): Generator
    {
        $stack = [$this];

        while ($stack !== []) {
            $node = array_pop($stack);

            yield $node;

            if ($prune !== null && $prune($node)) {
                continue;
            }

            $children = $node->children();
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $stack[] = $children[$i];
            }
        }
    }

    /**
     * @param  (callable(Expression): bool)|null  $prune
     * @return Generator<int, Expression>
     */
    public function bfs(?callable $prune = null): Generator
    {
        $queue = [$this];
        $head = 0;

        while (isset($queue[$head])) {
            $node = $queue[$head];
            unset($queue[$head]);
            $head++;

            yield $node;

            if ($prune !== null && $prune($node)) {
                continue;
            }

            foreach ($node->children() as $child) {
                $queue[] = $child;
            }
        }
    }

    /**
     * All nodes of the given types (classes or interfaces), in breadth-first order.
     *
     * @template T of object
     *
     * @param  class-string<T>  ...$types
     * @return list<T&Expression>
     */
    public function findAll(string ...$types): array
    {
        return $this->collect($types, true, false);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  ...$types
     * @return list<T&Expression>
     */
    public function findAllDfs(string ...$types): array
    {
        return $this->collect($types, false, false);
    }

    /**
     * The first node of the given types (classes or interfaces), breadth-first.
     *
     * @template T of object
     *
     * @param  class-string<T>  ...$types
     * @return (T&Expression)|null
     */
    public function find(string ...$types): ?self
    {
        return $this->collect($types, true, true)[0] ?? null;
    }

    /**
     * @template T of object
     *
     * @param  array<class-string<T>>  $types
     * @return list<T&Expression>
     */
    private function collect(array $types, bool $bfs, bool $first): array
    {
        $found = [];

        foreach ($this->walk($bfs) as $node) {
            foreach ($types as $type) {
                if ($node instanceof $type) {
                    $found[] = $node;

                    if ($first) {
                        return $found;
                    }

                    break;
                }
            }
        }

        return $found;
    }

    /**
     * The closest ancestor of the given types (classes or interfaces).
     *
     * @template T of object
     *
     * @param  class-string<T>  ...$types
     * @return (T&Expression)|null
     */
    public function findAncestor(string ...$types): ?self
    {
        $ancestor = $this->parent;

        while ($ancestor !== null) {
            foreach ($types as $type) {
                if ($ancestor instanceof $type) {
                    return $ancestor;
                }
            }

            $ancestor = $ancestor->parent;
        }

        return null;
    }

    public function parentSelect(): ?Select
    {
        return $this->findAncestor(Select::class);
    }

    public function sameParent(): bool
    {
        return $this->parent !== null && static::class === $this->parent::class;
    }

    public function root(): self
    {
        $node = $this;

        while ($node->parent !== null) {
            $node = $node->parent;
        }

        return $node;
    }

    public function depth(): int
    {
        $depth = 0;
        $node = $this;

        while ($node->parent !== null) {
            $depth++;
            $node = $node->parent;
        }

        return $depth;
    }

    /**
     * Strip any wrapping parentheses.
     */
    public function unnest(): self
    {
        $node = $this;

        while ($node instanceof Paren && $node->args['this'] instanceof self) {
            $node = $node->args['this'];
        }

        return $node;
    }

    public function unalias(): self
    {
        if ($this instanceof Alias && ($this->args['this'] ?? null) instanceof self) {
            return $this->args['this'];
        }

        return $this;
    }

    /**
     * @return list<Expression>
     */
    public function unnestOperands(): array
    {
        return array_map(static fn (Expression $child): Expression => $child->unnest(), $this->children());
    }

    /**
     * The operands of a chain of the same node type, e.g. every condition of `a AND b AND c`.
     *
     * @return list<Expression>
     */
    public function flatten(bool $unnest = true): array
    {
        $class = static::class;
        $out = [];
        $prune = static fn (Expression $node): bool => $node->parent !== null && $node::class !== $class;

        foreach ($this->dfs($prune) as $node) {
            if ($node::class !== $class) {
                $out[] = $unnest && ! $node::IS_SUBQUERY ? $node->unnest() : $node;
            }
        }

        return $out;
    }

    /**
     * Apply a function to every node (depth-first), replacing nodes with what it returns.
     * The children of a replaced node are not visited.
     *
     * @param  callable(Expression): (Expression|null)  $fn
     */
    public function transform(callable $fn, bool $copy = true): ?self
    {
        $start = $copy ? $this->copy() : $this;
        $root = null;
        $first = true;
        $stack = [$start];

        while ($stack !== []) {
            $node = array_pop($stack);
            $parent = $node->parent;
            $argKey = $node->argKey;
            $index = $node->index;

            $new = $fn($node);

            if ($first) {
                $root = $new;
                $first = false;
            } elseif ($parent !== null && $argKey !== null && $new !== $node) {
                $parent->set($argKey, $new, $index);
            }

            if ($new !== $node) {
                continue;
            }

            $children = $node->children();
            for ($i = count($children) - 1; $i >= 0; $i--) {
                $stack[] = $children[$i];
            }
        }

        return $root;
    }

    // ---------------------------------------------------------------------------------------
    // Copying, hashing, equality
    // ---------------------------------------------------------------------------------------

    /**
     * Deep copy, iteratively, so very deep trees are safe.
     */
    public function copy(): static
    {
        $root = clone $this;
        $root->parent = null;
        $root->argKey = null;
        $root->index = null;
        $stack = [$root];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node->inferredType !== null) {
                $node->inferredType = $node->inferredType->copy();
            }

            foreach ($node->args as $key => $value) {
                if ($value instanceof self) {
                    $child = clone $value;
                    $child->parent = $node;
                    $child->argKey = $key;
                    $child->index = null;
                    $node->args[$key] = $child;
                    $stack[] = $child;
                } elseif (is_array($value)) {
                    foreach ($value as $i => $item) {
                        if ($item instanceof self) {
                            $child = clone $item;
                            $child->parent = $node;
                            $child->argKey = $key;
                            $child->index = $i;
                            $value[$i] = $child;
                            $stack[] = $child;
                        }
                    }
                    $node->args[$key] = $value;
                }
            }
        }

        return $root;
    }

    /**
     * Structural hash: identical trees (ignoring identifier/keyword case) share a hash.
     */
    public function hashKey(): string
    {
        if ($this->hashCache !== null) {
            return $this->hashCache;
        }

        $nodes = [];
        $stack = [$this];

        while ($stack !== []) {
            $node = array_pop($stack);
            $nodes[] = $node;

            foreach ($node->args as $value) {
                if ($value instanceof self) {
                    if ($value->hashCache === null) {
                        $stack[] = $value;
                    }
                } elseif (is_array($value)) {
                    foreach ($value as $item) {
                        if ($item instanceof self && $item->hashCache === null) {
                            $stack[] = $item;
                        }
                    }
                }
            }
        }

        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            $node = $nodes[$i];
            $args = $node->args;
            ksort($args);
            $parts = [$node::KEY];

            foreach ($args as $key => $value) {
                if ($node::HASH_RAW_ARGS) {
                    if ($value !== null && $value !== false && $value !== '' && $value !== []) {
                        $parts[] = $key.'='.self::hashValue($value, false);
                    }

                    continue;
                }

                if (is_array($value)) {
                    foreach ($value as $item) {
                        $parts[] = $item === null || $item === false ? $key : $key.'='.self::hashValue($item, true);
                    }
                } elseif ($value !== null && $value !== false) {
                    $parts[] = $key.'='.self::hashValue($value, true);
                }
            }

            $node->hashCache = hash('xxh128', implode("\x1F", $parts));
        }

        return (string) $this->hashCache;
    }

    private static function hashValue(mixed $value, bool $lower): string
    {
        return match (true) {
            $value instanceof self => '#'.$value->hashKey(),
            is_string($value) => 's'.($lower ? strtolower($value) : $value),
            is_bool($value) => $value ? 'T' : 'F',
            is_int($value), is_float($value) => 'n'.$value,
            is_array($value) => '['.implode(',', array_map(static fn (mixed $item): string => self::hashValue($item, $lower), $value)).']',
            default => '?',
        };
    }

    /**
     * Structural equality (sqlglot's `==` on expressions).
     */
    public function equals(?self $other): bool
    {
        return $other !== null && ($this === $other || ($this::class === $other::class && $this->hashKey() === $other->hashKey()));
    }

    // ---------------------------------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>|null  $args  the positional args a function was built from
     * @return list<string>
     */
    public function errorMessages(?array $args = null): array
    {
        $errors = [];

        foreach (static::REQUIRED_ARGS as $key) {
            $value = $this->args[$key] ?? null;

            if ($value === null || $value === []) {
                $errors[] = "Required keyword: '{$key}' missing for ".static::class;
            }
        }

        if ($args !== null && $this instanceof Func && ! static::IS_VAR_LEN_ARGS && count($args) > count(static::ARG_TYPES)) {
            $errors[] = 'The number of provided arguments ('.count($args).') is greater than the maximum number of supported arguments ('.count(static::ARG_TYPES).')';
        }

        return $errors;
    }

    // ---------------------------------------------------------------------------------------
    // Output
    // ---------------------------------------------------------------------------------------

    /**
     * Generate SQL for this expression in the given dialect.
     *
     * @param  array<string, mixed>  $options  generator options (pretty, identify, ...)
     */
    public function sql(string|Dialect|null $dialect = null, array $options = [], bool $copy = true): string
    {
        return Dialect::get($dialect)->generate($this, $options, $copy);
    }

    public function __toString(): string
    {
        return $this->sql();
    }

    /**
     * A comparison-friendly array form of the tree: ['k' => key, 'a' => args, 'c' => comments].
     *
     * Null, false and empty-list arguments are dropped. This matches the reference trees
     * recorded from sqlglot by tools/sqlglot-reference/record.py.
     *
     * @return array<string, mixed>
     */
    public function toTree(): array
    {
        $node = ['k' => static::KEY];
        $args = [];

        foreach ($this->args as $key => $value) {
            if ($value === null || $value === false || $value === []) {
                continue;
            }

            $args[$key] = self::treeValue($value);
        }

        if ($args !== []) {
            $node['a'] = $args;
        }

        if ($this->comments !== null && $this->comments !== []) {
            $node['c'] = $this->comments;
        }

        return $node;
    }

    private static function treeValue(mixed $value): mixed
    {
        if ($value instanceof self) {
            return $value->toTree();
        }

        if (is_array($value)) {
            // sqlglot's recorder writes other values in lists with str(), so None becomes "None"
            return array_map(static fn (mixed $item): mixed => $item === null ? 'None' : self::treeValue($item), $value);
        }

        return $value;
    }
}
