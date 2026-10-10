<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\SetOperation;
use Kwasii\LaravelSqlParser\Expressions\Subquery;

/**
 * @mixin SetOperation
 */
trait SetOperationBehavior
{
    /**
     * Whether the query projects a star (sqlglot _is_star).
     */
    public function isStar(): bool
    {
        $stack = [$this];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node instanceof SetOperation) {
                $stack[] = $node->args['this'] ?? null;
                $stack[] = $node->args['expression'] ?? null;
            } elseif ($node instanceof Subquery) {
                $stack[] = $node->args['this'] ?? null;
            } elseif ($node instanceof Expression && $node->isStar()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The leftmost query's projections.
     *
     * @return list<Expression>
     */
    public function selects(): array
    {
        $expr = $this->leftmostQuery();

        return $expr instanceof Query ? $expr->selects() : [];
    }

    /**
     * @return list<string>
     */
    public function namedSelects(): array
    {
        $expr = $this;

        while ($expr instanceof SetOperation) {
            if ($expr->flag('by_name')) {
                $left = self::unnestedArg($expr, 'this');
                $right = self::unnestedArg($expr, 'expression');

                return array_values(array_unique([
                    ...($left instanceof Query ? $left->namedSelects() : []),
                    ...($right instanceof Query ? $right->namedSelects() : []),
                ]));
            }

            $expr = self::unnestedArg($expr, 'this');
        }

        // A set operation names its columns after its leftmost query's projections
        return $expr instanceof Query ? array_map(static fn (Expression $e): string => $e->outputName(), $expr->selects()) : [];
    }

    public function leftmostQuery(): ?Expression
    {
        $expr = $this;

        while ($expr instanceof SetOperation) {
            $expr = self::unnestedArg($expr, 'this');
        }

        return $expr;
    }

    public function kind(): string
    {
        return strtoupper($this->text('kind'));
    }

    public function side(): string
    {
        return strtoupper($this->text('side'));
    }

    /**
     * An argument with its wrapping subqueries stripped, or null. (Written without ?-> on a loop
     * variable, which trips a type inference warning in PHP's optimizer when preloading.)
     */
    private static function unnestedArg(Expression $expression, string $key): ?Expression
    {
        $value = $expression->args[$key] ?? null;

        return $value instanceof Expression ? $value->unnest() : null;
    }

    /**
     * Append to or set the projections of both sides (sqlglot SetOperation.select).
     * Options: append, dialect, copy.
     */
    public function select(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);
        $instance = $options['copy'] === false ? $this : $this->copy();

        foreach (['this', 'expression'] as $key) {
            $side = self::unnestedArg($instance, $key);

            if ($side instanceof Query) {
                $side->select(...$expressions, append: (bool) $options['append'], dialect: Builder::dialectOption($options), copy: false);
            }
        }

        return $instance;
    }
}
