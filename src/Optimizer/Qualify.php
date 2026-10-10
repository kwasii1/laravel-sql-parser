<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer;

use Closure;
use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Schema\Schema;

/**
 * Rewrite the AST to have normalized and qualified tables and columns (sqlglot
 * optimizer/qualify.py). This step is necessary for all further optimizations.
 */
final class Qualify
{
    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @param  array<array-key, mixed>|Schema|null  $schema
     * @param  (Closure(Table): void)|null  $onQualify
     * @return T
     */
    public static function qualify(
        Expression $expression,
        string|Dialect|null $dialect = null,
        ?string $db = null,
        ?string $catalog = null,
        array|Schema|null $schema = null,
        bool $expandAliasRefs = true,
        bool $expandStars = true,
        ?bool $inferSchema = null,
        bool $isolateTables = false,
        bool $qualifyColumns = true,
        bool $allowPartialQualification = false,
        bool $validateQualifyColumns = true,
        bool $quoteIdentifiers = true,
        bool $identify = true,
        bool $canonicalizeTableAliases = false,
        ?Closure $onQualify = null,
        ?string $sql = null,
    ): Expression {
        $schema = Schema::ensure($schema, $dialect);
        $dialect = Dialect::get($dialect);

        $expression = NormalizeIdentifiers::normalizeIdentifiers($expression, $dialect, storeOriginalColumnIdentifiers: true);
        $expression = QualifyTables::qualifyTables(
            $expression,
            db: $db,
            catalog: $catalog,
            onQualify: $onQualify,
            dialect: $dialect,
            canonicalizeTableAliases: $canonicalizeTableAliases,
        );

        if ($isolateTables) {
            $expression = IsolateTableSelects::isolateTableSelects($expression, $schema);
        }

        if ($qualifyColumns) {
            $expression = QualifyColumns::qualifyColumns(
                $expression,
                $schema,
                expandAliasRefs: $expandAliasRefs,
                expandStars: $expandStars,
                inferSchema: $inferSchema,
                allowPartialQualification: $allowPartialQualification,
            );
        }

        if ($quoteIdentifiers) {
            $expression = QualifyColumns::quoteIdentifiers($expression, $dialect, $identify);
        }

        if ($validateQualifyColumns) {
            QualifyColumns::validateQualifyColumns($expression, $sql);
        }

        return $expression;
    }
}
