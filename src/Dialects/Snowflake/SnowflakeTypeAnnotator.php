<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Snowflake;

use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\Ordered;
use Kwasii\LaravelSqlParser\Expressions\PercentileCont;
use Kwasii\LaravelSqlParser\Expressions\PercentileDisc;
use Kwasii\LaravelSqlParser\Optimizer\TypeAnnotator;

/**
 * Snowflake type inference (sqlglot typing/snowflake.py).
 */
class SnowflakeTypeAnnotator extends TypeAnnotator
{
    private const array DATE_PARTS = ['DAY' => true, 'WEEK' => true, 'MONTH' => true, 'QUARTER' => true, 'YEAR' => true];

    private const int MAX_PRECISION = 38;

    private const int MAX_SCALE = 37;

    private static function argType(Expression $expression, string $key = 'this'): ?DataType
    {
        $arg = $expression->args[$key] ?? null;

        return $arg instanceof Expression ? $arg->type() : null;
    }

    /**
     * The integer value of a type parameter, e.g. 10 in NUMBER(10, 2).
     */
    private static function typeParam(?DataType $type, int $index, int $default): int
    {
        $param = $type?->argList('expressions')[$index] ?? null;
        $value = $param instanceof Expression ? ($param->args['this'] ?? null) : null;

        return $value instanceof Expression && $value->isInt() ? (int) $value->name() : $default;
    }

    protected function annotateReverse(Expression $expression): Expression
    {
        $expression = $this->annotateByArgs($expression, ['this']);

        // Snowflake treats REVERSE(NULL) as a VARCHAR
        if ($expression->isType(DType::NULL)) {
            $this->setType($expression, DType::VARCHAR);
        }

        return $expression;
    }

    /**
     * TIMESTAMP_FROM_PARTS with a time zone is TIMESTAMPTZ, without one TIMESTAMP (TIMESTAMP_NTZ).
     */
    protected function annotateTimestampFromParts(Expression $expression): Expression
    {
        return $this->setType($expression, $expression->flag('zone') ? DType::TIMESTAMPTZ : DType::TIMESTAMP);
    }

    protected function annotateDateOrTimeAdd(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Expression && $this_->isType(DType::DATE) && ! isset(self::DATE_PARTS[strtoupper($expression->text('unit'))])) {
            return $this->setType($expression, DType::TIMESTAMPNTZ);
        }

        return $this->annotateByArgs($expression, ['this']);
    }

    /**
     * DECODE(expr, val1, ret1, val2, ret2, ..., default) has the type of its return values only.
     */
    protected function annotateDecodeCase(Expression $expression): Expression
    {
        $expressions = $expression->argList('expressions');
        $count = count($expressions);
        $returnTypes = [];

        for ($i = 2; $i < $count; $i += 2) {
            $returnTypes[] = $expressions[$i]->type();
        }

        // An even number of expressions means the last one is the default
        if ($count % 2 === 0 && $count > 0) {
            $returnTypes[] = $expressions[$count - 1]->type();
        }

        $lastType = null;

        foreach ($returnTypes as $returnType) {
            $lastType = $this->maybeCoerce($lastType ?? $returnType ?? DType::UNKNOWN, $returnType ?? DType::UNKNOWN);
        }

        return $this->setType($expression, $lastType);
    }

    protected function annotateArgMaxMin(Expression $expression): Expression
    {
        return $this->setType($expression, $expression->flag('count') ? DType::ARRAY : self::argType($expression));
    }

    /**
     * PERCENTILE_DISC / PERCENTILE_CONT WITHIN GROUP have the ordered expression's type.
     */
    protected function annotateWithinGroupSnowflake(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;
        $order = $expression->args['expression'] ?? null;
        $orderExpressions = $order instanceof Order ? $order->argList('expressions') : [];

        if (($this_ instanceof PercentileDisc || $this_ instanceof PercentileCont) && count($orderExpressions) === 1 && $orderExpressions[0] instanceof Ordered) {
            return $this->setType($expression, self::argType($orderExpressions[0]));
        }

        return $this->setType($expression, $this_ instanceof Expression ? $this_->type() : null);
    }

    /**
     * MEDIAN: FLOAT / DOUBLE is DOUBLE, NUMBER(p, s) is NUMBER(min(p + 3, 38), min(s + 3, 37)).
     */
    protected function annotateMedian(Expression $expression): Expression
    {
        $expression = $this->annotateByArgs($expression, ['this']);
        $inputType = self::argType($expression);

        if ($inputType !== null && $inputType->isType(DType::DOUBLE)) {
            return $this->setType($expression, DType::DOUBLE);
        }

        $precision = min(self::typeParam($inputType, 0, self::MAX_PRECISION) + 3, self::MAX_PRECISION);
        $scale = min(self::typeParam($inputType, 1, 0) + 3, self::MAX_SCALE);

        return $this->setType($expression, DataType::fromStr("NUMBER({$precision}, {$scale})", dialect: 'snowflake'));
    }

    /**
     * VAR_POP, VAR_SAMP, VARIANCE, VARIANCE_POP: DECFLOAT is DECFLOAT(38), FLOAT / DOUBLE is FLOAT,
     * INT and NUMBER(p, 0) are NUMBER(38, 6) and NUMBER(p, s) is NUMBER(38, max(12, s)).
     */
    protected function annotateVariance(Expression $expression): Expression
    {
        $expression = $this->annotateByArgs($expression, ['this']);
        $inputType = self::argType($expression);

        if ($inputType !== null && $inputType->isType(DType::DECFLOAT)) {
            return $this->setType($expression, DataType::fromStr('DECFLOAT', dialect: 'snowflake'));
        }

        if ($inputType !== null && $inputType->isType(DType::FLOAT, DType::DOUBLE)) {
            return $this->setType($expression, DType::DOUBLE);
        }

        $scale = self::typeParam($inputType, 1, 0);
        $newScale = $scale === 0 ? 6 : max(12, $scale);

        return $this->setType($expression, DataType::fromStr('NUMBER('.self::MAX_PRECISION.", {$newScale})", dialect: 'snowflake'));
    }

    /**
     * KURTOSIS: DECFLOAT is DECFLOAT, DOUBLE / FLOAT is DOUBLE, other numbers are NUMBER(38, 12).
     */
    protected function annotateKurtosis(Expression $expression): Expression
    {
        $expression = $this->annotateByArgs($expression, ['this']);
        $inputType = self::argType($expression);

        if ($inputType !== null && $inputType->isType(DType::DECFLOAT)) {
            return $this->setType($expression, DataType::fromStr('DECFLOAT', dialect: 'snowflake'));
        }

        if ($inputType !== null && $inputType->isType(DType::FLOAT, DType::DOUBLE)) {
            return $this->setType($expression, DType::DOUBLE);
        }

        return $this->setType($expression, DataType::fromStr('NUMBER('.self::MAX_PRECISION.', 12)', dialect: 'snowflake'));
    }

    /**
     * Trigonometric and exponential functions keep DECFLOAT and return DOUBLE otherwise.
     */
    protected function annotateMathWithFloatDecfloat(Expression $expression): Expression
    {
        $expression = $this->annotateByArgs($expression, ['this']);
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Expression && $this_->isType(DType::DECFLOAT)) {
            return $this->setType($expression, $this_->type());
        }

        return $this->setType($expression, DType::DOUBLE);
    }

    protected function annotateStrToTime(Expression $expression): Expression
    {
        $targetType = $expression->args['target_type'] ?? null;

        return $this->setType($expression, $targetType instanceof DataType ? (string) ($targetType->args['this'] ?? DType::TIMESTAMP) : DType::TIMESTAMP);
    }

    protected function annotateConvertTimezone(Expression $expression): Expression
    {
        return $this->setType($expression, $expression->flag('source_tz') ? DType::TIMESTAMPNTZ : DType::TIMESTAMPTZ);
    }
}
