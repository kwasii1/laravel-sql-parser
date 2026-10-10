<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Schema\Schema;

/**
 * Infer the types of an expression, annotating its AST (sqlglot optimizer/annotate_types.py).
 * The types of qualified table columns come from the schema.
 */
final class AnnotateTypes
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @param  array<array-key, mixed>|Schema|null  $schema
     * @return T
     */
    public static function annotateTypes(Expression $expression, array|Schema|null $schema = null, string|Dialect|null $dialect = null): Expression
    {
        $schema = Schema::ensure($schema, $dialect);
        $dialect = $schema->dialect() ?? Dialect::get($dialect);

        /** @var TypeAnnotator $annotator */
        $annotator = new ($dialect::TYPE_ANNOTATOR)($dialect);
        $annotator->schema = $schema;
        $annotator->annotate($expression);

        return $expression;
    }
}
