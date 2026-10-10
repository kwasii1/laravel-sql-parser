<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
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

    /**
     * AND conditions into the ON (sqlglot Join.on). Options: append, dialect, copy.
     */
    public function on(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);
        $join = Builder::applyConjunctionBuilder($expressions, $this, 'on', null, (bool) $options['append'], (bool) $options['copy'], Builder::dialectOption($options));

        if ($join->kind() === 'CROSS') {
            $join->set('kind', null);
        }

        return $join;
    }

    /**
     * Append to or set the USING columns (sqlglot Join.using). Options: append, dialect, copy.
     */
    public function using(mixed ...$args): static
    {
        [$expressions, $options] = Builder::splitOptions($args, ['append' => true, 'dialect' => null, 'copy' => true]);
        $join = Builder::applyListBuilder($expressions, $this, 'using', (bool) $options['append'], (bool) $options['copy'], dialect: Builder::dialectOption($options));

        if ($join->kind() === 'CROSS') {
            $join->set('kind', null);
        }

        return $join;
    }
}
