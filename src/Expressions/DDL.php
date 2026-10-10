<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions;

/**
 * Marks DDL statements that may contain a query.
 *
 * sqlglot: exp.DDL (a mixin; PHP models it as an interface).
 */
interface DDL extends Selectable {}
