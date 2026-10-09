<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;

/**
 * @mixin Bracket
 */
trait BracketBehavior
{
    public function outputName(): string
    {
        $expressions = $this->argList('expressions');

        return count($expressions) === 1 ? $expressions[0]->outputName() : parent::outputName();
    }
}
