<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Materialize;

use Kwasii\LaravelSqlParser\Dialects\Postgres\PostgresParser;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Kwarg;
use Kwasii\LaravelSqlParser\Expressions\PropertyEQ;
use Kwasii\LaravelSqlParser\Expressions\Struct;
use Kwasii\LaravelSqlParser\Expressions\ToMap;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Materialize parser (sqlglot parsers/materialize.py).
 */
class MaterializeParser extends PostgresParser
{
    public const string TABLES = MaterializeParserTables::class;

    /**
     * @param  list<Expression|null>  $expressions
     */
    protected function lambdaFarrow(array $expressions): Expression
    {
        return $this->expression(new Kwarg(['this' => $expressions[0] ?? null, 'expression' => $this->parseAssignment()]));
    }

    protected function parseLambdaArg(): ?Expression
    {
        return $this->parseField();
    }

    protected function parseMap(): ToMap
    {
        if ($this->match(TokenType::L_PAREN)) {
            $toMap = $this->expression(new ToMap(['this' => $this->parseSelect()]));
            $this->matchRParen();

            return $toMap;
        }

        if (! $this->match(TokenType::L_BRACKET)) {
            $this->raiseError('Expecting [');
        }

        $entries = [];

        foreach ($this->parseCsv(fn (): ?Expression => $this->parseLambda()) as $e) {
            $entries[] = new PropertyEQ(['this' => $e?->args['this'] ?? null, 'expression' => $e?->args['expression'] ?? null]);
        }

        if (! $this->match(TokenType::R_BRACKET)) {
            $this->raiseError('Expecting ]');
        }

        return $this->expression(new ToMap(['this' => $this->expression(new Struct(['expressions' => $entries]))]));
    }
}
