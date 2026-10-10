<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Bracket;

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
