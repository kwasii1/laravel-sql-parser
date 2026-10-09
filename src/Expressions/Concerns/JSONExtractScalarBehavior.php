<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;

/**
 * @mixin JSONExtractScalar
 */
trait JSONExtractScalarBehavior
{
    public function outputName(): string
    {
        $path = $this->args['expression'] ?? null;

        return $path instanceof Expression ? $path->outputName() : '';
    }
}
