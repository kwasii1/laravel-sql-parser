<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Parser\Concerns;

use Kwasii\LaravelSqlParser\Expressions\AlgorithmProperty;
use Kwasii\LaravelSqlParser\Expressions\AllowedValuesProperty;
use Kwasii\LaravelSqlParser\Expressions\Any;
use Kwasii\LaravelSqlParser\Expressions\ArgMax;
use Kwasii\LaravelSqlParser\Expressions\ArgMin;
use Kwasii\LaravelSqlParser\Expressions\ArrayAgg;
use Kwasii\LaravelSqlParser\Expressions\ArrayAppend;
use Kwasii\LaravelSqlParser\Expressions\ArrayConcat;
use Kwasii\LaravelSqlParser\Expressions\ArrayExpr;
use Kwasii\LaravelSqlParser\Expressions\ArrayIntersect;
use Kwasii\LaravelSqlParser\Expressions\ArrayPrepend;
use Kwasii\LaravelSqlParser\Expressions\ArrayRemove;
use Kwasii\LaravelSqlParser\Expressions\AutoIncrementProperty;
use Kwasii\LaravelSqlParser\Expressions\BackupProperty;
use Kwasii\LaravelSqlParser\Expressions\Binary;
use Kwasii\LaravelSqlParser\Expressions\BitString;
use Kwasii\LaravelSqlParser\Expressions\BitwiseNot;
use Kwasii\LaravelSqlParser\Expressions\Boolean;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\ByteString;
use Kwasii\LaravelSqlParser\Expressions\CaseSpecificColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Cbrt;
use Kwasii\LaravelSqlParser\Expressions\Ceil;
use Kwasii\LaravelSqlParser\Expressions\Cluster;
use Kwasii\LaravelSqlParser\Expressions\ClusteredColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Coalesce;
use Kwasii\LaravelSqlParser\Expressions\CollateColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\CollateProperty;
use Kwasii\LaravelSqlParser\Expressions\CommentColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Concat;
use Kwasii\LaravelSqlParser\Expressions\ConcatWs;
use Kwasii\LaravelSqlParser\Expressions\ConnectByRoot;
use Kwasii\LaravelSqlParser\Expressions\ConvertTimezone;
use Kwasii\LaravelSqlParser\Expressions\Count;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DateFormatColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\DefaultColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Delete;
use Kwasii\LaravelSqlParser\Expressions\Distribute;
use Kwasii\LaravelSqlParser\Expressions\DistStyleProperty;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\DuplicateKeyProperty;
use Kwasii\LaravelSqlParser\Expressions\DynamicProperty;
use Kwasii\LaravelSqlParser\Expressions\EmptyProperty;
use Kwasii\LaravelSqlParser\Expressions\EncodeColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\EngineProperty;
use Kwasii\LaravelSqlParser\Expressions\EnviromentProperty;
use Kwasii\LaravelSqlParser\Expressions\EphemeralColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Escape;
use Kwasii\LaravelSqlParser\Expressions\ExcludeColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\ExecuteAsProperty;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\ExternalProperty;
use Kwasii\LaravelSqlParser\Expressions\FileFormatProperty;
use Kwasii\LaravelSqlParser\Expressions\Floor;
use Kwasii\LaravelSqlParser\Expressions\GenerateDateArray;
use Kwasii\LaravelSqlParser\Expressions\Glob;
use Kwasii\LaravelSqlParser\Expressions\GlobalProperty;
use Kwasii\LaravelSqlParser\Expressions\Greatest;
use Kwasii\LaravelSqlParser\Expressions\HandlerProperty;
use Kwasii\LaravelSqlParser\Expressions\HeapProperty;
use Kwasii\LaravelSqlParser\Expressions\Hex;
use Kwasii\LaravelSqlParser\Expressions\HexString;
use Kwasii\LaravelSqlParser\Expressions\IcebergProperty;
use Kwasii\LaravelSqlParser\Expressions\InheritsProperty;
use Kwasii\LaravelSqlParser\Expressions\InputModelProperty;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\JSONBContainsTopKey;
use Kwasii\LaravelSqlParser\Expressions\JSONBExtract;
use Kwasii\LaravelSqlParser\Expressions\JSONBExtractScalar;
use Kwasii\LaravelSqlParser\Expressions\JSONCast;
use Kwasii\LaravelSqlParser\Expressions\JSONExtract;
use Kwasii\LaravelSqlParser\Expressions\JSONExtractScalar;
use Kwasii\LaravelSqlParser\Expressions\JSONKeys;
use Kwasii\LaravelSqlParser\Expressions\Kwarg;
use Kwasii\LaravelSqlParser\Expressions\Lambda;
use Kwasii\LaravelSqlParser\Expressions\LanguageProperty;
use Kwasii\LaravelSqlParser\Expressions\Least;
use Kwasii\LaravelSqlParser\Expressions\Like;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Ln;
use Kwasii\LaravelSqlParser\Expressions\LocationProperty;
use Kwasii\LaravelSqlParser\Expressions\Log;
use Kwasii\LaravelSqlParser\Expressions\Lower;
use Kwasii\LaravelSqlParser\Expressions\LowerHex;
use Kwasii\LaravelSqlParser\Expressions\MaterializedProperty;
use Kwasii\LaravelSqlParser\Expressions\MergeTreeTTL;
use Kwasii\LaravelSqlParser\Expressions\Mod;
use Kwasii\LaravelSqlParser\Expressions\National;
use Kwasii\LaravelSqlParser\Expressions\Neg;
use Kwasii\LaravelSqlParser\Expressions\NonClusteredColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Not;
use Kwasii\LaravelSqlParser\Expressions\NotNullColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\NullExpr;
use Kwasii\LaravelSqlParser\Expressions\OnProperty;
use Kwasii\LaravelSqlParser\Expressions\OnUpdateColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\OutputModelProperty;
use Kwasii\LaravelSqlParser\Expressions\Pad;
use Kwasii\LaravelSqlParser\Expressions\Paren;
use Kwasii\LaravelSqlParser\Expressions\ParseJSON;
use Kwasii\LaravelSqlParser\Expressions\PartitionByTruncate;
use Kwasii\LaravelSqlParser\Expressions\PartitionedByBucket;
use Kwasii\LaravelSqlParser\Expressions\PathColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Placeholder;
use Kwasii\LaravelSqlParser\Expressions\Pragma;
use Kwasii\LaravelSqlParser\Expressions\Properties;
use Kwasii\LaravelSqlParser\Expressions\RawString;
use Kwasii\LaravelSqlParser\Expressions\RowFormatProperty;
use Kwasii\LaravelSqlParser\Expressions\SampleProperty;
use Kwasii\LaravelSqlParser\Expressions\SchemaCommentProperty;
use Kwasii\LaravelSqlParser\Expressions\ScopeResolution;
use Kwasii\LaravelSqlParser\Expressions\SecureProperty;
use Kwasii\LaravelSqlParser\Expressions\Semicolon;
use Kwasii\LaravelSqlParser\Expressions\SetProperty;
use Kwasii\LaravelSqlParser\Expressions\SharingProperty;
use Kwasii\LaravelSqlParser\Expressions\Sort;
use Kwasii\LaravelSqlParser\Expressions\Sqrt;
use Kwasii\LaravelSqlParser\Expressions\StabilityProperty;
use Kwasii\LaravelSqlParser\Expressions\StarMap;
use Kwasii\LaravelSqlParser\Expressions\StreamingTableProperty;
use Kwasii\LaravelSqlParser\Expressions\StrictProperty;
use Kwasii\LaravelSqlParser\Expressions\StrPosition;
use Kwasii\LaravelSqlParser\Expressions\Substring;
use Kwasii\LaravelSqlParser\Expressions\SwapTable;
use Kwasii\LaravelSqlParser\Expressions\TemporaryProperty;
use Kwasii\LaravelSqlParser\Expressions\TitleColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\TransformModelProperty;
use Kwasii\LaravelSqlParser\Expressions\TransientProperty;
use Kwasii\LaravelSqlParser\Expressions\Trim;
use Kwasii\LaravelSqlParser\Expressions\UnicodeString;
use Kwasii\LaravelSqlParser\Expressions\UnloggedProperty;
use Kwasii\LaravelSqlParser\Expressions\Unnest;
use Kwasii\LaravelSqlParser\Expressions\Upper;
use Kwasii\LaravelSqlParser\Expressions\UppercaseColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Uuid;
use Kwasii\LaravelSqlParser\Expressions\VarMap;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Dispatch-table plumbing and the handlers that sqlglot writes as lambdas inside its tables.
 *
 * Handler names are derived by tools/generate.php from the table and key, e.g.
 * STATEMENT_PARSERS[PRAGMA] => statementPragma(). Function builders (FUNCTIONS) take the parsed
 * argument list.
 */
trait HandlesTables
{
    /**
     * A dispatch table handler's named arguments, with ['@literal' => text, 'is_string' => bool]
     * entries (an exp.Literal closure variable) rebuilt as Literals.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    protected static function handlerArguments(array $arguments): array
    {
        foreach ($arguments as $name => $value) {
            if (is_array($value) && isset($value['@literal'])) {
                $arguments[$name] = $value['is_string'] === true ? Literal::string((string) $value['@literal']) : Literal::number((string) $value['@literal']);
            }
        }

        return $arguments;
    }

    /**
     * Call a table handler: a method name, or [method, ...extra arguments].
     *
     * @param  string|array<int|string, mixed>  $handler
     */
    protected function callHandler(string|array $handler, mixed ...$args): mixed
    {
        if (is_array($handler)) {
            $method = $handler[0];
            unset($handler[0]);

            // The remaining entries are named arguments (sqlglot closure variables)
            return $this->{$method}(...$args, ...self::handlerArguments($handler));
        }

        return $this->{$handler}(...$args);
    }

    // ---------------------------------------------------------------------------------------
    // Function builders (FUNCTIONS)
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $args
     */
    protected function buildVarMap(array $args): Expression
    {
        if (count($args) === 1 && $args[0] instanceof Expression && $args[0]->isStar()) {
            return new StarMap(['this' => $args[0]]);
        }

        $keys = [];
        $values = [];

        for ($i = 0; $i < count($args); $i += 2) {
            $keys[] = $args[$i];
            $values[] = $args[$i + 1] ?? null;
        }

        return new VarMap(['keys' => new ArrayExpr(['expressions' => $keys]), 'values' => new ArrayExpr(['expressions' => $values])]);
    }

    /**
     * sqlglot has two build_like helpers: parser.build_like (LIKE(pattern, string), as in SQLite)
     * and the dialect.build_like(expr_type, not_like) factory (LIKE(string, pattern)), which passes
     * $exprType.
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>|null  $exprType
     */
    protected function buildLike(array $args, ?string $exprType = null, bool $notLike = false): Expression
    {
        if ($exprType === null) {
            $like = new Like(['this' => $args[1] ?? null, 'expression' => $args[0] ?? null]);

            return count($args) > 2 ? new Escape(['this' => $like, 'expression' => $args[2]]) : $like;
        }

        $like = new $exprType(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null]);

        if (($args[2] ?? null) instanceof Expression) {
            $like = new Escape(['this' => $like, 'expression' => $args[2]]);
        }

        return $notLike ? new Not(['this' => $like]) : $like;
    }

    /**
     * @param  class-string<Expression>  $exprType
     */
    protected function parseBinaryRange(?Expression $this_, string $exprType, bool $reverseArgs = false): ?Expression
    {
        $expression = $this->parseBitwise();

        if ($reverseArgs) {
            [$this_, $expression] = [$expression, $this_];
        }

        return $this->parseEscape($this->expression(new $exprType(['this' => $this_, 'expression' => $expression])));
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildLogarithm(array $args): Expression
    {
        // Default argument order is base, expression
        $this_ = $args[0] ?? null;
        $expression = $args[1] ?? null;

        if ($expression !== null) {
            if ($this->dialect->settings['LOG_BASE_FIRST'] !== true) {
                [$this_, $expression] = [$expression, $this_];
            }

            return new Log(['this' => $this_, 'expression' => $expression]);
        }

        return $this->t['LOG_DEFAULTS_TO_LN'] === true ? new Ln(['this' => $this_]) : new Log(['this' => $this_]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildHex(array $args): Expression
    {
        $arg = $args[0] ?? null;

        return $this->dialect->settings['HEX_LOWERCASE'] === true ? new LowerHex(['this' => $arg]) : new Hex(['this' => $arg]);
    }

    /**
     * LOWER(HEX(..)) can be simplified to LowerHex to simplify its transpilation.
     *
     * @param  list<mixed>  $args
     */
    protected function buildLower(array $args): Expression
    {
        $arg = $args[0] ?? null;

        return $arg instanceof Hex ? new LowerHex(['this' => $arg->args['this'] ?? null]) : new Lower(['this' => $arg]);
    }

    /**
     * UPPER(HEX(..)) can be simplified to Hex to simplify its transpilation.
     *
     * @param  list<mixed>  $args
     */
    protected function buildUpper(array $args): Expression
    {
        $arg = $args[0] ?? null;

        return $arg instanceof Hex ? new Hex(['this' => $arg->args['this'] ?? null]) : new Upper(['this' => $arg]);
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildExtractJsonWithPath(array $args, string $exprType): Expression
    {
        $expression = new $exprType([
            'this' => $args[0] ?? null,
            'expression' => $this->dialect->toJsonPath($args[1] ?? null),
        ]);

        if (count($args) > 2 && $exprType === JSONExtract::class) {
            $expression->set('expressions', array_slice($args, 2));
        }

        if ($exprType === JSONExtractScalar::class) {
            $expression->set('scalar_only', $this->dialect->settings['JSON_EXTRACT_SCALAR_SCALAR_ONLY']);
        }

        return $expression;
    }

    /**
     * Wrap the operands if they are binary nodes, e.g. MOD(a + 1, 7) -> (a + 1) % 7.
     *
     * @param  list<mixed>  $args
     */
    protected function buildMod(array $args): Expression
    {
        $this_ = $args[0] ?? null;
        $expression = $args[1] ?? null;

        $this_ = $this_ instanceof Binary ? new Paren(['this' => $this_]) : $this_;
        $expression = $expression instanceof Binary ? new Paren(['this' => $expression]) : $expression;

        return new Mod(['this' => $this_, 'expression' => $expression]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildPad(array $args, bool $isLeft = true): Expression
    {
        return new Pad([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'fill_pattern' => $args[2] ?? null,
            'is_left' => $isLeft,
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildConvertTimezone(array $args, ?string $defaultSourceTz = null): Expression
    {
        if (count($args) === 2) {
            $sourceTz = $defaultSourceTz !== null && $defaultSourceTz !== '' ? Literal::string($defaultSourceTz) : null;

            return new ConvertTimezone(['source_tz' => $sourceTz, 'target_tz' => $args[0], 'timestamp' => $args[1]]);
        }

        return ConvertTimezone::fromArgList($args);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTrim(array $args, bool $isLeft = true, bool $reverseArgs = false): Expression
    {
        $this_ = $args[0] ?? null;
        $expression = $args[1] ?? null;

        if ($expression !== null && $reverseArgs) {
            [$this_, $expression] = [$expression, $this_];
        }

        return new Trim(['this' => $this_, 'expression' => $expression, 'position' => $isLeft ? 'LEADING' : 'TRAILING']);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildCoalesce(array $args, ?bool $isNvl = null, ?bool $isNull = null): Expression
    {
        return new Coalesce(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1), 'is_nvl' => $isNvl, 'is_null' => $isNull]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildLocateStrposition(array $args): Expression
    {
        return new StrPosition(['this' => $args[1] ?? null, 'substr' => $args[0] ?? null, 'position' => $args[2] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildArrayAppend(array $args): Expression
    {
        return new ArrayAppend(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'null_propagation' => $this->dialect->settings['ARRAY_FUNCS_PROPAGATES_NULLS']]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildArrayPrepend(array $args): Expression
    {
        return new ArrayPrepend(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'null_propagation' => $this->dialect->settings['ARRAY_FUNCS_PROPAGATES_NULLS']]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildArrayConcat(array $args): Expression
    {
        return new ArrayConcat(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1), 'null_propagation' => $this->dialect->settings['ARRAY_FUNCS_PROPAGATES_NULLS']]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildArrayRemove(array $args): Expression
    {
        return new ArrayRemove(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'null_propagation' => $this->dialect->settings['ARRAY_FUNCS_PROPAGATES_NULLS']]);
    }

    /** @param list<mixed> $args */
    protected function buildArray(array $args): Expression
    {
        return new ArrayExpr(['expressions' => $args]);
    }

    /** @param list<mixed> $args */
    protected function buildArrayagg(array $args): Expression
    {
        return new ArrayAgg(['this' => $args[0] ?? null, 'nulls_excluded' => $this->dialect->settings['ARRAY_AGG_INCLUDES_NULLS'] === null ? true : null]);
    }

    /** @param list<mixed> $args */
    protected function buildArrayIntersect(array $args): Expression
    {
        return new ArrayIntersect(['expressions' => $args]);
    }

    /** @param list<mixed> $args */
    protected function buildArrayIntersection(array $args): Expression
    {
        return new ArrayIntersect(['expressions' => $args]);
    }

    /** @param list<mixed> $args */
    protected function buildCount(array $args): Expression
    {
        return new Count(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1), 'big_int' => true]);
    }

    /** @param list<mixed> $args */
    protected function buildConcat(array $args): Expression
    {
        return new Concat([
            'expressions' => $args,
            'safe' => $this->dialect->settings['STRICT_STRING_CONCAT'] !== true,
            'coalesce' => $this->dialect->settings['CONCAT_COALESCE'],
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildConcatWs(array $args): Expression
    {
        return new ConcatWs([
            'expressions' => $args,
            'safe' => $this->dialect->settings['STRICT_STRING_CONCAT'] !== true,
            'coalesce' => $this->dialect->settings['CONCAT_WS_COALESCE'],
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildDateToDateStr(array $args): Expression
    {
        return new Cast(['this' => $args[0] ?? null, 'to' => new DataType(['this' => DType::TEXT])]);
    }

    /** @param list<mixed> $args */
    protected function buildGenerateDateArray(array $args): Expression
    {
        return new GenerateDateArray([
            'start' => $args[0] ?? null,
            'end' => $args[1] ?? null,
            'step' => $args[2] ?? new Interval(['this' => Literal::string(1), 'unit' => Builder::var('DAY')]),
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildGenerateUuid(array $args): Expression
    {
        return new Uuid(['is_string' => $this->dialect->settings['UUID_IS_STRING_TYPE'] === true ? true : null]);
    }

    /** @param list<mixed> $args */
    protected function buildGlob(array $args): Expression
    {
        return new Glob(['this' => $args[1] ?? null, 'expression' => $args[0] ?? null]);
    }

    /** @param list<mixed> $args */
    protected function buildGreatest(array $args): Expression
    {
        return new Greatest(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1), 'ignore_nulls' => $this->dialect->settings['LEAST_GREATEST_IGNORES_NULLS']]);
    }

    /** @param list<mixed> $args */
    protected function buildLeast(array $args): Expression
    {
        return new Least(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1), 'ignore_nulls' => $this->dialect->settings['LEAST_GREATEST_IGNORES_NULLS']]);
    }

    /** @param list<mixed> $args */
    protected function buildJsonKeys(array $args): Expression
    {
        return new JSONKeys(['this' => $args[0] ?? null, 'expression' => $this->dialect->toJsonPath($args[1] ?? null)]);
    }

    /** @param list<mixed> $args */
    protected function buildLog2(array $args): Expression
    {
        return new Log(['this' => Literal::number(2), 'expression' => $args[0] ?? null]);
    }

    /** @param list<mixed> $args */
    protected function buildLog10(array $args): Expression
    {
        return new Log(['this' => Literal::number(10), 'expression' => $args[0] ?? null]);
    }

    /** @param list<mixed> $args */
    protected function buildLpad(array $args): Expression
    {
        return $this->buildPad($args);
    }

    /** @param list<mixed> $args */
    protected function buildLeftpad(array $args): Expression
    {
        return $this->buildPad($args);
    }

    /** @param list<mixed> $args */
    protected function buildRpad(array $args): Expression
    {
        return $this->buildPad($args, isLeft: false);
    }

    /** @param list<mixed> $args */
    protected function buildRightpad(array $args): Expression
    {
        return $this->buildPad($args, isLeft: false);
    }

    /** @param list<mixed> $args */
    protected function buildLtrim(array $args): Expression
    {
        return $this->buildTrim($args);
    }

    /** @param list<mixed> $args */
    protected function buildRtrim(array $args): Expression
    {
        return $this->buildTrim($args, isLeft: false);
    }

    /** @param list<mixed> $args */
    protected function buildScopeResolution(array $args): Expression
    {
        return count($args) !== 2
            ? new ScopeResolution(['expression' => $args[0] ?? null])
            : new ScopeResolution(['this' => $args[0], 'expression' => $args[1]]);
    }

    /** @param list<mixed> $args */
    protected function buildCharindex(array $args): Expression
    {
        return $this->buildLocateStrposition($args);
    }

    /** @param list<mixed> $args */
    protected function buildLocate(array $args): Expression
    {
        return $this->buildLocateStrposition($args);
    }

    /** @param list<mixed> $args */
    protected function buildTimeToTimeStr(array $args): Expression
    {
        return new Cast(['this' => $args[0] ?? null, 'to' => new DataType(['this' => DType::TEXT])]);
    }

    /** @param list<mixed> $args */
    protected function buildTsOrDsToDateStr(array $args): Expression
    {
        return new Substring([
            'this' => new Cast(['this' => $args[0] ?? null, 'to' => new DataType(['this' => DType::TEXT])]),
            'start' => Literal::number(1),
            'length' => Literal::number(10),
        ]);
    }

    /** @param list<mixed> $args */
    protected function buildUnnest(array $args): Expression
    {
        $first = $args[0] ?? null;

        return new Unnest(['expressions' => $first === null ? [] : (is_array($first) ? $first : [$first])]);
    }

    /** @param list<mixed> $args */
    protected function buildUuid(array $args): Expression
    {
        return new Uuid(['is_string' => $this->dialect->settings['UUID_IS_STRING_TYPE'] === true ? true : null]);
    }

    /** @param list<mixed> $args */
    protected function buildUuidString(array $args): Expression
    {
        return new Uuid([
            'this' => $args[0] ?? null,
            'name' => $args[1] ?? null,
            'is_string' => $this->dialect->settings['UUID_IS_STRING_TYPE'] === true ? true : null,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // COLUMN_OPERATORS (this, path) and LAMBDAS (expressions)
    // ---------------------------------------------------------------------------------------

    protected function columnOpDotcolon(?Expression $this_, ?Expression $to): Expression
    {
        return $this->expression(new JSONCast(['this' => $this_, 'to' => $to]));
    }

    protected function columnOpDcolon(?Expression $this_, ?Expression $to): Expression
    {
        return $this->buildCast($this->t['STRICT_CAST'] === true, ['this' => $this_, 'to' => $to]);
    }

    protected function columnOpArrow(?Expression $this_, ?Expression $path): Expression
    {
        return $this->expression(new JSONExtract([
            'this' => $this_,
            'expression' => $this->dialect->toJsonPath($path),
            'only_json_types' => $this->t['JSON_ARROWS_REQUIRE_JSON_TYPE'],
        ]));
    }

    protected function columnOpDarrow(?Expression $this_, ?Expression $path): Expression
    {
        return $this->expression(new JSONExtractScalar([
            'this' => $this_,
            'expression' => $this->dialect->toJsonPath($path),
            'only_json_types' => $this->t['JSON_ARROWS_REQUIRE_JSON_TYPE'],
            'scalar_only' => $this->dialect->settings['JSON_EXTRACT_SCALAR_SCALAR_ONLY'],
        ]));
    }

    protected function columnOpHashArrow(?Expression $this_, ?Expression $path): Expression
    {
        return $this->expression(new JSONBExtract(['this' => $this_, 'expression' => $path]));
    }

    protected function columnOpDhashArrow(?Expression $this_, ?Expression $path): Expression
    {
        return $this->expression(new JSONBExtractScalar(['this' => $this_, 'expression' => $path]));
    }

    protected function columnOpPlaceholder(?Expression $this_, ?Expression $key): Expression
    {
        return $this->expression(new JSONBContainsTopKey(['this' => $this_, 'expression' => $key]));
    }

    /**
     * @param  list<Expression|null>  $expressions
     */
    protected function lambdaArrow(array $expressions): Expression
    {
        return $this->expression(new Lambda([
            'this' => $this->replaceLambda($this->parseDisjunction(), $expressions),
            'expressions' => $expressions,
        ]));
    }

    /**
     * @param  list<Expression|null>  $expressions
     */
    protected function lambdaFarrow(array $expressions): Expression
    {
        return $this->expression(new Kwarg([
            'this' => Builder::var($expressions[0]?->name()),
            'expression' => $this->parseDisjunction() ?? $this->parseSelect(),
        ]));
    }

    // ---------------------------------------------------------------------------------------
    // EXPRESSION_PARSERS, STATEMENT_PARSERS, UNARY_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function parseIntoCluster(): ?Expression
    {
        return $this->parseSort(Cluster::class, TokenType::CLUSTER_BY);
    }

    protected function parseIntoColumnDef(): ?Expression
    {
        return $this->parseColumnDef($this->parseColumn());
    }

    protected function parseIntoDataType(): ?Expression
    {
        return $this->parseTypes(schema: true, allowIdentifiers: false);
    }

    protected function parseIntoFrom(): ?Expression
    {
        return $this->parseFrom(joins: true);
    }

    protected function parseIntoSort(): ?Expression
    {
        return $this->parseSort(Sort::class, TokenType::SORT_BY);
    }

    protected function parseIntoTuple(): ?Expression
    {
        return $this->parseValue(values: false);
    }

    protected function statementPragma(): Expression
    {
        return $this->expression(new Pragma(['this' => $this->parseExpression()]));
    }

    protected function statementUnpivot(): Expression
    {
        return $this->parseSimplifiedPivot(isUnpivot: true);
    }

    protected function statementSemicolon(): Expression
    {
        return new Semicolon;
    }

    protected function unaryNot(): Expression
    {
        return $this->expression(new Not(['this' => $this->parseEquality()]));
    }

    protected function unaryTilde(): Expression
    {
        return $this->expression(new BitwiseNot(['this' => $this->parseUnary()]));
    }

    protected function unaryDash(): Expression
    {
        return $this->expression(new Neg(['this' => $this->parseUnary()]));
    }

    protected function unaryPipeSlash(): Expression
    {
        return $this->expression(new Sqrt(['this' => $this->parseUnary()]));
    }

    protected function unaryDpipeSlash(): Expression
    {
        return $this->expression(new Cbrt(['this' => $this->parseUnary()]));
    }

    // ---------------------------------------------------------------------------------------
    // STRING_PARSERS, NUMERIC_PARSERS, PRIMARY_PARSERS, PLACEHOLDER_PARSERS (token)
    // ---------------------------------------------------------------------------------------

    protected function stringHeredocString(int $token): Expression
    {
        return $this->expression(new RawString(['this' => $this->texts[$token]]), $token);
    }

    protected function stringNationalString(int $token): Expression
    {
        return $this->expression(new National(['this' => $this->texts[$token]]), $token);
    }

    protected function stringRawString(int $token): Expression
    {
        return $this->expression(new RawString(['this' => $this->texts[$token]]), $token);
    }

    protected function stringString(int $token): Expression
    {
        return $this->expression(new Literal(['this' => $this->texts[$token], 'is_string' => true]), $token);
    }

    protected function stringUnicodeString(int $token): Expression
    {
        return $this->expression(new UnicodeString([
            'this' => $this->texts[$token],
            'escape' => $this->matchTextSeq('UESCAPE') ? $this->parseString() : null,
        ]), $token);
    }

    protected function numericBitString(int $token): Expression
    {
        return $this->expression(new BitString(['this' => $this->texts[$token]]), $token);
    }

    protected function numericByteString(int $token): Expression
    {
        return $this->expression(new ByteString([
            'this' => $this->texts[$token],
            'is_bytes' => $this->dialect->settings['BYTE_STRING_IS_BYTES_TYPE'] === true ? true : null,
        ]), $token);
    }

    protected function numericHexString(int $token): Expression
    {
        return $this->expression(new HexString([
            'this' => $this->texts[$token],
            'is_integer' => $this->dialect->settings['HEX_STRING_IS_INTEGER_TYPE'] === true ? true : null,
        ]), $token);
    }

    protected function numericNumber(int $token): Expression
    {
        return $this->expression(new Literal(['this' => $this->texts[$token], 'is_string' => false]), $token);
    }

    protected function primaryHeredocString(int $token): Expression
    {
        return $this->stringHeredocString($token);
    }

    protected function primaryNationalString(int $token): Expression
    {
        return $this->stringNationalString($token);
    }

    protected function primaryRawString(int $token): Expression
    {
        return $this->stringRawString($token);
    }

    protected function primaryString(int $token): Expression
    {
        return $this->stringString($token);
    }

    protected function primaryUnicodeString(int $token): Expression
    {
        return $this->stringUnicodeString($token);
    }

    protected function primaryBitString(int $token): Expression
    {
        return $this->numericBitString($token);
    }

    protected function primaryByteString(int $token): Expression
    {
        return $this->numericByteString($token);
    }

    protected function primaryHexString(int $token): Expression
    {
        return $this->numericHexString($token);
    }

    protected function primaryNumber(int $token): Expression
    {
        return $this->numericNumber($token);
    }

    protected function primaryIntroducer(int $token): Expression
    {
        return $this->parseIntroducer($token);
    }

    protected function primaryNull(int $token): Expression
    {
        return $this->expression(new NullExpr);
    }

    protected function primaryTrue(int $token): Expression
    {
        return $this->expression(new Boolean(['this' => true]));
    }

    protected function primaryFalse(int $token): Expression
    {
        return $this->expression(new Boolean(['this' => false]));
    }

    protected function primarySessionParameter(int $token): Expression
    {
        return $this->parseSessionParameter();
    }

    protected function primaryStar(int $token): ?Expression
    {
        return $this->parseStarOps();
    }

    protected function placeholderPlaceholder(): Expression
    {
        return $this->expression(new Placeholder);
    }

    protected function placeholderColon(): ?Expression
    {
        return $this->matchSet($this->t['COLON_PLACEHOLDER_TOKENS'])
            ? $this->expression(new Placeholder(['this' => $this->prevText()]))
            : null;
    }

    // ---------------------------------------------------------------------------------------
    // RANGE_PARSERS (this)
    // ---------------------------------------------------------------------------------------

    protected function rangeBetween(?Expression $this_): ?Expression
    {
        return $this->parseBetween($this_);
    }

    protected function rangeIn(?Expression $this_): ?Expression
    {
        return $this->parseIn($this_);
    }

    protected function rangeIs(?Expression $this_): ?Expression
    {
        return $this->parseIs($this_);
    }

    protected function rangeFor(?Expression $this_): ?Expression
    {
        return $this->parseComprehension($this_);
    }

    protected function rangeOperator(?Expression $this_): ?Expression
    {
        return $this->parseOperator($this_);
    }

    // ---------------------------------------------------------------------------------------
    // PROPERTY_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function propertyAllowedValues(): Expression
    {
        return $this->expression(new AllowedValuesProperty(['expressions' => $this->parseCsv(fn (): ?Expression => $this->parsePrimary())]));
    }

    protected function propertyAlgorithm(): Expression
    {
        return $this->parsePropertyAssignment(AlgorithmProperty::class);
    }

    protected function propertyAutoIncrement(): Expression
    {
        return $this->parsePropertyAssignment(AutoIncrementProperty::class);
    }

    protected function propertyBackup(): Expression
    {
        return $this->expression(new BackupProperty(['this' => $this->parseVar(anyToken: true)]));
    }

    protected function propertyCharset(bool $default = false): Expression
    {
        return $this->parseCharacterSet(default: $default);
    }

    protected function propertyCollate(bool $default = false): Expression
    {
        return $this->parsePropertyAssignment(CollateProperty::class, $default ? ['default' => true] : []);
    }

    protected function propertyComment(): Expression
    {
        return $this->parsePropertyAssignment(SchemaCommentProperty::class);
    }

    protected function propertyDatablocksize(?bool $default = null, ?bool $minimum = null, ?bool $maximum = null): Expression
    {
        return $this->parseDatablocksize($default, $minimum, $maximum);
    }

    protected function propertyDeterministic(): Expression
    {
        return $this->expression(new StabilityProperty(['this' => Literal::string('IMMUTABLE')]));
    }

    protected function propertyDuplicate(): Expression
    {
        return $this->parseCompositeKeyProperty(DuplicateKeyProperty::class);
    }

    protected function propertyDynamic(): Expression
    {
        return $this->expression(new DynamicProperty);
    }

    protected function propertyDiststyle(): Expression
    {
        return $this->parsePropertyAssignment(DistStyleProperty::class);
    }

    protected function propertyEmpty(): Expression
    {
        return $this->expression(new EmptyProperty);
    }

    protected function propertyEngine(): Expression
    {
        return $this->parsePropertyAssignment(EngineProperty::class);
    }

    protected function propertyEnvironment(): Expression
    {
        return $this->expression(new EnviromentProperty(['expressions' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseAssignment())]));
    }

    protected function propertyHandler(): Expression
    {
        return $this->parsePropertyAssignment(HandlerProperty::class);
    }

    protected function propertyExecute(): Expression
    {
        return $this->parsePropertyAssignment(ExecuteAsProperty::class);
    }

    protected function propertyExternal(): Expression
    {
        return $this->expression(new ExternalProperty);
    }

    protected function propertyFallback(bool $no = false): Expression
    {
        return $this->parseFallback(no: $no);
    }

    protected function propertyFormat(): Expression
    {
        return $this->parsePropertyAssignment(FileFormatProperty::class);
    }

    protected function propertyGlobal(): ?Expression
    {
        return $this->expression(new GlobalProperty);
    }

    protected function propertyHeap(): Expression
    {
        return $this->expression(new HeapProperty);
    }

    protected function propertyIceberg(): Expression
    {
        return $this->expression(new IcebergProperty);
    }

    protected function propertyImmutable(): Expression
    {
        return $this->expression(new StabilityProperty(['this' => Literal::string('IMMUTABLE')]));
    }

    protected function propertyInherits(): Expression
    {
        return $this->expression(new InheritsProperty(['expressions' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseTable())]));
    }

    protected function propertyInput(): Expression
    {
        return $this->expression(new InputModelProperty(['this' => $this->parseSchema()]));
    }

    protected function propertyJournal(mixed ...$kwargs): Expression
    {
        return $this->parseJournal(...$kwargs);
    }

    protected function propertyLanguage(): Expression
    {
        return $this->parsePropertyAssignment(LanguageProperty::class);
    }

    protected function propertyLayout(): Expression
    {
        return $this->parseDictProperty('LAYOUT');
    }

    protected function propertyLifetime(): Expression
    {
        return $this->parseDictRange('LIFETIME');
    }

    protected function propertyLocation(): Expression
    {
        return $this->parsePropertyAssignment(LocationProperty::class);
    }

    protected function propertyLog(bool $no = false): Expression
    {
        return $this->parseLog(no: $no);
    }

    protected function propertyMaterialized(): Expression
    {
        return $this->expression(new MaterializedProperty);
    }

    protected function propertyMergeblockratio(bool $no = false, bool $default = false): Expression
    {
        return $this->parseMergeblockratio(no: $no, default: $default);
    }

    protected function propertyMultiset(): Expression
    {
        return $this->expression(new SetProperty(['multi' => true]));
    }

    protected function propertyOrderBy(): ?Expression
    {
        return $this->parseOrder(skipOrderToken: true);
    }

    protected function propertyOutput(): Expression
    {
        return $this->expression(new OutputModelProperty(['this' => $this->parseSchema()]));
    }

    protected function propertyPrimaryKey(): Expression
    {
        return $this->parsePrimaryKey(inProps: true);
    }

    protected function propertyRange(): Expression
    {
        return $this->parseDictRange('RANGE');
    }

    protected function propertyRowFormat(): Expression
    {
        return $this->parsePropertyAssignment(RowFormatProperty::class);
    }

    protected function propertySample(): Expression
    {
        return $this->expression(new SampleProperty(['this' => $this->matchTextSeq('BY') ? $this->parseBitwise() : null]));
    }

    protected function propertySecure(): Expression
    {
        return $this->expression(new SecureProperty);
    }

    protected function propertySet(): Expression
    {
        return $this->expression(new SetProperty(['multi' => false]));
    }

    protected function propertySharing(): Expression
    {
        return $this->parsePropertyAssignment(SharingProperty::class);
    }

    protected function propertySource(): Expression
    {
        return $this->parseDictProperty('SOURCE');
    }

    protected function propertyStable(): Expression
    {
        return $this->expression(new StabilityProperty(['this' => Literal::string('STABLE')]));
    }

    protected function propertyStrict(): Expression
    {
        return $this->expression(new StrictProperty);
    }

    protected function propertyStreaming(): Expression
    {
        return $this->expression(new StreamingTableProperty);
    }

    protected function propertyTemp(): Expression
    {
        return $this->expression(new TemporaryProperty);
    }

    protected function propertyTemporary(): Expression
    {
        return $this->expression(new TemporaryProperty);
    }

    protected function propertyTransient(): Expression
    {
        return $this->expression(new TransientProperty);
    }

    protected function propertyTransform(): Expression
    {
        return $this->expression(new TransformModelProperty(['expressions' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseExpression())]));
    }

    protected function propertyUsing(): ?Expression
    {
        return $this->parsePropertyAssignment(FileFormatProperty::class);
    }

    protected function propertyUnlogged(): Expression
    {
        return $this->expression(new UnloggedProperty);
    }

    // ---------------------------------------------------------------------------------------
    // CONSTRAINT_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function constraintCasespecific(): Expression
    {
        return $this->expression(new CaseSpecificColumnConstraint(['not_' => false]));
    }

    protected function constraintCollate(): Expression
    {
        return $this->expression(new CollateColumnConstraint(['this' => $this->parseIdentifier() ?? $this->parseColumn()]));
    }

    protected function constraintComment(): Expression
    {
        return $this->expression(new CommentColumnConstraint(['this' => $this->parseString()]));
    }

    protected function constraintClustered(): Expression
    {
        return $this->expression(new ClusteredColumnConstraint(['this' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseOrdered())]));
    }

    protected function constraintNonclustered(): Expression
    {
        return $this->expression(new NonClusteredColumnConstraint(['this' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseOrdered())]));
    }

    protected function constraintDefault(): Expression
    {
        return $this->expression(new DefaultColumnConstraint(['this' => $this->parseBitwise()]));
    }

    protected function constraintEncode(): Expression
    {
        return $this->expression(new EncodeColumnConstraint(['this' => $this->parseVar()]));
    }

    protected function constraintEphemeral(): Expression
    {
        return $this->expression(new EphemeralColumnConstraint(['this' => $this->parseBitwise()]));
    }

    protected function constraintExclude(): Expression
    {
        return $this->expression(new ExcludeColumnConstraint(['this' => $this->parseIndexParams()]));
    }

    protected function constraintFormat(): Expression
    {
        return $this->expression(new DateFormatColumnConstraint(['this' => $this->parseVarOrString()]));
    }

    protected function constraintNull(): Expression
    {
        return $this->expression(new NotNullColumnConstraint(['allow_null' => true]));
    }

    protected function constraintOn(): Expression
    {
        if ($this->match(TokenType::UPDATE)) {
            return $this->expression(new OnUpdateColumnConstraint(['this' => $this->parseFunction()]));
        }

        return $this->expression(new OnProperty(['this' => $this->parseIdVar()]));
    }

    protected function constraintPath(): Expression
    {
        return $this->expression(new PathColumnConstraint(['this' => $this->parseString()]));
    }

    protected function constraintReferences(): ?Expression
    {
        return $this->parseReferences(match: false);
    }

    protected function constraintTitle(): Expression
    {
        return $this->expression(new TitleColumnConstraint(['this' => $this->parseVarOrString()]));
    }

    protected function constraintTtl(): Expression
    {
        return $this->expression(new MergeTreeTTL(['expressions' => [$this->parseBitwise()]]));
    }

    protected function constraintUppercase(): Expression
    {
        return $this->expression(new UppercaseColumnConstraint);
    }

    protected function constraintWith(): Expression
    {
        return $this->expression(new Properties(['expressions' => $this->parseWrappedProperties()]));
    }

    protected function parsePartitionedByBucketOrTruncate(): ?Expression
    {
        if (! $this->match(TokenType::L_PAREN, false)) {
            // Partitioning by bucket or truncate follows the syntax:
            // PARTITION BY (BUCKET(..) | TRUNCATE(..))
            // If we don't have parenthesis after each keyword, we should instead parse this as an identifier
            $this->retreat($this->index - 1);

            return null;
        }

        $class = strtoupper($this->prevText()) === 'BUCKET' ? PartitionedByBucket::class : PartitionByTruncate::class;

        $args = $this->parseWrappedCsv(fn (): ?Expression => $this->parsePrimary() ?? $this->parseColumn());
        $this_ = $args[0] ?? null;
        $expression = $args[1] ?? null;

        if ($this_ instanceof Literal) {
            // Iceberg partition transforms (bucket / truncate) are canonicalized to
            // `bucket(<col name>, <num buckets>)` (Trino order); Hive puts the number first.
            [$this_, $expression] = [$expression, $this_];
        }

        return $this->expression(new $class(['this' => $this_, 'expression' => $expression]));
    }

    // ---------------------------------------------------------------------------------------
    // ALTER_PARSERS, ALTER_ALTER_PARSERS, NO_PAREN_FUNCTION_PARSERS, SET_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function alterDelete(): Expression
    {
        return $this->expression(new Delete(['where' => $this->parseWhere()]));
    }

    protected function alterSwap(): Expression
    {
        return $this->expression(new SwapTable(['this' => $this->match(TokenType::WITH) ? $this->parseTable(schema: true) : null]));
    }

    protected function alterAlterCompound(): Expression
    {
        return $this->parseAlterSortkey(compound: true);
    }

    protected function noParenFunctionAny(): Expression
    {
        return $this->expression(new Any(['this' => $this->parseBitwise()]));
    }

    protected function noParenFunctionConnectByRoot(): Expression
    {
        return $this->expression(new ConnectByRoot(['this' => $this->parseColumn()]));
    }

    protected function setGlobal(): ?Expression
    {
        return $this->parseSetItemAssignment('GLOBAL');
    }

    protected function setLocal(): ?Expression
    {
        return $this->parseSetItemAssignment('LOCAL');
    }

    protected function setSession(): ?Expression
    {
        return $this->parseSetItemAssignment('SESSION');
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTION_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function funcArgMax(): Expression
    {
        return $this->parseDistinctArgFunction(ArgMax::class);
    }

    protected function funcMaxBy(): Expression
    {
        return $this->parseDistinctArgFunction(ArgMax::class);
    }

    protected function funcArgMin(): Expression
    {
        return $this->parseDistinctArgFunction(ArgMin::class);
    }

    protected function funcMinBy(): Expression
    {
        return $this->parseDistinctArgFunction(ArgMin::class);
    }

    protected function funcCast(): Expression
    {
        return $this->parseCast($this->t['STRICT_CAST'] === true);
    }

    protected function funcCeil(): Expression
    {
        return $this->parseCeilFloor(Ceil::class);
    }

    protected function funcConvert(): ?Expression
    {
        return $this->parseConvert($this->t['STRICT_CAST'] === true);
    }

    protected function funcFloor(): Expression
    {
        return $this->parseCeilFloor(Floor::class);
    }

    protected function funcJsonObjectagg(): Expression
    {
        return $this->parseJsonObject(agg: true);
    }

    protected function funcSafeCast(): Expression
    {
        return $this->parseCast(false, safe: true);
    }

    protected function funcTryCast(): Expression
    {
        return $this->parseCast(false, safe: true);
    }

    protected function funcTryConvert(): ?Expression
    {
        return $this->parseConvert(false, safe: true);
    }

    // ---------------------------------------------------------------------------------------
    // QUERY_MODIFIER_PARSERS: each returns [arg name, expression]
    // ---------------------------------------------------------------------------------------

    /** @return array{0: string, 1: mixed} */
    protected function modifierMatchRecognize(): array
    {
        return ['match', $this->parseMatchRecognize()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierPrewhere(): array
    {
        return ['prewhere', $this->parsePrewhere()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierWhere(): array
    {
        return ['where', $this->parseWhere()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierGroupBy(): array
    {
        return ['group', $this->parseGroup()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierHaving(): array
    {
        return ['having', $this->parseHaving()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierQualify(): array
    {
        return ['qualify', $this->parseQualify()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierWindow(): array
    {
        return ['windows', $this->parseWindowClause()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierOrderBy(): array
    {
        return ['order', $this->parseOrder()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierLimit(): array
    {
        return ['limit', $this->parseLimit()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierFetch(): array
    {
        return ['limit', $this->parseLimit()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierOffset(): array
    {
        return ['offset', $this->parseOffset()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierFor(): array
    {
        return ['locks', $this->parseLocks()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierLock(): array
    {
        return ['locks', $this->parseLocks()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierTableSample(): array
    {
        return ['sample', $this->parseTableSample(asModifier: true)];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierUsing(): array
    {
        return ['sample', $this->parseTableSample(asModifier: true)];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierClusterBy(): array
    {
        return ['cluster', $this->parseCluster()];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierDistributeBy(): array
    {
        return ['distribute', $this->parseSort(Distribute::class, TokenType::DISTRIBUTE_BY)];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierSortBy(): array
    {
        return ['sort', $this->parseSort(Sort::class, TokenType::SORT_BY)];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierConnectBy(): array
    {
        return ['connect', $this->parseConnect(skipStartToken: true)];
    }

    // ---------------------------------------------------------------------------------------
    // TYPE_LITERAL_PARSERS (this, data type)
    // ---------------------------------------------------------------------------------------

    protected function typeLiteralJson(?Expression $this_, DataType $dataType): Expression
    {
        return $this->expression(new ParseJSON(['this' => $this_, 'is_literal' => true]));
    }
}
