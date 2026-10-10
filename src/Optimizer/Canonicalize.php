<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Closure;
use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Add;
use Kwasii\LaravelSqlParser\Expressions\Between;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\CaseExpr;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Coalesce;
use Kwasii\LaravelSqlParser\Expressions\Concat;
use Kwasii\LaravelSqlParser\Expressions\Connector;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\Date;
use Kwasii\LaravelSqlParser\Expressions\DateAdd;
use Kwasii\LaravelSqlParser\Expressions\DateDiff;
use Kwasii\LaravelSqlParser\Expressions\DateSub;
use Kwasii\LaravelSqlParser\Expressions\DateTrunc;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Extract;
use Kwasii\LaravelSqlParser\Expressions\GT;
use Kwasii\LaravelSqlParser\Expressions\GTE;
use Kwasii\LaravelSqlParser\Expressions\Having;
use Kwasii\LaravelSqlParser\Expressions\IfExpr;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\LT;
use Kwasii\LaravelSqlParser\Expressions\LTE;
use Kwasii\LaravelSqlParser\Expressions\NEQ;
use Kwasii\LaravelSqlParser\Expressions\Not;
use Kwasii\LaravelSqlParser\Expressions\NullSafeEQ;
use Kwasii\LaravelSqlParser\Expressions\NullSafeNEQ;
use Kwasii\LaravelSqlParser\Expressions\Ordered;
use Kwasii\LaravelSqlParser\Expressions\Sub;
use Kwasii\LaravelSqlParser\Expressions\Timestamp;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsToDate;
use Kwasii\LaravelSqlParser\Expressions\Where;

/**
 * Port of sqlglot's optimizer/canonicalize.py: converts an expression into a standard form.
 * It relies on annotate_types, since many conversions depend on type inference.
 */
final class Canonicalize
{
    /** @var list<class-string> */
    public const array COERCIBLE_DATE_OPS = [
        Add::class, Sub::class, EQ::class, NEQ::class, GT::class, GTE::class, LT::class, LTE::class, NullSafeEQ::class, NullSafeNEQ::class,
    ];

    private const array DATE_UNITS = ['day' => true, 'week' => true, 'month' => true, 'quarter' => true, 'year' => true, 'year_month' => true];

    public static function canonicalize(Expression $expression, string|Dialect|null $dialect = null): Expression
    {
        $dialect = Dialect::get($dialect);
        $promote = $dialect->settings['PROMOTE_TO_INFERRED_DATETIME_TYPE'] === true;

        $fn = static function (Expression $node) use ($dialect, $promote): Expression {
            $node = self::addTextToConcat($node);
            $node = self::replaceDateFuncs($node, $dialect);
            $node = self::coerceType($node, $promote);
            $node = self::removeRedundantCasts($node);
            $node = self::ensureBools($node, self::replaceIntPredicate(...));

            return self::removeAscendingOrder($node);
        };

        // exp.replace_tree: reverse dfs (leaves first), visiting new nodes too
        $stack = iterator_to_array($expression->dfs(), false);
        $newNode = $expression;

        while ($stack !== []) {
            $node = array_pop($stack);
            $newNode = $fn($node);

            if ($newNode !== $node) {
                $node->replace($newNode);
                $stack[] = $newNode;
            }
        }

        return $newNode;
    }

    public static function addTextToConcat(Expression $node): Expression
    {
        $type = $node->type();

        if ($node instanceof Add && $type !== null && in_array($type->args['this'] ?? null, DataType::TEXT_TYPES, true)) {
            // Dialects that concatenate strings with + (Redshift, T-SQL) don't coalesce NULLs
            return new Concat(['expressions' => [$node->args['this'], $node->args['expression']], 'coalesce' => false]);
        }

        return $node;
    }

    public static function replaceDateFuncs(Expression $node, string|Dialect|null $dialect = null): Expression
    {
        $this_ = $node->argExpr('this');

        if (
            ($node instanceof Date || $node instanceof TsOrDsToDate)
            && $node->expressions() === []
            && ($node->args['zone'] ?? null) === null
            && $this_ !== null && $this_->isString()
            && TypeAnnotator::isIsoDate($this_->name())
        ) {
            return Builder::cast($this_, DType::DATE);
        }

        if ($node instanceof Timestamp && ($node->args['zone'] ?? null) === null && $this_ !== null) {
            if ($node->type() === null) {
                $node = AnnotateTypes::annotateTypes($node, dialect: $dialect);
            }

            return Builder::cast($node->argExpr('this') ?? $this_, $node->type() ?? DType::TIMESTAMP);
        }

        return $node;
    }

    public static function coerceType(Expression $node, bool $promoteToInferredDatetimeType): Expression
    {
        if (self::isCoercibleDateOp($node)) {
            self::coerceDate($node->argExpr('this'), $node->argExpr('expression'), $promoteToInferredDatetimeType);
        } elseif ($node instanceof Between) {
            self::coerceDate($node->argExpr('this'), $node->argExpr('low'), $promoteToInferredDatetimeType);
        } elseif ($node instanceof Extract && ($e = $node->argExpr('expression')) !== null && ! $e->isType(...DataType::TEMPORAL_TYPES)) {
            self::replaceCast($e, DType::DATETIME);
        } elseif (($node instanceof DateAdd || $node instanceof DateSub || $node instanceof DateTrunc) && ($this_ = $node->argExpr('this')) !== null) {
            self::coerceTimeunitArg($this_, $node->argExpr('unit'));
        } elseif ($node instanceof DateDiff) {
            foreach ([$node->argExpr('this'), $node->argExpr('expression')] as $e) {
                $type = $e?->type();

                if ($e !== null && ($type === null || ! in_array($type->args['this'] ?? null, DataType::TEMPORAL_TYPES, true))) {
                    $e->replace(Builder::cast($e->copy(), DType::DATETIME));
                }
            }
        }

        return $node;
    }

    public static function removeRedundantCasts(Expression $expression): Expression
    {
        $this_ = $expression->argExpr('this');
        $thisType = $this_?->type();

        if ($expression instanceof Cast && $this_ !== null && $thisType !== null && $thisType->equals($expression->argExpr('to'))) {
            return $this_;
        }

        if (
            ($expression instanceof Date || $expression instanceof TsOrDsToDate)
            && $this_ !== null && $thisType !== null
            && ($thisType->args['this'] ?? null) === DType::DATE
            && $thisType->expressions() === []
        ) {
            return $this_;
        }

        return $expression;
    }

    /**
     * @param  Closure(Expression): void  $replaceFunc
     */
    public static function ensureBools(Expression $expression, Closure $replaceFunc): Expression
    {
        $targets = [];

        if ($expression instanceof Connector) {
            $targets = [$expression->argExpr('this'), $expression->argExpr('expression')];
        } elseif ($expression instanceof Not) {
            $targets = [$expression->argExpr('this')];
        } elseif ($expression instanceof IfExpr && ! ($expression->parent instanceof CaseExpr && $expression->parent->argExpr('this') !== null)) {
            // We can't replace num in CASE x WHEN num ..., because it's not the full predicate
            $targets = [$expression->argExpr('this')];
        } elseif ($expression instanceof Where || $expression instanceof Having) {
            $targets = [$expression->argExpr('this')];
        }

        foreach ($targets as $target) {
            if ($target !== null) {
                $replaceFunc($target);
            }
        }

        return $expression;
    }

    public static function removeAscendingOrder(Expression $expression): Expression
    {
        if ($expression instanceof Ordered && ($expression->args['desc'] ?? null) === false) {
            // Convert ORDER BY a ASC to ORDER BY a
            $expression->set('desc', null);
        }

        return $expression;
    }

    private static function isCoercibleDateOp(Expression $node): bool
    {
        foreach (self::COERCIBLE_DATE_OPS as $class) {
            if ($node instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private static function coerceDate(?Expression $a, ?Expression $b, bool $promote): void
    {
        if ($a === null || $b === null) {
            return;
        }

        foreach ([[$a, $b], [$b, $a]] as [$x, $y]) {
            if ($y instanceof Interval) {
                $x = self::coerceTimeunitArg($x, $y->argExpr('unit'));
            }

            $xType = $x->type();
            $yType = $y->type();

            if (
                $xType === null || ! in_array($xType->args['this'] ?? null, DataType::TEMPORAL_TYPES, true)
                || $yType === null || ! in_array($yType->args['this'] ?? null, DataType::TEXT_TYPES, true)
            ) {
                continue;
            }

            $targetType = $xType;

            if ($promote) {
                if ($y->isString()) {
                    $text = $y->name();
                    $bType = match (true) {
                        TypeAnnotator::isIsoDate($text) => DType::DATE,
                        TypeAnnotator::isIsoDatetime($text) => DType::DATETIME,
                        default => (string) $xType->args['this'],
                    };
                } else {
                    // Conservatively promote to DATETIME, to avoid surprising truncations from downcasting
                    $bType = DType::DATETIME;
                }

                if (isset(TypeAnnotator::defaultCoercesTo()[(string) $xType->args['this']][$bType])) {
                    $targetType = new DataType(['this' => $bType]);
                }
            }

            if (! $targetType->equals($xType)) {
                self::replaceCast($x, $targetType);
            }

            self::replaceCast($y, $targetType);
        }
    }

    private static function isDateUnit(?Expression $unit): bool
    {
        return $unit !== null && isset(self::DATE_UNITS[strtolower($unit->name())]);
    }

    private static function coerceTimeunitArg(Expression $arg, ?Expression $unit): Expression
    {
        $type = $arg->type();

        if ($type === null) {
            return $arg;
        }

        $typeName = $type->args['this'] ?? null;

        if (in_array($typeName, DataType::TEXT_TYPES, true)) {
            $text = $arg->name();
            $isIsoDate = TypeAnnotator::isIsoDate($text);

            if ($isIsoDate && self::isDateUnit($unit)) {
                return $arg->replace(Builder::cast($arg->copy(), DType::DATE));
            }

            // An ISO date is also an ISO datetime, but not vice versa
            if ($isIsoDate || TypeAnnotator::isIsoDatetime($text)) {
                return $arg->replace(Builder::cast($arg->copy(), DType::DATETIME));
            }
        } elseif ($typeName === DType::DATE && ! self::isDateUnit($unit)) {
            return $arg->replace(Builder::cast($arg->copy(), DType::DATETIME));
        }

        return $arg;
    }

    private static function replaceCast(Expression $node, DataType|string $to): void
    {
        $node->replace(Builder::cast($node->copy(), $to));
    }

    /**
     * Presto-style: integer predicates become `x <> 0` (only on int types, unlike T-SQL's bits).
     */
    private static function replaceIntPredicate(Expression $expression): void
    {
        if ($expression instanceof Coalesce) {
            foreach ($expression->children() as $child) {
                self::replaceIntPredicate($child);
            }
        } elseif (($type = $expression->type()) !== null && in_array($type->args['this'] ?? null, DataType::INTEGER_TYPES, true)) {
            $expression->replace(Builder::binop(NEQ::class, $expression, 0));
        }
    }
}
