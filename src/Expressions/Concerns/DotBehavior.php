<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use InvalidArgumentException;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * @mixin Dot
 */
trait DotBehavior
{
    public function isStar(): bool
    {
        $expression = $this->args['expression'] ?? null;

        return $expression instanceof Expression && $expression->isStar();
    }

    public function name(): string
    {
        $expression = $this->args['expression'] ?? null;

        return $expression instanceof Expression ? $expression->name() : '';
    }

    public function outputName(): string
    {
        return $this->name();
    }

    /**
     * Fold a list of parts into nested Dot nodes: a.b.c => Dot(Dot(a, b), c).
     *
     * @param  list<Expression>  $expressions
     */
    public static function build(array $expressions): Dot
    {
        if (count($expressions) < 2) {
            throw new InvalidArgumentException('Dot requires >= 2 expressions.');
        }

        $dot = array_shift($expressions);

        foreach ($expressions as $expression) {
            $dot = new Dot(['this' => $dot, 'expression' => $expression]);
        }

        /** @var Dot $dot */
        return $dot;
    }

    /**
     * @return list<Expression>
     */
    public function parts(): array
    {
        $flat = $this->flatten();
        $this_ = array_shift($flat);
        $parts = array_reverse($flat);

        foreach (['this', 'table', 'db', 'catalog'] as $arg) {
            $part = $this_?->args[$arg] ?? null;

            if ($part instanceof Expression) {
                $parts[] = $part;
            }
        }

        return array_reverse($parts);
    }
}
