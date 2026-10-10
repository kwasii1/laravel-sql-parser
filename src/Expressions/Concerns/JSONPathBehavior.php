<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\JSONPath;

/**
 * @mixin JSONPath
 */
trait JSONPathBehavior
{
    public function outputName(): string
    {
        $segments = $this->args['expressions'] ?? [];
        $last = is_array($segments) && $segments !== [] ? $segments[count($segments) - 1] : null;
        $lastSegment = $last instanceof Expression ? ($last->args['this'] ?? null) : null;

        return is_string($lastSegment) ? $lastSegment : '';
    }
}
