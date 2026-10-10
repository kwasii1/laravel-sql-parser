<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Snowflake;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Table;

/**
 * The Snowflake dialect (sqlglot dialects/snowflake.py).
 */
class Snowflake extends Dialect
{
    public const string NAME = 'snowflake';

    public const string SETTINGS = SnowflakeDialectTables::class;

    public const string TOKENIZER_TABLES = SnowflakeTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = SnowflakeJsonPathTokenizerTables::class;

    public const string PARSER = SnowflakeParser::class;

    public const string GENERATOR = SnowflakeGenerator::class;

    public const string TYPE_TABLES = SnowflakeTypeTables::class;

    public const string TYPE_ANNOTATOR = SnowflakeTypeAnnotator::class;

    /**
     * DUAL is never quoted in SELECT ... FROM DUAL: Snowflake treats an unquoted DUAL keyword
     * specially and doesn't map it to a user-defined table.
     */
    public function canQuote(Identifier $identifier, string|bool $identify = 'safe'): bool
    {
        return parent::canQuote($identifier, $identify)
            && ! ($identifier->parent instanceof Table && ! $identifier->quoted() && strtolower($identifier->name()) === 'dual');
    }
}
