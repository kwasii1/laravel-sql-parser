<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

/**
 * Marks derived tables (subqueries, table-valued functions).
 *
 * sqlglot: exp.DerivedTable (a mixin; PHP models it as an interface).
 */
interface DerivedTable extends Selectable {}
