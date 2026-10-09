<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\MariaDb;

use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySqlGenerator;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;

/**
 * MariaDB generator: MySQL's, except where MariaDB lacks MySQL syntax.
 */
class MariaDbGenerator extends MySqlGenerator
{
    /**
     * MariaDB has no `->` / `->>` JSON operators, so extractions use the functions they stand for:
     * JSON_EXTRACT, and JSON_UNQUOTE(JSON_EXTRACT(...)) for `->>`.
     */
    public function arrowJsonExtractSql(Expression $expression, ?string $op = null): string
    {
        $extract = $this->func('JSON_EXTRACT', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);

        return $expression instanceof JSONExtractScalar ? $this->func('JSON_UNQUOTE', $extract) : $extract;
    }
}
