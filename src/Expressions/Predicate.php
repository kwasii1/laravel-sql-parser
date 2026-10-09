<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

/**
 * Marks predicates (comparisons, IN, LIKE, ...).
 *
 * sqlglot: exp.Predicate (a mixin; PHP models it as an interface).
 */
interface Predicate extends Condition {}
