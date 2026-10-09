<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Parser\Concerns;

use LaravelSqlParser\LaravelSqlParser\Errors\TokenError;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\IntervalSpan;
use LaravelSqlParser\LaravelSqlParser\Expressions\ObjectIdentifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\PseudoType;
use LaravelSqlParser\LaravelSqlParser\Expressions\SkipJSONColumn;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Data types (sqlglot parser.py, from _parse_types to _parse_struct_types).
 */
trait ParsesTypes
{
    protected function parseTypes(
        bool $checkFunc = false,
        bool $schema = false,
        bool $allowIdentifiers = true,
        bool $withCollation = false,
    ): ?Expression {
        $index = $this->index;
        $this_ = null;
        $typeTokens = $this->typeTokens;

        if ($this->curr !== 0 && isset($typeTokens[$this->curr])) {
            $this->advance();
            $typeToken = $this->prev;
        } else {
            $typeToken = null;
            $identifier = $allowIdentifiers ? $this->parseIdVar(anyToken: false, tokens: [TokenType::VAR => true]) : null;

            if (! $identifier instanceof Identifier) {
                return null;
            }

            if ($identifier->flag('quoted') && isset($this->t['QUOTED_TYPES_TO_PRESERVE'][$identifier->name()])) {
                $this_ = DataType::build($identifier, udt: true);
            } else {
                try {
                    $tokens = $this->dialect->tokenizer()->tokenize($identifier->name());
                } catch (TokenError) {
                    $tokens = null;
                }

                $firstType = $tokens !== null && $tokens->types !== [] ? $tokens->types[0] : null;

                if ($firstType !== null && isset($typeTokens[$firstType])) {
                    $typeToken = $firstType;

                    if (count($tokens->types) > 1) {
                        return DataType::fromStr($identifier->name(), $this->dialect);
                    }
                } elseif ($this->dialect->settings['SUPPORTS_USER_DEFINED_TYPES'] === true) {
                    $this_ = $this->parseUserDefinedType($identifier);
                } else {
                    $this->retreat($this->index - 1);

                    return null;
                }
            }
        }

        if ($typeToken === TokenType::PSEUDO_TYPE) {
            return $this->expression(new PseudoType(['this' => strtoupper($this->prevText())]));
        }

        if ($typeToken === TokenType::OBJECT_IDENTIFIER) {
            return $this->expression(new ObjectIdentifier(['this' => strtoupper($this->prevText())]));
        }

        // https://materialize.com/docs/sql/types/map/
        if ($typeToken === TokenType::MAP && $this->match(TokenType::L_BRACKET)) {
            $keyType = $this->parseTypes(checkFunc: $checkFunc, schema: $schema, allowIdentifiers: $allowIdentifiers);

            if (! $this->match(TokenType::FARROW)) {
                $this->retreat($index);

                return null;
            }

            $valueType = $this->parseTypes(checkFunc: $checkFunc, schema: $schema, allowIdentifiers: $allowIdentifiers);

            if (! $this->match(TokenType::R_BRACKET)) {
                $this->retreat($index);

                return null;
            }

            return new DataType(['this' => DType::MAP, 'expressions' => [$keyType, $valueType], 'nested' => true]);
        }

        $nested = $typeToken !== null && isset($this->t['NESTED_TYPE_TOKENS'][$typeToken]);
        $isStruct = $typeToken !== null && isset($this->t['STRUCT_TYPE_TOKENS'][$typeToken]);
        $isAggregate = $typeToken !== null && isset($this->t['AGGREGATE_TYPE_TOKENS'][$typeToken]);
        $expressions = null;
        $maybeFunc = false;

        if ($this->match(TokenType::L_PAREN)) {
            if ($isStruct) {
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseStructTypes(typeRequired: true));
            } elseif ($nested) {
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseTypes(checkFunc: $checkFunc, schema: $schema, allowIdentifiers: $allowIdentifiers));

                if ($typeToken === TokenType::NULLABLE && count($expressions) === 1) {
                    $this_ = $expressions[0];
                    $this_->set('nullable', true);
                    $this->matchRParen();

                    return $this_;
                }
            } elseif ($typeToken !== null && isset($this->t['ENUM_TYPE_TOKENS'][$typeToken])) {
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseEquality());
            } elseif ($typeToken === TokenType::JSON) {
                // ClickHouse JSON type supports arguments: JSON(col Type, SKIP col, param=value)
                // https://clickhouse.com/docs/sql-reference/data-types/newjson
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseJsonTypeArg());
            } elseif ($isAggregate) {
                $funcOrIdent = $this->parseFunction(anonymous: true)
                    ?? $this->parseIdVar(anyToken: false, tokens: [TokenType::VAR => true, TokenType::ANY => true]);

                if ($funcOrIdent === null) {
                    return null;
                }

                $expressions = [$funcOrIdent];

                if ($this->match(TokenType::COMMA)) {
                    array_push($expressions, ...$this->parseCsv(
                        fn (): ?Expression => $this->parseTypes(checkFunc: $checkFunc, schema: $schema, allowIdentifiers: $allowIdentifiers),
                    ));
                }
            } else {
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseTypeSize());

                // https://docs.snowflake.com/en/sql-reference/data-types-vector
                if ($typeToken === TokenType::VECTOR && count($expressions) === 2) {
                    $expressions = $this->parseVectorExpressions($expressions);
                }
            }

            if (! $this->match(TokenType::R_PAREN)) {
                $this->retreat($index);

                return null;
            }

            $maybeFunc = true;
        }

        $values = null;

        if ($nested && $this->match(TokenType::LT)) {
            if ($isStruct) {
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseStructTypes(typeRequired: true));
            } else {
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseTypes(
                    checkFunc: $checkFunc,
                    schema: $schema,
                    allowIdentifiers: $allowIdentifiers,
                    withCollation: true,
                ));
            }

            if (! $this->match(TokenType::GT)) {
                $this->raiseError('Expecting >');
            }

            if ($this->matchSet([TokenType::L_BRACKET => true, TokenType::L_PAREN => true])) {
                $values = $this->parseCsv(fn (): ?Expression => $this->parseDisjunction());

                if ($values === [] && $isStruct) {
                    $values = null;
                    $this->retreat($this->index - 1);
                } else {
                    $this->matchSet([TokenType::R_BRACKET => true, TokenType::R_PAREN => true]);
                }
            }
        }

        if ($typeToken !== null && isset($this->t['TIMESTAMPS'][$typeToken])) {
            if ($this->matchTextSeq('WITH', 'TIME', 'ZONE')) {
                $maybeFunc = false;
                $tzType = isset($this->t['TIMES'][$typeToken]) ? DType::TIMETZ : DType::TIMESTAMPTZ;
                $this_ = new DataType(['this' => $tzType, 'expressions' => $expressions]);
            } elseif ($this->matchTextSeq('WITH', 'LOCAL', 'TIME', 'ZONE')) {
                $maybeFunc = false;
                $this_ = new DataType(['this' => DType::TIMESTAMPLTZ, 'expressions' => $expressions]);
            } elseif ($this->matchTextSeq('WITHOUT', 'TIME', 'ZONE')) {
                $maybeFunc = false;
            }
        } elseif ($typeToken === TokenType::INTERVAL) {
            if (isset($this->dialect->settings['VALID_INTERVAL_UNITS'][strtoupper($this->currText())])) {
                $unit = $this->parseVar(upper: true);

                if ($this->matchTextSeq('TO')) {
                    $unit = new IntervalSpan(['this' => $unit, 'expression' => $this->parseVar(upper: true)]);
                }

                $this_ = $this->expression(new DataType(['this' => $this->expression(new Interval(['unit' => $unit]))]));
            } else {
                $this_ = $this->expression(new DataType(['this' => DType::INTERVAL]));
            }
        } elseif ($typeToken === TokenType::VOID) {
            $this_ = new DataType(['this' => DType::NULL]);
        }

        if ($maybeFunc && $checkFunc) {
            $index2 = $this->index;
            $peek = $this->parseString();

            if ($peek === null) {
                $this->retreat($index);

                return null;
            }

            $this->retreat($index2);
        }

        if ($this_ === null) {
            if ($typeToken === null) {
                return null;
            }

            if ($this->matchTextSeq('UNSIGNED')) {
                $unsignedTypeToken = $this->t['SIGNED_TO_UNSIGNED_TYPE_TOKEN'][$typeToken] ?? null;

                if ($unsignedTypeToken === null) {
                    $this->raiseError('Cannot convert '.TokenType::name($typeToken).' to unsigned.');
                }

                $typeToken = $unsignedTypeToken ?? $typeToken;
            }

            // NULLABLE without parentheses can be a column (Presto/Trino)
            if ($typeToken === TokenType::NULLABLE && ($expressions === null || $expressions === [])) {
                $this->retreat($index);

                return null;
            }

            $this_ = new DataType([
                'this' => TokenType::name($typeToken),
                'expressions' => $expressions,
                'nested' => $nested,
            ]);

            // Empty arrays/structs are allowed
            if ($values !== null) {
                $class = $isStruct ? Struct::class : ArrayExpr::class;
                $this_ = Builder::cast(new $class(['expressions' => $values]), $this_, copy: false);
            }
        } elseif ($expressions !== null && $expressions !== []) {
            $this_->set('expressions', $expressions);
        }

        // https://materialize.com/docs/sql/types/list/#type-name
        while ($this->match(TokenType::LIST)) {
            $this_ = new DataType(['this' => DType::LIST, 'expressions' => [$this_], 'nested' => true]);
        }

        $index = $this->index;

        // Postgres supports the INT ARRAY[3] syntax as a synonym for INT[3]
        $matchedArray = $this->match(TokenType::ARRAY);

        while ($this->curr !== 0) {
            $datatypeToken = $this->prev;
            $matchedLBracket = $this->match(TokenType::L_BRACKET);

            if (
                (! $matchedLBracket && ! $matchedArray)
                || ($datatypeToken === TokenType::ARRAY && $this->match(TokenType::R_BRACKET))
            ) {
                // Postgres allows casting empty arrays such as ARRAY[]::INT[],
                // not to be confused with the fixed size array parsing
                break;
            }

            $matchedArray = false;
            $values = $this->parseCsv(fn (): ?Expression => $this->parseDisjunction());
            $values = $values !== [] ? $values : null;

            if (
                $values !== null
                && ! $schema
                && (
                    $this->dialect->settings['SUPPORTS_FIXED_SIZE_ARRAYS'] !== true
                    || $datatypeToken === TokenType::ARRAY
                    || ! $this->match(TokenType::R_BRACKET, false)
                )
            ) {
                // Retreating here means that we should not parse the following values as part of the data type, e.g. in DuckDB
                // ARRAY[1] should retreat and instead be parsed into exp.Array in contrast to INT[x][y] which denotes a fixed-size array data type
                $this->retreat($index);

                break;
            }

            $this_ = new DataType(['this' => DType::ARRAY, 'expressions' => [$this_], 'values' => $values, 'nested' => true]);
            $this->match(TokenType::R_BRACKET);
        }

        $typeName = $this_->args['this'] ?? null;

        if ($this->t['TYPE_CONVERTERS'] !== [] && is_string($typeName) && $this_ instanceof DataType) {
            $converter = $this->t['TYPE_CONVERTERS'][$typeName] ?? null;

            if ($converter !== null) {
                $this_ = $this->callHandler($converter, $this_);
            }
        }

        if ($withCollation && $this_ instanceof DataType && $this->match(TokenType::COLLATE)) {
            $this_->set('collate', $this->parseIdentifier() ?? $this->parseColumn());
        }

        return $this_;
    }

    /**
     * Parse a single argument to ClickHouse's JSON type.
     */
    protected function parseJsonTypeArg(): ?Expression
    {
        // SKIP col or SKIP REGEXP 'pattern'
        if ($this->matchTextSeq('SKIP')) {
            $regexp = $this->match(TokenType::RLIKE);
            $arg = $this->parseColumn();

            if ($arg instanceof Column) {
                $arg = $arg->toDot();
            }

            return $this->expression(new SkipJSONColumn(['regexp' => $regexp, 'expression' => $arg]));
        }

        $paramOrCol = $this->parseColumn();

        if (! $paramOrCol instanceof Column) {
            return null;
        }

        // Parameter: name=value (e.g., max_dynamic_paths=2)
        if (count($paramOrCol->parts()) === 1 && $this->match(TokenType::EQ)) {
            $param = $paramOrCol->name();
            $value = $this->parsePrimary();

            return $this->expression(new EQ(['this' => Builder::var($param), 'expression' => $value]));
        }

        // Column type hint: col_name Type
        $col = $paramOrCol->toDot();
        $kind = $this->parseTypes(checkFunc: false, allowIdentifiers: false);

        return $this->expression(new ColumnDef(['this' => $col, 'kind' => $kind]));
    }

    /**
     * @param  list<Expression>  $expressions
     * @return list<Expression>
     */
    protected function parseVectorExpressions(array $expressions): array
    {
        return [DataType::fromStr($expressions[0]->name(), $this->dialect), ...array_slice($expressions, 1)];
    }

    protected function parseStructTypes(bool $typeRequired = false): ?Expression
    {
        $index = $this->index;

        if (
            $this->curr !== 0
            && $this->next !== 0
            && isset($this->typeTokens[$this->curr])
            && isset($this->typeTokens[$this->next])
        ) {
            // Takes care of special cases like `STRUCT<list ARRAY<...>>` where the identifier is also a
            // type token. Without this, the list will be parsed as a type and we'll eventually crash
            $this_ = $this->parseIdVar();
        } else {
            $this_ = $this->parseType(parseInterval: false, fallbackToIdentifier: true) ?? $this->parseIdVar();
        }

        $this->match(TokenType::COLON);

        if (
            $typeRequired
            && ! $this_ instanceof DataType
            && ! $this->matchSet($this->typeTokens, false)
        ) {
            $this->retreat($index);

            return $this->parseTypes();
        }

        return $this->parseColumnDef($this_);
    }
}
