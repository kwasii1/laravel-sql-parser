<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Neg;

/**
 * @mixin Literal
 */
trait LiteralBehavior
{
    public static function string(string|int|float $value): Literal
    {
        return new Literal(['this' => (string) $value, 'is_string' => true]);
    }

    /**
     * A number literal; negative numbers become Neg(literal), like sqlglot.
     */
    public static function number(string|int|float $value): Literal|Neg
    {
        $text = is_string($value) ? $value : self::numberText($value);
        $literal = new Literal(['this' => $text, 'is_string' => false]);

        if (is_numeric($text) && (float) $text < 0) {
            $literal->args['this'] = ltrim($text, '-');

            return new Neg(['this' => $literal]);
        }

        return $literal;
    }

    private static function numberText(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $text = (string) $value;

        return strpbrk($text, '.EeIN') !== false ? $text : $text.'.0';
    }

    public function outputName(): string
    {
        return $this->name();
    }

    /**
     * The literal as a PHP value: ints for integers, numeric strings for other numbers (to keep
     * their exact text, like Python's Decimal), strings for strings.
     */
    public function toPhp(): int|string
    {
        $text = (string) ($this->args['this'] ?? '');

        if ($this->isString()) {
            return $text;
        }

        if (preg_match('/^[+-]?\d+$/', $text) === 1 && filter_var($text, FILTER_VALIDATE_INT) !== false) {
            return (int) $text;
        }

        return $text;
    }

    /**
     * Python's str() of int(text) or Decimal(text) for a numeric literal; null if it isn't numeric.
     */
    public static function pythonNumberString(string $text): ?string
    {
        $text = trim($text);

        if (preg_match('/^([+-]?)(\d+)$/', $text, $m) === 1) {
            $digits = ltrim($m[2], '0');

            return ($m[1] === '-' && $digits !== '' ? '-' : '').($digits === '' ? '0' : $digits);
        }

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $text, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }

        $sign = $m[1] === '-' ? '-' : '';
        $fraction = $m[3] ?? '';
        $coefficient = ltrim($m[2].$fraction, '0');
        $coefficient = $coefficient === '' ? '0' : $coefficient;
        $exponent = (int) ($m[4] ?? 0) - strlen($fraction);

        // Decimal keeps trailing zeros of the coefficient; leading zeros don't count
        $digits = strlen($coefficient);
        $adjusted = $exponent + $digits - 1;

        if ($exponent <= 0 && $adjusted >= -6) {
            if ($exponent === 0) {
                return $sign.$coefficient;
            }

            $point = $digits + $exponent;

            if ($point > 0) {
                return $sign.substr($coefficient, 0, $point).'.'.substr($coefficient, $point);
            }

            return $sign.'0.'.str_repeat('0', -$point).$coefficient;
        }

        $mantissa = $digits > 1 ? $coefficient[0].'.'.substr($coefficient, 1) : $coefficient;

        return $sign.$mantissa.'E'.($adjusted >= 0 ? '+' : '').$adjusted;
    }
}
