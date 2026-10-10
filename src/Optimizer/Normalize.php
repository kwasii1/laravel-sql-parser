<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use Closure;
use Generator;
use LaravelSqlParser\LaravelSqlParser\Errors\OptimizeError;
use LaravelSqlParser\LaravelSqlParser\Expressions\AndExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Connector;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\OrExpr;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;

/**
 * Port of sqlglot's optimizer/normalize.py: rewrite conditions into conjunctive (CNF) or
 * disjunctive (DNF) normal form.
 */
final class Normalize
{
    /**
     * Rewrite an AST into CNF (or DNF), e.g. `(x AND y) OR z` becomes `(x OR z) AND (y OR z)`.
     *
     * @param  int  $maxDistance  the maximal estimated distance from cnf/dnf to attempt conversion
     */
    public static function normalize(Expression $expression, bool $dnf = false, int $maxDistance = 128): Expression
    {
        $simplifier = new Simplifier(annotateNewExpressions: false);
        $nodes = iterator_to_array($expression->walk(true, static fn (Expression $e): bool => $e instanceof Connector), false);

        foreach ($nodes as $node) {
            if (! $node instanceof Connector) {
                continue;
            }

            if (self::normalized($node, $dnf)) {
                continue;
            }

            $root = $node === $expression;
            $original = $node->copy();

            $node->transform(static fn (Expression $e): Expression => $simplifier->rewriteBetween($e), false);
            $distance = self::normalizationDistance($node, $dnf, $maxDistance);

            if ($distance > $maxDistance) {
                return $expression;
            }

            try {
                $node = $node->replace(Simplifier::whileChanging(
                    $node,
                    static fn (Expression $e): Expression => self::distributiveLaw($e, $dnf, $maxDistance, $simplifier),
                ));
            } catch (OptimizeError) {
                $node->replace($original);

                return $root ? $original : $expression;
            }

            if ($root) {
                $expression = $node;
            }
        }

        return $expression;
    }

    /**
     * Whether an expression is in the normal form of interest (CNF by default, or DNF).
     */
    public static function normalized(Expression $expression, bool $dnf = false): bool
    {
        [$ancestor, $root] = $dnf ? [AndExpr::class, OrExpr::class] : [OrExpr::class, AndExpr::class];

        foreach (Scope::findAllInScope($expression, $root) as $connector) {
            if ($connector->findAncestor($ancestor) !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * The difference in the number of predicates between an expression and its normalized form,
     * an estimate of the (exponential) cost of the conversion.
     */
    public static function normalizationDistance(Expression $expression, bool $dnf = false, float $max = INF): int
    {
        $total = -(count($expression->findAll(Connector::class)) + 1);

        foreach (self::predicateLengths($expression, $dnf, $max) as $length) {
            $total += $length;

            if ($total > $max) {
                return $total;
            }
        }

        return $total;
    }

    /**
     * Predicate lengths when expanded to normalized form: (A AND B) OR C -> [2, 2].
     *
     * @return Generator<int, int>
     */
    private static function predicateLengths(Expression $expression, bool $dnf, float $max = INF, int $depth = 0): Generator
    {
        if ($depth > $max) {
            yield $depth;

            return;
        }

        $expression = $expression->unnest();

        if (! $expression instanceof Connector) {
            yield 1;

            return;
        }

        $depth++;
        $left = $expression->argExpr('this');
        $right = $expression->argExpr('expression');

        if ($left === null || $right === null) {
            yield 1;

            return;
        }

        if ($expression instanceof ($dnf ? AndExpr::class : OrExpr::class)) {
            foreach (self::predicateLengths($left, $dnf, $max, $depth) as $a) {
                foreach (self::predicateLengths($right, $dnf, $max, $depth) as $b) {
                    yield $a + $b;
                }
            }
        } else {
            yield from self::predicateLengths($left, $dnf, $max, $depth);
            yield from self::predicateLengths($right, $dnf, $max, $depth);
        }
    }

    /**
     * x OR (y AND z) -> (x OR y) AND (x OR z)
     * (x AND y) OR (y AND z) -> (x OR y) AND (x OR z) AND (y OR y) AND (y OR z)
     */
    public static function distributiveLaw(Expression $expression, bool $dnf, float $maxDistance, ?Simplifier $simplifier = null): Expression
    {
        if (self::normalized($expression, $dnf)) {
            return $expression;
        }

        $distance = self::normalizationDistance($expression, $dnf, $maxDistance);

        if ($distance > $maxDistance) {
            throw new OptimizeError("Normalization distance {$distance} exceeds max {$maxDistance}");
        }

        self::replaceChildren($expression, static fn (Expression $e): Expression => self::distributiveLaw($e, $dnf, $maxDistance));
        [$toExp, $fromExp] = $dnf ? [OrExpr::class, AndExpr::class] : [AndExpr::class, OrExpr::class];

        if ($expression instanceof $fromExp) {
            [$a, $b] = $expression->unnestOperands() + [null, null];

            if ($a === null || $b === null) {
                return $expression;
            }

            $fromFunc = $fromExp === AndExpr::class ? Builder::and(...) : Builder::or(...);
            $toFunc = $toExp === AndExpr::class ? Builder::and(...) : Builder::or(...);
            $simplifier ??= new Simplifier(annotateNewExpressions: false);

            if ($a instanceof $toExp && $b instanceof $toExp) {
                if (count($a->findAll(Connector::class)) > count($b->findAll(Connector::class))) {
                    return self::distribute($a, $b, $fromFunc, $toFunc, $simplifier);
                }

                return self::distribute($b, $a, $fromFunc, $toFunc, $simplifier);
            }

            if ($a instanceof $toExp) {
                return self::distribute($b, $a, $fromFunc, $toFunc, $simplifier);
            }

            if ($b instanceof $toExp) {
                return self::distribute($a, $b, $fromFunc, $toFunc, $simplifier);
            }
        }

        return $expression;
    }

    /**
     * @param  Closure(list<Expression>, mixed...): Expression  $fromFunc
     * @param  Closure(list<Expression>, mixed...): Expression  $toFunc
     */
    private static function distribute(Expression $a, Expression $b, Closure $fromFunc, Closure $toFunc, Simplifier $simplifier): Expression
    {
        $bLeft = $b->argExpr('this');
        $bRight = $b->argExpr('expression');

        if ($bLeft === null || $bRight === null) {
            return $a;
        }

        $combine = static fn (Expression $c): Expression => $toFunc([
            $simplifier->uniqSort(Simplify::flatten($fromFunc([$c, $bLeft]))),
            $simplifier->uniqSort(Simplify::flatten($fromFunc([$c, $bRight]))),
        ], copy: false);

        // When `a` and `b` are connectors of the same polarity, `b` is distributed across `a`'s
        // children so the existing cross-product handling can simplify them
        if ($a instanceof Connector && $a instanceof $b) {
            self::replaceChildren($a, $combine);

            return $a;
        }

        // Otherwise apply the textbook rule a OR (b.left AND b.right) -> (a OR b.left) AND (a OR b.right)
        return $combine($a);
    }

    /**
     * Replace the children of an expression with fun(child) (sqlglot exp.replace_children).
     *
     * @param  Closure(Expression): Expression  $fun
     */
    private static function replaceChildren(Expression $expression, Closure $fun): void
    {
        foreach ($expression->args as $k => $v) {
            if (is_array($v)) {
                $new = [];

                foreach ($v as $cn) {
                    $new[] = $cn instanceof Expression ? $fun($cn) : $cn;
                }

                $expression->set($k, $new);
            } elseif ($v instanceof Expression) {
                $expression->set($k, $fun($v));
            }
        }
    }
}
