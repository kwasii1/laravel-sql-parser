<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;

/**
 * @mixin JSONExtract
 */
trait JSONExtractBehavior
{
    public function outputName(): string
    {
        $path = $this->args['expression'] ?? null;

        return $path instanceof Expression && $this->argList('expressions') === [] ? $path->outputName() : '';
    }
}
