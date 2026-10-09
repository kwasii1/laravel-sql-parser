<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

/**
 * Marks SQL functions. SQL names come from the class's SQL_NAMES constant.
 *
 * sqlglot: exp.Func (a mixin; PHP models it as an interface).
 */
interface Func extends Condition {}
