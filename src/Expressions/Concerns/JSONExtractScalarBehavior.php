<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\JSONExtractScalar;

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
