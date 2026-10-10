<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\JSONExtract;

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
