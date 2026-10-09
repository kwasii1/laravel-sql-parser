<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

/**
 * Marks expressions carrying a time unit; the unit is normalized on construction.
 *
 * sqlglot: exp.TimeUnit (a mixin; PHP models it as an interface).
 */
interface TimeUnit
{
    /** @var array<string, string> abbreviated unit => unit */
    public const array UNABBREVIATED_UNIT_NAME = [
        'D' => 'DAY',
        'H' => 'HOUR',
        'M' => 'MINUTE',
        'MS' => 'MILLISECOND',
        'NS' => 'NANOSECOND',
        'Q' => 'QUARTER',
        'S' => 'SECOND',
        'US' => 'MICROSECOND',
        'W' => 'WEEK',
        'Y' => 'YEAR',
    ];
}
