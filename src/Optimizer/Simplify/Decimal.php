<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer\Simplify;

use Kwasii\LaravelSqlParser\Expressions\Literal;

/**
 * The numbers simplify folds: Python ints (arbitrary precision) and decimal.Decimal values with
 * the default context (28 significant digits, ROUND_HALF_EVEN), as Literal.to_py() returns them.
 *
 * A value is an integer coefficient (a signed digit string) times 10 ** exponent.
 *
 * @internal
 */
final class Decimal
{
    private const int PRECISION = 28;

    private function __construct(
        public readonly string $coefficient,
        public readonly int $exponent,
        public readonly bool $isInt,
    ) {}

    /**
     * From a Literal::toPhp() value: an int, or the numeric text of a non-integer literal.
     */
    public static function from(int|string $value): ?self
    {
        if (is_int($value)) {
            return new self((string) $value, 0, true);
        }

        $text = trim($value);

        if (preg_match('/^([+-]?)(\d+)$/', $text, $m) === 1) {
            return new self(self::sign($m[1], self::strip($m[2])), 0, true);
        }

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/', $text, $m) !== 1 || ($m[2] === '' && ($m[3] ?? '') === '')) {
            return null;
        }

        $fraction = $m[3] ?? '';

        return new self(self::sign($m[1], self::strip($m[2].$fraction)), (int) ($m[4] ?? 0) - strlen($fraction), false);
    }

    public function isZero(): bool
    {
        return ltrim($this->coefficient, '-') === '0';
    }

    /**
     * Python's str() of the value, e.g. for Literal.number().
     */
    public function toString(): string
    {
        if ($this->isInt) {
            return $this->coefficient;
        }

        return Literal::pythonNumberString($this->coefficient.'E'.$this->exponent) ?? $this->coefficient;
    }

    public function add(self $other): self
    {
        $exponent = min($this->exponent, $other->exponent);
        $sum = self::addSigned(self::scale($this->coefficient, $this->exponent - $exponent), self::scale($other->coefficient, $other->exponent - $exponent));

        return self::fix($sum, $exponent, $this->isInt && $other->isInt);
    }

    public function sub(self $other): self
    {
        return $this->add($other->negate());
    }

    public function mul(self $other): self
    {
        return self::fix(self::mulSigned($this->coefficient, $other->coefficient), $this->exponent + $other->exponent, $this->isInt && $other->isInt);
    }

    /**
     * Decimal division (Python's Decimal.__truediv__); null on a division by zero.
     */
    public function div(self $other): ?self
    {
        if ($other->isZero()) {
            return null;
        }

        $negative = str_starts_with($this->coefficient, '-') !== str_starts_with($other->coefficient, '-');
        $a = ltrim($this->coefficient, '-');
        $b = ltrim($other->coefficient, '-');
        $ideal = $this->exponent - $other->exponent;

        if ($a === '0') {
            return self::fix('0', $ideal, false);
        }

        $shift = strlen($b) - strlen($a) + self::PRECISION + 1;
        $exponent = $ideal - $shift;

        [$coefficient, $remainder] = $shift >= 0
            ? self::divmod($a.str_repeat('0', $shift), $b)
            : self::divmod($a, $b.str_repeat('0', -$shift));

        if ($remainder !== '0') {
            if (in_array(substr($coefficient, -1), ['0', '5'], true)) {
                $coefficient = self::addUnsigned($coefficient, '1');
            }
        } else {
            while ($exponent < $ideal && $coefficient !== '0' && str_ends_with($coefficient, '0')) {
                $coefficient = substr($coefficient, 0, -1);
                $exponent++;
            }
        }

        return self::fix(self::sign($negative ? '-' : '', $coefficient), $exponent, false);
    }

    public function negate(): self
    {
        $negative = str_starts_with($this->coefficient, '-');

        return new self(self::sign($negative ? '' : '-', ltrim($this->coefficient, '-')), $this->exponent, $this->isInt);
    }

    /**
     * Python's int() of the value (truncating).
     */
    public function toInt(): ?int
    {
        $coefficient = $this->coefficient;

        if ($this->exponent < 0) {
            $digits = ltrim($coefficient, '-');
            $kept = strlen($digits) + $this->exponent;
            $coefficient = self::sign(str_starts_with($coefficient, '-') ? '-' : '', $kept > 0 ? self::strip(substr($digits, 0, $kept)) : '0');
        } elseif ($this->exponent > 0) {
            $coefficient = self::scale($coefficient, $this->exponent);
        }

        $int = filter_var($coefficient, FILTER_VALIDATE_INT);

        return is_int($int) ? $int : null;
    }

    public function compare(self $other): int
    {
        $exponent = min($this->exponent, $other->exponent);

        return self::compareSigned(self::scale($this->coefficient, $this->exponent - $exponent), self::scale($other->coefficient, $other->exponent - $exponent));
    }

    // ---------------------------------------------------------------------------------------
    // Arbitrary precision integer helpers (digit strings)
    // ---------------------------------------------------------------------------------------

    /**
     * Round to the context precision (Decimal._fix with ROUND_HALF_EVEN). Ints are exact.
     */
    private static function fix(string $coefficient, int $exponent, bool $isInt): self
    {
        if ($isInt) {
            return new self($coefficient, 0, true);
        }

        $negative = str_starts_with($coefficient, '-');
        $digits = ltrim($coefficient, '-');
        $excess = strlen($digits) - self::PRECISION;

        if ($excess > 0) {
            $kept = substr($digits, 0, self::PRECISION);
            $dropped = substr($digits, self::PRECISION);
            $first = (int) $dropped[0];
            $rest = trim(substr($dropped, 1), '0');
            $roundUp = $first > 5 || ($first === 5 && ($rest !== '' || ((int) substr($kept, -1)) % 2 === 1));

            if ($roundUp) {
                $kept = self::addUnsigned($kept, '1');
            }

            $exponent += $excess;

            if (strlen($kept) > self::PRECISION) {
                $kept = substr($kept, 0, -1);
                $exponent++;
            }

            $digits = $kept;
        }

        return new self(self::sign($negative ? '-' : '', $digits), $exponent, false);
    }

    private static function strip(string $digits): string
    {
        $digits = ltrim($digits, '0');

        return $digits === '' ? '0' : $digits;
    }

    private static function sign(string $sign, string $digits): string
    {
        return $sign === '-' && $digits !== '0' ? '-'.$digits : $digits;
    }

    private static function scale(string $coefficient, int $zeros): string
    {
        return ltrim($coefficient, '-') === '0' || $zeros <= 0 ? $coefficient : $coefficient.str_repeat('0', $zeros);
    }

    private static function compareUnsigned(string $a, string $b): int
    {
        $a = self::strip($a);
        $b = self::strip($b);

        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    private static function compareSigned(string $a, string $b): int
    {
        $an = str_starts_with($a, '-');
        $bn = str_starts_with($b, '-');

        if ($an !== $bn) {
            return $an ? -1 : 1;
        }

        $cmp = self::compareUnsigned(ltrim($a, '-'), ltrim($b, '-'));

        return $an ? -$cmp : $cmp;
    }

    private static function addUnsigned(string $a, string $b): string
    {
        $result = '';
        $carry = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;

        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = $carry + ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0);
            $result = ($sum % 10).$result;
            $carry = intdiv($sum, 10);
            $i--;
            $j--;
        }

        return self::strip($result);
    }

    /**
     * $a - $b for $a >= $b.
     */
    private static function subUnsigned(string $a, string $b): string
    {
        $result = '';
        $borrow = 0;
        $i = strlen($a) - 1;
        $j = strlen($b) - 1;

        while ($i >= 0) {
            $diff = (int) $a[$i] - $borrow - ($j >= 0 ? (int) $b[$j] : 0);
            $borrow = $diff < 0 ? 1 : 0;
            $result = ($diff + 10 * $borrow).$result;
            $i--;
            $j--;
        }

        return self::strip($result);
    }

    private static function addSigned(string $a, string $b): string
    {
        $an = str_starts_with($a, '-');
        $bn = str_starts_with($b, '-');
        $a = ltrim($a, '-');
        $b = ltrim($b, '-');

        if ($an === $bn) {
            return self::sign($an ? '-' : '', self::addUnsigned($a, $b));
        }

        $cmp = self::compareUnsigned($a, $b);

        if ($cmp === 0) {
            return '0';
        }

        return $cmp > 0 ? self::sign($an ? '-' : '', self::subUnsigned($a, $b)) : self::sign($bn ? '-' : '', self::subUnsigned($b, $a));
    }

    private static function mulSigned(string $a, string $b): string
    {
        $negative = str_starts_with($a, '-') !== str_starts_with($b, '-');
        $a = ltrim($a, '-');
        $b = ltrim($b, '-');
        $digits = array_fill(0, strlen($a) + strlen($b), 0);

        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) {
                $digits[$i + $j + 1] += (int) $a[$i] * (int) $b[$j];
            }
        }

        for ($k = count($digits) - 1; $k > 0; $k--) {
            $digits[$k - 1] += intdiv($digits[$k], 10);
            $digits[$k] %= 10;
        }

        return self::sign($negative ? '-' : '', self::strip(implode('', $digits)));
    }

    /**
     * @return array{0: string, 1: string} quotient and remainder of unsigned digit strings
     */
    private static function divmod(string $a, string $b): array
    {
        $quotient = '';
        $remainder = '0';

        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            $remainder = self::strip($remainder.$a[$i]);
            $digit = 0;

            while (self::compareUnsigned($remainder, $b) >= 0) {
                $remainder = self::subUnsigned($remainder, $b);
                $digit++;
            }

            $quotient .= $digit;
        }

        return [self::strip($quotient), $remainder];
    }
}
