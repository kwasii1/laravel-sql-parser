<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Snowflake;

use LaravelSqlParser\LaravelSqlParser\Expressions\AddMonths;
use LaravelSqlParser\LaravelSqlParser\Expressions\All;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\ApiProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\ApplicationProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\ApproxTopK;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayContains;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayDistinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExcept;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayIntersect;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayOverlaps;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayPosition;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArraySlice;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayToString;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseAnd;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseLeftShift;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseNot;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseOr;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseRightShift;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseXor;
use LaravelSqlParser\LaravelSqlParser\Expressions\Booland;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolnot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolor;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\CatalogProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ComputeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Corr;
use LaravelSqlParser\LaravelSqlParser\Expressions\CountIf;
use LaravelSqlParser\LaravelSqlParser\Expressions\Create;
use LaravelSqlParser\LaravelSqlParser\Expressions\CredentialsProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\CurrentTimestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\DatabaseProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateDiff;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateFromParts;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dayname;
use LaravelSqlParser\LaravelSqlParser\Expressions\Decrypt;
use LaravelSqlParser\LaravelSqlParser\Expressions\DecryptRaw;
use LaravelSqlParser\LaravelSqlParser\Expressions\Describe;
use LaravelSqlParser\LaravelSqlParser\Expressions\DirectoryStage;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DynamicIdentifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\DynamicProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Explode;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\ExternalProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Extract;
use LaravelSqlParser\LaravelSqlParser\Expressions\FileFormatProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\FirstValue;
use LaravelSqlParser\LaravelSqlParser\Expressions\ForeignKey;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateSeries;
use LaravelSqlParser\LaravelSqlParser\Expressions\Generator;
use LaravelSqlParser\LaravelSqlParser\Expressions\Get;
use LaravelSqlParser\LaravelSqlParser\Expressions\Greatest;
use LaravelSqlParser\LaravelSqlParser\Expressions\HybridProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\IcebergProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\JarowinklerSimilarity;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONObject;
use LaravelSqlParser\LaravelSqlParser\Expressions\Kwarg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lambda;
use LaravelSqlParser\LaravelSqlParser\Expressions\LastDay;
use LaravelSqlParser\LaravelSqlParser\Expressions\LastValue;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lateral;
use LaravelSqlParser\LaravelSqlParser\Expressions\Least;
use LaravelSqlParser\LaravelSqlParser\Expressions\Left;
use LaravelSqlParser\LaravelSqlParser\Expressions\Length;
use LaravelSqlParser\LaravelSqlParser\Expressions\Levenshtein;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Localtime;
use LaravelSqlParser\LaravelSqlParser\Expressions\LocationProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\MaskingPolicyColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\MaskingProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\MaterializedProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\ModelAttribute;
use LaravelSqlParser\LaravelSqlParser\Expressions\Monthname;
use LaravelSqlParser\LaravelSqlParser\Expressions\Neg;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\NetworkProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\NthValue;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ParseJSON;
use LaravelSqlParser\LaravelSqlParser\Expressions\ParseUrl;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pow;
use LaravelSqlParser\LaravelSqlParser\Expressions\ProjectionPolicyColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Put;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Rand;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpLike;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpReplace;
use LaravelSqlParser\LaravelSqlParser\Expressions\Right;
use LaravelSqlParser\LaravelSqlParser\Expressions\Round;
use LaravelSqlParser\LaravelSqlParser\Expressions\RowAccessProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Search;
use LaravelSqlParser\LaravelSqlParser\Expressions\SecurityIntegrationProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\SemanticView;
use LaravelSqlParser\LaravelSqlParser\Expressions\Set;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetItem;
use LaravelSqlParser\LaravelSqlParser\Expressions\Show;
use LaravelSqlParser\LaravelSqlParser\Expressions\SortArray;
use LaravelSqlParser\LaravelSqlParser\Expressions\Split;
use LaravelSqlParser\LaravelSqlParser\Expressions\SplitPart;
use LaravelSqlParser\LaravelSqlParser\Expressions\StarMap;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrPosition;
use LaravelSqlParser\LaravelSqlParser\Expressions\Strtok;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrtokToArray;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Substring;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableFromRows;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tags;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeFromParts;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampFromParts;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\ToBinary;
use LaravelSqlParser\LaravelSqlParser\Expressions\ToBoolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\ToDouble;
use LaravelSqlParser\LaravelSqlParser\Expressions\ToFile;
use LaravelSqlParser\LaravelSqlParser\Expressions\ToNumber;
use LaravelSqlParser\LaravelSqlParser\Expressions\TryCast;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\Undrop;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unicode;
use LaravelSqlParser\LaravelSqlParser\Expressions\UnixToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\UseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\UsingTemplateProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;
use LaravelSqlParser\LaravelSqlParser\Expressions\WindowSpec;
use LaravelSqlParser\LaravelSqlParser\Expressions\XorExpr;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Snowflake parser (sqlglot parsers/snowflake.py).
 */
class SnowflakeParser extends Parser
{
    public const string TABLES = SnowflakeParserTables::class;

    /** TO_TIMESTAMP* function names by timestamp type. */
    private const array TIMESTAMP_TYPES = [
        DType::TIMESTAMP => 'TO_TIMESTAMP',
        DType::TIMESTAMPLTZ => 'TO_TIMESTAMP_LTZ',
        DType::TIMESTAMPNTZ => 'TO_TIMESTAMP_NTZ',
        DType::TIMESTAMPTZ => 'TO_TIMESTAMP_TZ',
    ];

    /** Ranking window functions whose default frame is the whole partition. */
    public const array RANKING_WINDOW_FUNCTIONS_WITH_FRAME = [FirstValue::class, LastValue::class, NthValue::class];

    private const array FLATTEN_COLUMNS = ['SEQ', 'KEY', 'PATH', 'INDEX', 'VALUE', 'THIS'];

    private const array SCHEMA_KINDS = [
        'OBJECTS' => true, 'TABLES' => true, 'VIEWS' => true, 'SEQUENCES' => true, 'UNIQUE KEYS' => true, 'IMPORTED KEYS' => true,
    ];

    private const array NON_TABLE_CREATABLES = ['STORAGE INTEGRATION' => true, 'TAG' => true, 'WAREHOUSE' => true, 'STREAMLIT' => true];

    private const array UNDROP_OBJECTS = [
        'ACCOUNT' => [], 'DATABASE' => [], 'NOTEBOOK' => [], 'SCHEMA' => [], 'SNAPSHOT' => [], 'STREAMLIT' => [],
        'TABLE' => [], 'TAG' => [], 'TYPE' => [], 'DYNAMIC' => ['TABLE'], 'EXTERNAL' => ['VOLUME'], 'ICEBERG' => ['TABLE'],
    ];

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * APPROX_TOP_K(column [, k] [, counters]): k defaults to 1.
     *
     * @param  list<mixed>  $args
     */
    protected function buildApproxTopK(array $args): Expression
    {
        if (count($args) === 1) {
            $args[] = Literal::number(1);
        }

        return ApproxTopK::fromArgList($args);
    }

    /**
     * @param  list<mixed>  $args
     */
    private static function buildToNumberSnowflake(array $args, bool $safe = false): ToNumber
    {
        $second = $args[1] ?? null;

        if ($second instanceof Expression && $second->isNumber()) {
            $fmt = null;
            $precision = $second;
            $scale = $args[2] ?? Literal::number(0);
        } else {
            $fmt = $second;
            $precision = $args[2] ?? Literal::number(38);
            $scale = $args[3] ?? Literal::number(0);
        }

        return new ToNumber(['this' => $args[0] ?? null, 'format' => $fmt, 'precision' => $precision, 'scale' => $scale, 'safe' => $safe]);
    }

    /** @param  list<mixed>  $args */
    protected function buildToNumber(array $args): ToNumber
    {
        return self::buildToNumberSnowflake($args);
    }

    /** @param  list<mixed>  $args */
    protected function buildToDecimal(array $args): ToNumber
    {
        return self::buildToNumberSnowflake($args);
    }

    /** @param  list<mixed>  $args */
    protected function buildToNumeric(array $args): ToNumber
    {
        return self::buildToNumberSnowflake($args);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryToNumber(array $args): ToNumber
    {
        return self::buildToNumberSnowflake($args, safe: true);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryToDecimal(array $args): ToNumber
    {
        return self::buildToNumberSnowflake($args, safe: true);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryToNumeric(array $args): ToNumber
    {
        return self::buildToNumberSnowflake($args, safe: true);
    }

    /** @param  list<mixed>  $args */
    protected function buildDateFromParts(array $args): DateFromParts
    {
        return new DateFromParts(['year' => $args[0] ?? null, 'month' => $args[1] ?? null, 'day' => $args[2] ?? null, 'allow_overflow' => true]);
    }

    /**
     * TO_DATE, TO_TIME, TO_TIMESTAMP* and their TRY_ variants.
     *
     * @param  list<mixed>  $args
     */
    protected function buildDatetime(array $args, string $name, string $kind, bool $safe = false): Expression
    {
        $value = $args[0] ?? null;
        $scaleOrFmt = $args[1] ?? null;
        $intValue = $value instanceof Expression && preg_match('/^\s*[+-]?\d+\s*$/', $value->name()) === 1;
        $intScaleOrFmt = $scaleOrFmt instanceof Expression && $scaleOrFmt->isInt();

        if ($value instanceof Literal || $value instanceof Neg || ($value instanceof Expression && $scaleOrFmt instanceof Expression)) {
            // TO_TIME('01:02:03') and friends become casts
            if (count($args) === 1 && $value->isString() && ! $intValue) {
                $cast = $safe
                    ? new TryCast(['this' => $value, 'to' => new DataType(['this' => $kind]), 'requires_string' => true])
                    : Builder::cast($value, $kind);

                if ($safe && $kind === DType::DATE) {
                    $cast->set('probe_date_format', true);
                }

                return $cast;
            }

            // TO_TIMESTAMP(str, fmt) and TO_TIMESTAMP(num, scale) are common, so they're special
            // cased to be transpilable
            if (isset(self::TIMESTAMP_TYPES[$kind])) {
                if (! $safe && ($intScaleOrFmt || ($intValue && $scaleOrFmt === null))) {
                    // TRY_TO_TIMESTAMP('integer') isn't easily transpilable, and numeric-looking
                    // strings with formats (TO_TIMESTAMP('20240115', 'YYYYMMDD')) are StrToTime
                    $unix = new UnixToTime(['this' => $value, 'scale' => $scaleOrFmt]);
                    $unix->set('target_type', new DataType(['this' => $kind]));

                    return $unix;
                }

                if ($scaleOrFmt instanceof Expression && ! $intScaleOrFmt) {
                    $strToTime = $this->buildFormattedTime($args, StrToTime::class);
                    $strToTime->set('safe', $safe);
                    $strToTime->set('target_type', new DataType(['this' => $kind]));

                    return $strToTime;
                }
            }
        }

        // DATE / TIME with a format string, which allows an integer value
        $hasFormatString = $scaleOrFmt instanceof Expression && ! $intScaleOrFmt;

        if (($kind === DType::DATE || $kind === DType::TIME) && (! $intValue || $hasFormatString)) {
            $formatted = $this->buildFormattedTime($args, $kind === DType::DATE ? TsOrDsToDate::class : TsOrDsToTime::class);
            $formatted->set('safe', $safe);

            return $formatted;
        }

        return new Anonymous(['this' => $name, 'expressions' => $args]);
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildBitwise(array $args, string $exprType, string $name): Expression
    {
        if (count($args) === 3) {
            // Bitwise AND / OR / XOR take a padside argument
            if (in_array($exprType, [BitwiseAnd::class, BitwiseOr::class, BitwiseXor::class], true)) {
                return new $exprType(['this' => $args[0], 'expression' => $args[1], 'padside' => $args[2]]);
            }

            return new Anonymous(['this' => $name, 'expressions' => $args]);
        }

        $result = $this->binaryFromFunction($args, $exprType);

        // Snowflake specifies INT128 for bitwise shifts
        if ($exprType === BitwiseLeftShift::class || $exprType === BitwiseRightShift::class) {
            $result->set('requires_int128', true);
        }

        return $result;
    }

    /**
     * https://docs.snowflake.com/en/sql-reference/functions/div0
     *
     * @param  list<mixed>  $args
     */
    protected function buildIfFromDiv0(array $args): IfExpr
    {
        $lhs = Builder::wrap($args[0] ?? null, Binary::class);
        $rhs = Builder::wrap($args[1] ?? null, Binary::class);
        $cond = Builder::and([new EQ(['this' => $rhs, 'expression' => Literal::number(0)]), Builder::not(new Is(['this' => $lhs, 'expression' => new NullExpr]))], copy: false);

        return new IfExpr(['this' => $cond, 'true' => Literal::number(0), 'false' => new Div(['this' => $lhs?->copy(), 'expression' => $rhs?->copy()])]);
    }

    /**
     * https://docs.snowflake.com/en/sql-reference/functions/div0null: 0 when the divisor is 0 or NULL.
     *
     * @param  list<mixed>  $args
     */
    protected function buildIfFromDiv0null(array $args): IfExpr
    {
        $lhs = Builder::wrap($args[0] ?? null, Binary::class);
        $rhs = Builder::wrap($args[1] ?? null, Binary::class);
        $cond = Builder::or([new EQ(['this' => $rhs, 'expression' => Literal::number(0)]), new Is(['this' => $rhs?->copy(), 'expression' => new NullExpr])], copy: false);

        return new IfExpr(['this' => $cond, 'true' => Literal::number(0), 'false' => new Div(['this' => $lhs, 'expression' => $rhs?->copy()])]);
    }

    /**
     * https://docs.snowflake.com/en/sql-reference/functions/zeroifnull
     *
     * @param  list<mixed>  $args
     */
    protected function buildIfFromZeroifnull(array $args): IfExpr
    {
        return new IfExpr(['this' => new Is(['this' => $args[0] ?? null, 'expression' => new NullExpr]), 'true' => Literal::number(0), 'false' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildIfFromNullifzero(array $args): IfExpr
    {
        return new IfExpr(['this' => new EQ(['this' => $args[0] ?? null, 'expression' => Literal::number(0)]), 'true' => new NullExpr, 'false' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSearch(array $args): Search
    {
        $kwargs = ['this' => $args[0] ?? null, 'expression' => $args[1] ?? null];

        foreach (array_slice($args, 2) as $arg) {
            if ($arg instanceof Kwarg) {
                $kwargs[strtolower($arg->name())] = $arg;
            }
        }

        return new Search($kwargs);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildRegexpReplace(array $args): RegexpReplace
    {
        $regexpReplace = RegexpReplace::fromArgList($args);

        if (! $this->truthyArg($regexpReplace, 'replacement')) {
            $regexpReplace->set('replacement', Literal::string(''));
        }

        return $regexpReplace;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildRegexpLike(array $args): RegexpLike
    {
        return new RegexpLike(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'flag' => $args[2] ?? null, 'full_match' => true]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function dateTruncToTimeSnowflake(array $args): Expression
    {
        $trunc = $this->dateTruncToTime($args);
        $unit = $this->mapDatePart($trunc->args['unit'] ?? null);
        $trunc->set('unit', $unit);
        $this_ = $trunc->args['this'] ?? null;
        $isTimeInput = $this_ instanceof Expression && $this_->isType(DType::TIME, DType::TIMETZ);
        $isDateUnit = $unit instanceof Expression && in_array(strtolower($unit->name()), ['day', 'week', 'month', 'quarter', 'year', 'year_month'], true);

        if (($trunc instanceof TimestampTrunc && $isDateUnit) || $isTimeInput || ($trunc instanceof DateTrunc && ! $isDateUnit)) {
            $trunc->set('input_type_preserved', true);
        }

        return $trunc;
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildRegexpExtractSnowflake(array $args, string $exprType): Expression
    {
        $kwargs = [
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'position' => $args[2] ?? null,
            'occurrence' => $args[3] ?? null,
            'parameters' => $args[4] ?? null,
            'group' => $args[5] ?? Literal::number(0),
        ];

        if ($exprType === RegexpExtract::class) {
            $kwargs['null_if_pos_overflow'] = $this->dialect->settings['REGEXP_EXTRACT_POSITION_OVERFLOW_RETURNS_NULL'];
        }

        return new $exprType($kwargs);
    }

    /**
     * TIMESTAMP_FROM_PARTS(year, month, day, hour, minute, second [, nanosecond] [, time_zone]) or
     * TIMESTAMP_FROM_PARTS(date_expr, time_expr).
     *
     * @param  list<mixed>  $args
     */
    protected function buildTimestampFromParts(array $args): Expression
    {
        if (count($args) === 2) {
            return new TimestampFromParts(['this' => $args[0], 'expression' => $args[1]]);
        }

        return TimestampFromParts::fromArgList($args);
    }

    /**
     * ROUND with Snowflake's named parameters, EXPR => this, SCALE => decimals and ROUNDING_MODE =>
     * truncate. Named and positional arguments can't be mixed.
     *
     * @param  list<mixed>  $args
     */
    protected function buildRound(array $args): Round
    {
        $kwargMap = ['EXPR' => 'this', 'SCALE' => 'decimals', 'ROUNDING_MODE' => 'truncate'];
        $positionalKeys = ['this', 'decimals', 'truncate'];
        $roundArgs = [];
        $positional = 0;

        foreach ($args as $arg) {
            if ($arg instanceof Kwarg) {
                $key = $kwargMap[strtoupper($arg->name())] ?? null;

                if ($key !== null) {
                    $roundArgs[$key] = $arg->args['expression'] ?? null;
                }
            } elseif ($positional < count($positionalKeys)) {
                $roundArgs[$positionalKeys[$positional++]] = $arg;
            }
        }

        $expression = new Round($roundArgs);
        $expression->set('casts_non_integer_decimals', true);

        return $expression;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildArraySort(array $args): SortArray
    {
        $asc = $args[1] ?? null;
        $nullsFirst = $args[2] ?? null;

        if ($nullsFirst === null && $asc instanceof Boolean) {
            $nullsFirst = new Boolean(['this' => ! ($asc->args['this'] ?? false)]);
        }

        return new SortArray(['this' => $args[0] ?? null, 'asc' => $asc, 'nulls_first' => $nullsFirst]);
    }

    /**
     * GENERATOR with Snowflake's named parameters, ROWCOUNT => rowcount and TIMELIMIT => timelimit.
     *
     * @param  list<mixed>  $args
     */
    protected function buildGenerator(array $args): Generator
    {
        $kwargMap = ['ROWCOUNT' => 'rowcount', 'TIMELIMIT' => 'timelimit'];
        $positionalKeys = ['rowcount', 'timelimit'];
        $genArgs = [];

        foreach ($args as $i => $arg) {
            if ($arg instanceof Kwarg) {
                $key = $kwargMap[strtoupper($arg->name())] ?? null;

                if ($key !== null) {
                    $genArgs[$key] = $arg->args['expression'] ?? null;
                }
            } elseif (isset($positionalKeys[$i])) {
                $genArgs[$positionalKeys[$i]] = $arg;
            }
        }

        return new Generator($genArgs);
    }

    /**
     * OBJECT_CONSTRUCT(k1, v1, ...) as a Struct of k => v pairs (OBJECT_CONSTRUCT(*) stays a
     * StarMap).
     *
     * @param  list<mixed>  $args
     */
    protected function buildObjectConstruct(array $args): Expression
    {
        $expression = $this->buildVarMap($args);

        if ($expression instanceof StarMap) {
            return $expression;
        }

        $keys = $expression->args['keys'] ?? null;
        $values = $expression->args['values'] ?? null;
        $keyList = $keys instanceof Expression ? $keys->argList('expressions') : [];
        $valueList = $values instanceof Expression ? $values->argList('expressions') : [];
        $pairs = [];

        foreach ($keyList as $i => $key) {
            if (! isset($valueList[$i])) {
                break;
            }

            $pairs[] = new PropertyEQ(['this' => $key, 'expression' => $valueList[$i]]);
        }

        return new Struct(['expressions' => $pairs]);
    }

    /** @param  list<mixed>  $args */
    protected function buildCharindex(array $args): Expression
    {
        return new StrPosition(['this' => $args[1] ?? null, 'substr' => $args[0] ?? null, 'position' => $args[2] ?? null, 'clamp_position' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildAddMonths(array $args): AddMonths
    {
        return new AddMonths(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'preserve_end_of_month' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildCurrentTime(array $args): Localtime
    {
        return new Localtime(['this' => $args[0] ?? null]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArrayConstruct(array $args): ArrayExpr
    {
        return new ArrayExpr(['expressions' => $args]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArrayContains(array $args): ArrayContains
    {
        return new ArrayContains(['this' => $args[1] ?? null, 'expression' => $args[0] ?? null, 'ensure_variant' => false, 'check_null' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArrayDistinct(array $args): ArrayDistinct
    {
        return new ArrayDistinct(['this' => $args[0] ?? null, 'check_null' => true]);
    }

    /**
     * Snowflake's end bound is exclusive.
     *
     * @param  list<mixed>  $args
     */
    protected function buildArrayGenerateRange(array $args): GenerateSeries
    {
        return new GenerateSeries(['start' => $args[0] ?? null, 'end' => $args[1] ?? null, 'step' => $args[2] ?? null, 'is_end_exclusive' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArrayExcept(array $args): ArrayExcept
    {
        return new ArrayExcept(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'is_multiset' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArrayIntersection(array $args): Expression
    {
        return new ArrayIntersect(['expressions' => $args, 'is_multiset' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArrayPosition(array $args): ArrayPosition
    {
        return new ArrayPosition(['this' => $args[1] ?? null, 'expression' => $args[0] ?? null, 'zero_based' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArraySlice(array $args): ArraySlice
    {
        return new ArraySlice(['this' => $args[0] ?? null, 'start' => $args[1] ?? null, 'end' => $args[2] ?? null, 'zero_based' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArrayToString(array $args): ArrayToString
    {
        return new ArrayToString(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'null_is_empty' => true, 'null_delim_is_null' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArraysOverlap(array $args): ArrayOverlaps
    {
        return new ArrayOverlaps(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'null_safe' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildBitnot(array $args): BitwiseNot
    {
        return new BitwiseNot(['this' => $args[0] ?? null]);
    }

    /** @param  list<mixed>  $args */
    protected function buildBooland(array $args): Booland
    {
        return new Booland(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'round_input' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildBoolor(array $args): Boolor
    {
        return new Boolor(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'round_input' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildBoolnot(array $args): Boolnot
    {
        return new Boolnot(['this' => $args[0] ?? null, 'round_input' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildBoolxor(array $args): XorExpr
    {
        return new XorExpr(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'round_input' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildCorr(array $args): Corr
    {
        return new Corr(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'null_on_zero_variance' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildCountIf(array $args): CountIf
    {
        return new CountIf(['this' => $args[0] ?? null, 'zero_on_all_null' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildDateadd(array $args): DateAdd
    {
        return new DateAdd(['this' => $args[2] ?? null, 'expression' => $args[1] ?? null, 'unit' => $this->mapDatePart($args[0] ?? null)]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTimestampadd(array $args): DateAdd
    {
        return $this->buildDateadd($args);
    }

    /** @param  list<mixed>  $args */
    protected function buildTimeadd(array $args): TimeAdd
    {
        return new TimeAdd(['this' => $args[2] ?? null, 'expression' => $args[1] ?? null, 'unit' => $this->mapDatePart($args[0] ?? null)]);
    }

    /** @param  list<mixed>  $args */
    protected function buildDatediff(array $args): DateDiff
    {
        return new DateDiff(['this' => $args[2] ?? null, 'expression' => $args[1] ?? null, 'unit' => $this->mapDatePart($args[0] ?? null), 'date_part_boundary' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTimediff(array $args): DateDiff
    {
        return $this->buildDatediff($args);
    }

    /** @param  list<mixed>  $args */
    protected function buildTimestampdiff(array $args): DateDiff
    {
        return $this->buildDatediff($args);
    }

    /** @param  list<mixed>  $args */
    protected function buildDayname(array $args): Dayname
    {
        return new Dayname(['this' => $args[0] ?? null, 'abbreviated' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildMonthname(array $args): Monthname
    {
        return new Monthname(['this' => $args[0] ?? null, 'abbreviated' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildEditdistance(array $args): Levenshtein
    {
        return new Levenshtein(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'max_dist' => $args[2] ?? null]);
    }

    /** @param  list<mixed>  $args */
    protected function buildGetPath(array $args): JSONExtract
    {
        $path = $args[1] ?? null;

        return new JSONExtract(['this' => $args[0] ?? null, 'expression' => $this->dialect->toJsonPath($path instanceof Expression ? $path : null), 'requires_json' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildGreatestIgnoreNulls(array $args): Greatest
    {
        return new Greatest(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1), 'ignore_nulls' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildLeastIgnoreNulls(array $args): Least
    {
        return new Least(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1), 'ignore_nulls' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildLeft(array $args): Left
    {
        return new Left(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'negative_length_returns_empty' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildRight(array $args): Right
    {
        return new Right(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'negative_length_returns_empty' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildJarowinklerSimilarity(array $args): JarowinklerSimilarity
    {
        return new JarowinklerSimilarity(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'case_insensitive' => true, 'integer_scale' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildLastDay(array $args): LastDay
    {
        return new LastDay(['this' => $args[0] ?? null, 'unit' => $this->mapDatePart($args[1] ?? null)]);
    }

    /** @param  list<mixed>  $args */
    protected function buildLength(array $args): Length
    {
        return new Length(['this' => $args[0] ?? null, 'binary' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildLen(array $args): Length
    {
        return $this->buildLength($args);
    }

    /** @param  list<mixed>  $args */
    protected function buildParseUrl(array $args): ParseUrl
    {
        return new ParseUrl(['this' => $args[0] ?? null, 'permissive' => $args[1] ?? null]);
    }

    /**
     * RANDOM([seed]) spans the BIGINT range; the bounds are floats to avoid overflow.
     *
     * @param  list<mixed>  $args
     */
    protected function buildRandom(array $args): Rand
    {
        // sqlglot's Literal.number(-2.0 ** 63) goes through Decimal, hence the upper case E
        return new Rand([
            'this' => $args[0] ?? null,
            'lower' => new Neg(['this' => Literal::number('9.223372036854776E+18')]),
            'upper' => Literal::number('9.223372036854776e+18'),
        ]);
    }

    /** @param  list<mixed>  $args */
    protected function buildSplit(array $args): Split
    {
        return new Split(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'null_returns_null' => true, 'empty_delimiter_returns_whole' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildSquare(array $args): Pow
    {
        return new Pow(['this' => $args[0] ?? null, 'expression' => Literal::number(2)]);
    }

    /** @param  list<mixed>  $args */
    protected function buildSysdate(array $args): CurrentTimestamp
    {
        return new CurrentTimestamp(['this' => $args[0] ?? null, 'sysdate' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTable(array $args): TableFromRows
    {
        return new TableFromRows(['this' => $args[0] ?? null]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTimeFromParts(array $args): TimeFromParts
    {
        return new TimeFromParts(['hour' => $args[0] ?? null, 'min' => $args[1] ?? null, 'sec' => $args[2] ?? null, 'nano' => $args[3] ?? null, 'overflow' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTrunc(array $args, bool $dateTruncUnabbreviate = true, ?string $defaultDateTruncUnit = null, bool $dateTruncRequiresPart = false, bool $fractionsSupported = true): Expression
    {
        return parent::buildTrunc($args, $dateTruncUnabbreviate, $defaultDateTruncUnit, $dateTruncRequiresPart, $fractionsSupported);
    }

    /** @param  list<mixed>  $args */
    protected function buildTruncate(array $args): Expression
    {
        return $this->buildTrunc($args);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryDecrypt(array $args): Decrypt
    {
        return new Decrypt(['this' => $args[0] ?? null, 'passphrase' => $args[1] ?? null, 'aad' => $args[2] ?? null, 'encryption_method' => $args[3] ?? null, 'safe' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryDecryptRaw(array $args): DecryptRaw
    {
        return new DecryptRaw([
            'this' => $args[0] ?? null, 'key' => $args[1] ?? null, 'iv' => $args[2] ?? null,
            'aad' => $args[3] ?? null, 'encryption_method' => $args[4] ?? null, 'aead' => $args[5] ?? null, 'safe' => true,
        ]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryParseJson(array $args): ParseJSON
    {
        return new ParseJSON(['this' => $args[0] ?? null, 'safe' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryToBinary(array $args): ToBinary
    {
        return new ToBinary(['this' => $args[0] ?? null, 'format' => $args[1] ?? null, 'safe' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryToBoolean(array $args): ToBoolean
    {
        return new ToBoolean(['this' => $args[0] ?? null, 'safe' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryToDouble(array $args): ToDouble
    {
        return new ToDouble(['this' => $args[0] ?? null, 'format' => $args[1] ?? null, 'safe' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildTryToFile(array $args): ToFile
    {
        return new ToFile(['this' => $args[0] ?? null, 'path' => $args[1] ?? null, 'safe' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildToGeography(array $args): Expression
    {
        return count($args) === 1 ? Builder::cast($args[0], DType::GEOGRAPHY) : new Anonymous(['this' => 'TO_GEOGRAPHY', 'expressions' => $args]);
    }

    /** @param  list<mixed>  $args */
    protected function buildToGeometry(array $args): Expression
    {
        return count($args) === 1 ? Builder::cast($args[0], DType::GEOMETRY) : new Anonymous(['this' => 'TO_GEOMETRY', 'expressions' => $args]);
    }

    /** @param  list<mixed>  $args */
    protected function buildSplitPart(array $args): SplitPart
    {
        return new SplitPart([
            'this' => $args[0] ?? null, 'delimiter' => $args[1] ?? null, 'part_index' => $args[2] ?? null,
            'part_index_zero_as_one' => true, 'empty_delimiter_returns_whole' => true,
        ]);
    }

    /** @param  list<mixed>  $args */
    protected function buildStrtok(array $args): Strtok
    {
        return new Strtok(['this' => $args[0] ?? null, 'delimiter' => $args[1] ?? Literal::string(' '), 'part_index' => $args[2] ?? Literal::number('1')]);
    }

    /** @param  list<mixed>  $args */
    protected function buildStrtokToArray(array $args): StrtokToArray
    {
        return new StrtokToArray(['this' => $args[0] ?? null, 'expression' => $args[1] ?? Literal::string(' ')]);
    }

    /** @param  list<mixed>  $args */
    protected function buildUnicode(array $args): Unicode
    {
        return new Unicode(['this' => $args[0] ?? null, 'empty_is_zero' => true]);
    }

    private function truthyArg(Expression $expression, string $key): bool
    {
        $value = $expression->args[$key] ?? null;

        return $value !== null && $value !== false && $value !== '' && $value !== [];
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTION_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function parseDirectory(): DirectoryStage
    {
        $table = $this->parseTableParts();
        $this_ = $table instanceof Table ? ($table->args['this'] ?? null) : $table;

        return $this->expression(new DirectoryStage(['this' => $this_]));
    }

    protected function parseObjectConstructKeepNull(): Expression
    {
        $index = $this->index;
        $column = $this->parseColumn();

        if ($column !== null && $column->isStar()) {
            return $this->expression(new JSONObject(['expressions' => [$column]]));
        }

        $this->retreat($index);

        return $this->parseJsonObject(agg: false);
    }

    /**
     * DATE_PART(part, expr) and DATE_PART(part FROM expr).
     * https://docs.snowflake.com/en/sql-reference/functions/date_part.html
     */
    protected function parseDatePart(): ?Expression
    {
        $this_ = $this->parseVar() ?? $this->parseType();

        if ($this_ === null) {
            return null;
        }

        $expression = $this->matchSet([TokenType::FROM => true, TokenType::COMMA => true]) ? $this->parseBitwise() : false;

        return $this->expression(new Extract(['this' => $this->mapDatePart($this_), 'expression' => $expression]));
    }

    protected function parseSemanticView(): SemanticView
    {
        $kwargs = ['this' => $this->parseTableParts()];

        while ($this->curr !== 0 && ! $this->match(TokenType::R_PAREN, false)) {
            if ($this->matchTexts(['DIMENSIONS' => true, 'METRICS' => true, 'FACTS' => true])) {
                $keyword = strtolower($this->prevText());
                $kwargs[$keyword] = $this->parseCsv(fn (): ?Expression => $this->parseAlias($this->parseDisjunction(), explicit: true));
            } elseif ($this->matchTextSeq('WHERE')) {
                $kwargs['where'] = $this->parseExpression();
            } else {
                $this->raiseError('Expecting ) or encountered unexpected keyword');

                break;
            }
        }

        return $this->expression(new SemanticView($kwargs));
    }

    // ---------------------------------------------------------------------------------------
    // PROPERTY / CONSTRAINT / ALTER / STATEMENT / RANGE / COLUMN / LAMBDA / SHOW parsers
    // ---------------------------------------------------------------------------------------

    protected function parseCredentialsProperty(): CredentialsProperty
    {
        return $this->expression(new CredentialsProperty(['expressions' => $this->parseWrappedOptions()]));
    }

    protected function parseFileFormatProperty(): FileFormatProperty
    {
        $this->match(TokenType::EQ);
        $expressions = $this->match(TokenType::L_PAREN, false) ? $this->parseWrappedOptions() : [$this->parseFormatName()];

        return $this->expression(new FileFormatProperty(['expressions' => $expressions]));
    }

    protected function parseLocationProperty(): LocationProperty
    {
        $this->match(TokenType::EQ);

        return $this->expression(new LocationProperty(['this' => $this->parseLocationPath()]));
    }

    protected function propertyRow(): ?Expression
    {
        return $this->matchTextSeq('ACCESS', 'POLICY') ? $this->parseRowAccessPolicy() : $this->parseRow();
    }

    protected function propertyUsing(): ?Expression
    {
        return $this->matchTextSeq('TEMPLATE') ? $this->expression(new UsingTemplateProperty(['this' => $this->parseStatement()])) : null;
    }

    protected function parseTag(): Tags
    {
        return $this->expression(new Tags(['expressions' => $this->parseWrappedCsv(fn (): Expression|array|null => $this->parseProperty())]));
    }

    protected function parseWithConstraint(): ?Expression
    {
        if ($this->prev !== TokenType::WITH) {
            $this->retreat($this->index - 1);
        }

        if ($this->matchTextSeq('MASKING', 'POLICY')) {
            $policy = $this->parseColumn();

            return $this->expression(new MaskingPolicyColumnConstraint([
                'this' => $policy instanceof Column ? $policy->toDot() : $policy,
                'expressions' => $this->match(TokenType::USING) ? $this->parseWrappedCsv(fn (): ?Expression => $this->parseIdVar()) : false,
            ]));
        }

        if ($this->matchTextSeq('PROJECTION', 'POLICY')) {
            $policy = $this->parseColumn();

            return $this->expression(new ProjectionPolicyColumnConstraint(['this' => $policy instanceof Column ? $policy->toDot() : $policy]));
        }

        if ($this->match(TokenType::TAG)) {
            return $this->parseTag();
        }

        return null;
    }

    protected function parseWithProperty(): Expression|array|null
    {
        if ($this->match(TokenType::TAG)) {
            return $this->parseTag();
        }

        if ($this->matchTextSeq('ROW', 'ACCESS', 'POLICY')) {
            return $this->parseRowAccessPolicy();
        }

        return parent::parseWithProperty();
    }

    private function parseRowAccessPolicy(): RowAccessProperty
    {
        // GET_DDL outputs #unknown_policy when the user can't see the policy name
        if ($this->match(TokenType::HASH)) {
            $policy = $this->parseVar(anyToken: true);

            if ($policy !== null) {
                $policy = new VarExpr(['this' => '#'.$policy->name()]);
            }

            $expressions = null;
        } else {
            $policy = $this->parseColumn();

            if ($policy instanceof Column) {
                $policy = $policy->toDot();
            }

            if (! $this->match(TokenType::ON)) {
                $this->raiseError('Expected ON after ROW ACCESS POLICY name');
            }

            $expressions = $this->parseWrappedCsv(fn (): ?Expression => $this->parseIdVar());
        }

        return $this->expression(new RowAccessProperty(['this' => $policy, 'expressions' => $expressions]));
    }

    protected function alterUnset(): Expression
    {
        return $this->expression(new Set([
            'tag' => $this->matchTextSeq('TAG'),
            'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseIdVar()),
            'unset' => true,
        ]));
    }

    protected function rangeRlike(?Expression $this_): ?Expression
    {
        return $this->expression(new RegexpLike(['this' => $this_, 'expression' => $this->parseBitwise(), 'full_match' => true]));
    }

    protected function columnOpExclamation(?Expression $this_, ?Expression $attr): Expression
    {
        return $this->expression(new ModelAttribute(['this' => $this_, 'expression' => $attr]));
    }

    /**
     * @param  list<Expression>  $expressions
     */
    protected function lambdaArrow(array $expressions): Expression
    {
        return $this->expression(new Lambda([
            'this' => $this->replaceLambda($this->parseAssignment(), $expressions),
            'expressions' => array_map(static fn (Expression $e): Expression => $e instanceof Cast ? $e->args['this'] : $e, $expressions),
        ]));
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, bool>  $kwargs
     */
    protected function showParser(array $args, array $kwargs = []): Show
    {
        return $this->parseShowSnowflake($args[0], terse: $kwargs['terse'] ?? false, iceberg: $kwargs['iceberg'] ?? false);
    }

    /**
     * @param  class-string<Expression>  $class
     */
    private function describeQualifier(string $class): Expression
    {
        return $this->expression(new $class);
    }

    protected function describeQualifierParsersApi(): ?Expression
    {
        return $this->describeQualifier(ApiProperty::class);
    }

    protected function describeQualifierParsersApplication(): ?Expression
    {
        return $this->describeQualifier(ApplicationProperty::class);
    }

    protected function describeQualifierParsersCatalog(): ?Expression
    {
        return $this->describeQualifier(CatalogProperty::class);
    }

    protected function describeQualifierParsersCompute(): ?Expression
    {
        return $this->describeQualifier(ComputeProperty::class);
    }

    protected function describeQualifierParsersDatabase(): ?Expression
    {
        return $this->curr !== 0 && strtoupper($this->currText()) === 'ROLE' ? $this->describeQualifier(DatabaseProperty::class) : null;
    }

    protected function describeQualifierParsersDynamic(): ?Expression
    {
        return $this->describeQualifier(DynamicProperty::class);
    }

    protected function describeQualifierParsersExternal(): ?Expression
    {
        return $this->describeQualifier(ExternalProperty::class);
    }

    protected function describeQualifierParsersHybrid(): ?Expression
    {
        return $this->describeQualifier(HybridProperty::class);
    }

    protected function describeQualifierParsersIceberg(): ?Expression
    {
        return $this->describeQualifier(IcebergProperty::class);
    }

    protected function describeQualifierParsersMasking(): ?Expression
    {
        return $this->describeQualifier(MaskingProperty::class);
    }

    protected function describeQualifierParsersMaterialized(): ?Expression
    {
        return $this->describeQualifier(MaterializedProperty::class);
    }

    protected function describeQualifierParsersNetwork(): ?Expression
    {
        return $this->describeQualifier(NetworkProperty::class);
    }

    protected function describeQualifierParsersRow(): ?Expression
    {
        return $this->matchTextSeq('ACCESS') ? $this->describeQualifier(RowAccessProperty::class) : null;
    }

    protected function describeQualifierParsersSecurity(): ?Expression
    {
        return $this->curr !== 0 && strtoupper($this->currText()) === 'INTEGRATION' ? $this->describeQualifier(SecurityIntegrationProperty::class) : null;
    }

    protected function parseGet(): ?Expression
    {
        $start = $this->index - 1;

        // GET( is a function call, not a statement
        if ($this->match(TokenType::L_PAREN)) {
            $this->retreat($this->index - 2);

            return $this->parseExpression();
        }

        $target = $this->parseLocationPath();

        // An unquoted file path is parsed as a command
        if ($this->curr === TokenType::URI_START) {
            return $this->parseAsCommand($start);
        }

        return $this->expression(new Get(['this' => $this->parseString(), 'target' => $target, 'properties' => $this->parseProperties()]));
    }

    protected function parsePut(): Expression
    {
        if ($this->curr !== TokenType::STRING && $this->curr !== TokenType::URI_START) {
            return $this->parseAsCommand($this->index - 1);
        }

        return $this->expression(new Put([
            'this' => $this->parseString() ?? Literal::string($this->parseLocationPath()->name()),
            'target' => $this->parseLocationPath(),
            'properties' => $this->parseProperties(),
        ]));
    }

    protected function parseUndrop(): Expression
    {
        $start = $this->index - 1;
        $kind = $this->parseVarFromOptions(self::UNDROP_OBJECTS, raiseUnmatched: false);

        if ($kind === null) {
            return $this->parseAsCommand($start);
        }

        $this_ = $this->parseTableParts(isDbReference: in_array($kind->name(), ['ACCOUNT', 'DATABASE', 'SCHEMA'], true));
        $rename = $this->matchTextSeq('RENAME', 'TO') ? $this->parseTableParts() : null;

        return $this->expression(new Undrop(['this' => $this_, 'kind' => $kind->name(), 'rename' => $rename]));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseDescribe(): Describe
    {
        $index = $this->index;
        $qualifiers = $this->t['DESCRIBE_QUALIFIER_PARSERS'];

        if ($this->matchTexts($qualifiers)) {
            $qualifier = $this->callHandler($qualifiers[strtoupper($this->prevText())]);

            if ($qualifier instanceof Expression) {
                $kind = $this->matchSet($this->t['CREATABLES']) ? strtoupper($this->prevText()) : null;

                if ($kind !== null) {
                    $this_ = $this->parseTable(schema: true);
                    $properties = $this->expression(new Properties(['expressions' => [$qualifier]]));
                    $postProps = $this->parseProperties();

                    return $this->expression(new Describe([
                        'this' => $this_,
                        'kind' => $kind,
                        'properties' => $properties,
                        'expressions' => $postProps?->argList('expressions'),
                    ]));
                }
            }
        }

        $this->retreat($index);

        return parent::parseDescribe();
    }

    protected function parseUse(): UseExpr
    {
        if ($this->matchTextSeq('SECONDARY', 'ROLES')) {
            $this_ = $this->matchTexts(['ALL' => true, 'NONE' => true]) ? Builder::var(strtoupper($this->prevText())) : null;
            $roles = $this_ !== null ? null : $this->parseCsv(fn (): ?Expression => $this->parseTable(schema: false));

            return $this->expression(new UseExpr(['kind' => 'SECONDARY ROLES', 'this' => $this_, 'expressions' => $roles]));
        }

        return parent::parseUse();
    }

    /**
     * `value NOT IN (subquery)` is `value <> ALL (subquery)` in Snowflake, not `NOT value IN
     * (subquery)`, which can give different results.
     * https://docs.snowflake.com/en/sql-reference/functions/in
     */
    protected function negateRange(?Expression $this_ = null): ?Expression
    {
        if ($this_ === null) {
            return null;
        }

        $query = $this_->args['query'] ?? null;

        if ($this_ instanceof In && $query instanceof Query && $query instanceof Expression) {
            return $this->expression(new NEQ(['this' => $this_->args['this'] ?? null, 'expression' => new All(['this' => $query->unnest()])]));
        }

        return $this->expression(new Not(['this' => $this_]));
    }

    protected function parsePropertyBefore(): Expression|array|null
    {
        $prop = parent::parsePropertyBefore();

        if ($prop !== null && $prop !== []) {
            return $prop;
        }

        if ($this->next !== TokenType::EQ) {
            return null;
        }

        return $this->parseSequenceProperties() ?? $this->parseKeyValueProperty(fn (): ?Expression => $this->parsePrimaryOrVar());
    }

    protected function parseCreate(): Expression
    {
        $expression = parent::parseCreate();

        if ($expression instanceof Create && isset(self::NON_TABLE_CREATABLES[$expression->text('kind')])) {
            // The Table node becomes the identifier it wraps
            $table = $expression->args['this'] ?? null;

            if ($table instanceof Expression && ($table->args['this'] ?? null) instanceof Expression) {
                $table->replace($table->args['this']);
            }
        }

        return $expression;
    }

    protected function parseBracketKeyValue(bool $isMap = false): ?Expression
    {
        if ($isMap) {
            // Keys of Snowflake objects are strings
            // https://docs.snowflake.com/en/sql-reference/data-types-semistructured
            return $this->parseSlice($this->parseString()) ?? $this->parseAssignment();
        }

        return $this->parseSlice($this->parseAlias($this->parseAssignment(), explicit: true));
    }

    protected function parseLateral(): ?Lateral
    {
        $lateral = parent::parseLateral();

        if ($lateral === null) {
            return null;
        }

        if (($lateral->args['this'] ?? null) instanceof Explode) {
            $tableAlias = $lateral->args['alias'] ?? null;
            $columns = array_map(static fn (string $c): ?\LaravelSqlParser\LaravelSqlParser\Expressions\Identifier => Builder::toIdentifier($c), self::FLATTEN_COLUMNS);

            if ($tableAlias instanceof Expression && $tableAlias->argList('columns') === []) {
                $tableAlias->set('columns', $columns);
            } elseif ($tableAlias === null) {
                Builder::alias($lateral, '_flattened', table: self::FLATTEN_COLUMNS, copy: false);
            }
        }

        return $lateral;
    }

    protected function parseTableParts(bool $schema = false, bool $isDbReference = false, bool $wildcard = false, bool $fast = false): ?Expression
    {
        // https://docs.snowflake.com/en/user-guide/querying-stage
        if ($this->match(TokenType::STRING, false)) {
            $table = $this->parseString();
        } elseif ($this->peekTextSeq('@')) {
            $table = $this->parseLocationPath();
        } else {
            $table = null;
        }

        if ($table === null) {
            return parent::parseTableParts(schema: $schema, isDbReference: $isDbReference, fast: $fast);
        }

        $fileFormat = null;
        $pattern = null;
        $wrapped = $this->match(TokenType::L_PAREN);

        while ($this->curr !== 0 && $wrapped && ! $this->match(TokenType::R_PAREN)) {
            if ($this->matchTextSeq('FILE_FORMAT', '=>')) {
                $fileFormat = $this->parseString() ?? parent::parseTableParts(isDbReference: $isDbReference);
            } elseif ($this->matchTextSeq('PATTERN', '=>')) {
                $pattern = $this->parseString();
            } else {
                break;
            }

            $this->match(TokenType::COMMA);
        }

        return $this->expression(new Table(['this' => $table, 'format' => $fileFormat, 'pattern' => $pattern]));
    }

    protected function parseFunctionCall(?array $functions = null, bool $anonymous = false, bool $optionalParens = true, bool $anyToken = false): ?Expression
    {
        $this_ = parent::parseFunctionCall(functions: $functions, anonymous: $anonymous, optionalParens: $optionalParens, anyToken: $anyToken);

        // A dynamically resolved function, IDENTIFIER('my_func')(1, 2): the trailing argument list
        // is the call's arguments. https://docs.snowflake.com/en/sql-reference/identifier-literal
        if ($this_ instanceof DynamicIdentifier && $this->match(TokenType::L_PAREN, false)) {
            $this_->set('expressions', $this->parseWrappedCsv(fn (): ?Expression => $this->parseLambda()));
        }

        return $this_;
    }

    protected function parseIdVar(bool $anyToken = true, ?array $tokens = null): ?Expression
    {
        if ($this->matchTextSeq('IDENTIFIER', '(')) {
            $identifier = parent::parseIdVar(anyToken: $anyToken, tokens: $tokens) ?? $this->parseString();
            $this->matchRParen();

            return $this->expression(new DynamicIdentifier(['this' => $identifier]));
        }

        return parent::parseIdVar(anyToken: $anyToken, tokens: $tokens);
    }

    private function parseShowSnowflake(string $this_, bool $terse = false, bool $iceberg = false): Show
    {
        $scope = null;
        $scopeKind = null;
        $history = $this->matchTextSeq('HISTORY');
        $like = $this->match(TokenType::LIKE) ? $this->parseString() : null;

        if ($this->match(TokenType::IN)) {
            if ($this->matchTextSeq('ACCOUNT')) {
                $scopeKind = 'ACCOUNT';
            } elseif ($this->matchTextSeq('CLASS')) {
                $scopeKind = 'CLASS';
                $scope = $this->parseTableParts();
            } elseif ($this->matchTextSeq('APPLICATION')) {
                $scopeKind = 'APPLICATION';

                if ($this->matchTextSeq('PACKAGE')) {
                    $scopeKind .= ' PACKAGE';
                }

                $scope = $this->parseTableParts();
            } elseif ($this->matchSet($this->t['DB_CREATABLES'])) {
                $scopeKind = strtoupper($this->prevText());

                if ($this->curr !== 0) {
                    $scope = $this->parseTableParts();
                }
            } elseif ($this->curr !== 0) {
                $scopeKind = isset(self::SCHEMA_KINDS[$this_]) ? 'SCHEMA' : 'TABLE';
                $scope = $this->parseTableParts();
            }
        }

        return $this->expression(new Show([
            'terse' => $terse,
            'iceberg' => $iceberg,
            'this' => $this_,
            'history' => $history,
            'like' => $like,
            'scope' => $scope,
            'scope_kind' => $scopeKind,
            'starts_with' => $this->matchTextSeq('STARTS', 'WITH') ? $this->parseString() : false,
            'limit' => $this->parseLimit(),
            'from_' => $this->match(TokenType::FROM) ? $this->parseString() : null,
            'privileges' => $this->matchTextSeq('WITH', 'PRIVILEGES') ? $this->parseCsv(fn (): ?Expression => $this->parseVar(anyToken: true, upper: true)) : false,
        ]));
    }

    private function parseLocationPath(): VarExpr
    {
        $start = $this->index;
        $this->advanceAny(ignoreReserved: true);

        // Commas aren't consumed, since staged files @foo and @bar can be comma joined, nor closing
        // parens, for subqueries
        while ($this->isConnected() && ! $this->matchSet([TokenType::COMMA => true, TokenType::L_PAREN => true, TokenType::R_PAREN => true], false)) {
            $this->advanceAny(ignoreReserved: true);
        }

        return Builder::var($this->findSql($start, $this->index - 1));
    }

    /**
     * A subquery or a staged file.
     */
    protected function parseFileLocation(): ?Expression
    {
        return $this->match(TokenType::L_PAREN, false)
            ? $this->parseSelect(table: true, parseSubqueryAlias: false)
            : $this->parseTableParts();
    }

    protected function parseLambdaArg(): ?Expression
    {
        $this_ = parent::parseLambdaArg();

        if ($this_ === null) {
            return null;
        }

        $type = $this->parseTypes();

        return $type !== null ? $this->expression(new Cast(['this' => $this_, 'to' => $type])) : $this_;
    }

    protected function parseForeignKey(): ForeignKey
    {
        // An inline foreign key: the REFERENCES columns are implied
        if ($this->match(TokenType::REFERENCES, false)) {
            return $this->expression(new ForeignKey);
        }

        // An out-of-line foreign key names the columns
        return parent::parseForeignKey();
    }

    protected function parseSet(bool $unset = false, bool $tag = false): Expression
    {
        $set = parent::parseSet(unset: $unset, tag: $tag);

        if ($set instanceof Set) {
            foreach ($set->argList('expressions') as $expression) {
                if ($expression instanceof SetItem) {
                    $expression->set('kind', 'VARIABLE');
                }
            }
        }

        return $set;
    }

    protected function parsePosition(bool $haystackFirst = false): StrPosition
    {
        $result = parent::parsePosition($haystackFirst);
        $result->set('clamp_position', true);

        return $result;
    }

    protected function parseSubstring(): Substring
    {
        $result = parent::parseSubstring();
        $result->set('zero_start', true);

        return $result;
    }

    public function buildCast(bool $strict, array $args): Expression
    {
        $to = $args['to'] ?? null;

        if (! $strict && $to instanceof DataType && ($to->args['this'] ?? null) === DType::BOOLEAN) {
            return $this->expression(new ToBoolean(['this' => $args['this'] ?? null, 'safe' => true]));
        }

        $cast = parent::buildCast($strict, $args);

        if ($cast instanceof TryCast && $to instanceof DataType) {
            $castThis = $cast->args['this'] ?? null;

            if (in_array($to->args['this'] ?? null, DataType::TEXT_TYPES, true) && $to->argList('expressions') !== []) {
                $cast->set('null_on_text_overflow', true);
            } elseif (($to->args['this'] ?? null) === DType::DATE && $castThis instanceof Expression && $castThis->isString()) {
                $cast->set('probe_date_format', true);
            }
        }

        return $cast;
    }

    protected function parseWindow(?Expression $this_, bool $alias = false): ?Expression
    {
        $result = parent::parseWindow($this_, $alias);

        // Ranking functions get a default window frame
        if ($result instanceof Window && ($this_ instanceof FirstValue || $this_ instanceof LastValue || $this_ instanceof NthValue) && ! $this->truthyArg($result, 'spec')) {
            $result->set('spec', new WindowSpec([
                'kind' => 'ROWS',
                'start' => 'UNBOUNDED',
                'start_side' => 'PRECEDING',
                'end' => 'UNBOUNDED',
                'end_side' => 'FOLLOWING',
            ]));
        }

        return $result;
    }
}
