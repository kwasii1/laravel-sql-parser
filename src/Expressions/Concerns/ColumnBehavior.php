<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * @mixin Column
 */
trait ColumnBehavior
{
    public function table(): string
    {
        return $this->text('table');
    }

    public function db(): string
    {
        return $this->text('db');
    }

    public function catalog(): string
    {
        return $this->text('catalog');
    }

    public function outputName(): string
    {
        return $this->name();
    }

    /**
     * catalog, db, table and column parts that are set, outermost first.
     *
     * @return list<Expression>
     */
    public function parts(): array
    {
        $parts = [];

        foreach (['catalog', 'db', 'table', 'this'] as $part) {
            $value = $this->args[$part] ?? null;

            if ($value instanceof Expression) {
                $parts[] = $value;
            }
        }

        return $parts;
    }

    /**
     * The column as a chain of Dot nodes (or a single identifier / star).
     */
    public function toDot(bool $includeDots = true): Expression
    {
        $parts = $this->parts();
        $parent = $this->parent;

        if ($includeDots) {
            while ($parent instanceof Dot) {
                $expression = $parent->args['expression'] ?? null;

                if ($expression instanceof Expression) {
                    $parts[] = $expression;
                }

                $parent = $parent->parent;
            }
        }

        $parts = array_map(static fn (Expression $part): Expression => $part->copy(), $parts);

        return count($parts) > 1 ? Dot::build($parts) : $parts[0];
    }
}
