<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions;

/**
 * Marks user-defined table functions (LATERAL, UNNEST, EXPLODE, ...).
 *
 * sqlglot: exp.UDTF (a mixin; PHP models it as an interface).
 */
interface UDTF extends DerivedTable {}
