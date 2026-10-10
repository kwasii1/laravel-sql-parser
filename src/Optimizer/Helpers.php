<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Kwasii\LaravelSqlParser\Expressions\AggFunc;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Func;
use Kwasii\LaravelSqlParser\Expressions\Window;
use Kwasii\LaravelSqlParser\Support\Scope;

/**
 * Shared optimizer helpers (sqlglot optimizer/helpers.py).
 */
final class Helpers
{
    public const string WINDOW_HAS_AGGREGATE = 'window_has_aggregate';

    /**
     * Whether a projection aggregates rows, counting aggregates of the named windows it inherits from.
     *
     * @param  list<Expression>|null  $windows
     */
    public static function projectionHasAggregate(Expression $projection, ?array $windows = null): bool
    {
        $windowedAggregates = [];
        $remainingWindows = [];

        foreach ($windows ?? [] as $window) {
            $remainingWindows[$window->name()] = $window;
        }

        foreach (Scope::walkInScope($projection) as $node) {
            if ($node instanceof Window) {
                $target = $node->args['this'] ?? null;

                // parens, FILTER and IGNORE NULLS wrap that function without changing which one it is
                while ($target instanceof Expression && ! $target instanceof Func) {
                    $target = $target->args['this'] ?? null;
                }

                if ($target instanceof AggFunc) {
                    $windowedAggregates[spl_object_id($target)] = true;
                }

                $name = $node->alias();

                // Named window inheritance: WINDOW w1 AS (...), w2 AS (w1 ORDER BY 1), w3 AS (w2)
                while ($name !== '') {
                    $window = $remainingWindows[$name] ?? null;
                    unset($remainingWindows[$name]);

                    if ($window === null) {
                        break;
                    }

                    $hasAggregate = $window->metaGet(self::WINDOW_HAS_AGGREGATE);

                    if (! is_bool($hasAggregate)) {
                        $hasAggregate = Scope::findInScope($window, AggFunc::class) !== null;
                        $window->setMeta(self::WINDOW_HAS_AGGREGATE, $hasAggregate);
                    }

                    if ($hasAggregate) {
                        return true;
                    }

                    $name = $window->alias();
                }
            } elseif ($node instanceof AggFunc && ! isset($windowedAggregates[spl_object_id($node)])) {
                return true;
            }
        }

        return false;
    }
}
