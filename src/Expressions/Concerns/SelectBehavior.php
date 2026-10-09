<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Aliases;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;

/**
 * @mixin Select
 */
trait SelectBehavior
{
    public function isStar(): bool
    {
        foreach ($this->argList('expressions') as $projection) {
            if ($projection->isStar()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Expression>
     */
    public function selects(): array
    {
        return $this->argList('expressions');
    }

    /**
     * The output names of the projections that have one.
     *
     * @return list<string>
     */
    public function namedSelects(): array
    {
        $selects = [];

        foreach ($this->argList('expressions') as $e) {
            if ($e->aliasOrName() !== '') {
                $selects[] = $e->outputName();
            } elseif ($e instanceof Aliases) {
                foreach ($e->argList('expressions') as $alias) {
                    $selects[] = $alias->name();
                }
            }
        }

        return $selects;
    }
}
