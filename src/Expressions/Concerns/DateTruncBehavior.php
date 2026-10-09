<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeUnit;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;

/**
 * @mixin DateTrunc
 */
trait DateTruncBehavior
{
    /**
     * Normalizes the unit to an uppercase string literal; abbreviated units (e.g. 'Q') are spelled
     * out unless `unabbreviate` is false (Oracle).
     *
     * @param  array<string, mixed>  $args
     */
    public function __construct(array $args = [])
    {
        $unabbreviate = ($args['unabbreviate'] ?? true) !== false;
        unset($args['unabbreviate']);

        $unit = $args['unit'] ?? null;

        if (
            ($unit instanceof Column || $unit instanceof Literal || $unit instanceof VarExpr)
            && ! ($unit instanceof Column && count($unit->parts()) !== 1)
        ) {
            $unitName = strtoupper($unit->name());

            if ($unabbreviate && isset(TimeUnit::UNABBREVIATED_UNIT_NAME[$unitName])) {
                $unitName = TimeUnit::UNABBREVIATED_UNIT_NAME[$unitName];
            }

            $args['unit'] = Literal::string($unitName);
        }

        parent::__construct($args);
    }

    public function unit(): ?Expression
    {
        $unit = $this->args['unit'] ?? null;

        return $unit instanceof Expression ? $unit : null;
    }
}
