<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeUnit;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Week;

/**
 * Behavior of expressions with a time unit (sqlglot exp.TimeUnit): the unit is normalized to an
 * uppercase, unabbreviated Var on construction.
 */
trait TimeUnitBehavior
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(array $args = [])
    {
        parent::__construct($args);

        $unit = $this->args['unit'] ?? null;

        if (
            $unit instanceof Expression
            && in_array($unit::class, [Column::class, Literal::class, VarExpr::class], true)
            && ! ($unit instanceof Column && count($unit->parts()) !== 1)
        ) {
            $name = $unit->name();
            $unit = new VarExpr(['this' => strtoupper(TimeUnit::UNABBREVIATED_UNIT_NAME[$name] ?? $name)]);
            $this->args['unit'] = $unit;
            $unit->parent = $this;
            $unit->argKey = 'unit';
            $unit->index = null;
        } elseif ($unit instanceof Week) {
            $inner = $unit->args['this'] ?? null;
            $unit->set('this', new VarExpr(['this' => strtoupper($inner instanceof Expression ? $inner->name() : '')]));
        }
    }

    public function unit(): ?Expression
    {
        $unit = $this->args['unit'] ?? null;

        return $unit instanceof Expression ? $unit : null;
    }
}
