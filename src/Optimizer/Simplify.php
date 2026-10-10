<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\AndExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseNot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Condition;
use LaravelSqlParser\LaravelSqlParser\Expressions\Connector;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateSub;
use LaravelSqlParser\LaravelSqlParser\Expressions\DatetimeSub;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\GT;
use LaravelSqlParser\LaravelSqlParser\Expressions\GTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\LT;
use LaravelSqlParser\LaravelSqlParser\Expressions\LTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\Neg;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Predicate;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sub;
use LaravelSqlParser\LaravelSqlParser\Expressions\SubqueryPredicate;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\WeekStart;
use LaravelSqlParser\LaravelSqlParser\Optimizer\Simplify\DateValue;
use LaravelSqlParser\LaravelSqlParser\Optimizer\Simplify\Decimal;
use LaravelSqlParser\LaravelSqlParser\Optimizer\Simplify\Gen;
use LaravelSqlParser\LaravelSqlParser\Optimizer\Simplify\RelativeDelta;
use LaravelSqlParser\LaravelSqlParser\Optimizer\Simplify\UnsupportedUnit;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;
use LogicException;

/**
 * Port of sqlglot's optimizer/simplify.py module functions. The rewrite rules themselves
 * live in {@see Simplifier}.
 */
final class Simplify
{
    /** Meta key marking an expression that must not be simplified. */
    public const string FINAL = 'final';

    private const array WEEK_START_DAY_TO_DOW = [
        'MONDAY' => 1,
        'TUESDAY' => 2,
        'WEDNESDAY' => 3,
        'THURSDAY' => 4,
        'FRIDAY' => 5,
        'SATURDAY' => 6,
        'SUNDAY' => 7,
    ];

    /**
     * Rewrite an AST to simplify its expressions, e.g. `TRUE AND TRUE` becomes `TRUE`.
     *
     * @param  bool  $constantPropagation  whether the constant propagation rule should be used
     * @param  bool  $coalesceSimplification  whether to try removing COALESCE functions
     */
    public static function simplify(
        Expression $expression,
        bool $constantPropagation = false,
        bool $coalesceSimplification = false,
        string|Dialect|null $dialect = null,
    ): Expression {
        return (new Simplifier($dialect))->simplify($expression, $constantPropagation, $coalesceSimplification);
    }

    /**
     * A AND (B AND C) -> A AND B AND C
     * A OR (B OR C) -> A OR B OR C
     */
    public static function flatten(Expression $expression): Expression
    {
        if ($expression instanceof Connector) {
            foreach ($expression->args as $node) {
                if (! $node instanceof Expression) {
                    continue;
                }

                $child = $node->unnest();

                if ($child instanceof $expression) {
                    $node->replace($child);
                }
            }
        }

        return $expression;
    }

    public static function simplifyParens(Expression $expression, string|Dialect|null $dialect = null): Expression
    {
        if (! $expression instanceof Paren) {
            return $expression;
        }

        $this_ = $expression->args['this'] ?? null;
        $parent = $expression->parent;
        $parentIsPredicate = $parent instanceof Predicate;

        if (! $this_ instanceof Expression || $this_ instanceof Select) {
            return $expression;
        }

        if ($parent instanceof SubqueryPredicate || $parent instanceof Bracket) {
            return $expression;
        }

        if (
            Dialect::get($dialect)->settings['REQUIRES_PARENTHESIZED_STRUCT_ACCESS'] === true
            && $parent instanceof Dot
            && (($right = $parent->args['expression'] ?? null) instanceof Identifier || $right instanceof Star)
        ) {
            return $expression;
        }

        if ($this_ instanceof Predicate || $this_ instanceof Not) {
            if (
                $parentIsPredicate
                // unary operators that bind tighter than the predicate, unlike NOT
                || $parent instanceof Neg || $parent instanceof BitwiseNot
                || ($parent instanceof Binary && ! $parent instanceof Connector)
            ) {
                return $expression;
            }

            return $this_;
        }

        if (
            ! ($parent instanceof Condition || $parent instanceof Binary)
            || $parent instanceof Paren
            || ! $this_ instanceof Binary
            || ($this_ instanceof Add && $parent instanceof Add)
            || ($this_ instanceof Mul && $parent instanceof Mul)
            || ($this_ instanceof Mul && ($parent instanceof Add || $parent instanceof Sub))
        ) {
            return $this_;
        }

        return $expression;
    }

    /**
     * Propagate constants for conjunctions in DNF:
     *
     * SELECT * FROM t WHERE a = b AND b = 5 becomes
     * SELECT * FROM t WHERE a = 5 AND b = 5
     */
    public static function propagateConstants(Expression $expression, bool $root = true): Expression
    {
        if ($expression instanceof AndExpr && ($root || ! $expression->sameParent()) && Normalize::normalized($expression, dnf: true)) {
            /** @var array<string, array{0: int, 1: Expression}> $constantMapping */
            $constantMapping = [];

            foreach (Scope::walkInScope($expression, static fn (Expression $node): bool => $node instanceof IfExpr) as $expr) {
                if ($expr instanceof EQ) {
                    $l = $expr->args['this'] ?? null;
                    $r = $expr->args['expression'] ?? null;

                    // Substituting the constant is only an identity when the column can't be NULL:
                    // for a NULL x, `x = 1 AND x + 1 = 0` is NULL, not FALSE
                    if ($l instanceof Column && $r instanceof Literal && $l->metaGet('nonnull') === true) {
                        $constantMapping[self::key($l)] = [spl_object_id($l), $r];
                    }
                }
            }

            if ($constantMapping !== []) {
                foreach (Scope::findAllInScope($expression, Column::class) as $column) {
                    $parent = $column->parent;
                    $mapped = $constantMapping[self::key($column)] ?? null;

                    if (
                        $mapped !== null
                        && spl_object_id($column) !== $mapped[0]
                        && ! ($parent instanceof Is && ($parent->args['expression'] ?? null) instanceof NullExpr)
                    ) {
                        $column->replace($mapped[1]->copy());
                    }
                }
            }
        }

        return $expression;
    }

    /**
     * A structural set key (Python's hash + type equality of expressions).
     */
    public static function key(Expression $expression): string
    {
        return $expression::class.'#'.$expression->hashKey();
    }

    public static function isNumber(Expression $expression): bool
    {
        return $expression->isNumber();
    }

    public static function isInterval(Expression $expression): bool
    {
        return $expression instanceof Interval && self::extractInterval($expression) !== null;
    }

    public static function isNonnullConstant(Expression $expression): bool
    {
        return $expression instanceof Literal || $expression instanceof Boolean || self::isDateLiteral($expression);
    }

    public static function isConstant(Expression $expression): bool
    {
        $expr = $expression instanceof Neg && ($expression->args['this'] ?? null) instanceof Expression ? $expression->args['this'] : $expression;

        return $expr instanceof Literal || $expr instanceof Boolean || $expr instanceof NullExpr || self::isDateLiteral($expr);
    }

    /**
     * The [min, max) date range for a DATE_TRUNC equality comparison, or null if a value can
     * never be equal to `date` for `unit`.
     *
     * @return array{0: DateValue, 1: DateValue}|null
     */
    public static function datetruncRange(DateValue $date, string $unit, Dialect $dialect): ?array
    {
        $floor = self::datetimeFloor($date, $unit, $dialect);

        if (! $date->equals($floor)) {
            // This will always be False, except for NULL values.
            return null;
        }

        return [$floor, $floor->add(self::interval($unit))];
    }

    /**
     * @param  array{0: DateValue, 1: DateValue}  $range
     */
    public static function datetruncEqExpression(Expression $left, array $range, ?DataType $targetType): Expression
    {
        return Builder::and([
            Builder::binop(GTE::class, $left, self::dateLiteral($range[0], $targetType)),
            Builder::binop(LT::class, $left, self::dateLiteral($range[1], $targetType)),
        ], copy: false);
    }

    public static function datetruncEq(Expression $left, DateValue $date, string $unit, Dialect $dialect, ?DataType $targetType): ?Expression
    {
        $range = self::datetruncRange($date, $unit, $dialect);

        return $range === null ? null : self::datetruncEqExpression($left, $range, $targetType);
    }

    public static function datetruncNeq(Expression $left, DateValue $date, string $unit, Dialect $dialect, ?DataType $targetType): ?Expression
    {
        $range = self::datetruncRange($date, $unit, $dialect);

        if ($range === null) {
            return null;
        }

        return Builder::or([
            Builder::binop(LT::class, $left, self::dateLiteral($range[0], $targetType)),
            Builder::binop(GTE::class, $left, self::dateLiteral($range[1], $targetType)),
        ], copy: false);
    }

    /**
     * The generator flattens nested connectors and relies on Paren nodes for grouping. Operator
     * precedence varies across dialects, so wrap unless the parent is the same connector type.
     */
    public static function parenthesizeNestedConnector(Expression $expression, ?Expression $parent): Expression
    {
        if ($expression instanceof Connector && ($parent instanceof Not || ($parent instanceof Connector && $parent::class !== $expression::class))) {
            return Builder::paren($expression, copy: false);
        }

        return $expression;
    }

    public static function alwaysTrue(mixed $expression): bool
    {
        return ($expression instanceof Boolean && ($expression->args['this'] ?? false) === true)
            || ($expression instanceof Literal && $expression->isNumber() && ! self::isZero($expression));
    }

    public static function alwaysFalse(mixed $expression): bool
    {
        return self::isFalse($expression) || self::isNull($expression) || self::isZero($expression);
    }

    public static function isZero(mixed $expression): bool
    {
        if (! $expression instanceof Literal || $expression->isString()) {
            return false;
        }

        return self::number($expression)?->isZero() === true;
    }

    public static function isComplement(mixed $a, mixed $b): bool
    {
        return $b instanceof Not && $a instanceof Expression && $a->equals($b->argExpr('this'));
    }

    public static function isFalse(mixed $a): bool
    {
        return $a instanceof Boolean && $a::class === Boolean::class && ($a->args['this'] ?? false) !== true;
    }

    public static function isNull(mixed $a): bool
    {
        return $a instanceof NullExpr && $a::class === NullExpr::class;
    }

    /**
     * The value of a numeric literal (Literal.to_py()) as a Python int / Decimal.
     */
    public static function number(Expression $expression): ?Decimal
    {
        $value = $expression->toPhp();

        return is_int($value) || is_string($value) ? Decimal::from($value) : null;
    }

    /**
     * Python's comparison of two values of the same kind (numbers, strings, dates); null when
     * Python would refuse to order them.
     */
    public static function compareValues(Decimal|DateValue|string $a, Decimal|DateValue|string $b): ?int
    {
        if ($a instanceof Decimal && $b instanceof Decimal) {
            return $a->compare($b);
        }

        if ($a instanceof DateValue && $b instanceof DateValue) {
            return $a->compare($b);
        }

        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        return null;
    }

    public static function evalBoolean(Expression $expression, Decimal|DateValue|string $a, Decimal|DateValue|string $b): ?Boolean
    {
        $cmp = self::compareValues($a, $b);
        $equal = $cmp === 0;

        if ($expression instanceof EQ || $expression instanceof Is) {
            if ($expression instanceof Is && $expression->flag('negate')) {
                return self::booleanLiteral(! $equal);
            }

            return self::booleanLiteral($equal);
        }

        if ($expression instanceof NEQ) {
            return self::booleanLiteral(! $equal);
        }

        if ($cmp === null) {
            return null;
        }

        return match (true) {
            $expression instanceof GT => self::booleanLiteral($cmp > 0),
            $expression instanceof GTE => self::booleanLiteral($cmp >= 0),
            $expression instanceof LT => self::booleanLiteral($cmp < 0),
            $expression instanceof LTE => self::booleanLiteral($cmp <= 0),
            default => null,
        };
    }

    public static function castAsDate(DateValue|string $value): ?DateValue
    {
        if ($value instanceof DateValue) {
            return $value->toDate();
        }

        return DateValue::fromIso($value)?->toDate();
    }

    public static function castAsDatetime(DateValue|string $value): ?DateValue
    {
        if ($value instanceof DateValue) {
            return $value->toDatetime();
        }

        return DateValue::fromIso($value);
    }

    public static function castValue(DateValue|string|null $value, DataType $to): ?DateValue
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($to->isType(DType::DATE)) {
            return self::castAsDate($value);
        }

        if ($to->isType(...DataType::TEMPORAL_TYPES)) {
            return self::castAsDatetime($value);
        }

        return null;
    }

    public static function extractDate(?Expression $cast): ?DateValue
    {
        if ($cast instanceof Cast) {
            $to = $cast->args['to'] ?? null;
        } elseif ($cast instanceof TsOrDsToDate && ($cast->args['format'] ?? null) === null) {
            $to = new DataType(['this' => DType::DATE]);
        } else {
            return null;
        }

        if (! $to instanceof DataType) {
            return null;
        }

        $this_ = $cast->args['this'] ?? null;

        if ($this_ instanceof Literal) {
            $value = $this_->name();
        } elseif ($this_ instanceof Cast || $this_ instanceof TsOrDsToDate) {
            $value = self::extractDate($this_);
        } else {
            return null;
        }

        return self::castValue($value, $to);
    }

    public static function isDateLiteral(Expression $expression): bool
    {
        return self::extractDate($expression) !== null;
    }

    public static function extractInterval(Expression $expression): ?RelativeDelta
    {
        $this_ = $expression->args['this'] ?? null;

        if (! $this_ instanceof Expression) {
            return null;
        }

        try {
            $value = $this_->toPhp();
        } catch (LogicException) {
            return null;
        }

        if (is_bool($value)) {
            $n = (int) $value;
        } elseif (is_int($value)) {
            $n = $value;
        } elseif (! is_string($value)) {
            return null;
        } elseif ($this_->isString()) {
            // int('...') of a string
            if (preg_match('/^\s*[+-]?\d+\s*$/', $value) !== 1) {
                return null;
            }
            $n = (int) trim($value);
        } else {
            $n = Decimal::from($value)?->toInt();

            if ($n === null) {
                return null;
            }
        }

        try {
            return self::interval(strtolower($expression->text('unit')), $n);
        } catch (UnsupportedUnit) {
            return null;
        }
    }

    public static function isExactIntervalMove(Expression $op, Expression $literal, Interval $interval): bool
    {
        $delta = self::extractInterval($interval);
        $value = self::extractDate($literal);

        if ($delta === null || $value === null) {
            return false;
        }

        if ($delta->months === 0 && $delta->years === 0) {
            return true;
        }

        if ($op instanceof Sub || $op instanceof DateSub || $op instanceof DatetimeSub) {
            $delta = $delta->negate();
        }

        $moved = $value->sub($delta);

        // Exact iff `moved` is the only x with x + delta = value: it must map back onto `value`, and the
        // next day must not, since clamping folds the last days of a longer month onto the same date
        return $moved->add($delta)->equals($value) && ! $moved->addDays(1)->add($delta)->equals($value);
    }

    public static function extractType(Expression ...$expressions): ?DataType
    {
        $targetType = null;

        foreach ($expressions as $expression) {
            $targetType = $expression instanceof Cast ? $expression->argExpr('to') : $expression->type();

            if ($targetType !== null) {
                break;
            }
        }

        return $targetType instanceof DataType ? $targetType : null;
    }

    public static function dateLiteral(DateValue $date, ?DataType $targetType = null): Expression
    {
        if ($targetType === null || ! $targetType->isType(...DataType::TEMPORAL_TYPES)) {
            $targetType = $date->isDatetime ? DType::DATETIME : DType::DATE;
        }

        return Builder::cast(Literal::string($date->toString()), $targetType);
    }

    public static function interval(string $unit, int $n = 1): RelativeDelta
    {
        return RelativeDelta::forUnit($unit, $n);
    }

    public static function datetimeFloor(DateValue $d, string $unit, Dialect $dialect): DateValue
    {
        // Truncate sub-day units — only valid for datetime inputs
        if ($d->isDatetime) {
            switch ($unit) {
                case 'hour':
                    return $d->replace(['minute' => 0, 'second' => 0, 'microsecond' => 0]);
                case 'minute':
                    return $d->replace(['second' => 0, 'microsecond' => 0]);
                case 'second':
                    return $d->replace(['microsecond' => 0]);
                case 'millisecond':
                    return $d->replace(['microsecond' => intdiv($d->microsecond, 1000) * 1000]);
                case 'microsecond':
                    return $d;
            }
        }

        // Truncate date-level units, shared for both date and datetime
        $result = match ($unit) {
            'year' => $d->replace(['month' => 1, 'day' => 1]),
            'quarter' => $d->replace(['month' => match (true) {
                $d->month <= 3 => 1,
                $d->month <= 6 => 4,
                $d->month <= 9 => 7,
                default => 10,
            }, 'day' => 1]),
            'month' => $d->replace(['day' => 1]),
            // Week truncation respects dialect.WEEK_OFFSET (0=Monday, -1=Sunday)
            'week' => $d->addDays(-((($d->weekday() - (int) $dialect->settings['WEEK_OFFSET']) % 7 + 7) % 7)),
            'day' => $d,
            default => throw new UnsupportedUnit("Unsupported unit: {$unit}"),
        };

        // For datetime inputs, zero out the time component after date-level truncation
        if ($result->isDatetime) {
            return $result->replace(['hour' => 0, 'minute' => 0, 'second' => 0, 'microsecond' => 0]);
        }

        return $result;
    }

    public static function truncUnit(Expression $unit, Dialect $dialect): string
    {
        if ($unit instanceof WeekStart) {
            $offset = (int) $dialect->settings['WEEK_OFFSET'];

            if ((self::WEEK_START_DAY_TO_DOW[strtoupper($unit->name())] ?? null) !== (($offset % 7) + 7) % 7 + 1) {
                throw new UnsupportedUnit('Unsupported unit: '.$unit->sql());
            }

            return 'week';
        }

        return strtolower($unit->name());
    }

    public static function dateCeil(DateValue $d, string $unit, Dialect $dialect): DateValue
    {
        $floor = self::datetimeFloor($d, $unit, $dialect);

        if ($floor->equals($d)) {
            return $d;
        }

        return $floor->add(self::interval($unit));
    }

    public static function booleanLiteral(bool $condition): Boolean
    {
        return $condition ? Builder::true() : Builder::false();
    }

    /**
     * Simple pseudo SQL generator for quickly generating sortable and unique strings.
     */
    public static function gen(Expression $expression, bool $comments = false): string
    {
        return (new Gen)->gen($expression, $comments);
    }
}
