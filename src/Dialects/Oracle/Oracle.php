<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Oracle;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pseudocolumn;

/**
 * The Oracle dialect (sqlglot dialects/oracle.py).
 */
class Oracle extends Dialect
{
    public const string NAME = 'oracle';

    public const string SETTINGS = OracleDialectTables::class;

    public const string TOKENIZER_TABLES = OracleTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = OracleJsonPathTokenizerTables::class;

    public const string PARSER = OracleParser::class;

    public const string GENERATOR = OracleGenerator::class;

    public const string TYPE_TABLES = OracleTypeTables::class;

    /**
     * Pseudocolumns are never quoted: `WHERE "ROWNUM" = ...` doesn't work, `WHERE ROWNUM = ...` does.
     */
    public function canQuote(Identifier $identifier, string|bool $identify = 'safe'): bool
    {
        return ($identifier->quoted() || ! $identifier->parent instanceof Pseudocolumn) && parent::canQuote($identifier, $identify);
    }
}
