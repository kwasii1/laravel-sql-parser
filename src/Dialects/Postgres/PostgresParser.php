<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Postgres;

use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayPrepend;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseNot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\ComputedColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\ExplodingGenerateSeries;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Extract;
use LaravelSqlParser\LaravelSqlParser\Expressions\Getbit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\InOutColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\IntDiv;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONArrayAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONBExists;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONObjectAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Length;
use LaravelSqlParser\LaravelSqlParser\Expressions\Levenshtein;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\MatchAgainst;
use LaravelSqlParser\LaravelSqlParser\Expressions\Placeholder;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpReplace;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetConfigProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\SHA2;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\UnixToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\Uuid;
use LaravelSqlParser\LaravelSqlParser\Expressions\Variadic;
use LaravelSqlParser\LaravelSqlParser\Expressions\WidthBucket;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * PostgreSQL parser (sqlglot parsers/postgres.py).
 */
class PostgresParser extends Parser
{
    public const string TABLES = PostgresParserTables::class;

    /** Function parameter modes (sqlglot ARG_MODE_TOKENS). */
    private const array ARG_MODE_TOKENS = [
        TokenType::IN => true,
        TokenType::OUT => true,
        TokenType::INOUT => true,
        TokenType::VARIADIC => true,
    ];

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $args
     */
    protected function buildArrayPrepend(array $args): ArrayPrepend
    {
        return new ArrayPrepend(['this' => $args[1] ?? null, 'expression' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDiv(array $args): Expression
    {
        return Builder::cast(new IntDiv(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null]), DType::DECIMAL);
    }

    /**
     * Convert step values like '1 day' or INTERVAL '1 day' into INTERVAL '1' day. Postgres allows
     * calls with just two arguments: the step defaults to 1.
     *
     * @param  list<mixed>  $args
     */
    protected function buildGenerateSeries(array &$args): Expression
    {
        $step = $args[2] ?? null;

        if ($step instanceof Expression) {
            if ($step->isString()) {
                $args[2] = Builder::toInterval($step->name());
            } elseif ($step instanceof Interval && ! $step->flag('unit') && ($inner = $step->argExpr('this')) !== null) {
                $args[2] = Builder::toInterval($inner->argExpr('this') ?? $inner);
            }
        }

        return ExplodingGenerateSeries::fromArgList($args);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildGetBit(array $args): Expression
    {
        return new Getbit(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'zero_is_msb' => true]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildLength(array $args): Expression
    {
        return new Length(['this' => $args[0] ?? null, 'encoding' => $args[1] ?? null]);
    }

    /**
     * levenshtein_less_equal(source, target[, ins_cost, del_cost, sub_cost], max_d): max_dist is
     * always the last argument.
     *
     * @param  list<mixed>  $args
     */
    protected function buildLevenshteinLessEqual(array &$args): Expression
    {
        $maxDist = array_pop($args);

        return new Levenshtein([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'ins_cost' => $args[2] ?? null,
            'del_cost' => $args[3] ?? null,
            'sub_cost' => $args[4] ?? null,
            'max_dist' => $maxDist,
        ]);
    }

    /**
     * regexp_replace(source, pattern, replacement [, start [, N ]] [, flags ]). Any of start, N and
     * flags can be column references, so the last argument is only taken as the flags when it's
     * statically a non-integer string.
     *
     * @param  list<mixed>  $args
     */
    protected function buildRegexpReplace(array $args): Expression
    {
        $regexpReplace = null;

        if (count($args) > 3) {
            $last = $args[count($args) - 1];

            // sqlglot annotates types here; without the optimizer only string literals are text
            if ($last instanceof Expression && preg_match('/^\s*[+-]?\d+\s*$/', $last->name()) !== 1 && ($last->isString() || $last->isType(...DataType::TEXT_TYPES))) {
                $regexpReplace = RegexpReplace::fromArgList(array_slice($args, 0, -1));
                $regexpReplace->set('modifiers', $last);
            }
        }

        $regexpReplace ??= RegexpReplace::fromArgList($args);
        $regexpReplace->set('single_replace', true);

        return $regexpReplace;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSha256(array $args): Expression
    {
        return new SHA2(['this' => $args[0] ?? null, 'length' => Literal::number(256)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSha384(array $args): Expression
    {
        return new SHA2(['this' => $args[0] ?? null, 'length' => Literal::number(384)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSha512(array $args): Expression
    {
        return new SHA2(['this' => $args[0] ?? null, 'length' => Literal::number(512)]);
    }

    /**
     * TO_TIMESTAMP accepts either a single double argument or (text, text).
     *
     * @param  list<mixed>  $args
     */
    protected function buildToTimestamp(array $args): Expression
    {
        if (count($args) === 1) {
            return UnixToTime::fromArgList($args);
        }

        return $this->buildFormattedTime($args, StrToTime::class);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildJsonObjectAgg(array $args): Expression
    {
        return new JSONObjectAgg(['expressions' => $args]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildWidthBucket(array $args): Expression
    {
        return count($args) === 2
            ? new WidthBucket(['this' => $args[0], 'threshold' => $args[1]])
            : WidthBucket::fromArgList($args);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildUuid(array $args): Expression
    {
        return $args !== [] && $args[0] instanceof Expression ? Builder::cast($args[0], DType::UUID) : new Uuid;
    }

    // ---------------------------------------------------------------------------------------
    // Dispatch table handlers
    // ---------------------------------------------------------------------------------------

    protected function propertySet(): Expression
    {
        return $this->expression(new SetConfigProperty(['this' => $this->parseSet()]));
    }

    protected function placeholderPlaceholder(): Expression
    {
        return $this->expression(new Placeholder(['jdbc' => true]));
    }

    protected function noParenFunctionVariadic(): Expression
    {
        return $this->expression(new Variadic(['this' => $this->parseBitwise()]));
    }

    protected function funcJsonAgg(): Expression
    {
        return $this->expression(new JSONArrayAgg(['this' => $this->parseLambda(), 'order' => $this->parseOrder()]));
    }

    protected function rangeDat(?Expression $this_): ?Expression
    {
        return $this->expression(new MatchAgainst(['this' => $this->parseBitwise(), 'expressions' => [$this_]]));
    }

    protected function unaryRlike(): ?Expression
    {
        // The `~` token is remapped from TILDE to RLIKE in Postgres due to the binary REGEXP LIKE operator
        return $this->expression(new BitwiseNot(['this' => $this->parseUnary()]));
    }

    protected function jsonOpArrow(?Expression $this_, ?Expression $path): Expression
    {
        return $this->validateExpression($this->buildJsonExtractPath([$this_, $path], JSONExtract::class, arrowReqJsonType: $this->t['JSON_ARROWS_REQUIRE_JSON_TYPE'] === true));
    }

    protected function jsonOpDarrow(?Expression $this_, ?Expression $path): Expression
    {
        return $this->validateExpression($this->buildJsonExtractPath([$this_, $path], JSONExtractScalar::class, arrowReqJsonType: $this->t['JSON_ARROWS_REQUIRE_JSON_TYPE'] === true));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    /**
     * Parse a function parameter mode (IN, OUT, INOUT, VARIADIC), telling the mode keywords apart
     * from parameters with the same name: MODE TYPE means the keyword is the parameter's name
     * ("out INT"), MODE NAME TYPE means it's the mode ("OUT x INT").
     */
    protected function parseParameterMode(): ?int
    {
        if (! $this->matchSet(self::ARG_MODE_TOKENS, false) || $this->next === 0) {
            return null;
        }

        $modeToken = $this->curr;

        // Pattern 1: MODE TYPE, so the keyword is the parameter name
        $isFollowedByBuiltinType = $this->tryParse(function (): ?Expression {
            $this->advance();

            return $this->parseTypes(checkFunc: false, allowIdentifiers: false);
        }, retreat: true);

        if ($isFollowedByBuiltinType !== null) {
            return null;
        }

        // Pattern 2: MODE NAME TYPE, where the type may be user-defined
        if (! isset($this->idVarTokens[$this->next])) {
            return null;
        }

        $isFollowedByAnyType = $this->tryParse(function (): ?Expression {
            $this->advance(2);

            return $this->parseTypes(checkFunc: false, allowIdentifiers: true);
        }, retreat: true);

        return $isFollowedByAnyType !== null ? $modeToken : null;
    }

    protected function createModeConstraint(int $paramMode): InOutColumnConstraint
    {
        return $this->expression(new InOutColumnConstraint([
            'input_' => $paramMode === TokenType::IN || $paramMode === TokenType::INOUT,
            'output' => $paramMode === TokenType::OUT || $paramMode === TokenType::INOUT,
            'variadic' => $paramMode === TokenType::VARIADIC,
        ]));
    }

    protected function parseFunctionParameter(): ?Expression
    {
        $paramMode = $this->parseParameterMode();

        if ($paramMode !== null) {
            $this->advance();
        }

        // Parse parameter name and type
        $paramName = $this->parseIdVar();
        $columnDef = $this->parseColumnDef($paramName, computedColumn: false);

        // Attach mode as constraint
        if ($paramMode !== null && $columnDef instanceof ColumnDef) {
            $columnDef->set('constraints', [$this->createModeConstraint($paramMode), ...$columnDef->argList('constraints')]);
        }

        return $columnDef;
    }

    protected function parseQueryParameter(): ?Expression
    {
        $this_ = $this->match(TokenType::L_PAREN, false)
            ? $this->parseWrapped(fn (): ?Expression => $this->parseIdVar())
            : null;
        $this->matchTextSeq('S');

        return $this->expression(new Placeholder(['this' => $this_]));
    }

    protected function parseDatePart(): Expression
    {
        $part = $this->parseType();
        $this->match(TokenType::COMMA);
        $value = $this->parseBitwise();

        if ($part instanceof Column || $part instanceof Literal) {
            $part = Builder::var(strtoupper($part->name()));
        }

        return $this->expression(new Extract(['this' => $part, 'expression' => $value]));
    }

    protected function parseUniqueKey(): ?Expression
    {
        return null;
    }

    protected function parseJsonbExists(): JSONBExists
    {
        return $this->expression(new JSONBExists([
            'this' => $this->parseBitwise(),
            'path' => $this->match(TokenType::COMMA) ? $this->dialect->toJsonPath($this->parseBitwise()) : null,
        ]));
    }

    protected function parseGeneratedAsIdentity(): Expression
    {
        $this_ = parent::parseGeneratedAsIdentity();

        if ($this->matchTextSeq('STORED')) {
            $this_ = $this->expression(new ComputedColumnConstraint(['this' => $this_->args['expression'] ?? null]));
        }

        return $this_;
    }

    protected function parseUserDefinedType(Identifier $identifier): ?Expression
    {
        $udtType = $identifier;

        while ($this->match(TokenType::DOT)) {
            $part = $this->parseIdVar();

            if ($part !== null) {
                $udtType = new Dot(['this' => $udtType, 'expression' => $part]);
            }
        }

        return DataType::build($udtType, udt: true);
    }
}
