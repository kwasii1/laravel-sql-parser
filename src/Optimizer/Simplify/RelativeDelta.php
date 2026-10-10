<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer\Simplify;

/**
 * The subset of dateutil's relativedelta simplify uses: a calendar part (years and months,
 * applied first, clamping the day to the month's end) and an absolute part.
 *
 * @internal
 */
final class RelativeDelta
{
    public readonly int $years;

    public readonly int $months;

    public function __construct(
        int $years = 0,
        int $months = 0,
        public readonly int $days = 0,
        public readonly int $hours = 0,
        public readonly int $minutes = 0,
        public readonly int $seconds = 0,
        public readonly int $microseconds = 0,
    ) {
        // relativedelta normalizes months into years, keeping their sign
        $total = $years * 12 + $months;
        $sign = $total < 0 ? -1 : 1;
        $this->years = $sign * intdiv(abs($total), 12);
        $this->months = $sign * (abs($total) % 12);
    }

    public static function forUnit(string $unit, int $n = 1): self
    {
        return match ($unit) {
            'year' => new self(years: $n),
            'quarter' => new self(months: 3 * $n),
            'month' => new self(months: $n),
            'week' => new self(days: 7 * $n),
            'day' => new self(days: $n),
            'hour' => new self(hours: $n),
            'minute' => new self(minutes: $n),
            'second' => new self(seconds: $n),
            'millisecond' => new self(microseconds: 1000 * $n),
            'microsecond' => new self(microseconds: $n),
            default => throw new UnsupportedUnit("Unsupported unit: {$unit}"),
        };
    }

    public function negate(): self
    {
        return new self(-$this->years, -$this->months, -$this->days, -$this->hours, -$this->minutes, -$this->seconds, -$this->microseconds);
    }

    /**
     * Python truthiness: a delta is falsy when every field is zero.
     */
    public function isZero(): bool
    {
        return $this->years === 0 && $this->months === 0 && $this->days === 0 && $this->hours === 0
            && $this->minutes === 0 && $this->seconds === 0 && $this->microseconds === 0;
    }

    public function hasTime(): bool
    {
        return $this->hours !== 0 || $this->minutes !== 0 || $this->seconds !== 0 || $this->microseconds !== 0;
    }

    /**
     * The absolute part in microseconds.
     */
    public function absoluteMicroseconds(): int
    {
        return (((($this->days * 24) + $this->hours) * 60 + $this->minutes) * 60 + $this->seconds) * 1_000_000 + $this->microseconds;
    }
}
