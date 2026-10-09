<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

/**
 * Marks date arithmetic operations built from an interval.
 *
 * sqlglot: exp.IntervalOp (a mixin; PHP models it as an interface).
 */
interface IntervalOp extends TimeUnit {}
