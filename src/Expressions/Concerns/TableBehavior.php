<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use InvalidArgumentException;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Dot;
use Kwasii\LaravelSqlParser\Expressions\DynamicIdentifier;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Func;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Table;

/**
 * @mixin Table
 */
trait TableBehavior
{
    public function name(): string
    {
        $this_ = $this->args['this'] ?? null;

        if (! $this_ instanceof Expression || ($this_ instanceof Func && ! $this_ instanceof DynamicIdentifier)) {
            return '';
        }

        return $this_->name();
    }

    public function db(): string
    {
        return $this->text('db');
    }

    public function catalog(): string
    {
        return $this->text('catalog');
    }

    /**
     * @return list<Expression>
     */
    public function selects(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function namedSelects(): array
    {
        return [];
    }

    /**
     * catalog, db and table parts, with dotted names flattened.
     *
     * @return list<Expression>
     */
    public function parts(): array
    {
        $parts = [];

        foreach (['catalog', 'db', 'this'] as $arg) {
            $part = $this->args[$arg] ?? null;

            if ($part instanceof Dot) {
                array_push($parts, ...$part->flatten());
            } elseif ($part instanceof Expression) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    public function toColumn(bool $copy = true): Expression
    {
        $parts = $this->parts();
        $last = $parts[count($parts) - 1];

        if ($last instanceof Identifier) {
            $columnParts = array_map(
                static fn (Expression $p): Identifier => $p instanceof Identifier ? $p : throw new InvalidArgumentException('Table parts must be identifiers'),
                array_reverse(array_slice($parts, 0, 4)),
            );
            $column = Builder::column(
                $columnParts[0],
                $columnParts[1] ?? null,
                $columnParts[2] ?? null,
                $columnParts[3] ?? null,
                fields: array_values(array_filter(array_slice($parts, 4), static fn (Expression $p): bool => $p instanceof Identifier)),
                copy: $copy,
            );
        } else {
            $column = $last;
        }

        $alias = $this->args['alias'] ?? null;

        if ($alias instanceof Expression) {
            $aliasName = $alias->args['this'] ?? null;
            $column = Builder::alias($column, $aliasName instanceof Identifier ? $aliasName : null, copy: $copy);
        }

        return $column;
    }
}
