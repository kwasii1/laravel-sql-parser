<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

/**
 * Marks aggregate functions.
 *
 * sqlglot: exp.AggFunc (a mixin; PHP models it as an interface).
 */
interface AggFunc extends Func {}
