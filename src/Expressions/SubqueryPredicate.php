<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions;

/**
 * Marks subquery predicates such as ANY, ALL and EXISTS.
 *
 * sqlglot: exp.SubqueryPredicate (a mixin; PHP models it as an interface).
 */
interface SubqueryPredicate extends Predicate {}
