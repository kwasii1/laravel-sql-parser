<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\RisingWave;

use LaravelSqlParser\LaravelSqlParser\Dialects\Postgres\PostgresParser;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\EncodeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\IncludeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\WatermarkColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * RisingWave parser (sqlglot parsers/risingwave.py).
 */
class RisingWaveParser extends PostgresParser
{
    public const string TABLES = RisingWaveParserTables::class;

    protected function constraintWatermark(): Expression
    {
        return $this->expression(new WatermarkColumnConstraint([
            'this' => $this->match(TokenType::FOR) ? $this->parseColumn() : null,
            'expression' => $this->match(TokenType::ALIAS) ? $this->parseDisjunction() : null,
        ]));
    }

    protected function propertyKey(): Expression
    {
        return $this->parseEncodeProperty(key: true);
    }

    /**
     * There is no hint in RisingWave; skipping avoids a WITH keyword conflict in CREATE SINK.
     *
     * @return list<Expression>|null
     */
    protected function parseTableHints(): ?array
    {
        return null;
    }

    protected function parseIncludeProperty(): ?Expression
    {
        $coldef = null;

        $this_ = $this->parseVarOrString();

        if (! $this->match(TokenType::ALIAS)) {
            $header = $this->parseField();

            if ($header !== null) {
                $coldef = $this->expression(new ColumnDef(['this' => $header, 'kind' => $this->parseTypes()]));
            }
        }

        $this->match(TokenType::ALIAS);
        $alias = $this->parseIdVar(tokens: $this->t['ALIAS_TOKENS']);

        return $this->expression(new IncludeProperty(['this' => $this_, 'alias' => $alias, 'column_def' => $coldef]));
    }

    protected function parseEncodeProperty(?bool $key = null): EncodeProperty
    {
        $this->matchTextSeq('ENCODE');
        $this_ = $this->parseVarOrString();

        $properties = $this->match(TokenType::L_PAREN, false)
            ? $this->expression(new Properties(['expressions' => $this->parseWrappedProperties()]))
            : null;

        return $this->expression(new EncodeProperty(['this' => $this_, 'properties' => $properties, 'key' => $key]));
    }
}
