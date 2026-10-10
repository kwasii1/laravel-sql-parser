<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Athena;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Dialects\Hive\Hive;
use Kwasii\LaravelSqlParser\Dialects\Trino\Trino;
use Kwasii\LaravelSqlParser\Errors\ErrorLevel;
use Kwasii\LaravelSqlParser\Errors\ParseError;
use Kwasii\LaravelSqlParser\Expressions\Alter;
use Kwasii\LaravelSqlParser\Expressions\Create;
use Kwasii\LaravelSqlParser\Expressions\Describe;
use Kwasii\LaravelSqlParser\Expressions\Drop;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\ExternalProperty;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Show;
use Kwasii\LaravelSqlParser\Tokenizer\Token;
use Kwasii\LaravelSqlParser\Tokenizer\Tokenizer;
use Kwasii\LaravelSqlParser\Tokenizer\TokenStream;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * The Amazon Athena dialect (sqlglot dialects/athena.py).
 *
 * Athena routes a query to Hive (most DDL) or Trino (DML, CREATE VIEW, CTAS), so the SQL is
 * tokenized, parsed and generated with the engine the query would be routed to.
 */
class Athena extends Trino
{
    public const string NAME = 'athena';

    public const string SETTINGS = AthenaDialectTables::class;

    public const string TOKENIZER_TABLES = AthenaTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = AthenaJsonPathTokenizerTables::class;

    public const string PARSER = AthenaParser::class;

    public const string GENERATOR = AthenaGenerator::class;

    public const string TYPE_TABLES = AthenaTypeTables::class;

    private ?Tokenizer $trinoTokenizer = null;

    /**
     * sqlglot _tokenize_as_hive: whether the statement is routed to the Hive engine.
     */
    public static function tokenizeAsHive(TokenStream $tokens): bool
    {
        if ($tokens->count() < 2) {
            return false;
        }

        $firstType = $tokens->types[0];
        $firstText = strtoupper($tokens->texts[0]);
        $secondType = $tokens->types[1];
        $secondText = strtoupper($tokens->texts[1]);

        if (in_array($firstType, [TokenType::DESCRIBE, TokenType::SHOW], true) || $firstText === 'MSCK REPAIR') {
            return true;
        }

        if (in_array($firstType, [TokenType::ALTER, TokenType::CREATE, TokenType::DROP], true)) {
            if (in_array($secondText, ['DATABASE', 'EXTERNAL', 'SCHEMA'], true)) {
                return true;
            }

            if ($secondType === TokenType::VIEW) {
                return false;
            }

            return ! in_array(TokenType::SELECT, array_slice($tokens->types, 2), true);
        }

        return false;
    }

    /**
     * sqlglot _generate_as_hive: whether the expression is generated with the Hive engine.
     */
    public static function generateAsHive(Expression $expression): bool
    {
        if ($expression instanceof Create) {
            if ($expression->text('kind') === 'TABLE') {
                $properties = $expression->argExpr('properties');

                // CREATE EXTERNAL TABLE is Hive
                if ($properties?->find(ExternalProperty::class) !== null) {
                    return true;
                }

                // Any CREATE TABLE other than CREATE TABLE ... AS <query> is Hive
                return ! $expression->argExpr('expression') instanceof Query;
            }

            // CREATE VIEW is Trino, but CREATE SCHEMA, CREATE DATABASE, etc, is Hive
            return $expression->text('kind') !== 'VIEW';
        }

        if (
            $expression instanceof Alter
            || $expression instanceof Drop
            || $expression instanceof Describe
            || $expression instanceof Show
        ) {
            // DROP VIEW is Trino, because CREATE VIEW is as well; everything else is Hive
            return ! ($expression instanceof Drop && $expression->text('kind') === 'VIEW');
        }

        return false;
    }

    /**
     * The Trino engine's tokenizer, with Athena's extensions.
     */
    public function trinoTokenizer(): Tokenizer
    {
        return $this->trinoTokenizer ??= new Tokenizer(AthenaTrinoTokenizerTables::class);
    }

    public function tokenize(string $sql): array
    {
        if (self::tokenizeAsHive($this->tokenizer()->tokenize($sql))) {
            // sqlglot marks a Hive-routed token stream with a leading HIVE_TOKEN_STREAM token
            return [new Token(TokenType::HIVE_TOKEN_STREAM, ''), ...Dialect::get(Hive::NAME)->tokenize($sql)];
        }

        return $this->trinoTokenizer()->tokenize($sql)->tokens();
    }

    public function parse(string $sql, ErrorLevel $errorLevel = ErrorLevel::Immediate): array
    {
        if (self::tokenizeAsHive($this->tokenizer()->tokenize($sql))) {
            return Dialect::get(Hive::NAME)->parse($sql, $errorLevel);
        }

        $tokens = $this->trinoTokenizer()->tokenize($sql);
        $gc = gc_enabled();

        if ($gc) {
            gc_disable();
        }

        try {
            return $this->parser($errorLevel)->parse($tokens, $sql);
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
    }

    public function parseInto(string|array $types, string $sql, ErrorLevel $errorLevel = ErrorLevel::Immediate): Expression
    {
        if (self::tokenizeAsHive($this->tokenizer()->tokenize($sql))) {
            return Dialect::get(Hive::NAME)->parseInto($types, $sql, $errorLevel);
        }

        $result = $this->parser($errorLevel)->parseInto($types, $this->trinoTokenizer()->tokenize($sql), $sql);

        if (! isset($result[0])) {
            throw new ParseError("No expression was parsed from '{$sql}'");
        }

        return $result[0];
    }

    public function generate(Expression $expression, array $options = [], bool $copy = true): string
    {
        if (self::generateAsHive($expression)) {
            return (new AthenaHiveGenerator(Dialect::get(Hive::NAME), $options))->generate($expression, $copy);
        }

        return (new AthenaGenerator(Dialect::get(Trino::NAME), $options))->generate($expression, $copy);
    }
}
