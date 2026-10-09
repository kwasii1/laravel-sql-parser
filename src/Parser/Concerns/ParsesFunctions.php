<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Parser\Concerns;

use Closure;
use LaravelSqlParser\LaravelSqlParser\Expressions\AggFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Aliases;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\CastToStrType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Chr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Columns;
use LaravelSqlParser\LaravelSqlParser\Expressions\Commit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Comprehension;
use LaravelSqlParser\LaravelSqlParser\Expressions\Concat;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Decode;
use LaravelSqlParser\LaravelSqlParser\Expressions\DecodeCase;
use LaravelSqlParser\LaravelSqlParser\Expressions\DictProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\DictRange;
use LaravelSqlParser\LaravelSqlParser\Expressions\DictSubProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Extract;
use LaravelSqlParser\LaravelSqlParser\Expressions\Filter;
use LaravelSqlParser\LaravelSqlParser\Expressions\FormatJson;
use LaravelSqlParser\LaravelSqlParser\Expressions\Func;
use LaravelSqlParser\LaravelSqlParser\Expressions\GapFill;
use LaravelSqlParser\LaravelSqlParser\Expressions\GroupConcat;
use LaravelSqlParser\LaravelSqlParser\Expressions\HavingMax;
use LaravelSqlParser\LaravelSqlParser\Expressions\Heredoc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\IgnoreNulls;
use LaravelSqlParser\LaravelSqlParser\Expressions\Initcap;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Introducer;
use LaravelSqlParser\LaravelSqlParser\Expressions\JoinHint;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONKeyValue;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONObject;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONObjectAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONSchema;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONTable;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONValue;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\MacroOverload;
use LaravelSqlParser\LaravelSqlParser\Expressions\MacroOverloads;
use LaravelSqlParser\LaravelSqlParser\Expressions\MatchAgainst;
use LaravelSqlParser\LaravelSqlParser\Expressions\Neg;
use LaravelSqlParser\LaravelSqlParser\Expressions\NextValueFor;
use LaravelSqlParser\LaravelSqlParser\Expressions\Normalize;
use LaravelSqlParser\LaravelSqlParser\Expressions\NthValue;
use LaravelSqlParser\LaravelSqlParser\Expressions\OnCondition;
use LaravelSqlParser\LaravelSqlParser\Expressions\OpenJSON;
use LaravelSqlParser\LaravelSqlParser\Expressions\OpenJSONColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\Operator;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\OverflowTruncateBehavior;
use LaravelSqlParser\LaravelSqlParser\Expressions\Overlay;
use LaravelSqlParser\LaravelSqlParser\Expressions\Parameter;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Refresh;
use LaravelSqlParser\LaravelSqlParser\Expressions\RespectNulls;
use LaravelSqlParser\LaravelSqlParser\Expressions\Rollback;
use LaravelSqlParser\LaravelSqlParser\Expressions\SessionParameter;
use LaravelSqlParser\LaravelSqlParser\Expressions\Slice;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrPosition;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Substring;
use LaravelSqlParser\LaravelSqlParser\Expressions\Transaction;
use LaravelSqlParser\LaravelSqlParser\Expressions\Trim;
use LaravelSqlParser\LaravelSqlParser\Expressions\TryCast;
use LaravelSqlParser\LaravelSqlParser\Expressions\UserDefinedFunction;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;
use LaravelSqlParser\LaravelSqlParser\Expressions\WindowSpec;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithinGroup;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithOperator;
use LaravelSqlParser\LaravelSqlParser\Expressions\XMLElement;
use LaravelSqlParser\LaravelSqlParser\Expressions\XMLNamespace;
use LaravelSqlParser\LaravelSqlParser\Expressions\XMLTable;
use LaravelSqlParser\LaravelSqlParser\Support\TimeFormat;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Function calls and function-like syntax, windows, aliases, identifiers, literals and the
 * generic list helpers (sqlglot parser.py, _parse_function onwards, excluding DDL).
 */
trait ParsesFunctions
{
    /**
     * @param  array<string, mixed>|null  $functions
     */
    protected function parseFunction(?array $functions = null, bool $anonymous = false, bool $optionalParens = true, bool $anyToken = false): ?Expression
    {
        // This allows us to also parse {fn <function>} syntax (Snowflake, MySQL support this)
        // See: https://community.snowflake.com/s/article/SQL-Escape-Sequences
        $fnSyntax = false;

        if ($this->match(TokenType::L_BRACE, false) && $this->next !== 0 && strtoupper($this->nextText()) === 'FN') {
            $this->advance(2);
            $fnSyntax = true;
        }

        $func = $this->parseFunctionCall($functions, $anonymous, $optionalParens, $anyToken);

        if ($fnSyntax) {
            $this->match(TokenType::R_BRACE);
        }

        return $func;
    }

    /**
     * @return list<Expression>
     */
    protected function parseFunctionArgs(bool $alias = false): array
    {
        return $this->parseCsv(fn (): ?Expression => $this->parseLambda(alias: $alias));
    }

    /**
     * @param  Closure(list<Expression>): Expression  $connector
     */
    protected function parseConnectorFunction(Closure $connector): Paren
    {
        $args = $this->parseFunctionArgs(alias: false);

        if ($args === []) {
            $this->raiseError('Expected at least one argument');
        }

        // Wrapped so the connector keeps its precedence in the parent context
        return new Paren(['this' => $connector($args)]);
    }

    /**
     * @param  array<string, mixed>|null  $functions
     */
    protected function parseFunctionCall(?array $functions = null, bool $anonymous = false, bool $optionalParens = true, bool $anyToken = false): ?Expression
    {
        if ($this->curr === 0) {
            return null;
        }

        $comments = $this->currComments();
        $prev = $this->prev;
        $prevIndex = $this->index - 1;
        $token = $this->index;
        $tokenType = $this->curr;
        $this_ = $this->texts[$this->index];
        $upper = strtoupper($this_);

        $afterDot = $prev === TokenType::DOT;
        $parser = $this->t['NO_PAREN_FUNCTION_PARSERS'][$upper] ?? null;

        if ($optionalParens && $parser !== null && ! isset($this->t['INVALID_FUNC_NAME_TOKENS'][$tokenType]) && ! $afterDot) {
            $this->advance();

            return $this->parseWindow($this->callHandler($parser));
        }

        if ($this->next !== TokenType::L_PAREN) {
            if ($optionalParens && isset($this->t['NO_PAREN_FUNCTIONS'][$tokenType]) && ! $afterDot) {
                $this->advance();
                /** @var class-string<Expression> $class */
                $class = $this->t['NO_PAREN_FUNCTIONS'][$tokenType];

                return $this->expression(new $class);
            }

            return null;
        }

        if ($anyToken) {
            if (isset($this->t['RESERVED_TOKENS'][$tokenType])) {
                return null;
            }
        } elseif (! isset($this->funcTokens[$tokenType])) {
            return null;
        }

        $this->advance(2);

        $parser = $this->t['FUNCTION_PARSERS'][$upper] ?? null;

        if ($parser !== null && ! $anonymous) {
            $result = $this->callHandler($parser);
        } else {
            /** @var class-string<Expression>|null $subqueryPredicate */
            $subqueryPredicate = $this->t['SUBQUERY_PREDICATES'][$tokenType] ?? null;

            if ($subqueryPredicate !== null) {
                $expr = null;

                if ($this->curr !== 0 && isset($this->t['SUBQUERY_TOKENS'][$this->curr])) {
                    $expr = $this->parseSelect();
                    $this->matchRParen();
                } elseif ($prev === TokenType::LIKE || $prev === TokenType::ILIKE) {
                    // Backtrack one token since we've consumed the L_PAREN here. Instead, we'd like
                    // to parse "LIKE [ANY | ALL] (...)" as a whole into an exp.Tuple or exp.Paren
                    $this->advance(-1);
                    $expr = $this->parseBitwise();
                }

                if ($expr !== null) {
                    return $this->expression(new $subqueryPredicate(['this' => $expr]), null, $comments);
                }
            }

            $functions ??= $this->t['FUNCTIONS'];
            $function = $functions[$upper] ?? null;
            $knownFunction = $function !== null && ! $anonymous;

            $alias = ! $knownFunction || isset($this->t['FUNCTIONS_WITH_ALIASED_ARGS'][$upper]);
            $args = $this->parseFunctionArgs($alias);

            $postFuncComments = $this->currComments();

            if ($knownFunction && $postFuncComments !== []) {
                // If the user-inputted comment "/* sqlglot.anonymous */" is following the function
                // call we'll construct it as exp.Anonymous, even if it's "known"
                foreach ($postFuncComments as $comment) {
                    if (str_starts_with(ltrim($comment), 'sqlglot.anonymous')) {
                        $knownFunction = false;

                        break;
                    }
                }
            }

            if ($alias && $knownFunction) {
                $args = $this->kvToPropEq($args);
            }

            if ($knownFunction) {
                $func = $this->callFunctionBuilder($function, $args);
                $func = $this->validateExpression($func, $args);

                if ($this->dialect->settings['PRESERVE_ORIGINAL_NAMES'] === true) {
                    $func->setMeta('name', $this_);
                }

                $result = $func;
            } else {
                if ($tokenType === TokenType::IDENTIFIER) {
                    $this_ = new Identifier(['this' => $this_, 'quoted' => true]);
                }

                $result = $this->expression(new Anonymous(['this' => $this_, 'expressions' => $args]));
            }

            $result->meta ??= [];
            $result->meta['start'] = $this->starts[$token];
            $result->meta['end'] = $this->ends[$token];
        }

        if ($result instanceof Expression) {
            $result->addComments($comments);
        }

        if ($parser !== null) {
            $this->match(TokenType::R_PAREN, true, $result);
        } else {
            $this->matchRParen($result);
        }

        return $this->parseWindow($result);
    }

    /**
     * Build a known function from its parsed arguments using the FUNCTIONS table.
     *
     * @param  list<Expression>  $args
     */
    public function buildFunction(string $upper, array $args): ?Expression
    {
        $function = $this->t['FUNCTIONS'][$upper] ?? null;

        return $function === null ? null : $this->callFunctionBuilder($function, $args);
    }

    /**
     * Builders receive the argument list by reference when they declare it so: like sqlglot's,
     * some remove or add arguments, which the validation of the result then sees.
     *
     * @param  string|list<mixed>  $function  a Func class (built with fromArgList) or a builder handler
     * @param  list<mixed>  $args
     */
    protected function callFunctionBuilder(string|array $function, array &$args): Expression
    {
        if (is_string($function) && str_contains($function, '\\')) {
            /** @var class-string<Expression> $function */
            return $function::fromArgList($args);
        }

        if (is_array($function)) {
            $method = $function[0];
            unset($function[0]);

            return $this->{$method}($args, ...self::handlerArguments($function));
        }

        return $this->{$function}($args);
    }

    protected function toPropEq(Expression $expression, int $index): Expression
    {
        return $expression;
    }

    /**
     * @param  list<Expression>  $expressions
     * @return list<Expression>
     */
    protected function kvToPropEq(array $expressions, bool $parseMap = false): array
    {
        $transformed = [];

        foreach ($expressions as $index => $e) {
            if ($this->isKeyValueDefinition($e)) {
                if ($e instanceof Alias) {
                    $e = $this->expression(new PropertyEQ(['this' => $e->args['alias'] ?? null, 'expression' => $e->args['this'] ?? null]));
                }

                if (! $e instanceof PropertyEQ) {
                    $left = $e->args['this'] ?? null;
                    $e = $this->expression(new PropertyEQ([
                        'this' => $parseMap ? $left : Builder::toIdentifier($left instanceof Expression ? $left->name() : (string) $left),
                        'expression' => $e->args['expression'] ?? null,
                    ]));
                }

                $left = $e->args['this'] ?? null;

                if ($left instanceof Column) {
                    $left->replace($left->args['this']);
                }
            } else {
                $e = $this->toPropEq($e, $index);
            }

            $transformed[] = $e;
        }

        return $transformed;
    }

    protected function isKeyValueDefinition(Expression $expression): bool
    {
        foreach ($this->t['KEY_VALUE_DEFINITIONS'] as $class) {
            if ($expression instanceof $class) {
                return true;
            }
        }

        return false;
    }

    protected function parseFunctionProperties(): ?Properties
    {
        // Skip the generic `key = value` fallback in _parse_property since this
        // runs post-AS where a function body like `name = expr` can be misread
        // as a property.
        $properties = [];

        while (true) {
            if ($this->matchTexts($this->t['PROPERTY_PARSERS'])) {
                $keyword = strtoupper($this->prevText());
                $prop = $this->callHandler($this->t['PROPERTY_PARSERS'][$keyword]);
            } elseif ($this->match(TokenType::DEFAULT) && $this->matchTexts($this->t['PROPERTY_PARSERS'])) {
                $keyword = strtoupper($this->prevText());
                $prop = $this->callHandler($this->t['PROPERTY_PARSERS'][$keyword], default: true);
            } else {
                break;
            }

            if ($prop === null || $prop === []) {
                $this->raiseError("Failed to parse property '{$keyword}'");

                break;
            }

            foreach (is_array($prop) ? $prop : [$prop] as $p) {
                $properties[] = $p;
            }
        }

        return $properties !== [] ? $this->expression(new Properties(['expressions' => $properties])) : null;
    }

    protected function parseUserDefinedFunctionExpression(): ?Expression
    {
        return $this->parseStatement();
    }

    protected function parseFunctionParameter(): ?Expression
    {
        return $this->parseColumnDef($this->parseIdVar(), computedColumn: false);
    }

    protected function parseUserDefinedFunction(?int $kind = null): ?Expression
    {
        $this_ = $this->parseTableParts(schema: true);

        if (! $this->match(TokenType::L_PAREN)) {
            return $this_;
        }

        $expressions = $this->parseCsv(fn (): ?Expression => $this->parseFunctionParameter());
        $this->matchRParen();

        return $this->expression(new UserDefinedFunction(['this' => $this_, 'expressions' => $expressions, 'wrapped' => true]));
    }

    protected function parseMacroOverloads(UserDefinedFunction $this_, Expression $firstBody, bool $firstIsTable = false): MacroOverloads
    {
        $parameters = $this_->argList('expressions');
        $overloads = [$this->expression(new MacroOverload([
            'this' => $firstBody,
            'expressions' => $parameters !== [] ? $parameters : null,
            'is_table' => $firstIsTable,
        ]))];

        $this_->set('expressions', null);
        $this_->set('wrapped', false);

        while ($this->match(TokenType::COMMA)) {
            if (! $this->match(TokenType::L_PAREN)) {
                break;
            }

            $params = $this->parseCsv(fn (): ?Expression => $this->parseFunctionParameter());
            $this->matchRParen();

            if (! $this->match(TokenType::ALIAS)) {
                break;
            }

            $isTable = $this->match(TokenType::TABLE);
            $body = $this->parseExpression();
            $overloads[] = $this->expression(new MacroOverload(['this' => $body, 'expressions' => $params, 'is_table' => $isTable]));
        }

        return $this->expression(new MacroOverloads(['expressions' => $overloads]));
    }

    protected function parseIntroducer(int $token): Expression
    {
        $literal = $this->parsePrimary();

        if ($literal !== null) {
            return $this->expression(new Introducer(['this' => $this->texts[$token], 'expression' => $literal]), $token);
        }

        return $this->identifierExpression($token);
    }

    protected function parseSessionParameter(): SessionParameter
    {
        $kind = null;
        $this_ = $this->parseIdVar() ?? $this->parsePrimary();

        if ($this_ !== null && $this->match(TokenType::DOT)) {
            $kind = $this_->name();
            $this_ = $this->parseVar() ?? $this->parsePrimary();
        }

        return $this->expression(new SessionParameter(['this' => $this_, 'kind' => $kind]));
    }

    protected function parseLambdaArg(): ?Expression
    {
        return $this->parseIdVar();
    }

    protected function parseLambda(bool $alias = false): ?Expression
    {
        $nextTokenType = $this->next;

        // Fast path: simple atom (column, literal, null, bool) followed by , or )
        if (isset($this->t['LAMBDA_ARG_TERMINATORS'][$nextTokenType]) && ($atom = $this->parseAtom()) !== null) {
            return $atom;
        }

        $index = $this->index;

        if ($this->match(TokenType::L_PAREN)) {
            $expressions = $this->parseCsv(fn (): ?Expression => $this->parseLambdaArg());

            if (! $this->match(TokenType::R_PAREN)) {
                $this->retreat($index);
            } elseif ($this->matchSet($this->t['LAMBDAS'])) {
                return $this->callHandler($this->t['LAMBDAS'][$this->prev], $expressions);
            } else {
                $this->retreat($index);
            }
        } elseif ($this->t['TYPED_LAMBDA_ARGS'] === true || isset($this->t['LAMBDAS'][$nextTokenType])) {
            $expressions = [$this->parseLambdaArg()];

            if ($this->matchSet($this->t['LAMBDAS'])) {
                return $this->callHandler($this->t['LAMBDAS'][$this->prev], $expressions);
            }

            $this->retreat($index);
        }

        if ($this->match(TokenType::DISTINCT)) {
            $this_ = $this->expression(new Distinct(['expressions' => $this->parseCsv(fn (): ?Expression => $this->parseDisjunction())]));
        } else {
            $this->match(TokenType::ALL); // ALL is the default/no-op aggregate modifier (SQL-92)
            $this_ = $this->parseSelectOrExpression(alias: $alias);
        }

        return $this->parseLimit($this->parseRespectOrIgnoreNulls(
            $this->parseOrder($this->parseHavingMax($this->parseRespectOrIgnoreNulls($this_))),
        ));
    }

    protected function parseBracketKeyValue(bool $isMap = false): ?Expression
    {
        return $this->parseSlice($this->parseAlias($this->parseDisjunction(), explicit: true));
    }

    /**
     * Parses a datetime column in ODBC format, e.g. `{d'yyyy-mm-dd'}` is parsed as a `Date`
     * column, exactly the same as `DATE('yyyy-mm-dd')`.
     */
    protected function parseOdbcDatetimeLiteral(): Expression
    {
        $this->match(TokenType::VAR);
        /** @var class-string<Expression> $class */
        $class = $this->t['ODBC_DATETIME_LITERALS'][strtolower($this->prevText())];
        $expression = $this->expression(new $class(['this' => $this->parseString()]));

        if (! $this->match(TokenType::R_BRACE)) {
            $this->raiseError('Expected }');
        }

        return $expression;
    }

    protected function parseBracket(?Expression $this_ = null): ?Expression
    {
        if (! $this->matchSet($this->t['BRACKETS'])) {
            return $this_;
        }

        if ($this->t['MAP_KEYS_ARE_ARBITRARY_EXPRESSIONS'] === true) {
            $mapToken = $this->index - 2 >= $this->chunkStart ? $this->index - 2 : null;
            $parseMap = $mapToken !== null && strtoupper($this->texts[$mapToken]) === 'MAP';
        } else {
            $parseMap = false;
        }

        $bracketKind = $this->prev;

        if (
            $bracketKind === TokenType::L_BRACE
            && $this->curr === TokenType::VAR
            && isset($this->t['ODBC_DATETIME_LITERALS'][strtolower($this->currText())])
        ) {
            return $this->parseOdbcDatetimeLiteral();
        }

        $expressions = $this->parseCsv(fn (): ?Expression => $this->parseBracketKeyValue(isMap: $bracketKind === TokenType::L_BRACE));

        if ($bracketKind === TokenType::L_BRACKET && ! $this->match(TokenType::R_BRACKET)) {
            $this->raiseError('Expected ]');
        } elseif ($bracketKind === TokenType::L_BRACE && ! $this->match(TokenType::R_BRACE)) {
            $this->raiseError('Expected }');
        }

        // https://duckdb.org/docs/sql/data_types/struct.html#creating-structs
        if ($bracketKind === TokenType::L_BRACE) {
            $this_ = $this->expression(new Struct(['expressions' => $this->kvToPropEq($expressions, parseMap: $parseMap)]));
        } elseif ($this_ === null) {
            $this_ = $this->buildArrayConstructor(ArrayExpr::class, $expressions, $bracketKind);
        } else {
            $constructorType = $this->t['ARRAY_CONSTRUCTORS'][strtoupper($this_->name())] ?? null;

            if ($constructorType !== null) {
                return $this->buildArrayConstructor($constructorType, $expressions, $bracketKind);
            }

            $expressions = Builder::applyIndexOffset($this_, $expressions, -(int) $this->dialect->settings['INDEX_OFFSET'], $this->dialect);
            $this_ = $this->expression(new Bracket(['this' => $this_, 'expressions' => $expressions]), null, $this_->popComments());
        }

        $this->addComments($this_);

        return $this->parseBracket($this_);
    }

    /**
     * @param  class-string<Expression>  $class
     * @param  list<Expression>  $args
     */
    protected function buildArrayConstructor(string $class, array $args, int $bracketKind): Expression
    {
        $array = new $class(['expressions' => $args]);

        if ($class === ArrayExpr::class && $this->dialect->settings['HAS_DISTINCT_ARRAY_CONSTRUCTORS'] === true) {
            $array->set('bracket_notation', $bracketKind === TokenType::L_BRACKET);
        }

        return $array;
    }

    protected function parseSlice(?Expression $this_): ?Expression
    {
        if (! $this->match(TokenType::COLON)) {
            return $this_;
        }

        if ($this->matchPair(TokenType::DASH, TokenType::COLON, false)) {
            $this->advance();
            $end = new Neg(['this' => Literal::number('1')]);
        } else {
            $end = $this->parseAssignment();
        }

        $step = $this->match(TokenType::COLON) ? $this->parseUnary() : null;

        return $this->expression(new Slice(['this' => $this_, 'expression' => $end, 'step' => $step]));
    }

    protected function parseCase(): ?Expression
    {
        if ($this->match(TokenType::DOT, false)) {
            // Avoid raising on valid expressions like case.*, supported by, e.g., spark & snowflake
            $this->retreat($this->index - 1);

            return null;
        }

        $ifs = [];
        $default = null;

        $comments = $this->prevComments;
        $expression = $this->parseDisjunction();

        while ($this->match(TokenType::WHEN)) {
            $this_ = $this->parseDisjunction();
            $this->match(TokenType::THEN);
            $then = $this->parseDisjunction();
            $ifs[] = $this->expression(new IfExpr(['this' => $this_, 'true' => $then]));
        }

        if ($this->match(TokenType::ELSE)) {
            $default = $this->parseDisjunction();
        }

        if (! $this->match(TokenType::END)) {
            if ($default instanceof Interval && strtoupper((string) $default->argExpr('this')?->sql()) === 'END') {
                $default = Builder::column('interval');
            } else {
                $this->raiseError('Expected END after CASE', $this->prevToken());
            }
        }

        return $this->expression(new CaseExpr(['this' => $expression, 'ifs' => $ifs, 'default' => $default]), null, $comments);
    }

    protected function parseIf(): ?Expression
    {
        if ($this->match(TokenType::L_PAREN)) {
            $args = $this->parseCsv(fn (): ?Expression => $this->parseAlias($this->parseAssignment(), explicit: true));
            $this_ = $this->validateExpression(IfExpr::fromArgList($args), $args);
            $this->matchRParen();
        } else {
            $index = $this->index - 1;

            if ($this->t['NO_PAREN_IF_COMMANDS'] === true && $index === $this->chunkStart) {
                return $this->parseAsCommand($index);
            }

            $condition = $this->parseDisjunction();

            if ($condition === null) {
                $this->retreat($index);

                return null;
            }

            $this->match(TokenType::THEN);
            $true = $this->parseDisjunction();
            $false = $this->match(TokenType::ELSE) ? $this->parseDisjunction() : null;
            $this->match(TokenType::END);
            $this_ = $this->expression(new IfExpr(['this' => $condition, 'true' => $true, 'false' => $false]));
        }

        return $this_;
    }

    protected function parseNextValueFor(): ?Expression
    {
        if (! $this->matchTextSeq('VALUE', 'FOR')) {
            $this->retreat($this->index - 1);

            return null;
        }

        return $this->expression(new NextValueFor([
            'this' => $this->parseColumn(),
            'order' => $this->match(TokenType::OVER) ? $this->parseWrapped(fn (): ?Expression => $this->parseOrder()) : null,
        ]));
    }

    protected function parseExtract(): Expression
    {
        $this_ = $this->parseFunction() ?? $this->parseVarOrString(upper: true);

        if ($this->match(TokenType::FROM)) {
            return $this->expression(new Extract(['this' => $this_, 'expression' => $this->parseBitwise()]));
        }

        if (! $this->match(TokenType::COMMA)) {
            $this->raiseError('Expected FROM or comma after EXTRACT', $this->prevToken());
        }

        return $this->expression(new Extract(['this' => $this_, 'expression' => $this->parseBitwise()]));
    }

    protected function parseGapFill(): GapFill
    {
        $this->match(TokenType::TABLE);
        $this_ = $this->parseTable();

        $this->match(TokenType::COMMA);
        $args = [$this_, ...$this->parseCsv(fn (): ?Expression => $this->parseLambda())];

        return $this->validateExpression(GapFill::fromArgList($args), $args);
    }

    protected function parseChar(): Chr
    {
        return $this->expression(new Chr([
            'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseAssignment()),
            'charset' => $this->match(TokenType::USING) ? $this->parseCharsetName() : null,
        ]));
    }

    /**
     * Parse a charset name after USING or CHARACTER SET.
     */
    protected function parseCharsetName(): ?Expression
    {
        return $this->parseVar(tokens: [TokenType::BINARY => true, TokenType::IDENTIFIER => true]);
    }

    protected function parseCast(bool $strict, ?bool $safe = null): Expression
    {
        $this_ = $this->parseAssignment();

        if (! $this->match(TokenType::ALIAS)) {
            if ($this->match(TokenType::COMMA)) {
                return $this->expression(new CastToStrType(['this' => $this_, 'to' => $this->parseString()]));
            }

            $this->raiseError('Expected AS after CAST');
        }

        $fmt = null;
        $to = $this->parseTypes(withCollation: true);

        $default = null;

        if ($this->match(TokenType::DEFAULT)) {
            $default = $this->parseBitwise();
            $this->matchTextSeq('ON', 'CONVERSION', 'ERROR');
        }

        if ($this->matchSet([TokenType::FORMAT => true, TokenType::COMMA => true])) {
            $fmtString = $this->parseWrapped(fn (): ?Expression => $this->parseString(), optional: true);
            $fmt = $this->parseAtTimeZone($fmtString);

            $to ??= new DataType(['this' => DType::UNKNOWN]);
            $toType = $to->args['this'] ?? null;

            if (is_string($toType) && in_array($toType, DataType::TEMPORAL_TYPES, true)) {
                $class = $toType === DType::DATE ? StrToDate::class : StrToTime::class;
                $mapping = $this->dialect->settings['FORMAT_MAPPING'] !== [] ? 'FORMAT' : 'TIME';
                $this_ = $this->expression(new $class([
                    'this' => $this_,
                    'format' => Literal::string(TimeFormat::convert(
                        $fmtString !== null ? (string) $fmtString->this() : '',
                        $this->dialect->settings["{$mapping}_MAPPING"],
                        $this->dialect->settings["{$mapping}_TRIE"],
                    )),
                    'safe' => $safe,
                ]));

                if ($fmt instanceof AtTimeZone && $this_ instanceof StrToTime) {
                    $this_->set('zone', $fmt->args['zone'] ?? null);
                }

                return $this_;
            }
        } elseif ($to === null) {
            $this->raiseError('Expected TYPE after CAST');
        } elseif ($to instanceof Identifier) {
            $to = DataType::fromStr($to->name(), $this->dialect, udt: true);
        } elseif (($to->args['this'] ?? null) === DType::CHAR && ($this->match(TokenType::CHARACTER_SET) || $this->matchTextSeq('CHARACTER', 'SET'))) {
            $to = new DataType(['this' => DType::CHARACTER_SET, 'kind' => $this->parseVarOrString()]);
        }

        return $this->buildCast($strict, [
            'this' => $this_,
            'to' => $to,
            'format' => $fmt,
            'safe' => $safe,
            'action' => $this->parseVarFromOptions($this->t['CAST_ACTIONS'], raiseUnmatched: false),
            'default' => $default,
        ]);
    }

    protected function parseStringAgg(): Expression
    {
        if ($this->match(TokenType::DISTINCT)) {
            $args = [$this->expression(new Distinct(['expressions' => [$this->parseDisjunction()]]))];

            if ($this->match(TokenType::COMMA)) {
                array_push($args, ...$this->parseCsv(fn (): ?Expression => $this->parseDisjunction()));
            }
        } else {
            $args = $this->parseCsv(fn (): ?Expression => $this->parseDisjunction());
        }

        if ($this->matchTextSeq('ON', 'OVERFLOW')) {
            // trino: LISTAGG(expression [, separator] [ON OVERFLOW overflow_behavior])
            if ($this->matchTextSeq('ERROR')) {
                $onOverflow = Builder::var('ERROR');
            } else {
                $this->matchTextSeq('TRUNCATE');
                $onOverflow = $this->expression(new OverflowTruncateBehavior([
                    'this' => $this->parseString(),
                    'with_count' => $this->matchTextSeq('WITH', 'COUNT') || ! $this->matchTextSeq('WITHOUT', 'COUNT'),
                ]));
            }
        } else {
            $onOverflow = null;
        }

        $index = $this->index;

        if (! $this->match(TokenType::R_PAREN) && $args !== []) {
            // postgres: STRING_AGG([DISTINCT] expression, separator [ORDER BY expression1 {ASC | DESC} [, ...]])
            // bigquery: STRING_AGG([DISTINCT] expression [, separator] [ORDER BY key [{ASC | DESC}] [, ... ]] [LIMIT n])
            // The order is parsed through `this` as a canonicalization for WITHIN GROUPs
            $args[0] = $this->parseLimit(this_: $this->parseOrder(this_: $args[0]));

            return $this->expression(new GroupConcat(['this' => $args[0], 'separator' => $args[1] ?? null]));
        }

        // Checks if we can parse an order clause: WITHIN GROUP (ORDER BY <order_by_expression_list> [ASC | DESC]).
        // This is done "manually", instead of letting _parse_window parse it into an exp.WithinGroup node, so that
        // the STRING_AGG call is parsed like in MySQL / SQLite and can thus be transpiled more easily to them.
        if (! $this->matchTextSeq('WITHIN', 'GROUP')) {
            $this->retreat($index);

            return $this->validateExpression(GroupConcat::fromArgList($args), $args);
        }

        // The corresponding match_r_paren will be called in parse_function (caller)
        $this->matchLParen();

        return $this->expression(new GroupConcat([
            'this' => $this->parseOrder(this_: $args[0] ?? null),
            'separator' => $args[1] ?? null,
            'on_overflow' => $onOverflow,
        ]));
    }

    protected function parseConvert(bool $strict, ?bool $safe = null): ?Expression
    {
        $this_ = $this->parseBitwise();

        if ($this->match(TokenType::USING)) {
            $to = new DataType(['this' => DType::CHARACTER_SET, 'kind' => $this->parseCharsetName()]);
        } elseif ($this->match(TokenType::COMMA)) {
            $to = $this->parseTypes();
        } else {
            $to = null;
        }

        return $this->buildCast($strict, ['this' => $this_, 'to' => $to, 'safe' => $safe]);
    }

    protected function parseXmlElement(): XMLElement
    {
        if ($this->matchTextSeq('EVALNAME')) {
            $evalname = true;
            $this_ = $this->parseBitwise();
        } else {
            $evalname = null;
            $this->matchTextSeq('NAME');
            $this_ = $this->parseIdVar();
        }

        return $this->expression(new XMLElement([
            'this' => $this_,
            'expressions' => $this->match(TokenType::COMMA) ? $this->parseCsv(fn (): ?Expression => $this->parseBitwise()) : null,
            'evalname' => $evalname,
        ]));
    }

    protected function parseXmlTable(): XMLTable
    {
        $namespaces = null;
        $passing = null;
        $columns = null;

        if ($this->matchTextSeq('XMLNAMESPACES', '(')) {
            $namespaces = $this->parseXmlNamespace();
            $this->matchTextSeq(')', ',');
        }

        $this_ = $this->parseString();

        if ($this->matchTextSeq('PASSING')) {
            // The BY VALUE keywords are optional and are provided for semantic clarity
            $this->matchTextSeq('BY', 'VALUE');
            $passing = $this->parseCsv(fn (): ?Expression => $this->parseColumn());
        }

        $byRef = $this->matchTextSeq('RETURNING', 'SEQUENCE', 'BY', 'REF');

        if ($this->matchTextSeq('COLUMNS')) {
            $columns = $this->parseCsv(fn (): ?Expression => $this->parseFieldDef());
        }

        return $this->expression(new XMLTable([
            'this' => $this_,
            'namespaces' => $namespaces,
            'passing' => $passing,
            'columns' => $columns,
            'by_ref' => $byRef,
        ]));
    }

    /**
     * @return list<XMLNamespace>
     */
    protected function parseXmlNamespace(): array
    {
        $namespaces = [];

        while (true) {
            if ($this->match(TokenType::DEFAULT)) {
                $uri = $this->parseString();
            } else {
                $uri = $this->parseAlias($this->parseString());
            }

            $namespaces[] = $this->expression(new XMLNamespace(['this' => $uri]));

            if (! $this->match(TokenType::COMMA)) {
                break;
            }
        }

        return $namespaces;
    }

    protected function parseDecode(): ?Expression
    {
        $args = $this->parseCsv(fn (): ?Expression => $this->parseDisjunction());

        if (count($args) < 3) {
            return $this->expression(new Decode(['this' => $args[0] ?? null, 'charset' => $args[1] ?? null]));
        }

        return $this->expression(new DecodeCase(['expressions' => $args]));
    }

    protected function parseJsonKeyValue(): ?JSONKeyValue
    {
        $this->matchTextSeq('KEY');
        $key = $this->parseColumn();
        $this->matchSet($this->t['JSON_KEY_VALUE_SEPARATOR_TOKENS']);
        $this->matchTextSeq('VALUE');
        $value = $this->parseBitwise();

        if ($key === null && $value === null) {
            return null;
        }

        return $this->expression(new JSONKeyValue(['this' => $key, 'expression' => $value]));
    }

    protected function parseFormatJson(?Expression $this_): ?Expression
    {
        if ($this_ === null || ! $this->matchTextSeq('FORMAT', 'JSON')) {
            return $this_;
        }

        return $this->expression(new FormatJson(['this' => $this_]));
    }

    protected function parseOnCondition(): ?OnCondition
    {
        $tokens = $this->t['ON_CONDITION_TOKENS'];

        // MySQL uses "X ON EMPTY Y ON ERROR" (e.g. JSON_VALUE) while Oracle uses the opposite (e.g. JSON_EXISTS)
        if ($this->dialect->settings['ON_CONDITION_EMPTY_BEFORE_ERROR'] === true) {
            $empty = $this->parseOnHandling('EMPTY', ...array_map(strval(...), array_keys($tokens)));
            $error = $this->parseOnHandling('ERROR', ...array_map(strval(...), array_keys($tokens)));
        } else {
            $error = $this->parseOnHandling('ERROR', ...array_map(strval(...), array_keys($tokens)));
            $empty = $this->parseOnHandling('EMPTY', ...array_map(strval(...), array_keys($tokens)));
        }

        $null = $this->parseOnHandling('NULL', ...array_map(strval(...), array_keys($tokens)));

        if ($empty === null && $error === null && $null === null) {
            return null;
        }

        return $this->expression(new OnCondition(['empty' => $empty, 'error' => $error, 'null' => $null]));
    }

    /**
     * Parses the "X ON Y" or "DEFAULT <expr> ON Y syntax, e.g. NULL ON NULL (Oracle, T-SQL, MySQL).
     */
    protected function parseOnHandling(string $on, string ...$values): string|Expression|null
    {
        foreach ($values as $value) {
            if ($this->matchTextSeq($value, 'ON', $on)) {
                return "{$value} ON {$on}";
            }
        }

        $index = $this->index;

        if ($this->match(TokenType::DEFAULT)) {
            $defaultValue = $this->parseBitwise();

            if ($this->matchTextSeq('ON', $on)) {
                return $defaultValue;
            }

            $this->retreat($index);
        }

        return null;
    }

    protected function parseJsonObject(bool $agg = false): Expression
    {
        $star = $this->parseStar();
        $expressions = $star !== null
            ? [$star]
            : $this->parseCsv(fn (): ?Expression => $this->parseFormatJson($this->parseJsonKeyValue()));
        $nullHandling = $this->parseOnHandling('NULL', 'NULL', 'ABSENT');

        $uniqueKeys = null;

        if ($this->matchTextSeq('WITH', 'UNIQUE')) {
            $uniqueKeys = true;
        } elseif ($this->matchTextSeq('WITHOUT', 'UNIQUE')) {
            $uniqueKeys = false;
        }

        $this->matchTextSeq('KEYS');

        $returnType = $this->matchTextSeq('RETURNING') ? $this->parseFormatJson($this->parseType()) : null;
        $encoding = $this->matchTextSeq('ENCODING') ? $this->parseVar() : null;

        $class = $agg ? JSONObjectAgg::class : JSONObject::class;

        return $this->expression(new $class([
            'expressions' => $expressions,
            'null_handling' => $nullHandling,
            'unique_keys' => $uniqueKeys,
            'return_type' => $returnType,
            'encoding' => $encoding,
        ]));
    }

    // Note: this is currently incomplete; it only implements the "JSON_value_column" part
    protected function parseJsonColumnDef(): JSONColumnDef
    {
        if (! $this->matchTextSeq('NESTED')) {
            $this_ = $this->parseIdVar();
            $ordinality = $this->matchPair(TokenType::FOR, TokenType::ORDINALITY);
            $kind = $this->parseTypes(allowIdentifiers: false);
            $nested = null;
        } else {
            $this_ = null;
            $ordinality = null;
            $kind = null;
            $nested = true;
        }

        $formatJson = $this->matchTextSeq('FORMAT', 'JSON');
        $path = $this->matchTextSeq('PATH') ? $this->parseString() : null;
        $nestedSchema = $nested === true ? $this->parseJsonSchema() : null;

        return $this->expression(new JSONColumnDef([
            'this' => $this_,
            'kind' => $kind,
            'path' => $path,
            'nested_schema' => $nestedSchema,
            'ordinality' => $ordinality,
            'format_json' => $formatJson,
        ]));
    }

    protected function parseJsonSchema(): JSONSchema
    {
        $this->matchTextSeq('COLUMNS');

        return $this->expression(new JSONSchema([
            'expressions' => $this->parseWrappedCsv(fn (): Expression => $this->parseJsonColumnDef(), optional: true),
        ]));
    }

    protected function parseJsonTable(): JSONTable
    {
        $this_ = $this->parseFormatJson($this->parseBitwise());
        $path = $this->match(TokenType::COMMA) ? $this->parseString() : null;
        $errorHandling = $this->parseOnHandling('ERROR', 'ERROR', 'NULL');
        $emptyHandling = $this->parseOnHandling('EMPTY', 'ERROR', 'NULL');
        $schema = $this->parseJsonSchema();

        return new JSONTable([
            'this' => $this_,
            'schema' => $schema,
            'path' => $path,
            'error_handling' => $errorHandling,
            'empty_handling' => $emptyHandling,
        ]);
    }

    protected function parseMatchAgainst(): MatchAgainst
    {
        if ($this->matchTextSeq('TABLE')) {
            // parse SingleStore MATCH(TABLE ...) syntax
            // https://docs.singlestore.com/cloud/reference/sql-reference/full-text-search-functions/match/
            $expressions = [];
            $table = $this->parseTable();

            if ($table !== null) {
                $expressions = [$table];
            }
        } else {
            $expressions = $this->parseCsv(fn (): ?Expression => $this->parseColumn());
        }

        $this->matchTextSeq(')', 'AGAINST', '(');

        $this_ = $this->parseString();

        if ($this->matchTextSeq('IN', 'NATURAL', 'LANGUAGE', 'MODE')) {
            $modifier = 'IN NATURAL LANGUAGE MODE';

            if ($this->matchTextSeq('WITH', 'QUERY', 'EXPANSION')) {
                $modifier = "{$modifier} WITH QUERY EXPANSION";
            }
        } elseif ($this->matchTextSeq('IN', 'BOOLEAN', 'MODE')) {
            $modifier = 'IN BOOLEAN MODE';
        } elseif ($this->matchTextSeq('WITH', 'QUERY', 'EXPANSION')) {
            $modifier = 'WITH QUERY EXPANSION';
        } else {
            $modifier = null;
        }

        return $this->expression(new MatchAgainst(['this' => $this_, 'expressions' => $expressions, 'modifier' => $modifier]));
    }

    // https://learn.microsoft.com/en-us/sql/t-sql/functions/openjson-transact-sql?view=sql-server-ver16
    protected function parseOpenJson(): OpenJSON
    {
        $this_ = $this->parseBitwise();
        $path = $this->match(TokenType::COMMA) ? $this->parseString() : null;

        $parseOpenJsonColumnDef = function (): OpenJSONColumnDef {
            $this_ = $this->parseField(anyToken: true);
            $kind = $this->parseTypes();
            $path = $this->parseString();
            $asJson = $this->matchPair(TokenType::ALIAS, TokenType::JSON);

            return $this->expression(new OpenJSONColumnDef(['this' => $this_, 'kind' => $kind, 'path' => $path, 'as_json' => $asJson]));
        };

        $expressions = null;

        if ($this->matchPair(TokenType::R_PAREN, TokenType::WITH)) {
            $this->matchLParen();
            $expressions = $this->parseCsv($parseOpenJsonColumnDef);
        }

        return $this->expression(new OpenJSON(['this' => $this_, 'path' => $path, 'expressions' => $expressions]));
    }

    protected function parsePosition(bool $haystackFirst = false): StrPosition
    {
        $args = $this->parseCsv(fn (): ?Expression => $this->parseBitwise());

        if ($this->match(TokenType::IN)) {
            return $this->expression(new StrPosition(['this' => $this->parseBitwise(), 'substr' => $args[0] ?? null]));
        }

        if ($haystackFirst) {
            $haystack = $args[0] ?? null;
            $needle = $args[1] ?? null;
        } else {
            $haystack = $args[1] ?? null;
            $needle = $args[0] ?? null;
        }

        return $this->expression(new StrPosition(['this' => $haystack, 'substr' => $needle, 'position' => $args[2] ?? null]));
    }

    protected function parseJoinHint(string $funcName): JoinHint
    {
        $args = $this->parseCsv(fn (): ?Expression => $this->parseTable());

        return new JoinHint(['this' => strtoupper($funcName), 'expressions' => $args]);
    }

    protected function parseSubstring(): Substring
    {
        // Postgres supports the form: substring(string [from int] [for int])
        // (despite being undocumented, the reverse order also works)
        // https://www.postgresql.org/docs/9.1/functions-string.html @ Table 9-6
        $args = $this->parseCsv(fn (): ?Expression => $this->parseBitwise());

        $start = null;
        $length = null;

        while ($this->curr !== 0) {
            if ($this->match(TokenType::FROM)) {
                $start = $this->parseBitwise();
            } elseif ($this->match(TokenType::FOR)) {
                $start ??= Literal::number(1);
                $length = $this->parseBitwise();
            } else {
                break;
            }
        }

        if ($start !== null) {
            $args[] = $start;
        }

        if ($length !== null) {
            $args[] = $length;
        }

        return $this->validateExpression(Substring::fromArgList($args), $args);
    }

    protected function parseTrim(): Trim
    {
        // https://www.w3resource.com/sql/character-functions/trim.php
        // https://docs.oracle.com/javadb/10.8.3.0/ref/rreftrimfunc.html
        $position = null;
        $collation = null;
        $expression = null;

        if ($this->matchTexts($this->t['TRIM_TYPES'])) {
            $position = strtoupper($this->prevText());
        }

        $this_ = $this->parseBitwise();

        if ($this->matchSet([TokenType::FROM => true, TokenType::COMMA => true])) {
            $invertOrder = $this->prev === TokenType::FROM || $this->t['TRIM_PATTERN_FIRST'] === true;
            $expression = $this->parseBitwise();

            if ($invertOrder) {
                [$this_, $expression] = [$expression, $this_];
            }
        }

        if ($this->match(TokenType::COLLATE)) {
            $collation = $this->parseBitwise();
        }

        return $this->expression(new Trim(['this' => $this_, 'position' => $position, 'expression' => $expression, 'collation' => $collation]));
    }

    /**
     * @return list<Expression>|null
     */
    protected function parseWindowClause(): ?array
    {
        return $this->match(TokenType::WINDOW) ? $this->parseCsv(fn (): ?Expression => $this->parseNamedWindow()) : null;
    }

    protected function parseNamedWindow(): ?Expression
    {
        return $this->parseWindow($this->parseIdVar(), alias: true);
    }

    protected function parseRespectOrIgnoreNulls(?Expression $this_): ?Expression
    {
        if ($this->curr === TokenType::VAR) {
            if ($this->matchTextSeq('IGNORE', 'NULLS')) {
                return $this->expression(new IgnoreNulls(['this' => $this_]));
            }

            if ($this->matchTextSeq('RESPECT', 'NULLS')) {
                return $this->expression(new RespectNulls(['this' => $this_]));
            }
        }

        return $this_;
    }

    protected function parseHavingMax(?Expression $this_): ?Expression
    {
        if ($this->match(TokenType::HAVING)) {
            $this->matchTexts(['MAX' => true, 'MIN' => true]);
            $max = strtoupper($this->prevText()) !== 'MIN';

            return $this->expression(new HavingMax(['this' => $this_, 'expression' => $this->parseColumn(), 'max' => $max]));
        }

        return $this_;
    }

    protected function parseWindow(?Expression $this_, bool $alias = false): ?Expression
    {
        $func = $this_;
        $comments = $func?->comments;

        // https://docs.oracle.com/en/database/oracle/oracle-database/19/sqlrf/img_text/nth_value.html
        if ($this->t['SUPPORTS_NTH_VALUE_FROM_MODIFIER'] === true && $this_ instanceof NthValue) {
            if ($this->matchTextSeq('FROM', 'FIRST')) {
                $this_->set('from_first', true);
            } elseif ($this->matchTextSeq('FROM', 'LAST')) {
                $this_->set('from_first', false);
            }
        }

        // T-SQL allows the OVER (...) syntax after WITHIN GROUP.
        // https://learn.microsoft.com/en-us/sql/t-sql/functions/percentile-disc-transact-sql?view=sql-server-ver16
        if ($this->matchTextSeq('WITHIN', 'GROUP')) {
            $order = $this->parseWrapped(fn (): ?Expression => $this->parseOrder());
            $this_ = $this->expression(new WithinGroup(['this' => $this_, 'expression' => $order]));
        }

        if ($this->matchPair(TokenType::FILTER, TokenType::L_PAREN)) {
            $this->match(TokenType::WHERE);
            $this_ = $this->expression(new Filter(['this' => $this_, 'expression' => $this->parseWhere(skipWhereToken: true)]));
            $this->matchRParen();
        }

        // SQL spec defines an optional [ { IGNORE | RESPECT } NULLS ] OVER
        // Some dialects choose to implement and some do not.
        // https://dev.mysql.com/doc/refman/8.0/en/window-function-descriptions.html
        //
        // Oracle allows both FIRST_VALUE(x IGNORE NULLS) OVER and FIRST_VALUE(x) IGNORE NULLS OVER
        if ($this_ instanceof AggFunc) {
            $ignoreRespect = $this->findInScope($this_, IgnoreNulls::class, RespectNulls::class);

            if ($ignoreRespect !== null && $ignoreRespect !== $this_) {
                $ignoreRespect->replace($ignoreRespect->args['this'] ?? null);
                $class = $ignoreRespect::class;
                $this_ = $this->expression(new $class(['this' => $this_]));
            }
        }

        $this_ = $this->parseRespectOrIgnoreNulls($this_);

        // bigquery select from window x AS (partition by ...)
        if ($alias) {
            $over = null;
            $this->match(TokenType::ALIAS);
        } elseif (! $this->matchSet($this->t['WINDOW_BEFORE_PAREN_TOKENS'])) {
            return $this_;
        } else {
            $over = strtoupper($this->prevText());
        }

        if ($comments !== null && $comments !== []) {
            $func->popComments();
        }

        if (! $this->match(TokenType::L_PAREN)) {
            return $this->expression(new Window(['this' => $this_, 'alias' => $this->parseIdVar(false), 'over' => $over]), null, $comments);
        }

        $windowAlias = $this->parseIdVar(anyToken: false, tokens: $this->t['WINDOW_ALIAS_TOKENS']);

        $first = $this->match(TokenType::FIRST) ? true : null;

        if ($this->matchTextSeq('LAST')) {
            $first = false;
        }

        [$partition, $order] = $this->parsePartitionAndOrder();
        $kind = ($this->matchSet([TokenType::ROWS => true, TokenType::RANGE => true]) || $this->matchTextSeq('GROUPS'))
            ? $this->prevText()
            : null;

        if ($kind !== null) {
            $this->match(TokenType::BETWEEN);
            $start = $this->parseWindowSpec();

            $end = $this->match(TokenType::AND) ? $this->parseWindowSpec() : [];
            $exclude = $this->matchTextSeq('EXCLUDE') ? $this->parseVarFromOptions($this->t['WINDOW_EXCLUDE_OPTIONS']) : null;

            $spec = $this->expression(new WindowSpec([
                'kind' => $kind,
                'start' => $start['value'],
                'start_side' => $start['side'],
                'end' => $end['value'] ?? null,
                'end_side' => $end['side'] ?? null,
                'exclude' => $exclude,
            ]));
        } else {
            $spec = null;
        }

        $this->matchRParen();

        $window = $this->expression(new Window([
            'this' => $this_,
            'partition_by' => $partition,
            'order' => $order,
            'spec' => $spec,
            'alias' => $windowAlias,
            'over' => $over,
            'first' => $first,
        ]), null, $comments);

        // This covers Oracle's FIRST/LAST syntax: aggregate KEEP (...) OVER (...)
        if ($this->matchSet($this->t['WINDOW_BEFORE_PAREN_TOKENS'], false)) {
            return $this->parseWindow($window, alias: $alias);
        }

        return $window;
    }

    /**
     * The first node of the given types within the expression's scope (sqlglot find_in_scope):
     * a breadth-first search that doesn't descend into subqueries.
     *
     * @param  class-string  ...$types
     */
    protected function findInScope(Expression $expression, string ...$types): ?Expression
    {
        $queue = [$expression];

        while ($queue !== []) {
            $node = array_shift($queue);

            foreach ($types as $type) {
                if ($node instanceof $type) {
                    return $node;
                }
            }

            if ($node !== $expression && ($node instanceof Query || $node instanceof Subquery)) {
                continue;
            }

            foreach ($node->children() as $child) {
                $queue[] = $child;
            }
        }

        return null;
    }

    /**
     * @return array{0: list<Expression>, 1: Expression|null}
     */
    protected function parsePartitionAndOrder(): array
    {
        return [$this->parsePartitionBy(), $this->parseOrder()];
    }

    /**
     * @return array{value: string|Expression|null, side: string|null}
     */
    protected function parseWindowSpec(): array
    {
        $this->match(TokenType::BETWEEN);

        if ($this->matchTextSeq('UNBOUNDED')) {
            $value = 'UNBOUNDED';
        } elseif ($this->matchTextSeq('CURRENT', 'ROW')) {
            $value = 'CURRENT ROW';
        } else {
            $value = $this->parseBitwise();
        }

        return [
            'value' => $value,
            'side' => $this->matchTexts($this->t['WINDOW_SIDES']) ? $this->prevText() : null,
        ];
    }

    protected function parseAlias(?Expression $this_, bool $explicit = false): ?Expression
    {
        // In some dialects, LIMIT and OFFSET can act as both identifiers and keywords (clauses)
        // so this section tries to parse the clause version and if it fails, it treats the token
        // as an identifier (alias)
        if ($this->canParseLimitOrOffset()) {
            return $this_;
        }

        // WINDOW is in ID_VAR_TOKENS, so it can be consumed as an implicit alias. Detect the
        // named-window clause shape (`WINDOW <ident> AS (...)`) and avoid swallowing it.
        if ($this->canParseNamedWindow()) {
            return $this_;
        }

        $anyToken = $this->match(TokenType::ALIAS);
        $comments = $this->prevComments;

        if ($explicit && ! $anyToken) {
            return $this_;
        }

        if ($this->match(TokenType::L_PAREN)) {
            $aliases = $this->expression(new Aliases([
                'this' => $this_,
                'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseIdVar($anyToken)),
            ]), null, $comments);
            $this->matchRParen($aliases);

            return $aliases;
        }

        $alias = $this->parseIdVar($anyToken, tokens: $this->t['ALIAS_TOKENS'])
            ?? ($this->t['STRING_ALIASES'] === true ? $this->parseStringAsIdentifier() : null);

        if ($alias !== null) {
            array_push($comments, ...$alias->popComments());
            $this_ = $this->expression(new Alias(['this' => $this_, 'alias' => $alias]), null, $comments);
            $column = $this_->args['this'] ?? null;

            // Moves the comment next to the alias in `expr /* comment */ AS alias`
            if (($this_->comments === null || $this_->comments === []) && $column instanceof Expression && $column->comments !== null && $column->comments !== []) {
                $this_->comments = $column->popComments();
            }
        }

        return $this_;
    }

    /**
     * @param  array<int, mixed>|null  $tokens
     */
    protected function parseIdVar(bool $anyToken = true, ?array $tokens = null): ?Expression
    {
        $expression = $this->parseIdentifier();

        if ($expression === null && (($anyToken && $this->advanceAny() !== null) || $this->matchSet($tokens !== null && $tokens !== [] ? $tokens : $this->idVarTokens))) {
            $quoted = $this->prev === TokenType::STRING;
            $expression = $this->identifierExpression(quoted: $quoted);
        }

        return $expression;
    }

    protected function parseString(): ?Expression
    {
        if ($this->matchSet($this->t['STRING_PARSERS'])) {
            return $this->callHandler($this->t['STRING_PARSERS'][$this->prev], $this->index - 1);
        }

        return $this->parsePlaceholder();
    }

    protected function parseStringAsIdentifier(): ?Identifier
    {
        if (! $this->match(TokenType::STRING)) {
            return null;
        }

        $output = Builder::toIdentifier($this->prevText(), quoted: true);
        $output->meta = ['start' => $this->starts[$this->index - 1], 'end' => $this->ends[$this->index - 1]];

        return $output;
    }

    protected function parseNumber(): ?Expression
    {
        if ($this->matchSet($this->t['NUMERIC_PARSERS'])) {
            return $this->callHandler($this->t['NUMERIC_PARSERS'][$this->prev], $this->index - 1);
        }

        return $this->parsePlaceholder();
    }

    protected function parseIdentifier(): ?Expression
    {
        if ($this->match(TokenType::IDENTIFIER)) {
            return $this->identifierExpression(quoted: true);
        }

        return $this->parsePlaceholder();
    }

    /**
     * @param  array<int, mixed>|null  $tokens
     */
    protected function parseVar(bool $anyToken = false, ?array $tokens = null, bool $upper = false): ?Expression
    {
        if (
            ($anyToken && $this->advanceAny() !== null)
            || $this->match(TokenType::VAR)
            || ($tokens !== null && $tokens !== [] && $this->matchSet($tokens))
        ) {
            return $this->expression(new VarExpr(['this' => $upper ? strtoupper($this->prevText()) : $this->prevText()]));
        }

        return $this->parsePlaceholder();
    }

    /**
     * Advance past any non-reserved token; returns the consumed token's index.
     */
    protected function advanceAny(bool $ignoreReserved = false): ?int
    {
        if ($this->curr !== 0 && ($ignoreReserved || ! isset($this->t['RESERVED_TOKENS'][$this->curr]))) {
            $this->advance();

            return $this->index - 1;
        }

        return null;
    }

    protected function parseVarOrString(bool $upper = false): ?Expression
    {
        return $this->parseString() ?? $this->parseVar(anyToken: true, upper: $upper);
    }

    protected function parsePrimaryOrVar(): ?Expression
    {
        return $this->parsePrimary() ?? $this->parseVar(anyToken: true);
    }

    protected function parseNull(): ?Expression
    {
        if ($this->matchSet([TokenType::NULL => true, TokenType::UNKNOWN => true])) {
            return $this->callHandler($this->t['PRIMARY_PARSERS'][TokenType::NULL], $this->index - 1);
        }

        return $this->parsePlaceholder();
    }

    protected function parseBoolean(): ?Expression
    {
        if ($this->match(TokenType::TRUE)) {
            return $this->callHandler($this->t['PRIMARY_PARSERS'][TokenType::TRUE], $this->index - 1);
        }

        if ($this->match(TokenType::FALSE)) {
            return $this->callHandler($this->t['PRIMARY_PARSERS'][TokenType::FALSE], $this->index - 1);
        }

        return $this->parsePlaceholder();
    }

    protected function parseStar(): ?Expression
    {
        if ($this->match(TokenType::STAR)) {
            return $this->callHandler($this->t['PRIMARY_PARSERS'][TokenType::STAR], $this->index - 1);
        }

        return $this->parsePlaceholder();
    }

    protected function parseParameter(): Parameter
    {
        $this_ = $this->parseIdentifier() ?? $this->parsePrimaryOrVar();

        return $this->expression(new Parameter(['this' => $this_]));
    }

    protected function parsePlaceholder(): ?Expression
    {
        if ($this->matchSet($this->t['PLACEHOLDER_PARSERS'])) {
            $placeholder = $this->callHandler($this->t['PLACEHOLDER_PARSERS'][$this->prev]);

            if ($placeholder !== null) {
                return $placeholder;
            }

            $this->advance(-1);
        }

        return null;
    }

    /**
     * @return list<Expression>|null
     */
    protected function parseStarOp(string ...$keywords): ?array
    {
        if (! $this->matchTexts(array_fill_keys($keywords, true))) {
            return null;
        }

        if ($this->match(TokenType::L_PAREN, false)) {
            return $this->parseWrappedCsv(fn (): ?Expression => $this->parseExpression());
        }

        $expression = $this->parseAlias($this->parseDisjunction(), explicit: true);

        return $expression !== null ? [$expression] : null;
    }

    /**
     * @template T
     *
     * @param  Closure(): (T|null)  $parseMethod
     * @return list<T>
     */
    protected function parseCsv(Closure $parseMethod, int $separator = TokenType::COMMA): array
    {
        $parseResult = $parseMethod();
        $items = $parseResult !== null ? [$parseResult] : [];

        while ($this->match($separator)) {
            if ($parseResult instanceof Expression) {
                $this->addComments($parseResult);
            }

            $parseResult = $parseMethod();

            if ($parseResult !== null) {
                $items[] = $parseResult;
            }
        }

        return $items;
    }

    /**
     * @return list<Expression>
     */
    protected function parseWrappedIdVars(bool $optional = false): array
    {
        return $this->parseWrappedCsv(fn (): ?Expression => $this->parseIdVar(), optional: $optional);
    }

    /**
     * @template T
     *
     * @param  Closure(): (T|null)  $parseMethod
     * @return list<T>
     */
    protected function parseWrappedCsv(Closure $parseMethod, int $separator = TokenType::COMMA, bool $optional = false): array
    {
        return $this->parseWrapped(fn (): array => $this->parseCsv($parseMethod, $separator), optional: $optional);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $parseMethod
     * @return T
     */
    protected function parseWrapped(Closure $parseMethod, bool $optional = false): mixed
    {
        $wrapped = $this->match(TokenType::L_PAREN);

        if (! $wrapped && ! $optional) {
            $this->raiseError('Expecting (');
        }

        $parseResult = $parseMethod();

        if ($wrapped) {
            $this->matchRParen();
        }

        return $parseResult;
    }

    /**
     * @return list<Expression>
     */
    protected function parseExpressions(): array
    {
        return $this->parseCsv(fn (): ?Expression => $this->parseExpression());
    }

    protected function parseSelectOrExpression(bool $alias = false): ?Expression
    {
        return $this->parseSetOperations(
            $alias ? $this->parseAlias($this->parseAssignment(), explicit: true) : $this->parseAssignment(),
        ) ?? $this->parseSelect();
    }

    protected function parseDdlSelect(): ?Expression
    {
        return $this->parseQueryModifiers(
            $this->parseSetOperations($this->parseSelect(nested: true, parseSubqueryAlias: false)),
        );
    }

    protected function parseTransaction(): Expression
    {
        $this_ = null;

        if ($this->matchTexts($this->t['TRANSACTION_KIND'])) {
            $this_ = $this->prevText();
        }

        $this->matchTexts(['TRANSACTION' => true, 'WORK' => true]);

        $modes = [];

        while (true) {
            $mode = [];

            while ($this->match(TokenType::VAR) || $this->match(TokenType::NOT)) {
                $mode[] = $this->prevText();
            }

            if ($mode !== []) {
                $modes[] = implode(' ', $mode);
            }

            if (! $this->match(TokenType::COMMA)) {
                break;
            }
        }

        return $this->expression(new Transaction(['this' => $this_, 'modes' => $modes]));
    }

    protected function parseCommitOrRollback(): Expression
    {
        $chain = null;
        $savepoint = null;
        $isRollback = $this->prev === TokenType::ROLLBACK;

        $this->matchTexts(['TRANSACTION' => true, 'WORK' => true]);

        if ($this->matchTextSeq('TO')) {
            $this->matchTextSeq('SAVEPOINT');
            $savepoint = $this->parseIdVar();
        }

        if ($this->match(TokenType::AND)) {
            $chain = ! $this->matchTextSeq('NO');
            $this->matchTextSeq('CHAIN');
        }

        if ($isRollback) {
            return $this->expression(new Rollback(['savepoint' => $savepoint]));
        }

        return $this->expression(new Commit(['chain' => $chain]));
    }

    protected function parseRefresh(): Expression
    {
        if ($this->matchTextSeq('EXTERNAL', 'TABLE')) {
            $kind = 'EXTERNAL TABLE';
        } elseif ($this->match(TokenType::TABLE)) {
            $kind = 'TABLE';
        } elseif ($this->matchTextSeq('MATERIALIZED', 'VIEW')) {
            $kind = 'MATERIALIZED VIEW';
        } else {
            $kind = '';
        }

        $this_ = $this->parseString() ?? $this->parseTable();

        if ($kind === '' && ! $this_ instanceof Literal) {
            return $this->parseAsCommand($this->index - 1);
        }

        return $this->expression(new Refresh(['this' => $this_, 'kind' => $kind]));
    }

    protected function parseComprehension(?Expression $this_): ?Comprehension
    {
        $index = $this->index;
        $expression = $this->parseColumn();
        $position = $this->match(TokenType::COMMA) ? $this->parseColumn() : null;

        if (! $this->match(TokenType::IN)) {
            $this->retreat($index - 1);

            return null;
        }

        $iterator = $this->parseColumn();
        $condition = $this->matchTextSeq('IF') ? $this->parseDisjunction() : null;

        return $this->expression(new Comprehension([
            'this' => $this_,
            'expression' => $expression,
            'position' => $position,
            'iterator' => $iterator,
            'condition' => $condition,
        ]));
    }

    protected function parseHeredoc(): ?Heredoc
    {
        if ($this->match(TokenType::HEREDOC_STRING)) {
            return $this->expression(new Heredoc(['this' => $this->prevText()]));
        }

        if (! $this->matchTextSeq('$')) {
            return null;
        }

        $tags = ['$'];
        $tagText = null;

        if ($this->isConnected()) {
            $this->advance();
            $tags[] = strtoupper($this->prevText());
        } else {
            $this->raiseError('No closing $ found');
        }

        if (end($tags) !== '$') {
            if ($this->isConnected() && $this->matchTextSeq('$')) {
                $tagText = end($tags);
                $tags[] = '$';
            } else {
                $this->raiseError('No closing $ found');
            }
        }

        $heredocStart = $this->index;

        while ($this->curr !== 0) {
            if ($this->peekTextSeq(...$tags)) {
                $this_ = $this->findSql($heredocStart, $this->index - 1);
                $this->advance(count($tags));

                return $this->expression(new Heredoc(['this' => $this_, 'tag' => $tagText]));
            }

            $this->advance();
        }

        $this->raiseError('No closing '.implode('', $tags).' found');

        return null;
    }

    protected function matchLParen(?Expression $expression = null): void
    {
        if (! $this->match(TokenType::L_PAREN, true, $expression)) {
            $this->raiseError('Expecting (');
        }
    }

    protected function matchRParen(?Expression $expression = null): void
    {
        if (! $this->match(TokenType::R_PAREN, true, $expression)) {
            $this->raiseError('Expecting )');
        }
    }

    /**
     * @param  list<Expression|null>  $expressions
     */
    protected function replaceLambda(?Expression $node, array $expressions): ?Expression
    {
        if ($node === null) {
            return null;
        }

        $lambdaTypes = [];
        foreach ($expressions as $e) {
            if ($e !== null) {
                $lambdaTypes[$e->name()] = $e->args['to'] ?? false;
            }
        }

        foreach ($node->findAll(Column::class) as $column) {
            $parts = $column->parts();
            $typ = isset($parts[0]) && array_key_exists($parts[0]->name(), $lambdaTypes) ? $lambdaTypes[$parts[0]->name()] : null;

            if ($typ === null) {
                continue;
            }

            $dotOrId = $column->text('table') !== '' ? $column->toDot() : $column->args['this'];

            if ($typ !== false) {
                $dotOrId = $this->expression(new Cast(['this' => $dotOrId, 'to' => $typ]));
            }

            $parent = $column->parent;
            $replaced = false;

            while ($parent instanceof Dot) {
                if (! $parent->parent instanceof Dot) {
                    $parent->replace($dotOrId);
                    $replaced = true;

                    break;
                }

                $parent = $parent->parent;
            }

            if (! $replaced && ! $column->parent instanceof Dot) {
                if ($column === $node) {
                    $node = $dotOrId;
                } else {
                    $column->replace($dotOrId);
                }
            }
        }

        return $node;
    }

    protected function parseIndexedColumn(): ?Expression
    {
        return $this->parseOrdered(fn (): ?Expression => $this->parseOpclass());
    }

    protected function parseWithOperator(): ?Expression
    {
        $this_ = $this->parseIndexedColumn();

        if (! $this->match(TokenType::WITH)) {
            return $this_;
        }

        $op = $this->parseVar(anyToken: true, tokens: $this->t['RESERVED_TOKENS']);

        return $this->expression(new WithOperator(['this' => $this_, 'op' => $op]));
    }

    protected function parseNormalize(): Normalize
    {
        return $this->expression(new Normalize([
            'this' => $this->parseBitwise(),
            'form' => $this->match(TokenType::COMMA) ? $this->parseVar() : null,
        ]));
    }

    /**
     * @param  class-string<Expression>  $class
     */
    protected function parseCeilFloor(string $class): Expression
    {
        $args = $this->parseCsv(fn (): ?Expression => $this->parseLambda());

        return new $class([
            'this' => $args[0] ?? null,
            'decimals' => $args[1] ?? null,
            'to' => $this->matchTextSeq('TO') ? $this->parseVar() : null,
        ]);
    }

    protected function parseStarOps(): ?Expression
    {
        $starToken = $this->index - 1;

        if ($this->peekTextSeq('COLUMNS', '(')) {
            $this_ = $this->parseFunction();

            if ($this_ instanceof Columns) {
                $this_->set('unpack', true);
            }

            return $this_;
        }

        $index = $this->index;
        $ilike = $this->match(TokenType::ILIKE) ? $this->parseString() : null;

        if ($ilike === null) {
            // ILIKE without a string pattern is not a star filter, e.g. `* ILIKE (foo)`
            $this->retreat($index);
        }

        $star = $this->expression(new Star([
            'ilike' => $ilike,
            'except_' => $this->parseStarOp('EXCEPT', 'EXCLUDE'),
            'replace' => $this->parseStarOp('REPLACE'),
            'rename' => $this->parseStarOp('RENAME'),
        ]));
        $star->meta = ['start' => $this->starts[$starToken], 'end' => $this->ends[$starToken]];

        return $star;
    }

    protected function parseOverlay(): Overlay
    {
        $parseOverlayArg = fn (string $text): ?Expression => $this->match(TokenType::COMMA) || $this->matchTextSeq($text)
            ? $this->parseBitwise()
            : null;

        return $this->expression(new Overlay([
            'this' => $this->parseBitwise(),
            'expression' => $parseOverlayArg('PLACING'),
            'from_' => $parseOverlayArg('FROM'),
            'for_' => $parseOverlayArg('FOR'),
        ]));
    }

    /**
     * @param  class-string<Expression>  $class
     */
    protected function parseDistinctArgFunction(string $class, int $distinctIndex = 0): Expression
    {
        $isDistinct = $this->match(TokenType::DISTINCT);

        if (! $isDistinct) {
            $this->match(TokenType::ALL);
        }

        $args = [$this->parseLambda()];

        if ($this->match(TokenType::COMMA)) {
            array_push($args, ...$this->parseFunctionArgs());
        }

        $target = $args[$distinctIndex] ?? null;

        if ($isDistinct && $target !== null) {
            $args[$distinctIndex] = $this->expression(new Distinct(['expressions' => [$target]]));
        }

        return $class::fromArgList($args);
    }

    protected function identifierExpression(?int $token = null, ?bool $quoted = null): Identifier
    {
        $token ??= $this->index - 1;

        return $this->expression(new Identifier(['this' => $this->texts[$token], 'quoted' => $quoted]), $token);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    public function buildCast(bool $strict, array $args): Expression
    {
        if (! $strict) {
            $args['requires_string'] = $this->dialect->settings['TRY_CAST_REQUIRES_STRING'];

            return $this->expression(new TryCast($args));
        }

        return $this->expression(new Cast($args));
    }

    protected function parseJsonValue(): JSONValue
    {
        $this_ = $this->parseBitwise();
        $this->match(TokenType::COMMA);
        $path = $this->parseBitwise();

        $returning = $this->match(TokenType::RETURNING) ? $this->parseType() : null;

        return $this->expression(new JSONValue([
            'this' => $this_,
            'path' => $this->dialect->toJsonPath($path),
            'returning' => $returning,
            'on_condition' => $this->parseOnCondition(),
        ]));
    }

    protected function parseGroupConcat(): ?Expression
    {
        $args = $this->parseCsv(fn (): ?Expression => $this->parseLambda());

        $concatExprs = function (?Expression $node, array $exprs) use (&$args): Expression {
            if ($node instanceof Distinct && count($node->argList('expressions')) > 1) {
                $node->set('expressions', [$this->expression(new Concat([
                    'expressions' => $node->argList('expressions'),
                    'safe' => true,
                    'coalesce' => $this->dialect->settings['CONCAT_COALESCE'],
                ]))]);

                return $node;
            }

            if (count($exprs) === 1) {
                return $exprs[0];
            }

            return $this->expression(new Concat(['expressions' => $args, 'safe' => true, 'coalesce' => $this->dialect->settings['CONCAT_COALESCE']]));
        };

        if ($args !== []) {
            $last = $args[count($args) - 1];
            $order = $last instanceof Order ? $last : null;

            if ($order !== null) {
                // Order By is the last (or only) expression in the list and has consumed the 'expr' before it,
                // remove 'expr' from exp.Order and add it back to args
                $args[count($args) - 1] = $order->args['this'] ?? null;
                $order->set('this', $concatExprs($order->args['this'] ?? null, $args));
            }

            $this_ = $order ?? $concatExprs($args[0], $args);
        } else {
            $this_ = null;
        }

        $separator = $this->match(TokenType::SEPARATOR) ? $this->parseField() : null;

        return $this->expression(new GroupConcat(['this' => $this_, 'separator' => $separator]));
    }

    protected function parseInitcap(): Initcap
    {
        $expr = Initcap::fromArgList($this->parseFunctionArgs());

        // attach dialect's default delimiters
        if (($expr->args['expression'] ?? null) === null) {
            $expr->set('expression', Literal::string($this->dialect->settings['INITCAP_DEFAULT_DELIMITER_CHARS']));
        }

        return $expr;
    }

    protected function parseOperator(?Expression $this_): ?Expression
    {
        if (! $this->match(TokenType::L_PAREN)) {
            $this->retreat($this->index - 1);

            return null;
        }

        $op = '';

        while ($this->curr !== 0 && ! $this->match(TokenType::R_PAREN)) {
            $op .= $this->currText();
            $this->advance();
        }

        $comments = $this->prevComments;

        return $this->expression(new Operator(['this' => $this_, 'operator' => $op, 'expression' => $this->parseBitwise()]), null, $comments);
    }

    protected function parseDictProperty(string $this_): DictProperty
    {
        $settings = [];

        $this->matchLParen();
        $kind = $this->parseIdVar();

        if ($this->match(TokenType::L_PAREN)) {
            while (true) {
                $key = $this->parseIdVar();
                $value = $this->parseFunction() ?? $this->parsePrimaryOrVar();

                if ($key === null && $value === null) {
                    break;
                }

                $settings[] = $this->expression(new DictSubProperty(['this' => $key, 'value' => $value]));
            }

            $this->match(TokenType::R_PAREN);
        }

        $this->matchRParen();

        return $this->expression(new DictProperty(['this' => $this_, 'kind' => $kind?->args['this'], 'settings' => $settings]));
    }

    protected function parseDictRange(string $this_): DictRange
    {
        $this->matchLParen();
        $hasMin = $this->matchTextSeq('MIN');

        if ($hasMin) {
            $min = $this->parseVar() ?? $this->parsePrimary();
            $this->matchTextSeq('MAX');
            $max = $this->parseVar() ?? $this->parsePrimary();
        } else {
            $max = $this->parseVar() ?? $this->parsePrimary();
            $min = Literal::number(0);
        }

        $this->matchRParen();

        return $this->expression(new DictRange(['this' => $this_, 'min' => $min, 'max' => $max]));
    }
}
