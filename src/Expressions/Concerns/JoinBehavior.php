<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;

/**
 * @mixin Join
 */
trait JoinBehavior
{
    public function method(): string
    {
        return strtoupper($this->text('method'));
    }

    public function kind(): string
    {
        return strtoupper($this->text('kind'));
    }

    public function side(): string
    {
        return strtoupper($this->text('side'));
    }

    public function hint(): string
    {
        return strtoupper($this->text('hint'));
    }

    public function aliasOrName(): string
    {
        $this_ = $this->args['this'] ?? null;

        return $this_ instanceof Expression ? $this_->aliasOrName() : '';
    }

    public function isSemiOrAntiJoin(): bool
    {
        $kind = $this->kind();

        return $kind === 'SEMI' || $kind === 'ANTI';
    }
}
