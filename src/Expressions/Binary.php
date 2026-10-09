<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

/**
 * Marks binary operators. The left operand is the `this` arg, the right one `expression`.
 *
 * sqlglot: exp.Binary (a mixin; PHP models it as an interface).
 */
interface Binary extends Condition {}
