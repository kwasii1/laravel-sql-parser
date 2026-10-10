<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Schema\MappingSchema;

/**
 * Normalize identifiers by converting them to lower or upper case, the way the dialect resolves
 * them, respecting case-sensitivity (sqlglot optimizer/normalize_identifiers.py).
 *
 * An identifier marked with a `sqlglot.meta case_sensitive` comment is left as is.
 */
final class NormalizeIdentifiers
{
    /**
     * @template T of Expression
     *
     * @param  T|string  $expression  a string is parsed as an identifier
     * @return ($expression is string ? Identifier : T)
     */
    public static function normalizeIdentifiers(Expression|string $expression, string|Dialect|null $dialect = null, bool $storeOriginalColumnIdentifiers = false): Expression
    {
        $dialect = Dialect::get($dialect);

        if (is_string($expression)) {
            $expression = MappingSchema::parseIdentifier($expression, $dialect);
        }

        $prune = static fn (Expression $n): bool => (bool) $n->metaGet('case_sensitive');

        foreach ($expression->walk(prune: $prune) as $node) {
            if ((bool) $node->metaGet('case_sensitive')) {
                continue;
            }

            // A dot chain is recorded once, at its outermost Dot. Ancestors are visited before
            // their descendants, so none of the names it wraps have been normalized yet
            if ($storeOriginalColumnIdentifiers && ($node instanceof Column || $node instanceof Dot) && ! $node->parent instanceof Dot) {
                if ($node instanceof Column) {
                    $node->setMeta('dot_parts', array_map(static fn (Expression $p): string => $p->name(), $node->parts()));
                } elseif (! $node->isStar()) {
                    $root = $node;
                    $dotParts = [];

                    while ($root instanceof Dot) {
                        $right = $root->args['expression'] ?? null;
                        $dotParts[] = $right instanceof Expression ? $right->name() : '';
                        $root = $root->args['this'] ?? null;
                    }

                    $dotParts = array_reverse($dotParts);

                    // The chain may be rooted at a column (j.k), or at an arbitrary expression (f().k)
                    if ($root instanceof Column) {
                        $dotParts = [...array_map(static fn (Expression $p): string => $p->name(), $root->parts()), ...$dotParts];
                    }

                    if ($root instanceof Expression) {
                        $root->setMeta('dot_parts', $dotParts);
                    }
                }
            }

            if ($node instanceof Identifier) {
                $dialect->normalizeIdentifier($node);
            }
        }

        return $expression;
    }
}
