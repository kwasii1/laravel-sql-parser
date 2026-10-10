<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Parser\Concerns;

use Kwasii\LaravelSqlParser\Expressions\Add;
use Kwasii\LaravelSqlParser\Expressions\AggFunc;
use Kwasii\LaravelSqlParser\Expressions\AtTimeZone;
use Kwasii\LaravelSqlParser\Expressions\Between;
use Kwasii\LaravelSqlParser\Expressions\BitwiseLeftShift;
use Kwasii\LaravelSqlParser\Expressions\BitwiseRightShift;
use Kwasii\LaravelSqlParser\Expressions\Boolean;
use Kwasii\LaravelSqlParser\Expressions\Bracket;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Coalesce;
use Kwasii\LaravelSqlParser\Expressions\Collate;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\Concat;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DataTypeParam;
use Kwasii\LaravelSqlParser\Expressions\Div;
use Kwasii\LaravelSqlParser\Expressions\Dot;
use Kwasii\LaravelSqlParser\Expressions\DPipe;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Escape;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Func;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\ILike;
use Kwasii\LaravelSqlParser\Expressions\In;
use Kwasii\LaravelSqlParser\Expressions\IntDiv;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\IntervalSpan;
use Kwasii\LaravelSqlParser\Expressions\Is;
use Kwasii\LaravelSqlParser\Expressions\JSON;
use Kwasii\LaravelSqlParser\Expressions\JSONExtract;
use Kwasii\LaravelSqlParser\Expressions\JSONPath;
use Kwasii\LaravelSqlParser\Expressions\JSONPathKey;
use Kwasii\LaravelSqlParser\Expressions\JSONPathRoot;
use Kwasii\LaravelSqlParser\Expressions\JSONPathSubscript;
use Kwasii\LaravelSqlParser\Expressions\JSONPathWildcard;
use Kwasii\LaravelSqlParser\Expressions\Like;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Neg;
use Kwasii\LaravelSqlParser\Expressions\Not;
use Kwasii\LaravelSqlParser\Expressions\NullExpr;
use Kwasii\LaravelSqlParser\Expressions\NullSafeEQ;
use Kwasii\LaravelSqlParser\Expressions\NullSafeNEQ;
use Kwasii\LaravelSqlParser\Expressions\Paren;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\SetOperation;
use Kwasii\LaravelSqlParser\Expressions\Subquery;
use Kwasii\LaravelSqlParser\Expressions\Tuple;
use Kwasii\LaravelSqlParser\Expressions\Values;
use Kwasii\LaravelSqlParser\Expressions\VarExpr;
use Kwasii\LaravelSqlParser\Expressions\Window;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * The expression precedence chain and its operands: assignment down to primaries, columns,
 * column operators and fields (sqlglot parser.py, from _parse_expression to _parse_field).
 */
trait ParsesExpressions
{
    /** sqlglot INTERVAL_STRING_RE (findall). */
    protected const string INTERVAL_STRING_RE = '/\s*(-?[0-9]+(?:\.[0-9]+)?)\s*([a-zA-Z]+)\s*/';

    /** sqlglot INTERVAL_DAY_TIME_RE (match, i.e. anchored at the start). */
    protected const string INTERVAL_DAY_TIME_RE = '/\A\s*-?\s*\d+(?:\.\d+)?\s+(?:-?(?:\d+:)?\d+:\d+(?:\.\d+)?|-?(?:\d+:){1,2}|:)\s*/';

    /** Used to detect alphabetical characters and +/- in timestamp literals. */
    protected const string TIME_ZONE_RE = '/:.*?[a-zA-Z\+\-]/s';

    protected function parseExpression(): ?Expression
    {
        return $this->parseAlias($this->parseAssignment());
    }

    protected function parseAssignment(): ?Expression
    {
        $assignment = $this->t['ASSIGNMENT'];
        $this_ = $this->parseDisjunction();

        if ($this_ === null && $this->next !== 0 && isset($assignment[$this->next])) {
            // This allows us to parse <non-identifier token> := <expr>
            $this_ = Builder::column($this->advanceAny(ignoreReserved: true) !== null ? $this->prevText() : '');
        }

        while ($this->matchSet($assignment)) {
            if ($this_ instanceof Column && count($this_->parts()) === 1) {
                $this_ = $this_->args['this'];
            }

            $comments = $this->prevComments;
            /** @var class-string<Expression> $class */
            $class = $assignment[$this->prev];
            $this_ = $this->expression(new $class(['this' => $this_, 'expression' => $this->parseAssignment()]), null, $comments);
        }

        return $this_;
    }

    protected function parseDisjunction(): ?Expression
    {
        $disjunction = $this->t['DISJUNCTION'];

        if ($this->parseOperandOnly($left)) {
            return $left;
        }

        $this_ = $this->parseConjunction($left);

        while ($this->curr !== 0 && isset($disjunction[$this->curr])) {
            $this->advance();
            $comments = $this->prevComments;
            /** @var class-string<Expression> $class */
            $class = $disjunction[$this->prev];
            $right = $this->parseOperandOnly($operand) ? $operand : $this->parseConjunction($operand);
            $this_ = $this->expression(new $class(['this' => $this_, 'expression' => $right]), null, $comments);
        }

        return $this_;
    }

    /**
     * Fast path through the operator levels: parse one operand, and report true when the next
     * token can't continue any level between disjunction and factor (a comma, AS, FROM, a closing
     * paren, ...), so the caller can skip descending through them. Otherwise the operand is to be
     * handed down as the left side, so nothing is parsed twice.
     *
     * @param-out Expression|null $operand
     */
    protected function parseOperandOnly(?Expression &$operand): bool
    {
        $start = $this->index;
        $operand = $this->parseAtTimeZone($this->parseFactorOperand());

        if ($this->curr === 0 || ! isset($this->operandContinuations[$this->curr])) {
            return true;
        }

        if ($operand === null) {
            $this->retreat($start);
        }

        return false;
    }

    protected function parseConjunction(?Expression $left = null): ?Expression
    {
        $conjunction = $this->t['CONJUNCTION'];
        $this_ = $this->parseEquality($left);

        while ($this->curr !== 0 && isset($conjunction[$this->curr])) {
            $this->advance();
            $comments = $this->prevComments;
            /** @var class-string<Expression> $class */
            $class = $conjunction[$this->prev];
            $right = $this->parseOperandOnly($operand) ? $operand : $this->parseEquality($operand);
            $this_ = $this->expression(new $class(['this' => $this_, 'expression' => $right]), null, $comments);
        }

        return $this_;
    }

    protected function parseEquality(?Expression $left = null): ?Expression
    {
        $equality = $this->t['EQUALITY'];
        $this_ = $this->parseComparison($left);

        while ($this->curr !== 0 && isset($equality[$this->curr])) {
            $this->advance();
            $comments = $this->prevComments;
            /** @var class-string<Expression> $class */
            $class = $equality[$this->prev];
            $right = $this->parseOperandOnly($operand) ? $operand : $this->parseComparison($operand);
            $this_ = $this->expression(new $class(['this' => $this_, 'expression' => $right]), null, $comments);
        }

        return $this_;
    }

    protected function parseComparison(?Expression $left = null): ?Expression
    {
        $comparison = $this->t['COMPARISON'];
        $this_ = $left === null ? $this->parseRange() : $this->parseRange($this->parseBitwise($left));

        while ($this->curr !== 0 && isset($comparison[$this->curr])) {
            $this->advance();
            $comments = $this->prevComments;
            /** @var class-string<Expression> $class */
            $class = $comparison[$this->prev];
            $this_ = $this->expression(new $class(['this' => $this_, 'expression' => $this->parseRange()]), null, $comments);
        }

        return $this_;
    }

    protected function parseRange(?Expression $this_ = null): ?Expression
    {
        $this_ ??= $this->parseBitwise();
        $rangeParsers = $this->t['RANGE_PARSERS'];

        while (true) {
            $negate = $this->match(TokenType::NOT);

            if ($this->matchSet($rangeParsers)) {
                $expression = $this->callHandler($rangeParsers[$this->prev], $this_);

                if ($expression === null) {
                    return $this_;
                }

                $this_ = $expression;
            } elseif ($this->match(TokenType::ISNULL) || ($negate && $this->match(TokenType::NULL))) {
                $this_ = $this->expression(new Is(['this' => $this_, 'expression' => new NullExpr]));
            } elseif ($this->match(TokenType::NOTNULL)) {
                // Postgres supports ISNULL and NOTNULL for conditions.
                // https://blog.andreiavram.ro/postgresql-null-composite-type/
                if ($this->dialect->settings['NORMALIZE_NOT_NULL'] === true) {
                    $this_ = $this->expression(new Is(['this' => $this_, 'expression' => new NullExpr]));
                    $this_ = $this->expression(new Not(['this' => $this_]));
                } else {
                    $this_ = $this->expression(new Is(['this' => $this_, 'expression' => new NullExpr, 'negate' => true]));
                }
            } else {
                if ($negate) {
                    $this->retreat($this->index - 1);
                }

                break;
            }

            if ($negate) {
                $this_ = $this->negateRange($this_);

                if ($this->curr !== 0 && ($this->curr === TokenType::NOT || isset($rangeParsers[$this->curr]))) {
                    $this_ = $this->expression(new Paren(['this' => $this_]));
                }
            }
        }

        return $this_;
    }

    protected function negateRange(?Expression $this_ = null): ?Expression
    {
        if ($this_ === null) {
            return null;
        }

        $expression = $this_ instanceof Escape ? $this_->args['this'] : $this_;

        if ($expression instanceof Like || $expression instanceof ILike) {
            $expression->set('negate', true);

            return $this_;
        }

        return $this->expression(new Not(['this' => $this_]));
    }

    protected function parseIs(?Expression $this_): ?Expression
    {
        $index = $this->index - 1;
        $negate = $this->match(TokenType::NOT);

        if ($this->matchTextSeq('DISTINCT', 'FROM')) {
            $class = $negate ? NullSafeEQ::class : NullSafeNEQ::class;

            return $this->expression(new $class(['this' => $this_, 'expression' => $this->parseBitwise()]));
        }

        if ($this->match(TokenType::JSON)) {
            $kind = $this->matchTexts($this->t['IS_JSON_PREDICATE_KIND']) ? strtoupper($this->prevText()) : null;

            if ($this->matchTextSeq('WITH')) {
                $with = true;
            } elseif ($this->matchTextSeq('WITHOUT')) {
                $with = false;
            } else {
                $with = null;
            }

            $unique = $this->match(TokenType::UNIQUE);
            $this->matchTextSeq('KEYS');
            $expression = $this->expression(new JSON(['this' => $kind, 'with_' => $with, 'unique' => $unique]));
        } else {
            $expression = $this->parseNull() ?? $this->parseBitwise();

            if ($expression === null) {
                $this->retreat($index);

                return null;
            }
        }

        if ($negate && $expression instanceof NullExpr && $this->dialect->settings['NORMALIZE_NOT_NULL'] !== true) {
            $this_ = $this->expression(new Is(['this' => $this_, 'expression' => $expression, 'negate' => true]));
        } else {
            $this_ = $this->expression(new Is(['this' => $this_, 'expression' => $expression]));
            $this_ = $negate ? $this->expression(new Not(['this' => $this_])) : $this_;
        }

        return $this->parseColumnOps($this_);
    }

    protected function parseIn(?Expression $this_, bool $alias = false): In
    {
        $unnest = $this->parseUnnest(withAlias: false);

        if ($unnest !== null) {
            $this_ = $this->expression(new In(['this' => $this_, 'unnest' => $unnest]));
        } elseif ($this->matchSet([TokenType::L_PAREN => true, TokenType::L_BRACKET => true])) {
            $matchedLParen = $this->prev === TokenType::L_PAREN;
            $expressions = $this->parseCsv(fn (): ?Expression => $this->parseSelectOrExpression(alias: $alias));

            if (count($expressions) === 1 && $expressions[0] instanceof Query) {
                $query = $this->parseQueryModifiers($expressions[0]);
                $this_ = $this->expression(new In(['this' => $this_, 'query' => $query instanceof Query ? $query->subquery(copy: false) : $query]));
            } else {
                $this_ = $this->expression(new In(['this' => $this_, 'expressions' => $expressions]));
            }

            if ($matchedLParen) {
                $this->matchRParen($this_);
            } elseif (! $this->match(TokenType::R_BRACKET, true, $this_)) {
                $this->raiseError('Expecting ]');
            }
        } else {
            $this_ = $this->expression(new In(['this' => $this_, 'field' => $this->parseColumn()]));
        }

        return $this_;
    }

    protected function parseBetween(?Expression $this_): Between
    {
        $symmetric = null;

        if ($this->matchTextSeq('SYMMETRIC')) {
            $symmetric = true;
        } elseif ($this->matchTextSeq('ASYMMETRIC')) {
            $symmetric = false;
        }

        $low = $this->parseBitwise();
        $this->match(TokenType::AND);
        $high = $this->parseBitwise();

        return $this->expression(new Between(['this' => $this_, 'low' => $low, 'high' => $high, 'symmetric' => $symmetric]));
    }

    protected function parseEscape(?Expression $this_): ?Expression
    {
        if (! $this->match(TokenType::ESCAPE)) {
            return $this_;
        }

        return $this->expression(new Escape(['this' => $this_, 'expression' => $this->parseString() ?? $this->parseNull()]));
    }

    protected function parseIntervalSpan(Expression $this_, bool $parseFunctionUnit = true): Interval
    {
        // handle day-time format interval span with omitted units:
        //   INTERVAL '<number days> hh[:][mm[:ss[.ff]]]' <maybe `unit TO unit`>
        $intervalSpanUnitsOmitted = null;

        if (
            $this_->isString()
            && $this->t['SUPPORTS_OMITTED_INTERVAL_SPAN_UNIT'] === true
            && preg_match(self::INTERVAL_DAY_TIME_RE, $this_->name()) === 1
        ) {
            $index = $this->index;

            // Var "TO" Var
            $firstUnit = $this->parseVar(anyToken: true, upper: true);
            $secondUnit = null;

            if ($firstUnit !== null && $this->matchTextSeq('TO')) {
                $secondUnit = $this->parseVar(anyToken: true, upper: true);
            }

            $intervalSpanUnitsOmitted = ! ($firstUnit !== null && $secondUnit !== null);

            $this->retreat($index);
        }

        $unitIndex = $this->index;

        if ($intervalSpanUnitsOmitted === true) {
            $unit = null;
        } else {
            // Only attempt to parse a unit if the current token can actually be one, so that a
            // trailing operator isn't swallowed, e.g. INTERVAL '1 day' AND (x)
            $isUnit = $this->curr !== 0 && (
                $this->curr === TokenType::VAR
                || isset($this->dialect->settings['VALID_INTERVAL_UNITS'][strtoupper($this->currText())])
            );
            $unit = $parseFunctionUnit && $isUnit ? $this->parseFunction() : null;

            if ($unit === null && $isUnit) {
                $unit = $this->parseVar(anyToken: true, upper: true);
            }
        }

        // Most dialects support, e.g., the form INTERVAL '5' day, thus we try to parse
        // each INTERVAL expression into this canonical form so it's easy to transpile
        if ($this_->isNumber()) {
            // A negated number (Neg) keeps its sign, like sqlglot's to_py()
            $text = $this_ instanceof Neg ? '-'.($this_->argExpr('this') ?? $this_)->name() : $this_->name();
            $value = Literal::pythonNumberString($text);

            if ($value === null) {
                $this->raiseError("Invalid numeric interval literal: '{$this_->name()}'");
            } else {
                $this_ = Literal::string($value);
            }
        } elseif ($this_->isString()) {
            preg_match_all(self::INTERVAL_STRING_RE, $this_->name(), $parts, PREG_SET_ORDER);

            if ($parts !== [] && $unit !== null) {
                // Unconsume the eagerly-parsed unit, since the real unit was part of the string
                $unit = null;
                $this->retreat($unitIndex);
            }

            if (count($parts) === 1) {
                $this_ = Literal::string($parts[0][1]);
                $unit = $this->expression(new VarExpr(['this' => strtoupper($parts[0][2])]));
            }
        }

        if ($this->t['INTERVAL_SPANS'] === true && $this->matchTextSeq('TO')) {
            $unit = $this->expression(new IntervalSpan([
                'this' => $unit,
                'expression' => $this->parseFunction() ?? $this->parseVar(anyToken: true, upper: true),
            ]));
        }

        return $this->expression(new Interval(['this' => $this_, 'unit' => $unit]));
    }

    protected function parseInterval(bool $requireInterval = true, bool $parseFunctionUnit = true): ?Expression
    {
        $index = $this->index;

        if (! $this->match(TokenType::INTERVAL) && $requireInterval) {
            return null;
        }

        if ($this->match(TokenType::STRING, false)) {
            $this_ = $this->parsePrimary();
        } else {
            $this_ = $this->parseTerm();
        }

        if (
            $this_ === null
            || (
                $this_ instanceof Column
                && $this_->text('table') === ''
                && ! ($this_->argExpr('this')?->flag('quoted') ?? false)
                && $this->curr !== 0
                && ! isset($this->dialect->settings['VALID_INTERVAL_UNITS'][strtoupper($this->currText())])
            )
        ) {
            $this->retreat($index);

            return null;
        }

        $interval = $this->parseIntervalSpan($this_, parseFunctionUnit: $parseFunctionUnit);

        $index = $this->index;
        $this->match(TokenType::PLUS);

        // Convert INTERVAL 'val_1' unit_1 [+] ... [+] 'val_n' unit_n into a sum of intervals
        if ($this->matchSet([TokenType::STRING => true, TokenType::NUMBER => true], false)) {
            return $this->expression(new Add([
                'this' => $interval,
                'expression' => $this->parseInterval(false, parseFunctionUnit: $parseFunctionUnit),
            ]));
        }

        $this->retreat($index);

        return $interval;
    }

    /**
     * @param  Expression|null  $left  an already parsed first operand (see parseDisjunction())
     */
    protected function parseBitwise(?Expression $left = null): ?Expression
    {
        $bitwise = $this->t['BITWISE'];
        $this_ = $this->parseTerm(left: $left);

        while (true) {
            if ($this->matchSet($bitwise)) {
                /** @var class-string<Expression> $class */
                $class = $bitwise[$this->prev];
                $this_ = $this->expression(new $class(['this' => $this_, 'expression' => $this->parseTerm()]));
            } elseif ($this->dialect->settings['DPIPE_IS_STRING_CONCAT'] === true && $this->match(TokenType::DPIPE)) {
                $this_ = $this->expression(new DPipe([
                    'this' => $this_,
                    'expression' => $this->parseTerm(),
                    'safe' => $this->dialect->settings['STRICT_STRING_CONCAT'] !== true,
                ]));
            } elseif ($this->match(TokenType::DQMARK)) {
                $term = $this->parseTerm();
                $this_ = $this->expression(new Coalesce(['this' => $this_, 'expressions' => $term === null ? [] : [$term]]));
            } elseif ($this->matchPair(TokenType::LT, TokenType::LT)) {
                $this_ = $this->expression(new BitwiseLeftShift(['this' => $this_, 'expression' => $this->parseTerm()]));
            } elseif ($this->matchPair(TokenType::GT, TokenType::GT)) {
                $this_ = $this->expression(new BitwiseRightShift(['this' => $this_, 'expression' => $this->parseTerm()]));
            } elseif ($this->t['JSON_OPERATORS'] !== [] && $this->matchSet($this->t['JSON_OPERATORS'])) {
                $handler = $this->t['JSON_OPERATORS'][$this->prev];
                $this_ = $this->callHandler($handler, $this_, $this->parseTerm());
            } else {
                break;
            }
        }

        return $this_;
    }

    protected function parseTerm(bool $parseMod = true, ?Expression $left = null): ?Expression
    {
        $term = $this->t['TERM'];
        $this_ = $this->parseFactor(parseMod: $parseMod, left: $left);

        while ($this->curr !== 0 && isset($term[$this->curr])) {
            $this->advance();
            /** @var class-string<Expression> $class */
            $class = $term[$this->prev];
            $comments = $this->prevComments;
            $expression = $this->parseFactor(parseMod: $parseMod);

            $this_ = $this->expression(new $class(['this' => $this_, 'expression' => $expression]), null, $comments);

            if ($this_ instanceof Collate) {
                $this->normalizeCollate($this_);
            }
        }

        return $this_;
    }

    protected function normalizeCollate(Collate $collate): void
    {
        $expr = $collate->args['expression'] ?? null;

        // Preserve collations such as pg_catalog."default" (Postgres) as columns, otherwise
        // fallback to Identifier / Var
        if ($expr instanceof Column && count($expr->parts()) === 1) {
            $ident = $expr->args['this'];

            if ($ident instanceof Identifier) {
                $collate->set('expression', $ident->flag('quoted') ? $ident : Builder::var($ident->name()));
            }
        }
    }

    protected function parseFactor(bool $parseMod = true, ?Expression $left = null): ?Expression
    {
        $factor = $this->t['FACTOR'];
        $this_ = $left ?? $this->parseAtTimeZone($this->parseFactorOperand());

        while ($this->curr !== 0 && isset($factor[$this->curr])) {
            if (! $parseMod && $this->curr === TokenType::MOD) {
                break;
            }

            $this->advance();
            /** @var class-string<Expression> $class */
            $class = $factor[$this->prev];
            $comments = $this->prevComments;
            $expression = $this->parseFactorOperand();

            if ($expression === null && $class === IntDiv::class && ctype_alpha($this->prevText())) {
                $this->retreat($this->index - 1);

                return $this_;
            }

            $this_ = $this->expression(new $class(['this' => $this_, 'expression' => $expression]), null, $comments);

            if ($this_ instanceof Div) {
                $this_->set('typed', $this->dialect->settings['TYPED_DIVISION']);
                $this_->set('safe', $this->dialect->settings['SAFE_DIVISION']);
            }
        }

        return $this_;
    }

    protected function parseFactorOperand(): ?Expression
    {
        return $this->t['EXPONENT'] !== [] ? $this->parseExponent() : $this->parseUnary();
    }

    protected function parseExponent(): ?Expression
    {
        $exponent = $this->t['EXPONENT'];
        $this_ = $this->parseUnary();

        while ($this->matchSet($exponent)) {
            $comments = $this->prevComments;
            /** @var class-string<Expression> $class */
            $class = $exponent[$this->prev];
            $this_ = $this->expression(new $class(['this' => $this_, 'expression' => $this->parseUnary()]), null, $comments);
        }

        return $this_;
    }

    protected function parseUnary(): ?Expression
    {
        if ($this->curr !== 0 && isset($this->t['UNARY_PARSERS'][$this->curr])) {
            $this->advance();

            return $this->callHandler($this->t['UNARY_PARSERS'][$this->prev]);
        }

        return $this->parseType();
    }

    protected function parseType(bool $parseInterval = true, bool $fallbackToIdentifier = false): ?Expression
    {
        if (! $fallbackToIdentifier && ($atom = $this->parseAtom()) !== null) {
            return $atom;
        }

        if ($parseInterval && ($interval = $this->parseInterval()) !== null) {
            return $this->parseColumnOps($interval);
        }

        $index = $this->index;
        $dataType = $this->parseTypes(checkFunc: true, allowIdentifiers: false);

        // parse_types() returns a Cast if we parsed BQ's inline constructor <type>(<values>) e.g.
        // STRUCT<a INT, b STRING>(1, 'foo'), which is canonicalized to CAST(<values> AS <type>)
        if ($dataType instanceof Cast) {
            // This constructor can contain ops directly after it, for instance struct unnesting:
            // STRUCT<a INT, b STRING>(1, 'foo').* --> CAST(STRUCT(1, 'foo') AS STRUCT<a iNT, b STRING).*
            return $this->parseColumnOps($dataType);
        }

        if ($dataType !== null) {
            $index2 = $this->index;
            $this_ = $this->parsePrimary();

            if ($this_ instanceof Literal) {
                $literal = $this_->name();
                $this_ = $this->parseColumnOps($this_);

                $typeKey = $dataType->args['this'] ?? null;
                $parser = is_string($typeKey) ? ($this->t['TYPE_LITERAL_PARSERS'][$typeKey] ?? null) : null;

                if ($parser !== null) {
                    return $this->callHandler($parser, $this_, $dataType);
                }

                if ($this->t['ZONE_AWARE_TIMESTAMP_CONSTRUCTOR'] === true && preg_match(self::TIME_ZONE_RE, $literal) === 1) {
                    if ($dataType->isType(DType::TIMESTAMP)) {
                        $dataType = new DataType(['this' => DType::TIMESTAMPTZ]);
                    } elseif ($dataType->isType(DType::TIME)) {
                        $dataType = new DataType(['this' => DType::TIMETZ]);
                    }
                }

                return $this->expression(new Cast(['this' => $this_, 'to' => $dataType]));
            }

            // The expressions arg gets set by the parser when we have something like DECIMAL(38, 0)
            // in the input SQL. In that case, we'll produce these tokens: DECIMAL ( 38 , 0 )
            //
            // If the index difference here is greater than 1, that means the parser itself must have
            // consumed additional tokens such as the DECIMAL scale and precision in the above example.
            //
            // If it's not greater than 1, then it must be 1, because we've consumed at least the type
            // keyword, meaning that the expressions arg of the DataType must have gotten set by a
            // callable in the TYPE_CONVERTERS mapping. For example, Snowflake converts DECIMAL to
            // DECIMAL(38, 0)) in order to facilitate the data type's transpilation.
            //
            // In these cases, we don't really want to return the converted type, but instead retreat
            // and try to parse a Column or Identifier in the section below.
            if ($dataType->argList('expressions') !== [] && $index2 - $index > 1) {
                $this->retreat($index2);

                return $this->parseColumnOps($dataType);
            }

            $this->retreat($index);
        }

        if ($fallbackToIdentifier) {
            return $this->parseIdVar();
        }

        return $this->parseColumn();
    }

    protected function parseTypeSize(): ?DataTypeParam
    {
        $this_ = $this->parseType();

        if ($this_ === null) {
            return null;
        }

        if ($this_ instanceof Column && $this_->text('table') === '') {
            $this_ = Builder::var(strtoupper($this_->name()));
        }

        return $this->expression(new DataTypeParam(['this' => $this_, 'expression' => $this->parseVar(anyToken: true)]));
    }

    protected function parseUserDefinedType(Identifier $identifier): ?Expression
    {
        $typeName = $identifier->name();

        while ($this->match(TokenType::DOT)) {
            $typeName .= '.'.($this->advanceAny() !== null ? $this->prevText() : 'None');
        }

        return DataType::fromStr($typeName, $this->dialect, udt: true);
    }

    protected function parseAtTimeZone(?Expression $this_): ?Expression
    {
        while ($this->matchTextSeq('AT', 'TIME', 'ZONE')) {
            $this_ = $this->expression(new AtTimeZone(['this' => $this_, 'zone' => $this->parseUnary()]));
        }

        return $this_;
    }

    protected function parseAtom(): ?Expression
    {
        if ($this->curr !== 0 && isset($this->t['IDENTIFIER_TOKENS'][$this->curr]) && ($column = $this->parseColumn()) !== null) {
            return $column;
        }

        $tokenType = $this->curr;
        $primaryParser = $tokenType !== 0 ? ($this->t['PRIMARY_PARSERS'][$tokenType] ?? null) : null;

        if ($primaryParser === null) {
            return null;
        }

        $nextType = $this->next;

        if (
            array_key_exists($nextType, $this->t['COLUMN_OPERATORS'])
            || isset($this->t['COLUMN_POSTFIX_TOKENS'][$nextType])
            || ($tokenType === TokenType::STRING && $nextType === TokenType::STRING)
        ) {
            return null;
        }

        $token = $this->index;
        $this->advance();

        return $this->callHandler($primaryParser, $token);
    }

    protected function parseColumn(): ?Expression
    {
        $column = $this->parseColumnPartsFast();

        if ($column === null) {
            $this_ = $this->parseColumnReference();

            if ($this_ === null) {
                $this_ = $this->parseBracket($this_);
            }

            $column = $this_ !== null ? $this->parseColumnOps($this_) : $this_;
        }

        if ($column !== null) {
            if ($this->dialect->settings['SUPPORTS_COLUMN_JOIN_MARKS'] === true) {
                $column->set('join_mark', $this->match(TokenType::JOIN_MARKER));
            }

            if ($this->t['COLON_IS_VARIANT_EXTRACT'] === true) {
                $column = $this->parseColonAsVariantExtract($column);
            }
        }

        return $column;
    }

    /**
     * Fast path for simple column and dot references (a, a.b, ...).
     *
     * Greedily consumes VAR/IDENTIFIER tokens separated by DOTs, then checks that nothing complex
     * follows. If it does, retreats and returns null so the slow path can handle it. For >4 parts,
     * wraps in Dot nodes.
     */
    protected function parseColumnPartsFast(): ?Expression
    {
        $index = $this->index;
        $parts = null;
        $allComments = null;
        $identifierTokens = $this->t['IDENTIFIER_TOKENS'];
        $columnOperators = $this->t['COLUMN_OPERATORS'];
        $postfixTokens = $this->t['COLUMN_POSTFIX_TOKENS'];

        while ($this->curr !== 0 && isset($identifierTokens[$this->curr])) {
            $this->advance();
            $token = $this->index - 1;
            $comments = $this->prevComments;

            if ($parts === null && isset($this->t['NO_PAREN_FUNCTION_PARSERS'][strtoupper($this->texts[$token])])) {
                $this->retreat($index);

                return null;
            }

            $hasDot = $this->match(TokenType::DOT);
            $currType = $this->curr;

            if (! $hasDot) {
                if (array_key_exists($currType, $columnOperators) || isset($postfixTokens[$currType])) {
                    $this->retreat($index);

                    return null;
                }
            } elseif (! isset($identifierTokens[$currType])) {
                $this->retreat($index);

                return null;
            }

            $parts ??= [];

            if ($comments !== []) {
                $allComments ??= [];
                array_push($allComments, ...$comments);
                $this->prevComments = [];
            }

            $parts[] = $this->expression(new Identifier([
                'this' => $this->texts[$token],
                'quoted' => $this->types[$token] === TokenType::IDENTIFIER,
            ]), $token);

            if (! $hasDot) {
                break;
            }
        }

        if ($parts === null) {
            return null;
        }

        $n = count($parts);

        if ($n === 1) {
            $column = new Column(['this' => $parts[0]]);
        } elseif ($n === 2) {
            $column = new Column(['this' => $parts[1], 'table' => $parts[0]]);
        } elseif ($n === 3) {
            $column = new Column(['this' => $parts[2], 'table' => $parts[1], 'db' => $parts[0]]);
        } else {
            $column = new Column(['this' => $parts[3], 'table' => $parts[2], 'db' => $parts[1], 'catalog' => $parts[0]]);

            for ($i = 4; $i < $n; $i++) {
                $column = new Dot(['this' => $column, 'expression' => $parts[$i]]);
            }
        }

        if ($allComments !== null) {
            $column->addComments($allComments);
        }

        return $column;
    }

    protected function parseColumnReference(): ?Expression
    {
        $this_ = $this->parseField();

        if (
            $this_ === null
            && $this->match(TokenType::VALUES, false)
            && $this->t['VALUES_FOLLOWED_BY_PAREN'] === true
            && $this->next !== TokenType::L_PAREN
        ) {
            $this_ = $this->parseIdVar();
        }

        if ($this_ instanceof Identifier) {
            // We bubble up comments from the Identifier to the Column
            $this_ = $this->expression(new Column(['this' => $this_]), null, $this_->popComments());
        }

        return $this_;
    }

    /**
     * @param  list<Expression>  $pathParts
     * @return array{0: Expression|null, 1: list<Expression>}
     */
    protected function buildJsonExtract(?Expression $this_, array $pathParts): array
    {
        if (count($pathParts) > 1) {
            $this_ = $this->expression(new JSONExtract([
                'this' => $this_,
                'expression' => new JSONPath(['expressions' => $pathParts]),
                'variant_extract' => true,
                'requires_json' => $this->t['JSON_EXTRACT_REQUIRES_JSON_EXPRESSION'],
            ]));
            $pathParts = [new JSONPathRoot];
        }

        return [$this_, $pathParts];
    }

    protected function parseColonAsVariantExtract(?Expression $this_): ?Expression
    {
        $pathParts = [new JSONPathRoot];

        while ($this->match(TokenType::COLON)) {
            if ($this->t['COLON_CHAIN_IS_SINGLE_EXTRACT'] !== true) {
                [$this_, $pathParts] = $this->buildJsonExtract($this_, $pathParts);
            }

            $key = $this->parseIdVar(anyToken: true, tokens: [TokenType::SELECT => true]);

            if ($key !== null) {
                $quoted = $key instanceof Identifier && $key->flag('quoted');
                $pathParts[] = new JSONPathKey(['this' => $key->name(), 'quoted' => $quoted]);
            }

            while (true) {
                if ($this->match(TokenType::DOT)) {
                    $nextKey = $this->parseIdVar(anyToken: true, tokens: [TokenType::SELECT => true]);

                    if ($nextKey !== null) {
                        $quoted = $nextKey instanceof Identifier && $nextKey->flag('quoted');
                        $pathParts[] = new JSONPathKey(['this' => $nextKey->name(), 'quoted' => $quoted]);
                    }
                } elseif ($this->match(TokenType::L_BRACKET)) {
                    $bracketExpr = $this->parseBracketKeyValue();

                    if (! $this->match(TokenType::R_BRACKET)) {
                        $this->raiseError('Expected ]');
                    }

                    if ($bracketExpr !== null) {
                        if ($bracketExpr->isString()) {
                            $pathParts[] = new JSONPathKey(['this' => $bracketExpr->name(), 'quoted' => true]);
                        } elseif ($bracketExpr->isStar()) {
                            $pathParts[] = new JSONPathSubscript(['this' => new JSONPathWildcard]);
                        } elseif ($bracketExpr->isNumber()) {
                            $pathParts[] = new JSONPathSubscript(['this' => $bracketExpr->toPhp()]);
                        } else {
                            [$this_, $pathParts] = $this->buildJsonExtract($this_, $pathParts);

                            $this_ = $this->expression(new Bracket(['this' => $this_, 'expressions' => [$bracketExpr], 'json_access' => true]));
                        }
                    }
                } elseif ($this->match(TokenType::DCOLON)) {
                    [$this_, $pathParts] = $this->buildJsonExtract($this_, $pathParts);

                    $castType = $this->parseTypes();

                    if ($castType !== null) {
                        $this_ = $this->expression(new Cast(['this' => $this_, 'to' => $castType]));
                    } else {
                        $this->raiseError("Expected type after '::'");
                    }
                } else {
                    break;
                }
            }
        }

        [$this_] = $this->buildJsonExtract($this_, $pathParts);

        return $this_;
    }

    protected function parseDcolon(): ?Expression
    {
        return $this->parseTypes();
    }

    protected function parseColumnOps(?Expression $this_): ?Expression
    {
        $brackets = $this->t['BRACKETS'];

        while ($this->curr !== 0 && isset($brackets[$this->curr])) {
            $this_ = $this->parseBracket($this_);
        }

        $columnOperators = $this->t['COLUMN_OPERATORS'];
        $castColumnOperators = $this->t['CAST_COLUMN_OPERATORS'];

        while ($this->curr !== 0) {
            $opToken = $this->curr;

            if (! array_key_exists($opToken, $columnOperators)) {
                break;
            }

            $op = $columnOperators[$opToken];
            $this->advance();

            if (isset($castColumnOperators[$opToken])) {
                $field = $this->parseDcolon();

                if ($field === null) {
                    $this->raiseError('Expected type');
                }
            } elseif ($op !== null && $this->curr !== 0) {
                $field = $this->parseColumnReference() ?? $this->parseBitwise();

                if ($field instanceof Column && $this->match(TokenType::DOT, false)) {
                    $field = $this->parseColumnOps($field);
                }
            } else {
                $dot = $this->isConnected() && $this->prev === TokenType::DOT;
                $field = $this->parseField(anyToken: true, anonymousFunc: true);

                // In t.true, t.null we should produce an Identifier node
                if ($dot && ($field instanceof NullExpr || $field instanceof Boolean)) {
                    $field = $this->expression(new Identifier(['this' => $this->prevText()]), null, $field->comments);
                }
            }

            // Function calls can be qualified, e.g., x.y.FOO()
            // This converts the final AST to a series of Dots leading to the function call
            // https://cloud.google.com/bigquery/docs/reference/standard-sql/functions-reference#function_call_rules
            if (($field instanceof Func || $field instanceof Window) && $this_ !== null) {
                $this_ = $this_->transform(static fn (Expression $n): Expression => $n instanceof Column ? $n->toDot(includeDots: false) : $n);
            }

            if ($op !== null) {
                $this_ = $this->callHandler($op, $this_, $field);
            } elseif ($this_ instanceof Column && ! $this_->flag('catalog')) {
                $this_ = $this->expression(new Column([
                    'this' => $field,
                    'table' => $this_->args['this'] ?? null,
                    'db' => $this_->args['table'] ?? null,
                    'catalog' => $this_->args['db'] ?? null,
                ]), null, $this_->comments);
            } elseif ($field instanceof Window) {
                // Move the exp.Dot's to the window's function
                $windowFunc = $this->expression(new Dot(['this' => $this_, 'expression' => $field->args['this'] ?? null]));
                $field->set('this', $windowFunc);
                $this_ = $field;
            } else {
                $this_ = $this->expression(new Dot(['this' => $this_, 'expression' => $field]));
            }

            if ($field !== null && $field->comments !== null && $field->comments !== []) {
                $this_?->addComments($field->popComments());
            }

            $this_ = $this->parseBracket($this_);
        }

        return $this_;
    }

    protected function parseParen(): ?Expression
    {
        if (! $this->match(TokenType::L_PAREN)) {
            return null;
        }

        $comments = $this->prevComments;
        $query = $this->parseSelect();

        if ($query !== null) {
            $expressions = [$query];
        } else {
            $expressions = $this->parseExpressions();
        }

        $this_ = $expressions[0] ?? null;

        if ($this_ === null && $this->match(TokenType::R_PAREN, false)) {
            $this_ = $this->expression(new Tuple);
        } elseif (count($expressions) > 1 || $this->prev === TokenType::COMMA) {
            $this_ = $this->expression(new Tuple(['expressions' => $expressions]));
        } elseif ($this_ instanceof Select || $this_ instanceof SetOperation) {
            $this_ = $this->parseSubquery(this_: $this_, parseAlias: false);
        } elseif ($this_ instanceof Subquery || $this_ instanceof Values) {
            $this_ = $this->parseSubquery(
                this_: $this->parseQueryModifiers($this->parseSetOperations($this_)),
                parseAlias: false,
            );
        } else {
            $this_ = $this->expression(new Paren(['this' => $this_]));
        }

        $this_?->addComments($comments);

        $this->matchRParen($this_);

        if ($this_ instanceof Paren && ($this_->args['this'] ?? null) instanceof AggFunc) {
            return $this->parseWindow($this_);
        }

        return $this_;
    }

    protected function parsePrimary(): ?Expression
    {
        if ($this->matchSet($this->t['PRIMARY_PARSERS'])) {
            $tokenType = $this->prev;
            $primary = $this->callHandler($this->t['PRIMARY_PARSERS'][$tokenType], $this->index - 1);

            if ($tokenType === TokenType::STRING) {
                $expressions = [$primary];

                while ($this->match(TokenType::STRING, false)) {
                    if ($this->isConnected() && $this->t['ADJACENT_STRINGS_CANNOT_BE_CONNECTED'] === true) {
                        $this->raiseError('Adjacent string literals need to be separated by whitespace or comments');
                    }

                    $this->advance();
                    $expressions[] = Literal::string($this->prevText());
                }

                if (count($expressions) > 1) {
                    return $this->expression(new Concat(['expressions' => $expressions, 'coalesce' => $this->dialect->settings['CONCAT_COALESCE']]));
                }
            }

            return $primary;
        }

        if ($this->matchPair(TokenType::DOT, TokenType::NUMBER)) {
            return Literal::number('0.'.$this->prevText());
        }

        return $this->parseParen();
    }

    /**
     * @param  array<int, true>|null  $tokens
     */
    protected function parseField(bool $anyToken = false, ?array $tokens = null, bool $anonymousFunc = false): ?Expression
    {
        $afterDot = $this->t['SUPPORTS_DIGIT_PREFIXED_FIELD_NAMES'] === true && $this->prev === TokenType::DOT;

        if ($anonymousFunc) {
            $field = $this->parseFunction(anonymous: $anonymousFunc, anyToken: $anyToken) ?? $this->parsePrimary();
        } else {
            $field = $this->parsePrimary() ?? $this->parseFunction(anonymous: $anonymousFunc, anyToken: $anyToken);
        }

        $field ??= $this->parseIdVar(anyToken: $anyToken, tokens: $tokens);

        if ($afterDot && $field instanceof Literal && $field->isNumber()) {
            $name = $field->name();

            if ($this->isConnected() && $this->parseVar(anyToken: true) !== null) {
                $name .= $this->prevText();
            }

            $identifier = new Identifier(['this' => $name, 'quoted' => true]);
            $identifier->meta = $field->meta;
            $field = $identifier;
        }

        return $field;
    }
}
