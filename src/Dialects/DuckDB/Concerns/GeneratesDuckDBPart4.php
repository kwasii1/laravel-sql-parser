<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Aliases;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\AnyValue;
use LaravelSqlParser\LaravelSqlParser\Expressions\ApproxQuantile;
use LaravelSqlParser\LaravelSqlParser\Expressions\ApproxQuantiles;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayCompact;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayFilter;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArraySize;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayToString;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Chr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Coalesce;
use LaravelSqlParser\LaravelSqlParser\Expressions\Concat;
use LaravelSqlParser\LaravelSqlParser\Expressions\Corr;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DPipe;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Encode;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Filter;
use LaravelSqlParser\LaravelSqlParser\Expressions\First;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Generator as GeneratorFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Greatest;
use LaravelSqlParser\LaravelSqlParser\Expressions\GTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Hex;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\IntervalSpan;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\IsNan;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;
use LaravelSqlParser\LaravelSqlParser\Expressions\Kwarg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lambda;
use LaravelSqlParser\LaravelSqlParser\Expressions\Length;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lower;
use LaravelSqlParser\LaravelSqlParser\Expressions\LT;
use LaravelSqlParser\LaravelSqlParser\Expressions\LTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Map;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\Neg;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Nullif;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Placeholder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Posexplode;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpExtractAll;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpLike;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpReplace;
use LaravelSqlParser\LaravelSqlParser\Expressions\Repeat;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Slice;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sub;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Substring;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\ToBase64;
use LaravelSqlParser\LaravelSqlParser\Expressions\ToMap;
use LaravelSqlParser\LaravelSqlParser\Expressions\Transform;
use LaravelSqlParser\LaravelSqlParser\Expressions\Trim;
use LaravelSqlParser\LaravelSqlParser\Expressions\TryCast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unhex;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;

/**
 * DuckDB generator, part 4 (sqlglot generators/duckdb.py, from arrayuniqueagg_sql to uuid_sql).
 */
trait GeneratesDuckDBPart4
{
    private const string P4_ARRAYS_ZIP_TEMPLATE = <<<'SQL'
        CASE WHEN :null_check THEN NULL
        WHEN :all_empty_check THEN [:empty_struct]
        ELSE LIST_TRANSFORM(RANGE(0, :max_len), __i -> :transform_struct)
        END
        SQL;

    private const string P4_UUID_V5_TEMPLATE = <<<'SQL'
        (SELECT
            LOWER(
                SUBSTR(h, 1, 8) || '-' ||
                SUBSTR(h, 9, 4) || '-' ||
                '5' || SUBSTR(h, 14, 3) || '-' ||
                FORMAT('{:02x}', CAST('0x' || SUBSTR(h, 17, 2) AS INT) & 63 | 128) || SUBSTR(h, 19, 2) || '-' ||
                SUBSTR(h, 21, 12)
            )
        FROM (
            SELECT SUBSTR(SHA1(UNHEX(REPLACE(:namespace, '-', '')) || ENCODE(:name, 'utf8')), 1, 32) AS h
        ))
        SQL;

    private const string P4_ARRAY_EXCEPT_CONDITION = 'LEN(LIST_FILTER(:arr1[1:pair[1]], e -> e IS NOT DISTINCT FROM pair[0]))'
        .' > LEN(LIST_FILTER(:arr2, e -> e IS NOT DISTINCT FROM pair[0]))';

    private const string P4_ARRAY_INTERSECTION_CONDITION = 'LEN(LIST_FILTER(:arr1[1:pair[1]], e -> e IS NOT DISTINCT FROM pair[0]))'
        .' <= LEN(LIST_FILTER(:arr2, e -> e IS NOT DISTINCT FROM pair[0]))';

    private const string P4_ARRAY_EXCEPT_SET_TEMPLATE = <<<'SQL'
        CASE
            WHEN :arr1 IS NULL OR :arr2 IS NULL THEN NULL
            ELSE LIST_FILTER(
                LIST_DISTINCT(:arr1),
                e -> LEN(LIST_FILTER(:arr2, x -> x IS NOT DISTINCT FROM e)) = 0
            )
        END
        SQL;

    private const string P4_MAPCAT_TEMPLATE = <<<'SQL'
        CASE
            WHEN :map1 IS NULL OR :map2 IS NULL THEN NULL
            ELSE MAP_FROM_ENTRIES(LIST_FILTER(LIST_TRANSFORM(
                LIST_DISTINCT(LIST_CONCAT(MAP_KEYS(:map1), MAP_KEYS(:map2))),
                __k -> STRUCT_PACK(
                    key := __k,
                    value := COALESCE(:map2[__k], :map1[__k])
                )
            ), __x -> __x.value IS NOT NULL))
        END
        SQL;

    private const string P4_STRTOK_TO_ARRAY_TEMPLATE = <<<'SQL'
        CASE WHEN :delimiter IS NULL THEN NULL
        ELSE LIST_FILTER(
            REGEXP_SPLIT_TO_ARRAY(:string, CASE WHEN :delimiter = '' THEN '.^' ELSE CONCAT('[', :escaped, ']') END),
            x -> NOT x = ''
        ) END
        SQL;

    private const string P4_STRTOK_TEMPLATE = <<<'SQL'
        CASE
            WHEN :delimiter = '' AND :string = '' THEN NULL
            WHEN :delimiter = '' AND :part_index = 1 THEN :string
            WHEN :delimiter = '' THEN NULL
            WHEN :part_index < 0 THEN NULL
            WHEN :string IS NULL OR :delimiter IS NULL OR :part_index IS NULL THEN NULL
            ELSE :base_func
        END
        SQL;

    private const string P4_TRYCAST_DATE_SLASH_FMT = '%m/%d/%Y';

    private const string P4_TRYCAST_DATE_MON_FMT = '%d-%b-%Y';

    private const array P4_DATE_UNITS = ['day', 'week', 'month', 'quarter', 'year', 'year_month'];

    /**
     * Parse one of this part's templates (base dialect, like exp.maybe_parse) and substitute
     * its named placeholders (exp.replace_placeholders).
     *
     * @param  array<string, Expression>  $replacements
     */
    private function duckdbPart4Template(string $sql, array $replacements = []): Expression
    {
        $template = Dialect::get()->parseOne($sql);

        if ($replacements === []) {
            return $template;
        }

        return $template->transform(static function (Expression $node) use ($replacements): Expression {
            if ($node instanceof Placeholder) {
                $name = $node->args['this'] ?? null;

                if (is_string($name) && $name !== '' && isset($replacements[$name])) {
                    return $replacements[$name]->copy();
                }
            }

            return $node;
        }) ?? $template;
    }

    /**
     * exp.case().when(...).else_(...)
     *
     * @param  list<array{0: Expression, 1: Expression}>  $whens
     */
    private function duckdbPart4Case(array $whens, ?Expression $default = null): CaseExpr
    {
        $ifs = [];
        foreach ($whens as [$condition, $then]) {
            $ifs[] = new IfExpr(['this' => $condition->copy(), 'true' => $then->copy()]);
        }

        return new CaseExpr(['ifs' => $ifs, 'default' => $default?->copy()]);
    }

    private function duckdbPart4IsNull(Expression $expression): Expression
    {
        return Builder::binop(Is::class, $expression, new NullExpr);
    }

    private function duckdbPart4Identifier(string $name): Identifier
    {
        return new Identifier(['this' => $name]);
    }

    private function duckdbPart4IsDateUnit(?Expression $expression): bool
    {
        return $expression !== null && in_array(strtolower($expression->name()), self::P4_DATE_UNITS, true);
    }

    /** Python's Decimal(i) / Decimal(n) string for 0 <= i <= n (28 significant digits, half-even). */
    private function duckdbPart4DecimalRatio(int $numerator, int $denominator): string
    {
        $integer = intdiv($numerator, $denominator);
        $remainder = $numerator % $denominator;

        if ($remainder === 0) {
            return (string) $integer;
        }

        $digits = '';
        $significant = $integer > 0 ? strlen((string) $integer) : 0;

        while ($remainder !== 0 && $significant < 28) {
            $remainder *= 10;
            $digit = intdiv($remainder, $denominator);
            $remainder %= $denominator;
            $digits .= (string) $digit;

            if ($significant > 0 || $digit > 0) {
                $significant++;
            }
        }

        if ($remainder !== 0) {
            $twice = $remainder * 2;
            $last = (int) substr($digits, -1);
            $roundUp = $twice > $denominator || ($twice === $denominator && $last % 2 === 1);

            if ($roundUp) {
                $chars = str_split((string) $integer.$digits);
                $i = count($chars) - 1;
                while ($i >= 0) {
                    if ($chars[$i] === '9') {
                        $chars[$i] = '0';
                        $i--;

                        continue;
                    }
                    $chars[$i] = (string) ((int) $chars[$i] + 1);

                    break;
                }
                $all = ($i < 0 ? '1' : '').implode('', $chars);
                $integerLength = strlen($all) - strlen($digits);
                $integer = (int) substr($all, 0, $integerLength);
                $digits = substr($all, $integerLength);
            }
        }

        $digits = rtrim($digits, '0');

        return $digits === '' ? (string) $integer : $integer.'.'.$digits;
    }

    protected function arrayuniqueaggSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        return $this->sql(new Filter([
            'this' => Builder::func('LIST', [new Distinct(['expressions' => [$this_]])]),
            'expression' => new Where(['this' => Builder::not($this->duckdbPart4IsNull($this_?->copy() ?? new NullExpr))]),
        ]));
    }

    protected function arrayconcataggSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ instanceof Limit) {
            $this->unsupported('LIMIT in ARRAY_CONCAT_AGG cannot be transpiled to DuckDB');
            $this_ = $this_->argExpr('this');
        }

        $inner = $this_ instanceof Order ? $this_->argExpr('this') : $this_;

        return $this->func(
            'FLATTEN',
            new Filter([
                'this' => new ArrayAgg(['this' => $this_]),
                'expression' => new Where(['this' => Builder::not($this->duckdbPart4IsNull($inner?->copy() ?? new NullExpr))]),
            ]),
        );
    }

    protected function arrayunionaggSql(Expression $expression): string
    {
        $this->unsupported('ARRAY_UNION_AGG is not supported in DuckDB');

        return $this->functionFallbackSql($expression);
    }

    protected function arraydistinctSql(Expression $expression): string
    {
        $arr = $expression->argExpr('this');
        $func = $this->func('LIST_DISTINCT', $arr);

        if ($expression->flag('check_null') && $arr !== null) {
            $addNullToArray = Builder::func('LIST_APPEND', [
                Builder::func('LIST_DISTINCT', [new ArrayCompact(['this' => $arr->copy()])]),
                new NullExpr,
            ]);

            return $this->sql(new IfExpr([
                'this' => new NEQ([
                    'this' => new ArraySize(['this' => $arr->copy()]),
                    'expression' => Builder::func('LIST_COUNT', [$arr->copy()]),
                ]),
                'true' => $addNullToArray,
                'false' => new VarExpr(['this' => $func]),
            ]));
        }

        return $func;
    }

    protected function arrayintersectSql(Expression $expression): string
    {
        $expressions = $expression->argList('expressions');

        if ($expression->flag('is_multiset') && count($expressions) === 2) {
            return $this->arrayBagSqlInternal(
                $this->duckdbPart4Template(self::P4_ARRAY_INTERSECTION_CONDITION),
                $expressions[0],
                $expressions[1],
            );
        }

        return $this->functionFallbackSql($expression);
    }

    protected function arrayexceptSql(Expression $expression): string
    {
        $arr1 = $expression->argExpr('this') ?? new NullExpr;
        $arr2 = $expression->argExpr('expression') ?? new NullExpr;

        if ($expression->flag('is_multiset')) {
            return $this->arrayBagSqlInternal($this->duckdbPart4Template(self::P4_ARRAY_EXCEPT_CONDITION), $arr1, $arr2);
        }

        return $this->sql($this->duckdbPart4Template(self::P4_ARRAY_EXCEPT_SET_TEMPLATE, ['arr1' => $arr1, 'arr2' => $arr2]));
    }

    /**
     * Transpiles Snowflake's ARRAY_SLICE (0-indexed, exclusive end) to DuckDB's ARRAY_SLICE
     * (1-indexed, inclusive end) by wrapping start and end in CASE expressions.
     */
    protected function arraysliceSql(Expression $expression): string
    {
        $start = $expression->argExpr('start');
        $end = $expression->argExpr('end');

        if ($expression->flag('zero_based')) {
            if ($start !== null) {
                $start = $this->duckdbPart4Case([[
                    new GTE(['this' => $start->copy(), 'expression' => Literal::number(0)]),
                    new Add(['this' => $start->copy(), 'expression' => Literal::number(1)]),
                ]], $start);
            }

            if ($end !== null) {
                $end = $this->duckdbPart4Case([[
                    new LT(['this' => $end->copy(), 'expression' => Literal::number(0)]),
                    new Sub(['this' => $end->copy(), 'expression' => Literal::number(1)]),
                ]], $end);
            }
        }

        return $this->func('ARRAY_SLICE', $expression->args['this'] ?? null, $start, $end, $expression->args['step'] ?? null);
    }

    protected function arrayszipSql(Expression $expression): string
    {
        $args = $expression->argList('expressions');

        if ($args === []) {
            // Return [{}] - using MAP([], []) since DuckDB can't represent empty structs
            return $this->sql(new ArrayExpr(['expressions' => [
                new Map(['keys' => new ArrayExpr(['expressions' => []]), 'values' => new ArrayExpr(['expressions' => []])]),
            ]]));
        }

        // Build placeholder values for template
        $lengths = array_map(static fn (Expression $arg): Expression => new Length(['this' => $arg->copy()]), $args);
        $maxLen = count($lengths) === 1
            ? $lengths[0]
            : new Greatest(['this' => $lengths[0], 'expressions' => array_slice($lengths, 1)]);

        // Empty struct with same schema: {'$1': NULL, '$2': NULL, ...}
        $emptyArgs = [];
        $transformArgs = [];
        // Struct for transform: {'$1': COALESCE(arr1, [])[__i + 1], ...}
        // COALESCE wrapping handles NULL arrays - prevents invalid NULL[i] syntax
        $index = Builder::binop(Add::class, Builder::column('__i'), 1);
        foreach ($args as $i => $arg) {
            $key = '$'.($i + 1);
            $emptyArgs[] = new PropertyEQ(['this' => Literal::string($key), 'expression' => new NullExpr]);
            $transformArgs[] = new PropertyEQ([
                'this' => Literal::string($key),
                'expression' => new Bracket([
                    'this' => Builder::func('COALESCE', [$arg->copy(), new ArrayExpr(['expressions' => []])]),
                    'expressions' => [$index->copy()],
                ]),
            ]);
        }

        $result = $this->duckdbPart4Template(self::P4_ARRAYS_ZIP_TEMPLATE, [
            'null_check' => Builder::or(array_map(fn (Expression $arg): Expression => $this->duckdbPart4IsNull($arg), $args)),
            'all_empty_check' => Builder::and(array_map(
                static fn (Expression $arg): Expression => new EQ(['this' => new Length(['this' => $arg->copy()]), 'expression' => Literal::number(0)]),
                $args,
            )),
            'empty_struct' => Builder::func('STRUCT', $emptyArgs),
            'max_len' => $maxLen,
            'transform_struct' => Builder::func('STRUCT', $transformArgs),
        ]);

        return $this->sql($result);
    }

    protected function lowerSql(Expression $expression): string
    {
        $resultSql = $this->func('LOWER', $this->castToVarchar($expression->argExpr('this')));

        return $this->genWithCastToBlob($expression, $resultSql);
    }

    protected function upperSql(Expression $expression): string
    {
        $resultSql = $this->func('UPPER', $this->castToVarchar($expression->argExpr('this')));

        return $this->genWithCastToBlob($expression, $resultSql);
    }

    protected function reverseSql(Expression $expression): string
    {
        $resultSql = $this->func('REVERSE', $this->castToVarchar($expression->argExpr('this')));

        return $this->genWithCastToBlob($expression, $resultSql);
    }

    protected function leftRightSqlInternal(Expression $expression, string $funcName): string
    {
        $arg = $expression->argExpr('this') ?? new NullExpr;
        $length = $expression->argExpr('expression') ?? new NullExpr;
        $isBinary = $this->isBinary($arg);

        if ($isBinary) {
            // LEFT/RIGHT(blob, n) becomes UNHEX(LEFT/RIGHT(HEX(blob), n * 2))
            // Each byte becomes 2 hex chars, so multiply length by 2
            $hexArg = new Hex(['this' => $arg]);
            $hexLength = new Mul(['this' => $length, 'expression' => Literal::number(2)]);
            $result = new Unhex(['this' => new Anonymous(['this' => $funcName, 'expressions' => [$hexArg, $hexLength]])]);
        } else {
            $result = new Anonymous(['this' => $funcName, 'expressions' => [$arg, $length]]);
        }

        if ($expression->flag('negative_length_returns_empty')) {
            $empty = Literal::string('');

            if ($isBinary) {
                $empty = new Unhex(['this' => $empty]);
            }
            $result = $this->duckdbPart4Case([[Builder::binop(LT::class, $length, Literal::number(0)), $empty]], $result);
        }

        return $this->sql($result);
    }

    protected function leftSql(Expression $expression): string
    {
        return $this->leftRightSqlInternal($expression, 'LEFT');
    }

    protected function rightSql(Expression $expression): string
    {
        return $this->leftRightSqlInternal($expression, 'RIGHT');
    }

    protected function rtrimmedlengthSql(Expression $expression): string
    {
        return $this->func('LENGTH', new Trim(['this' => $expression->args['this'] ?? null, 'position' => 'TRAILING']));
    }

    protected function stuffSql(Expression $expression): string
    {
        $base = $expression->argExpr('this') ?? new NullExpr;
        $start = $expression->argExpr('start') ?? new NullExpr;
        $length = $expression->argExpr('length') ?? new NullExpr;
        $insertion = $expression->argExpr('expression') ?? new NullExpr;
        $isBinary = $this->isBinary($base);

        if ($isBinary) {
            // DuckDB's SUBSTRING doesn't accept BLOB; operate on the HEX string instead
            // (each byte = 2 hex chars), then UNHEX back to BLOB
            $base = new Hex(['this' => $base]);
            $insertion = new Hex(['this' => $insertion]);
            $left = new Substring([
                'this' => $base->copy(),
                'start' => Literal::number(1),
                'length' => Builder::binop(Mul::class, Builder::binop(Sub::class, $start, Literal::number(1)), Literal::number(2)),
            ]);
            $right = new Substring([
                'this' => $base->copy(),
                'start' => Builder::binop(
                    Add::class,
                    Builder::binop(Mul::class, Builder::binop(Sub::class, Builder::binop(Add::class, $start, $length), Literal::number(1)), Literal::number(2)),
                    Literal::number(1),
                ),
            ]);
        } else {
            $left = new Substring([
                'this' => $base->copy(),
                'start' => Literal::number(1),
                'length' => Builder::binop(Sub::class, $start, Literal::number(1)),
            ]);
            $right = new Substring(['this' => $base->copy(), 'start' => Builder::binop(Add::class, $start, $length)]);
        }

        $result = new DPipe(['this' => new DPipe(['this' => $left, 'expression' => $insertion]), 'expression' => $right]);

        if ($isBinary) {
            $result = new Unhex(['this' => $result]);
        }

        return $this->sql($result);
    }

    protected function randSql(Expression $expression): string
    {
        if (($expression->args['this'] ?? null) !== null) {
            $this->unsupported('RANDOM with seed is not supported in DuckDB');
        }

        $lower = $expression->argExpr('lower');
        $upper = $expression->argExpr('upper');

        if ($lower !== null && $upper !== null) {
            // scale DuckDB's [0,1) to the specified range
            $rangeSize = Builder::paren(Builder::binop(Sub::class, $upper, $lower));
            $scaled = new Add(['this' => $lower, 'expression' => Builder::binop(Mul::class, Builder::func('random'), $rangeSize)]);

            // For now we assume that if bounds are set, return type is BIGINT. Snowflake/Teradata
            return $this->sql(Builder::cast($scaled, DType::BIGINT));
        }

        // Default DuckDB behavior - just return RANDOM() as float
        return 'RANDOM()';
    }

    protected function bytelengthSql(Expression $expression): string
    {
        $arg = $expression->argExpr('this');

        // Check if it's a text type (handles both literals and annotated expressions)
        if ($arg !== null && $arg->isType(...DataType::TEXT_TYPES)) {
            return $this->func('OCTET_LENGTH', new Encode(['this' => $arg]));
        }

        // Default: pass through as-is (conservative for DuckDB, handles binary and unannotated)
        return $this->func('OCTET_LENGTH', $arg);
    }

    protected function base64encodeSql(Expression $expression): string
    {
        // DuckDB TO_BASE64 requires BLOB input; ENCODE unless the input is a binary type
        $result = $expression->argExpr('this') ?? new NullExpr;

        if ($result->isType(...DataType::TEXT_TYPES)) {
            $result = new Encode(['this' => $result]);
        }

        $result = new ToBase64(['this' => $result]);

        $maxLineLength = $expression->argExpr('max_line_length');
        $alphabet = $expression->argExpr('alphabet');

        // Handle custom alphabet by replacing standard chars with custom ones
        $result = $this->applyBase64AlphabetReplacements($result, $alphabet);

        // Handle max_line_length by inserting newlines every N characters
        $lineLength = $maxLineLength instanceof Literal && $maxLineLength->isNumber() ? (int) $maxLineLength->toPhp() : 0;

        if ($lineLength > 0) {
            $newline = new Chr(['expressions' => [Literal::number(10)]]);
            $result = new Trim([
                'this' => new RegexpReplace([
                    'this' => $result,
                    'expression' => Literal::string("(.{{$lineLength}})"),
                    'replacement' => new Concat(['expressions' => [Literal::string('\\1'), $newline->copy()]]),
                ]),
                'expression' => $newline,
                'position' => 'TRAILING',
            ]);
        }

        return $this->sql($result);
    }

    protected function hexSql(Expression $expression): string
    {
        $case = $expression->argExpr('case');

        if ($case === null) {
            return $this->func('HEX', $expression->args['this'] ?? null);
        }

        $hexExpr = new Hex(['this' => $expression->args['this'] ?? null]);

        return $this->sql($this->duckdbPart4Case([
            [$this->duckdbPart4IsNull($case), new NullExpr],
            [Builder::binop(EQ::class, $case->copy(), 0), new Lower(['this' => $hexExpr->copy()])],
        ], $hexExpr));
    }

    protected function replaceSql(Expression $expression): string
    {
        $resultSql = $this->func(
            'REPLACE',
            $this->castToVarchar($expression->argExpr('this')),
            $this->castToVarchar($expression->argExpr('expression')),
            $this->castToVarchar($expression->argExpr('replacement')),
        );

        return $this->genWithCastToBlob($expression, $resultSql);
    }

    public function bitwiseOp(Expression $expression, string $op): string
    {
        $this->prepareBinaryBitwiseArgs($expression);
        $resultSql = $this->binary($expression, $op);

        return $this->genWithCastToBlob($expression, $resultSql);
    }

    protected function bitwisexorSql(Expression $expression): string
    {
        $this->prepareBinaryBitwiseArgs($expression);
        $resultSql = $this->func('XOR', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);

        return $this->genWithCastToBlob($expression, $resultSql);
    }

    protected function objectinsertSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $key = $expression->argExpr('key');
        $keySql = $key !== null ? $key->name() : '';
        $valueSql = $this->sql($expression, 'value');

        $kvSql = "{$keySql} := {$valueSql}";

        // STRUCT_INSERT({}, key := value) is not valid DuckDB, so build an empty input with STRUCT_PACK
        if ($this_ instanceof Struct && $this_->argList('expressions') === []) {
            return $this->func('STRUCT_PACK', $kvSql);
        }

        return $this->func('STRUCT_INSERT', $this_, $kvSql);
    }

    protected function mapcatSql(Expression $expression): string
    {
        return $this->sql($this->duckdbPart4Template(self::P4_MAPCAT_TEMPLATE, [
            'map1' => $expression->argExpr('this') ?? new NullExpr,
            'map2' => $expression->argExpr('expression') ?? new NullExpr,
        ]));
    }

    protected function mapcontainskeySql(Expression $expression): string
    {
        return $this->func(
            'ARRAY_CONTAINS',
            Builder::func('MAP_KEYS', [$expression->argExpr('key')]),
            $expression->args['this'] ?? null,
        );
    }

    protected function mapdeleteSql(Expression $expression): string
    {
        $xDotKey = new Dot(['this' => $this->duckdbPart4Identifier('x'), 'expression' => $this->duckdbPart4Identifier('key')]);

        $lambda = new Lambda([
            'this' => Builder::not(new In(['this' => $xDotKey, 'expressions' => $expression->argList('expressions')])),
            'expressions' => [$this->duckdbPart4Identifier('x')],
        ]);

        return $this->sql(Builder::func('MAP_FROM_ENTRIES', [
            new ArrayFilter(['this' => Builder::func('MAP_ENTRIES', [$expression->argExpr('this')]), 'expression' => $lambda]),
        ]));
    }

    protected function mappickSql(Expression $expression): string
    {
        $keysToPick = $expression->argList('expressions');
        $xDotKey = new Dot(['this' => $this->duckdbPart4Identifier('x'), 'expression' => $this->duckdbPart4Identifier('key')]);

        if (count($keysToPick) === 1 && $keysToPick[0]->isType(DType::ARRAY)) {
            $lambda = new Lambda([
                'this' => Builder::func('ARRAY_CONTAINS', [$keysToPick[0], $xDotKey]),
                'expressions' => [$this->duckdbPart4Identifier('x')],
            ]);
        } else {
            $lambda = new Lambda([
                'this' => new In(['this' => $xDotKey, 'expressions' => $keysToPick]),
                'expressions' => [$this->duckdbPart4Identifier('x')],
            ]);
        }

        return $this->sql(Builder::func('MAP_FROM_ENTRIES', [
            Builder::func('LIST_FILTER', [Builder::func('MAP_ENTRIES', [$expression->argExpr('this')]), $lambda]),
        ]));
    }

    protected function mapsizeSql(Expression $expression): string
    {
        return $this->func('CARDINALITY', $expression->args['this'] ?? null);
    }

    protected function mapinsertSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'update_flag');

        $mapArg = $expression->argExpr('this');
        $key = $expression->argExpr('key');
        $value = $expression->argExpr('value');

        $mapType = $mapArg?->type();

        if ($value !== null && $mapType !== null && count($mapType->argList('expressions')) > 1) {
            // Cast value to match the map's value type to avoid type conflicts
            $valueType = $mapType->argList('expressions')[1];

            if ($valueType instanceof DataType) {
                $value = Builder::cast($value, $valueType);
            }
        }

        // Create a single-entry map for the new key-value pair, then merge with MAP_CONCAT
        $newEntry = new ToMap(['this' => new Struct(['expressions' => [new PropertyEQ(['this' => $key, 'expression' => $value])]])]);

        return $this->sql(Builder::func('MAP_CONCAT', [$mapArg, $newEntry]));
    }

    protected function startswithSql(Expression $expression): string
    {
        return $this->func(
            'STARTS_WITH',
            $this->castToVarchar($expression->argExpr('this')),
            $this->castToVarchar($expression->argExpr('expression')),
        );
    }

    protected function spaceSql(Expression $expression): string
    {
        // DuckDB's REPEAT requires BIGINT for the count parameter
        return $this->sql(new Repeat([
            'this' => Literal::string(' '),
            'times' => Builder::cast($expression->argExpr('this') ?? new NullExpr, DType::BIGINT),
        ]));
    }

    protected function tablefromrowsSql(Expression $expression): string
    {
        // For GENERATOR, unwrap TABLE() - just emit the Generator (becomes RANGE)
        if (($expression->args['this'] ?? null) instanceof GeneratorFunc) {
            return $this->sql(new Table([
                'this' => $expression->args['this'],
                'alias' => $expression->args['alias'] ?? null,
                'joins' => $expression->args['joins'] ?? null,
            ]));
        }

        return parent::tablefromrowsSql($expression);
    }

    protected function unnestSql(Expression $expression): string
    {
        if ($expression->flag('explode_array')) {
            // BigQuery's UNNEST of a nested array explodes the top-level array & struct:
            // "FROM UNNEST(...)" becomes "FROM (SELECT UNNEST(..., max_depth => 2))"
            $expression->append('expressions', new Kwarg(['this' => Builder::var('max_depth'), 'expression' => Literal::number(2)]));

            // If BQ's UNNEST is aliased, we transform it from a column alias to a table alias in DDB
            $alias = $expression->args['alias'] ?? null;

            if ($alias instanceof TableAlias) {
                $expression->set('alias', null);
                $columns = $alias->argList('columns');

                if ($columns !== []) {
                    $alias = new TableAlias(['this' => $columns[0]]);
                }
            }

            $unnestSql = parent::unnestSql($expression);
            $select = (new Select(['expressions' => [new VarExpr(['this' => $unnestSql])]]))
                ->subquery($alias instanceof Expression ? $alias : null);

            return $this->sql($select);
        }

        return parent::unnestSql($expression);
    }

    protected function arrayaggSql(Expression $expression): string
    {
        if (($expression->args['this'] ?? null) instanceof Limit) {
            $this->unsupported('LIMIT inside ARRAY_AGG is not supported in DuckDB');
        }

        return parent::arrayaggSql($expression);
    }

    protected function ignorenullsSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ !== null && $this->isInstanceOfAny($this_, $this->t['IGNORE_RESPECT_NULLS_WINDOW_FUNCTIONS'])) {
            // DuckDB renders IGNORE NULLS only for the window functions that accept it
            return parent::ignorenullsSql($expression);
        }

        // ARRAY_AGG(expr IGNORE NULLS ...) becomes a FILTER(WHERE expr IS NOT NULL) clause
        if ($this_ instanceof ArrayAgg) {
            $this_->set('nulls_excluded', true);

            return $this->sql($this_);
        }

        if ($this_ instanceof First) {
            $this_ = new AnyValue(['this' => $this_->args['this'] ?? null]);
        }

        if (! $this_ instanceof AnyValue && ! $this_ instanceof ApproxQuantiles) {
            $this->unsupported('IGNORE NULLS is not supported for non-window functions.');
        }

        return $this->sql($this_);
    }

    protected function splitSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $delimiter = $expression->argExpr('expression') ?? new NullExpr;
        $baseFunc = Builder::func('STR_SPLIT', [$this_, $delimiter]);

        $whens = [];

        if ($expression->flag('null_returns_null')) {
            $whens[] = [$this->duckdbPart4IsNull($delimiter), new NullExpr];
        }

        if ($expression->flag('empty_delimiter_returns_whole')) {
            // When delimiter is empty string, return input string as single array element
            $whens[] = [
                Builder::binop(EQ::class, $delimiter, Literal::string('')),
                new ArrayExpr(['expressions' => [$this_?->copy() ?? new NullExpr]]),
            ];
        }

        return $this->sql($whens !== [] ? $this->duckdbPart4Case($whens, $baseFunc) : $baseFunc);
    }

    protected function splitpartSql(Expression $expression): string
    {
        $stringArg = $expression->argExpr('this') ?? new NullExpr;
        $delimiterArg = $expression->argExpr('delimiter');
        $partIndexArg = $expression->argExpr('part_index');

        if ($delimiterArg !== null && $partIndexArg !== null) {
            // Handle Snowflake's "index 0 and 1 both return first element" behavior
            if ($expression->flag('part_index_zero_as_one')) {
                $partIndexArg = new Paren(['this' => $this->duckdbPart4Case(
                    [[Builder::binop(EQ::class, $partIndexArg, Literal::number('0')), Literal::number('1')]],
                    $partIndexArg,
                )]);
            }

            // Use Anonymous to avoid recursion
            $baseFuncExpr = new Anonymous(['this' => 'SPLIT_PART', 'expressions' => [$stringArg, $delimiterArg, $partIndexArg]]);

            if ($expression->flag('empty_delimiter_returns_whole')) {
                // Empty delimiter: whole string if part_index is 1 or -1, empty string otherwise
                $emptyCase = new Paren(['this' => $this->duckdbPart4Case([[
                    Builder::or([
                        Builder::binop(EQ::class, $partIndexArg, Literal::number('1')),
                        Builder::binop(EQ::class, $partIndexArg, Literal::number('-1')),
                    ]),
                    $stringArg,
                ]], Literal::string(''))]);

                return $this->sql($this->duckdbPart4Case(
                    [[Builder::binop(EQ::class, $delimiterArg, Literal::string('')), $emptyCase]],
                    $baseFuncExpr,
                ));
            }

            return $this->sql($baseFuncExpr);
        }

        return $this->functionFallbackSql($expression);
    }

    protected function respectnullsSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ !== null && $this->isInstanceOfAny($this_, $this->t['IGNORE_RESPECT_NULLS_WINDOW_FUNCTIONS'])) {
            return parent::respectnullsSql($expression);
        }

        $this->unsupported('RESPECT NULLS is not supported for non-window functions.');

        return $this->sql($expression, 'this');
    }

    protected function arraytostringSql(Expression $expression): string
    {
        $null = $expression->argExpr('null');
        $this_ = $expression->argExpr('this');
        $delimiter = $expression->argExpr('expression');

        if ($expression->flag('null_is_empty')) {
            $x = $this->duckdbPart4Identifier('x');
            $listTransform = new Transform([
                'this' => $this_?->copy(),
                'expression' => new Lambda([
                    'this' => new Coalesce(['this' => Builder::cast($x, 'TEXT'), 'expressions' => [Literal::string('')]]),
                    'expressions' => [$x->copy()],
                ]),
            ]);
            $arrayToString = new ArrayToString(['this' => $listTransform, 'expression' => $delimiter]);

            if ($expression->flag('null_delim_is_null')) {
                return $this->sql($this->duckdbPart4Case(
                    [[$this->duckdbPart4IsNull($delimiter ?? new NullExpr), new NullExpr]],
                    $arrayToString,
                ));
            }

            return $this->sql($arrayToString);
        }

        if ($null !== null) {
            $x = $this->duckdbPart4Identifier('x');

            return $this->sql(new ArrayToString([
                'this' => new Transform([
                    'this' => $this_,
                    'expression' => new Lambda([
                        'this' => new Coalesce(['this' => $x, 'expressions' => [$null]]),
                        'expressions' => [$x->copy()],
                    ]),
                ]),
                'expression' => $delimiter,
            ]));
        }

        return $this->func('ARRAY_TO_STRING', $this_, $delimiter);
    }

    protected function concatwsSql(Expression $expression): string
    {
        // DuckDB-specific: handle binary types using DPipe (||) operator
        $expressions = $expression->argList('expressions');
        $separator = $expressions[0] ?? null;
        $args = array_slice($expressions, 1);

        $anyBinary = false;
        foreach ([$separator, ...$args] as $arg) {
            if ($arg !== null && $this->isBinary($arg)) {
                $anyBinary = true;

                break;
            }
        }

        if ($anyBinary && $args !== []) {
            $result = $args[0];
            foreach (array_slice($args, 1) as $arg) {
                $result = new DPipe([
                    'this' => new DPipe(['this' => $result, 'expression' => $separator?->copy()]),
                    'expression' => $arg,
                ]);
            }

            return $this->sql($result);
        }

        return parent::concatwsSql($expression);
    }

    protected function regexpExtractSqlInternal(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $group = $expression->argExpr('group');
        $params = $expression->argExpr('parameters');
        $position = $expression->argExpr('position');
        $occurrence = $expression->argExpr('occurrence');
        $nullIfPosOverflow = $expression->flag('null_if_pos_overflow');

        // Snowflake's 'e' flag enables capture group extraction; DuckDB uses the group parameter
        if ($params !== null && $params->isString() && str_contains($params->name(), 'e')) {
            $params = Literal::string(str_replace('e', '', $params->name()));
        }

        $validatedFlags = $this->validateRegexpFlags($params, 'cims');

        // Strip default group when no following params (DuckDB default is same as group=0)
        if (
            ($validatedFlags === null || $validatedFlags === '')
            && $group !== null
            && $group->name() === (string) $this->dialect->settings['REGEXP_EXTRACT_DEFAULT_GROUP']
        ) {
            $group = null;
        }

        $flagsExpr = $validatedFlags !== null && $validatedFlags !== '' ? Literal::string($validatedFlags) : null;

        // use substring to handle position argument
        if ($position !== null && (! $position->isInt() || $position->toPhp() > 1)) {
            $this_ = new Substring(['this' => $this_, 'start' => $position]);

            if ($nullIfPosOverflow) {
                $this_ = new Nullif(['this' => $this_, 'expression' => Literal::string('')]);
            }
        }

        $isExtractAll = $expression instanceof RegexpExtractAll;
        $nonSingleOccurrence = $occurrence !== null && (! $occurrence->isInt() || $occurrence->toPhp() > 1);

        $name = $isExtractAll || $nonSingleOccurrence ? 'REGEXP_EXTRACT_ALL' : 'REGEXP_EXTRACT';

        $result = new Anonymous([
            'this' => $name,
            'expressions' => array_values(array_filter(
                [$this_, $expression->argExpr('expression'), $group, $flagsExpr],
                static fn (?Expression $e): bool => $e !== null,
            )),
        ]);

        if ($isExtractAll && $nonSingleOccurrence) {
            // Array slicing for REGEXP_EXTRACT_ALL with occurrence
            $result = new Bracket(['this' => $result, 'expressions' => [new Slice(['this' => $occurrence])]]);
        } elseif ($nonSingleOccurrence) {
            // ARRAY_EXTRACT for REGEXP_EXTRACT with occurrence > 1
            $result = new Anonymous(['this' => 'ARRAY_EXTRACT', 'expressions' => [$result, $occurrence]]);
        }

        return $this->sql($result);
    }

    protected function regexpextractSql(Expression $expression): string
    {
        return $this->regexpExtractSqlInternal($expression);
    }

    protected function regexpextractallSql(Expression $expression): string
    {
        return $this->regexpExtractSqlInternal($expression);
    }

    protected function regexpinstrSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $pattern = $expression->argExpr('expression') ?? new NullExpr;
        $position = $expression->argExpr('position');
        $origOcc = $expression->argExpr('occurrence');
        $occurrence = $origOcc ?? Literal::number(1);
        $option = $expression->argExpr('option');
        $parameters = $expression->argExpr('parameters');

        $validatedFlags = $this->validateRegexpFlags($parameters, 'ims');

        if ($validatedFlags !== null && $validatedFlags !== '') {
            $pattern = new Concat(['expressions' => [Literal::string("(?{$validatedFlags})"), $pattern]]);
        }

        // Handle starting position offset
        $posOffset = Literal::number(0);

        if ($position !== null && (! $position->isInt() || $position->toPhp() > 1)) {
            $this_ = new Substring(['this' => $this_, 'start' => $position]);
            $posOffset = Builder::binop(Sub::class, $position, Literal::number(1));
        }

        // Helper: LIST_SUM(LIST_TRANSFORM(list[1:end], x -> LENGTH(x)))
        $sumLengths = static function (string $funcName, Expression $end) use ($this_, $pattern): Expression {
            $lst = new Bracket([
                'this' => new Anonymous(['this' => $funcName, 'expressions' => [$this_->copy(), $pattern->copy()]]),
                'expressions' => [new Slice(['this' => Literal::number(1), 'expression' => $end])],
                'offset' => 1,
            ]);
            $transform = new Anonymous([
                'this' => 'LIST_TRANSFORM',
                'expressions' => [
                    $lst,
                    new Lambda([
                        'this' => new Length(['this' => new Identifier(['this' => 'x'])]),
                        'expressions' => [new Identifier(['this' => 'x'])],
                    ]),
                ],
            ]);

            return new Coalesce([
                'this' => new Anonymous(['this' => 'LIST_SUM', 'expressions' => [$transform]]),
                'expressions' => [Literal::number(0)],
            ]);
        };

        // Position = 1 + sum(split_lengths[1:occ]) + sum(match_lengths[1:occ-1]) + offset
        $basePos = Builder::binop(
            Add::class,
            Builder::binop(
                Add::class,
                Builder::binop(Add::class, Literal::number(1), $sumLengths('STRING_SPLIT_REGEX', $occurrence->copy())),
                $sumLengths('REGEXP_EXTRACT_ALL', Builder::binop(Sub::class, $occurrence, Literal::number(1))),
            ),
            $posOffset,
        );

        // option=1: add match length for end position
        if ($option !== null && $option->isInt() && $option->toPhp() === 1) {
            $matchAtOcc = new Bracket([
                'this' => new Anonymous(['this' => 'REGEXP_EXTRACT_ALL', 'expressions' => [$this_->copy(), $pattern->copy()]]),
                'expressions' => [$occurrence->copy()],
                'offset' => 1,
            ]);
            $basePos = Builder::binop(
                Add::class,
                $basePos,
                new Coalesce(['this' => new Length(['this' => $matchAtOcc]), 'expressions' => [Literal::number(0)]]),
            );
        }

        // NULL checks for all provided arguments
        $nullChecks = [];
        foreach ([$expression->argExpr('this'), $expression->argExpr('expression'), $position, $origOcc, $option, $parameters] as $arg) {
            if ($arg !== null) {
                $nullChecks[] = $this->duckdbPart4IsNull($arg->copy());
            }
        }

        $matches = new Anonymous(['this' => 'REGEXP_EXTRACT_ALL', 'expressions' => [$this_->copy(), $pattern->copy()]]);

        return $this->sql($this->duckdbPart4Case([
            [Builder::or($nullChecks), new NullExpr],
            [Builder::binop(EQ::class, $pattern->copy(), Literal::string('')), Literal::number(0)],
            [Builder::binop(LT::class, new Length(['this' => $matches]), $occurrence), Literal::number(0)],
        ], $basePos));
    }

    protected function numbertostrSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'culture');

        $fmt = $expression->argExpr('format');

        if ($fmt !== null && $fmt->isInt()) {
            return $this->func('FORMAT', "'{:,.{$fmt->name()}f}'", $expression->args['this'] ?? null);
        }

        $this->unsupported('Only integer formats are supported by NumberToStr');

        return $this->functionFallbackSql($expression);
    }

    protected function autoincrementcolumnconstraintSql(Expression $expression): string
    {
        $this->unsupported('The AUTOINCREMENT column constraint is not supported by DuckDB');

        return '';
    }

    protected function aliasesSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ instanceof Posexplode) {
            return $this->posexplodeSql($this_);
        }

        return parent::aliasesSql($expression);
    }

    protected function posexplodeSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $parent = $expression->parent;

        // The default Spark aliases are "pos" and "col", unless specified otherwise
        $pos = $this->duckdbPart4Identifier('pos');
        $col = $this->duckdbPart4Identifier('col');

        if ($parent instanceof Aliases) {
            // Column case: SELECT POSEXPLODE(col) [AS (a, b)]
            [$pos, $col] = $parent->argList('expressions');
        } elseif ($parent instanceof Table) {
            // Table case: SELECT * FROM POSEXPLODE(col) [AS (a, b)]
            $alias = $parent->argExpr('alias');

            if ($alias !== null) {
                $columns = $alias->argList('columns');

                if ($columns !== []) {
                    [$pos, $col] = $columns;
                }
                $alias->pop();
            }
        }

        // Translate POSEXPLODE to UNNEST + GENERATE_SUBSCRIPTS
        // Note: In Spark pos is 0-indexed, but in DuckDB it's 1-indexed, so we subtract 1
        $unnestSql = $this->sql(new Unnest(['expressions' => [$this_], 'alias' => $col]));
        $genSubscripts = $this->sql(new Alias([
            'this' => Builder::binop(
                Sub::class,
                new Anonymous(['this' => 'GENERATE_SUBSCRIPTS', 'expressions' => [$this_?->copy(), Literal::number(1)]]),
                Literal::number(1),
            ),
            'alias' => $pos,
        ]));

        $posexplodeSql = $this->formatArgs([$genSubscripts, $unnestSql]);

        if ($parent instanceof From || ($parent !== null && $parent->parent instanceof From)) {
            // SELECT * FROM POSEXPLODE(col) -> SELECT * FROM (SELECT GENERATE_SUBSCRIPTS(...), UNNEST(...))
            return $this->sql(new Subquery(['this' => new Select(['expressions' => [new VarExpr(['this' => $posexplodeSql])]])]));
        }

        return $posexplodeSql;
    }

    /**
     * Handles float/decimal months (rounded via TO_MONTHS), end-of-month preservation and
     * type preservation (DuckDB defaults to TIMESTAMP).
     */
    protected function addmonthsSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;

        if ($this_->type() === null) {
            $this_ = $this->dialect->annotateTypes($this_);
        }

        if ($this_->isType(...DataType::TEXT_TYPES)) {
            $this_ = new Cast(['this' => $this_, 'to' => new DataType(['this' => DType::TIMESTAMP])]);
        }

        // Detect float/decimal months to apply rounding (Snowflake behavior)
        $monthsExpr = $expression->argExpr('expression') ?? new NullExpr;

        if ($monthsExpr->type() === null) {
            $monthsExpr = $this->dialect->annotateTypes($monthsExpr);
        }

        $intervalOrToMonths = $monthsExpr->isType(DType::FLOAT, DType::DOUBLE, DType::DECIMAL)
            ? Builder::func('TO_MONTHS', [Builder::cast(Builder::func('ROUND', [$monthsExpr]), 'INT')])
            // Integer case: standard INTERVAL N MONTH syntax
            : new Interval(['this' => $monthsExpr, 'unit' => Builder::var('MONTH')]);

        $dateAddExpr = new Add(['this' => $this_, 'expression' => $intervalOrToMonths]);

        // CASE WHEN LAST_DAY(date) = date THEN LAST_DAY(result) ELSE result END
        $resultExpr = $expression->flag('preserve_end_of_month')
            ? $this->duckdbPart4Case([[
                new EQ(['this' => Builder::func('LAST_DAY', [$this_->copy()]), 'expression' => $this_->copy()]),
                Builder::func('LAST_DAY', [$dateAddExpr]),
            ]], $dateAddExpr)
            : $dateAddExpr;

        // Cast back to the original type when the input is DATE or TIMESTAMPTZ
        $type = $this_->type();

        if ($type !== null && $this_->isType(DType::DATE, DType::TIMESTAMPTZ)) {
            return $this->sql(new Cast(['this' => $resultExpr, 'to' => $type->copy()]));
        }

        return $this->sql($resultExpr);
    }

    protected function formatSql(Expression $expression): string
    {
        $expressions = $expression->argList('expressions');

        if (strtolower($expression->name()) === '%s' && count($expressions) === 1) {
            return $this->func('FORMAT', "'{}'", $expressions[0]);
        }

        return $this->functionFallbackSql($expression);
    }

    protected function hexstringSql(Expression $expression, ?string $binaryFunctionRepr = null): string
    {
        // UNHEX('FF') correctly produces blob \xFF in DuckDB
        return parent::hexstringSql($expression, 'UNHEX');
    }

    protected function datetruncSql(Expression $expression): string
    {
        $date = $expression->argExpr('this') ?? new NullExpr;

        $weekStart = $this->weekTruncStartDow($expression->argExpr('unit'));
        $unit = Builder::unitToStr($expression);

        if ($weekStart !== null && $weekStart !== 0) {
            $result = $this->sql($this->buildWeekTruncExpression($date, $weekStart, true));
        } else {
            $result = $this->func('DATE_TRUNC', $unit, $date);
        }

        $type = $date->type();

        if (
            $expression->flag('input_type_preserved')
            && $type !== null
            && $date->isType(...DataType::TEMPORAL_TYPES)
            && ! ($this->duckdbPart4IsDateUnit($unit) && $date->isType(DType::DATE))
        ) {
            return $this->sql(new Cast(['this' => new VarExpr(['this' => $result]), 'to' => $type->copy()]));
        }

        return $result;
    }

    protected function datetimetruncSql(Expression $expression): string
    {
        $this_ = Builder::cast($expression->argExpr('this') ?? new NullExpr, DType::DATETIME);
        $weekStart = $this->weekTruncStartDow($expression->argExpr('unit'));

        if ($weekStart !== null && $weekStart !== 0) {
            return $this->sql($this->buildWeekTruncExpression($this_, $weekStart, true, false));
        }

        return $this->func('DATE_TRUNC', Builder::unitToStr($expression), $this_);
    }

    protected function timestamptruncSql(Expression $expression): string
    {
        $zone = $expression->argExpr('zone');
        $timestamp = $expression->argExpr('this') ?? new NullExpr;
        $weekStart = $this->weekTruncStartDow($expression->argExpr('unit'));
        $hasWeekStart = $weekStart !== null && $weekStart !== 0;

        // The week start emulation below is exact, so avoid weekstart_unit_to_str's degrade warning
        $unit = $hasWeekStart ? Builder::unitToStr($expression) : $this->weekstartUnitToStr($expression);
        $dateUnit = $this->duckdbPart4IsDateUnit($unit) || $hasWeekStart;

        $truncExpr = function (Expression $this_) use ($hasWeekStart, $weekStart, $unit): Expression {
            if ($hasWeekStart) {
                return $this->buildWeekTruncExpression($this_, $weekStart, true, false);
            }

            return Builder::func('DATE_TRUNC', [$unit, $this_]);
        };

        if ($dateUnit && $zone !== null) {
            // BigQuery's TIMESTAMP_TRUNC with timezone truncates in the target timezone and returns as UTC
            $timestamp = new AtTimeZone(['this' => $timestamp, 'zone' => $zone]);
            $trunced = $truncExpr($timestamp);

            if ($trunced instanceof DateAdd) {
                // Parenthesize so the trailing AT TIME ZONE binds to the whole shifted expression
                $trunced = new Paren(['this' => $trunced]);
            }

            return $this->sql(new AtTimeZone(['this' => $trunced, 'zone' => $zone->copy()]));
        }

        $result = $this->sql($truncExpr($timestamp));
        $type = $timestamp->type();

        if ($expression->flag('input_type_preserved') && $type !== null) {
            if ($timestamp->isType(DType::TIME, DType::TIMETZ)) {
                $dummyDate = new Cast(['this' => Literal::string('1970-01-01'), 'to' => new DataType(['this' => DType::DATE])]);
                $dateTime = new Add(['this' => $dummyDate, 'expression' => $timestamp]);
                $result = $this->func('DATE_TRUNC', $unit, $dateTime);

                return $this->sql(new Cast(['this' => new VarExpr(['this' => $result]), 'to' => $type->copy()]));
            }

            if ($timestamp->isType(...DataType::TEMPORAL_TYPES) && ! ($dateUnit && $timestamp->isType(DType::DATE))) {
                return $this->sql(new Cast(['this' => new VarExpr(['this' => $result]), 'to' => $type->copy()]));
            }
        }

        return $result;
    }

    protected function trimSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $this_?->replace($this->castToVarchar($this_));
        $chars = $expression->argExpr('expression');
        $chars?->replace($this->castToVarchar($chars));

        $resultSql = parent::trimSql($expression);

        return $this->genWithCastToBlob($expression, $resultSql);
    }

    protected function roundSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $decimals = $expression->argExpr('decimals');
        $truncate = $expression->argExpr('truncate');

        // DuckDB requires the scale (decimals) argument to be an INT
        if ($decimals !== null && $expression->flag('casts_non_integer_decimals')) {
            if (! ($decimals->isInt() || $decimals->isType(...DataType::INTEGER_TYPES))) {
                $decimals = Builder::cast($decimals, DType::INT);
            }
        }

        $func = 'ROUND';

        if ($truncate !== null) {
            $mode = $truncate->args['this'] ?? null;

            // BigQuery uses ROUND_HALF_EVEN; Snowflake uses HALF_TO_EVEN
            if (in_array($mode, ['ROUND_HALF_EVEN', 'HALF_TO_EVEN'], true)) {
                $func = 'ROUND_EVEN';
                $truncate = null;
            } elseif (in_array($mode, ['ROUND_HALF_AWAY_FROM_ZERO', 'HALF_AWAY_FROM_ZERO'], true)) {
                // BigQuery uses ROUND_HALF_AWAY_FROM_ZERO; Snowflake uses HALF_AWAY_FROM_ZERO
                $truncate = null;
            }
        }

        return $this->func($func, $this_, $decimals, $truncate);
    }

    protected function trycastSql(Expression $expression): string
    {
        $to = $expression->argExpr('to') ?? new DataType(['this' => DType::UNKNOWN]);
        $toType = $to->args['this'] ?? null;
        $src = $expression->argExpr('this') ?? new NullExpr;
        $toExpressions = $to->argList('expressions');

        if (
            $expression->flag('null_on_text_overflow')
            && is_string($toType) && in_array($toType, DataType::TEXT_TYPES, true)
            && $toExpressions !== []
        ) {
            return $this->sql($this->duckdbPart4Case([[
                new LTE(['this' => Builder::func('LENGTH', [$src]), 'expression' => $toExpressions[0]->args['this'] ?? null]),
                Builder::cast($src, 'TEXT'),
            ]], new NullExpr));
        }

        if ($toType === DType::DATE && $expression->flag('probe_date_format')) {
            $slashStrptime = Builder::cast(
                Builder::func('TRY_STRPTIME', [$src, Literal::string(self::P4_TRYCAST_DATE_SLASH_FMT)]),
                'DATE',
            );
            $monStrptime = Builder::cast(
                Builder::func('TRY_STRPTIME', [$src, Literal::string(self::P4_TRYCAST_DATE_MON_FMT)]),
                'DATE',
            );

            return $this->sql($this->duckdbPart4Case([
                [Builder::func('CONTAINS', [$src, Literal::string('/')]), $slashStrptime],
                [new RegexpLike(['this' => $src->copy(), 'expression' => Literal::string('[A-Za-z]')]), $monStrptime],
            ], new TryCast(['this' => $src->copy(), 'to' => $to->copy()])));
        }

        $unit = $toType instanceof Interval ? $toType->argExpr('unit') : null;

        if ($unit !== null && $expression->flag('requires_string')) {
            $intervalType = DataType::build('INTERVAL');

            if ($unit instanceof IntervalSpan) {
                $this->unsupported('TRY_CAST to INTERVAL with span (e.g. HOUR TO MINUTE) is not supported in DuckDB');

                return $this->sql(new TryCast(['this' => $src, 'to' => $intervalType]));
            }

            return $this->sql(new TryCast([
                'this' => new DPipe(['this' => $src, 'expression' => Literal::string(" {$unit->name()}")]),
                'to' => $intervalType,
            ]));
        }

        return parent::trycastSql($expression);
    }

    protected function strtokSql(Expression $expression): string
    {
        $stringArg = $expression->argExpr('this') ?? new NullExpr;
        $delimiterArg = $expression->argExpr('delimiter');
        $partIndexArg = $expression->argExpr('part_index');

        if ($delimiterArg !== null && $partIndexArg !== null) {
            // Escape regex chars and build character class at runtime using REGEXP_REPLACE
            $escapedDelimiter = new Anonymous([
                'this' => 'REGEXP_REPLACE',
                'expressions' => [
                    $delimiterArg->copy(),
                    Literal::string('([\[\]^.\-*+?(){}|$\\\\])'), // Escape problematic regex chars
                    Literal::string('\\\\\\1'), // Replace with escaped version using $1 backreference
                    Literal::string('g'), // Global flag
                ],
            ]);
            // CASE WHEN delimiter = '' THEN '' ELSE CONCAT('[', escaped_delimiter, ']') END
            $regexPattern = $this->duckdbPart4Case(
                [[Builder::binop(EQ::class, $delimiterArg, Literal::string('')), Literal::string('')]],
                Builder::func('CONCAT', [Literal::string('['), $escapedDelimiter, Literal::string(']')]),
            );

            // STRTOK skips empty strings, so we need to filter them out
            // LIST_FILTER(REGEXP_SPLIT_TO_ARRAY(string, pattern), x -> x != '')[index]
            $splitArray = Builder::func('REGEXP_SPLIT_TO_ARRAY', [$stringArg->copy(), $regexPattern]);
            $x = $this->duckdbPart4Identifier('x');
            $isEmpty = Builder::binop(EQ::class, $x, Literal::string(''));
            $filteredArray = Builder::func('LIST_FILTER', [
                $splitArray,
                new Lambda(['this' => Builder::not($isEmpty->copy()), 'expressions' => [$x->copy()]]),
            ]);
            $baseFunc = new Bracket(['this' => $filteredArray, 'expressions' => [$partIndexArg->copy()], 'offset' => 1]);

            return $this->sql($this->duckdbPart4Template(self::P4_STRTOK_TEMPLATE, [
                'string' => $stringArg,
                'delimiter' => $delimiterArg,
                'part_index' => $partIndexArg,
                'base_func' => $baseFunc,
            ]));
        }

        return $this->functionFallbackSql($expression);
    }

    protected function strtoktoarraySql(Expression $expression): string
    {
        $stringArg = $expression->argExpr('this') ?? new NullExpr;
        $delimiterArg = $expression->argExpr('expression') ?? Literal::string(' ');

        $escaped = new RegexpReplace([
            'this' => $delimiterArg->copy(),
            'expression' => Literal::string('([\[\]^.\-*+?(){}|$\\\\])'),
            'replacement' => Literal::string('\\\\\\1'),
            'modifiers' => Literal::string('g'),
        ]);

        return $this->sql($this->duckdbPart4Template(self::P4_STRTOK_TO_ARRAY_TEMPLATE, [
            'string' => $stringArg,
            'delimiter' => $delimiterArg,
            'escaped' => $escaped,
        ]));
    }

    protected function approxquantileSql(Expression $expression): string
    {
        $result = $this->func('APPROX_QUANTILE', $expression->args['this'] ?? null, $expression->args['quantile'] ?? null);

        // DuckDB returns integers for APPROX_QUANTILE, cast to DOUBLE if the expected type is a real type
        if ($expression->isType(...DataType::REAL_TYPES)) {
            $result = "CAST({$result} AS DOUBLE)";
        }

        return $result;
    }

    /**
     * BigQuery's APPROX_QUANTILES(expr, n) returns an array of n+1 approximate quantile values
     * dividing the input distribution into n equal-sized buckets.
     */
    protected function approxquantilesSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ instanceof Distinct) {
            // APPROX_QUANTILES requires 2 args and DISTINCT node grabs both
            $distinctArgs = $this_->argList('expressions');

            if (count($distinctArgs) < 2) {
                $this->unsupported('APPROX_QUANTILES requires a bucket count argument');

                return $this->functionFallbackSql($expression);
            }
            $numQuantilesExpr = $distinctArgs[1]->pop();
        } else {
            $numQuantilesExpr = $expression->argExpr('expression');
        }

        if (! $numQuantilesExpr instanceof Literal || ! $numQuantilesExpr->isInt()) {
            $this->unsupported('APPROX_QUANTILES bucket count must be a positive integer');

            return $this->functionFallbackSql($expression);
        }

        $numQuantiles = (int) $numQuantilesExpr->toPhp();

        if ($numQuantiles <= 0) {
            $this->unsupported('APPROX_QUANTILES bucket count must be a positive integer');

            return $this->functionFallbackSql($expression);
        }

        $quantiles = [];
        for ($i = 0; $i <= $numQuantiles; $i++) {
            $quantiles[] = Literal::number($this->duckdbPart4DecimalRatio($i, $numQuantiles));
        }

        return $this->sql(new ApproxQuantile(['this' => $this_, 'quantile' => new ArrayExpr(['expressions' => $quantiles])]));
    }

    protected function jsonextractscalarSql(Expression $expression): string
    {
        if ($expression->flag('scalar_only')) {
            $jsonValue = new JSONExtractScalar([
                'this' => new VarExpr(['this' => $this->renameFunc($expression, 'JSON_VALUE')]),
                'expression' => new VarExpr(['this' => "'$'"]),
            ]);

            // `->>` binds looser than most operators, so the wrap logic needs the parent
            $jsonValue->parent = $expression->parent;
            $expression = $jsonValue;
        }

        return $this->arrowJsonExtractSqlDuckdb($expression);
    }

    protected function bitwisenotSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;

        if ($this->isBinary($this_)) {
            $expression->setType(DType::BINARY);
        }

        $arg = $this->castToBit($this_);

        if ($this_ instanceof Neg) {
            $arg = new Paren(['this' => $arg]);
        }

        $expression->set('this', $arg);

        $resultSql = '~'.$this->sql($expression, 'this');

        return $this->genWithCastToBlob($expression, $resultSql);
    }

    protected function windowSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ instanceof Corr || ($this_ instanceof Filter && ($this_->args['this'] ?? null) instanceof Corr)) {
            return $this->corrSqlInternal($expression);
        }

        return parent::windowSql($expression);
    }

    protected function filterSql(Expression $expression): string
    {
        if (($expression->args['this'] ?? null) instanceof Corr) {
            return $this->corrSqlInternal($expression);
        }

        return parent::filterSql($expression);
    }

    protected function corrSqlInternal(Expression $expression): string
    {
        if ($expression instanceof Corr && ! $expression->flag('null_on_zero_variance')) {
            return $this->func('CORR', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
        }

        $corrExpr = $this->maybeCorrNullToFalse($expression);

        if ($corrExpr === null) {
            if ($expression instanceof Window) {
                return parent::windowSql($expression);
            }

            if ($expression instanceof Filter) {
                return parent::filterSql($expression);
            }
            $corrExpr = $expression;
        }

        return $this->sql($this->duckdbPart4Case([[new IsNan(['this' => $corrExpr]), new NullExpr]], $corrExpr));
    }

    protected function uuidSql(Expression $expression): string
    {
        $namespace = $expression->argExpr('this');
        $name = $expression->argExpr('name');

        // UUID v5 (namespace + name) - Emulate using SHA1
        if ($namespace !== null && $name !== null) {
            return $this->sql($this->duckdbPart4Template(self::P4_UUID_V5_TEMPLATE, ['namespace' => $namespace, 'name' => $name]));
        }

        return parent::uuidSql($expression);
    }
}
