<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\CaseExpr;
use Kwasii\LaravelSqlParser\Expressions\IfExpr;

/**
 * @mixin CaseExpr
 */
trait CaseExprBehavior
{
    /**
     * Add a WHEN <condition> THEN <then> branch (sqlglot Case.when).
     */
    public function when(mixed $condition, mixed $then, bool $copy = true, string|Dialect|null $dialect = null): static
    {
        $instance = $copy ? $this->copy() : $this;
        $instance->append('ifs', new IfExpr([
            'this' => Builder::parseSql($condition, dialect: $dialect, copy: $copy),
            'true' => Builder::parseSql($then, dialect: $dialect, copy: $copy),
        ]));

        return $instance;
    }

    /**
     * Set the ELSE branch (sqlglot Case.else_).
     */
    public function else(mixed $condition, bool $copy = true, string|Dialect|null $dialect = null): static
    {
        $instance = $copy ? $this->copy() : $this;
        $instance->set('default', Builder::parseSql($condition, dialect: $dialect, copy: $copy));

        return $instance;
    }
}
