<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer\Simplify;

/**
 * A Python datetime.date or datetime.datetime value, as simplify folds them.
 *
 * @internal
 */
final class DateValue
{
    private const int DAY_MICROSECONDS = 86_400_000_000;

    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
        public readonly bool $isDatetime = false,
        public readonly int $hour = 0,
        public readonly int $minute = 0,
        public readonly int $second = 0,
        public readonly int $microsecond = 0,
        public readonly ?string $tz = null,
    ) {}

    /**
     * datetime.fromisoformat (Python 3.11+), or null for text it rejects.
     */
    public static function fromIso(string $text): ?self
    {
        $pattern = '/^(\d{4})-?(\d{2})-?(\d{2})(?:.(\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,](\d+))?)?)?(Z|[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:\.\d+)?)?)?)?)?$/u';

        if (preg_match($pattern, $text, $m) !== 1) {
            return null;
        }

        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        if ($year < 1 || $month < 1 || $month > 12 || $day < 1 || $day > self::daysInMonth($year, $month)) {
            return null;
        }

        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) ($m[6] ?? 0);
        $fraction = $m[7] ?? '';
        $microsecond = $fraction === '' ? 0 : (int) str_pad(substr($fraction, 0, 6), 6, '0');

        if ($hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        $tz = null;
        $zone = $m[8] ?? '';

        if ($zone !== '') {
            if ($zone === 'Z') {
                $tz = '+00:00';
            } else {
                $digits = str_replace(':', '', substr($zone, 1));
                $tz = $zone[0].substr($digits, 0, 2).':'.(substr($digits, 2, 2) ?: '00');
                $tz .= strlen($digits) > 4 ? ':'.substr($digits, 4, 2) : '';
            }
        }

        return new self($year, $month, $day, true, $hour, $minute, $second, $microsecond, $tz);
    }

    public function toDate(): self
    {
        return new self($this->year, $this->month, $this->day);
    }

    public function toDatetime(): self
    {
        return $this->isDatetime ? $this : new self($this->year, $this->month, $this->day, true);
    }

    /**
     * @param  array{year?: int, month?: int, day?: int, hour?: int, minute?: int, second?: int, microsecond?: int}  $parts
     */
    public function replace(array $parts): self
    {
        return new self(
            $parts['year'] ?? $this->year,
            $parts['month'] ?? $this->month,
            $parts['day'] ?? $this->day,
            $this->isDatetime,
            $parts['hour'] ?? $this->hour,
            $parts['minute'] ?? $this->minute,
            $parts['second'] ?? $this->second,
            $parts['microsecond'] ?? $this->microsecond,
            $this->tz,
        );
    }

    /**
     * Monday is 0 (date.weekday()).
     */
    public function weekday(): int
    {
        return ((self::daysFromCivil($this->year, $this->month, $this->day) % 7) + 7 + 3) % 7;
    }

    /**
     * date + relativedelta (dateutil semantics).
     */
    public function add(RelativeDelta $delta): self
    {
        $value = $delta->hasTime() ? $this->toDatetime() : $this;
        $year = $value->year;
        $month = $value->month;
        $day = $value->day;

        if ($delta->years !== 0 || $delta->months !== 0) {
            $total = $year * 12 + ($month - 1) + $delta->years * 12 + $delta->months;
            $year = intdiv($total, 12);
            $month = $total % 12 + 1;
            $day = min($day, self::daysInMonth($year, $month));
        }

        $micros = (((($value->hour * 60) + $value->minute) * 60 + $value->second) * 1_000_000 + $value->microsecond) + $delta->absoluteMicroseconds();
        $days = intdiv($micros, self::DAY_MICROSECONDS);
        $micros %= self::DAY_MICROSECONDS;

        if ($micros < 0) {
            $micros += self::DAY_MICROSECONDS;
            $days--;
        }

        [$year, $month, $day] = self::civilFromDays(self::daysFromCivil($year, $month, $day) + $days);

        return new self(
            $year,
            $month,
            $day,
            $value->isDatetime,
            intdiv($micros, 3_600_000_000),
            intdiv($micros, 60_000_000) % 60,
            intdiv($micros, 1_000_000) % 60,
            $micros % 1_000_000,
            $value->tz,
        );
    }

    public function sub(RelativeDelta $delta): self
    {
        return $this->add($delta->negate());
    }

    public function addDays(int $days): self
    {
        return $this->add(new RelativeDelta(days: $days));
    }

    /**
     * Python's ordering; null where Python would refuse to compare (a date with a datetime).
     */
    public function compare(self $other): ?int
    {
        if ($this->isDatetime !== $other->isDatetime) {
            return null;
        }

        return [$this->year, $this->month, $this->day, $this->hour, $this->minute, $this->second, $this->microsecond]
            <=> [$other->year, $other->month, $other->day, $other->hour, $other->minute, $other->second, $other->microsecond];
    }

    /**
     * Python's ==, where a date never equals a datetime.
     */
    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    /**
     * Python's str() of the date or datetime.
     */
    public function toString(): string
    {
        $text = sprintf('%04d-%02d-%02d', $this->year, $this->month, $this->day);

        if (! $this->isDatetime) {
            return $text;
        }

        $text .= sprintf(' %02d:%02d:%02d', $this->hour, $this->minute, $this->second);

        if ($this->microsecond !== 0) {
            $text .= sprintf('.%06d', $this->microsecond);
        }

        return $text.($this->tz ?? '');
    }

    public static function daysInMonth(int $year, int $month): int
    {
        return match ($month) {
            2 => ($year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0)) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    /**
     * Days since 1970-01-01 (Howard Hinnant's days_from_civil).
     */
    private static function daysFromCivil(int $year, int $month, int $day): int
    {
        $year -= $month <= 2 ? 1 : 0;
        $era = intdiv($year >= 0 ? $year : $year - 399, 400);
        $yoe = $year - $era * 400;
        $doy = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function civilFromDays(int $days): array
    {
        $days += 719468;
        $era = intdiv($days >= 0 ? $days : $days - 146096, 146097);
        $doe = $days - $era * 146097;
        $yoe = intdiv($doe - intdiv($doe, 1460) + intdiv($doe, 36524) - intdiv($doe, 146096), 365);
        $year = $yoe + $era * 400;
        $doy = $doe - (365 * $yoe + intdiv($yoe, 4) - intdiv($yoe, 100));
        $mp = intdiv(5 * $doy + 2, 153);
        $day = $doy - intdiv(153 * $mp + 2, 5) + 1;
        $month = $mp + ($mp < 10 ? 3 : -9);

        return [$year + ($month <= 2 ? 1 : 0), $month, $day];
    }
}
