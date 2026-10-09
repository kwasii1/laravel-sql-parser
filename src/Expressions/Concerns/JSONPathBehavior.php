<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPath;

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
