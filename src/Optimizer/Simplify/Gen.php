<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer\Simplify;

use InvalidArgumentException;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Func;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Table;

/**
 * A bare-bones pseudo SQL generator producing sortable, unique strings for expressions
 * (sqlglot simplify.Gen). Its output only has to match sqlglot's, not be valid SQL.
 *
 * @internal
 */
final class Gen
{
    /** @var list<mixed> */
    private array $stack = [];

    /** @var list<string> */
    private array $sqls = [];

    private const array BINARY = [
        'add' => ' + ',
        'and' => ' AND ',
        'div' => ' / ',
        'dot' => '.',
        'eq' => ' = ',
        'gt' => ' > ',
        'gte' => ' >= ',
        'intdiv' => ' DIV ',
        'lt' => ' < ',
        'lte' => ' <= ',
        'mod' => ' % ',
        'mul' => ' * ',
        'neq' => ' <> ',
        'or' => ' OR ',
        'sub' => ' - ',
    ];

    public function gen(Expression $expression, bool $comments = false): string
    {
        $this->stack = [$expression];
        $this->sqls = [];

        while ($this->stack !== []) {
            $node = array_pop($this->stack);

            if ($node instanceof Expression) {
                if ($comments && $node->comments !== null && $node->comments !== []) {
                    $this->stack[] = ' /*'.implode(',', $node->comments).'*/';
                }

                if (! $this->dispatch($node)) {
                    if ($node instanceof Func) {
                        $this->stack[] = ')';
                        $this->stack[] = array_values($node->args);
                        $this->stack[] = '(';
                        $this->stack[] = $node::sqlName();
                    } else {
                        $key = strtoupper($node::KEY);
                        $this->stack[] = $this->args($node) ? "{$key} " : $key;
                    }
                }
            } elseif (is_array($node)) {
                foreach (array_reverse($node) as $n) {
                    if ($n !== null) {
                        $this->stack[] = $n;
                        $this->stack[] = ',';
                    }
                }

                // Like Python, this pops the last pushed item even when every element was None
                if ($node !== []) {
                    array_pop($this->stack);
                }
            } elseif ($node !== null) {
                $this->sqls[] = self::str($node);
            }
        }

        return implode('', $this->sqls);
    }

    private function dispatch(Expression $e): bool
    {
        $key = $e::KEY;

        if (isset(self::BINARY[$key])) {
            $this->binary($e, self::BINARY[$key]);

            return true;
        }

        switch ($key) {
            case 'alias':
                array_push($this->stack, $e->args['alias'] ?? null, ' AS ', $e->args['this'] ?? null);

                return true;
            case 'anonymous':
                $this_ = $e->args['this'] ?? null;

                if (is_string($this_)) {
                    $name = strtoupper($this_);
                } elseif ($this_ instanceof Identifier) {
                    $name = (string) $this_->args['this'];
                    $name = $this_->flag('quoted') ? '"'.str_replace('"', '""', $name).'"' : strtoupper($name);
                } else {
                    throw new InvalidArgumentException("Anonymous.this expects a str or an Identifier, got '".get_debug_type($this_)."'.");
                }

                array_push($this->stack, ')', $e->expressions(), '(', $name);

                return true;
            case 'between':
                array_push($this->stack, $e->args['high'] ?? null, ' AND ', $e->args['low'] ?? null, ' BETWEEN ', $e->args['this'] ?? null);

                return true;
            case 'boolean':
                $this->stack[] = ($e->args['this'] ?? false) === true ? 'TRUE' : 'FALSE';

                return true;
            case 'bracket':
                array_push($this->stack, ']', $e->expressions(), '[', $e->args['this'] ?? null);

                return true;
            case 'column':
                if ($e instanceof Column) {
                    foreach (array_reverse($e->parts()) as $p) {
                        array_push($this->stack, $p, '.');
                    }
                    array_pop($this->stack);
                }

                return true;
            case 'datatype':
                $this->args($e, 1);
                $this->stack[] = self::str($e->args['this'] ?? '').' ';

                return true;
            case 'from':
                array_push($this->stack, $e->args['this'] ?? null, 'FROM ');

                return true;
            case 'identifier':
                $this_ = (string) ($e->args['this'] ?? '');
                $this->stack[] = $e->flag('quoted') ? '"'.str_replace('"', '""', $this_).'"' : $this_;

                return true;
            case 'ilike':
                $this->binary($e, $e->flag('negate') ? ' NOT ILIKE ' : ' ILIKE ');

                return true;
            case 'in':
                $this->stack[] = ')';
                $this->args($e, 1);
                array_push($this->stack, '(', ' IN ', $e->args['this'] ?? null);

                return true;
            case 'is':
                $this->binary($e, $e->flag('negate') ? ' IS NOT ' : ' IS ');

                return true;
            case 'like':
                $this->binary($e, $e->flag('negate') ? ' NOT Like ' : ' Like ');

                return true;
            case 'literal':
                $this_ = (string) ($e->args['this'] ?? '');
                $this->stack[] = $e->isString() ? "'".str_replace("'", "''", $this_)."'" : $this_;

                return true;
            case 'neg':
                array_push($this->stack, $e->args['this'] ?? null, '-');

                return true;
            case 'not':
                array_push($this->stack, $e->args['this'] ?? null, 'NOT ');

                return true;
            case 'null':
                $this->stack[] = 'NULL';

                return true;
            case 'paren':
                array_push($this->stack, ')', $e->args['this'] ?? null, '(');

                return true;
            case 'subquery':
                $this->args($e, 2);
                $alias = $e->args['alias'] ?? null;

                if ($alias !== null) {
                    $this->stack[] = $alias;
                }
                array_push($this->stack, ')', $e->args['this'] ?? null, '(');

                return true;
            case 'table':
                $this->args($e, 4);
                $alias = $e->args['alias'] ?? null;

                if ($alias !== null) {
                    $this->stack[] = $alias;
                }

                if ($e instanceof Table) {
                    foreach (array_reverse($e->parts()) as $p) {
                        array_push($this->stack, $p, '.');
                    }
                    array_pop($this->stack);
                }

                return true;
            case 'tablealias':
                $columns = $e->argList('columns');

                if ($columns !== []) {
                    array_push($this->stack, ')', $columns, '(');
                }
                array_push($this->stack, $e->args['this'] ?? null, ' AS ');

                return true;
            case 'var':
                $this->stack[] = self::str($e->args['this'] ?? '');

                return true;
        }

        return false;
    }

    private function binary(Expression $e, string $op): void
    {
        array_push($this->stack, $e->args['expression'] ?? null, $op, $e->args['this'] ?? null);
    }

    private function args(Expression $node, int $argIndex = 0): bool
    {
        $kvs = [];

        foreach (array_slice(array_keys($node::ARG_TYPES), $argIndex) as $k) {
            $v = $node->args[$k] ?? null;

            if ($v !== null) {
                // repr() plain strings so their content can't mimic gen's structural text
                $kvs[] = [":{$k}", is_string($v) ? self::repr($v) : $v];
            }
        }

        if ($kvs !== []) {
            $this->stack[] = $kvs;

            return true;
        }

        return false;
    }

    /**
     * Python's str() of a scalar.
     */
    private static function str(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'True' : 'False',
            is_scalar($value) => (string) $value,
            $value instanceof Expression => $value::class,
            default => '',
        };
    }

    /**
     * Python's repr() of a string.
     */
    private static function repr(string $value): string
    {
        $quote = str_contains($value, "'") && ! str_contains($value, '"') ? '"' : "'";
        $escaped = strtr($value, ['\\' => '\\\\', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);

        if ($quote === "'") {
            $escaped = str_replace("'", "\\'", $escaped);
        }

        return $quote.$escaped.$quote;
    }
}
