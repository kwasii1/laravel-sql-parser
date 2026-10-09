<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\DuckDBGeneratorSupport;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayOverlaps;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArraySize;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ConnectByRoot;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Decode;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\FromBase64;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateDateArray;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateSeries;
use LaravelSqlParser\LaravelSqlParser\Expressions\Generator as GeneratorExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\HexString;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONValueArray;
use LaravelSqlParser\LaravelSqlParser\Expressions\Length;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mod;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Nullif;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\OrExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Placeholder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Prior;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Replace;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Slice;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sub;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableFromRows;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unhex;
use LaravelSqlParser\LaravelSqlParser\Expressions\Union;
use LaravelSqlParser\LaravelSqlParser\Expressions\Upper;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;
use LaravelSqlParser\LaravelSqlParser\Expressions\With;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;

/**
 * Module-level helpers of sqlglot generators/duckdb.py (lines 1-1043).
 */
trait GeneratesDuckDBPart1
{
    /**
     * Base64 alphabet replacements: 1st char = index 62 ('+'), 2nd = index 63 ('/'), 3rd = padding ('=').
     * With $reverse, custom chars are replaced by the default ones (decode).
     */
    protected function applyBase64AlphabetReplacements(?Expression $result, ?Expression $alphabet, bool $reverse = false): ?Expression
    {
        if ($alphabet instanceof Literal && $alphabet->isString()) {
            $chars = mb_str_split($alphabet->name());

            foreach (['+', '/', '='] as $i => $defaultChar) {
                if (! isset($chars[$i])) {
                    break;
                }

                $newChar = $chars[$i];

                if ($newChar !== $defaultChar) {
                    [$find, $replace] = $reverse ? [$newChar, $defaultChar] : [$defaultChar, $newChar];
                    $result = new Replace([
                        'this' => $result,
                        'expression' => Literal::string($find),
                        'replacement' => Literal::string($replace),
                    ]);
                }
            }
        }

        return $result;
    }

    /**
     * Snowflake BASE64_DECODE_STRING/BINARY: FROM_BASE64 returns BLOB, DECODE() makes it a string.
     */
    protected function base64DecodeSql(Expression $expression, bool $toString): string
    {
        $input = $this->applyBase64AlphabetReplacements($expression->args['this'] ?? null, $expression->argExpr('alphabet'), reverse: true);
        $input = new FromBase64(['this' => $input]);

        if ($toString) {
            $input = new Decode(['this' => $input]);
        }

        return $this->sql($input);
    }

    /**
     * DuckDB's LAST_DAY only supports months; other date parts are emulated.
     */
    protected function lastDaySqlHandler(Expression $expression): string
    {
        $dateExpr = $expression->args['this'] ?? null;
        $weekStart = static::weekUnitToDow($expression->argExpr('unit'));

        if ($weekStart !== null && $weekStart !== 0) {
            // The week's last day precedes its start day; DuckDB DAYOFWEEK: Sunday=0, ..., Saturday=6
            $lastDow = $weekStart - 1;
            $dow = Builder::func('EXTRACT', ['DAYOFWEEK', $dateExpr]);

            // Days to the last day of week: (last_dow + 7 - dayofweek) % 7
            $daysToLast = new Mod([
                'this' => new Paren(['this' => new Sub(['this' => Literal::number($lastDow + 7), 'expression' => $dow])]),
                'expression' => Literal::number(7),
            ]);
            $interval = new Interval(['this' => $daysToLast, 'unit' => Builder::var('DAY')]);

            return $this->sql(Builder::cast(new Add(['this' => $dateExpr, 'expression' => $interval]), DType::DATE));
        }

        $unit = strtoupper($expression->text('unit'));

        if ($unit === '' || $unit === 'MONTH') {
            return $this->func('LAST_DAY', $dateExpr);
        }

        if ($unit === 'YEAR') {
            $year = Builder::func('EXTRACT', ['YEAR', $dateExpr]);

            return $this->sql(Builder::func('MAKE_DATE', [$year, Literal::number(12), Literal::number(31)]));
        }

        if ($unit === 'QUARTER') {
            $year = Builder::func('EXTRACT', ['YEAR', $dateExpr]);
            $quarter = Builder::func('EXTRACT', ['QUARTER', $dateExpr]);

            // Last month of quarter: quarter * 3
            $lastMonth = new Mul(['this' => $quarter, 'expression' => Literal::number(3)]);
            $firstDayLastMonth = Builder::func('MAKE_DATE', [$year, $lastMonth, Literal::number(1)]);

            return $this->sql(Builder::func('LAST_DAY', [$firstDayLastMonth]));
        }

        $this->unsupported("Unsupported date part '{$expression->text('unit')}' in LAST_DAY function");

        return $this->functionFallbackSql($expression);
    }

    protected function isNanosecondUnit(mixed $unit): bool
    {
        return ($unit instanceof VarExpr || $unit instanceof Literal) && strtoupper($unit->name()) === 'NANOSECOND';
    }

    /**
     * NANOSECOND diff using EPOCH_NS since DATE_DIFF doesn't support it.
     */
    protected function handleNanosecondDiff(Expression $endTime, Expression $startTime): string
    {
        $endNs = Builder::cast($endTime, DType::TIMESTAMP_NS);
        $startNs = Builder::cast($startTime, DType::TIMESTAMP_NS);

        return $this->sql(new Sub([
            'this' => Builder::func('EPOCH_NS', [$endNs]),
            'expression' => Builder::func('EPOCH_NS', [$startNs]),
        ]));
    }

    /**
     * Snowflake TO_BOOLEAN / TRY_TO_BOOLEAN: handle 'on'/'off' explicitly, NaN/INF raise an error.
     */
    protected function toBooleanSqlHandler(Expression $expression): string
    {
        $arg = $expression->argExpr('this') ?? new NullExpr;

        $ifs = [
            new IfExpr([
                'this' => new EQ(['this' => new Upper(['this' => Builder::cast($arg, DType::VARCHAR)]), 'expression' => Literal::string('ON')]),
                'true' => Builder::true(),
            ]),
            new IfExpr([
                'this' => new EQ(['this' => new Upper(['this' => Builder::cast($arg, DType::VARCHAR)]), 'expression' => Literal::string('OFF')]),
                'true' => Builder::false(),
            ]),
        ];

        if ($expression->flag('safe')) {
            $default = Builder::func('TRY_CAST', [$arg->copy(), DataType::build(DType::BOOLEAN)]);
        } else {
            $castToReal = Builder::func('TRY_CAST', [$arg->copy(), DataType::build(DType::FLOAT)]);
            $nanInfCheck = new OrExpr([
                'this' => Builder::func('ISNAN', [$castToReal]),
                'expression' => Builder::func('ISINF', [$castToReal]),
            ]);
            $ifs[] = new IfExpr([
                'this' => $nanInfCheck,
                'true' => Builder::func('ERROR', [Literal::string('TO_BOOLEAN: Non-numeric values NaN and INF are not supported')]),
            ]);
            $default = Builder::cast($arg, DType::BOOLEAN);
        }

        return $this->sql(new CaseExpr(['ifs' => $ifs, 'default' => $default]));
    }

    /**
     * BigQuery -> DuckDB conversion for the DATE function.
     */
    protected function dateSqlHandler(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $zone = $this->sql($expression, 'zone');

        if ($zone !== '' && $this_ instanceof Expression) {
            // BigQuery considers "this" at UTC, converts it to the zone and keeps the DATE part
            $this_ = Builder::cast($this_, DType::TIMESTAMP);
            $atUtc = new AtTimeZone(['this' => $this_, 'zone' => Literal::string('UTC')]);
            $this_ = new AtTimeZone(['this' => $atUtc, 'zone' => $expression->args['zone'] ?? null]);
        }

        return $this->sql(Builder::cast($this_ ?? Builder::null(), DType::DATE));
    }

    /**
     * BigQuery -> DuckDB conversion for the TIME_DIFF function.
     */
    protected function timediffSqlHandler(Expression $expression): string
    {
        $unit = $expression->args['unit'] ?? null;
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $other = $expression->argExpr('expression') ?? new NullExpr;

        if ($this->isNanosecondUnit($unit)) {
            return $this->handleNanosecondDiff($other, $this_);
        }

        // BQ seems to inverse the sign of the result so the operands are flipped
        return $this->func('DATE_DIFF', Builder::unitToStr($expression), Builder::cast($other, DType::TIME), Builder::cast($this_, DType::TIME));
    }

    /**
     * DuckDB override of date_delta_to_binary_interval_op: NANOSECOND units (no INTERVAL ... NANOSECOND)
     * and float/decimal interval values (INTERVAL requires integers).
     *
     * @param  string|array<int|string, mixed>  $baseImpl
     */
    protected function dateDeltaToBinaryIntervalOpDuckdb(Expression $expression, string|array $baseImpl = ['dateDeltaToBinaryIntervalOp', 'cast' => true]): string
    {
        $unit = $expression->args['unit'] ?? null;
        $intervalValue = $expression->argExpr('expression');

        if ($this->isNanosecondUnit($unit)) {
            if ($intervalValue instanceof Interval) {
                $intervalValue = $intervalValue->argExpr('this');
            }

            $timestampNs = Builder::cast($expression->argExpr('this') ?? Builder::null(), DType::TIMESTAMP_NS);

            return $this->sql(Builder::func('MAKE_TIMESTAMP_NS', [
                new Add(['this' => Builder::func('EPOCH_NS', [$timestampNs]), 'expression' => $intervalValue]),
            ]));
        }

        if ($intervalValue !== null && ! $intervalValue instanceof Interval && $intervalValue->isType(...DataType::REAL_TYPES)) {
            $expression->set('expression', Builder::cast(Builder::func('ROUND', [$intervalValue]), 'INT'));
        }

        return $this->callTransform($baseImpl, $expression);
    }

    /**
     * ARRAY_INSERT via LIST_CONCAT and slicing; NULL arrays propagate NULL.
     */
    protected function arrayInsertSqlHandler(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $position = $expression->argExpr('position');
        $element = $expression->args['expression'] ?? null;
        $elementArray = new ArrayExpr(['expressions' => [$element]]);
        $indexOffset = (int) ($expression->args['offset'] ?? 0);

        if ($position === null || ! $position->isInt() || $this_ === null) {
            $this->unsupported('ARRAY_INSERT can only be transpiled with a literal position');

            return $this->func('ARRAY_INSERT', $this_, $position, $element);
        }

        $pos = (int) $position->toPhp();

        // Normalize one-based indexing to zero-based for slice calculations
        if ($pos > 0) {
            $pos -= $indexOffset;
        } elseif ($pos < 0) {
            $pos += $indexOffset;
        }

        if ($pos === 0) {
            $concat = [$elementArray, $this_];
        } elseif ($pos > 0) {
            // LIST_CONCAT(arr[1:pos], [elem], arr[pos+1:])
            $sliceStart = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => Literal::number(1), 'expression' => Literal::number($pos)])]]);
            $sliceEnd = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => Literal::number($pos + 1)])]]);
            $concat = [$sliceStart, $elementArray, $sliceEnd];
        } else {
            // arr[1:LEN(arr)+pos], [elem], arr[LEN(arr)+pos+1:]
            $sliceEndPos = new Add(['this' => new Length(['this' => $this_]), 'expression' => Literal::number($pos)]);
            $sliceStartPos = new Add(['this' => $sliceEndPos, 'expression' => Literal::number(1)]);
            $sliceStart = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => Literal::number(1), 'expression' => $sliceEndPos])]]);
            $sliceEnd = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => $sliceStartPos])]]);
            $concat = [$sliceStart, $elementArray, $sliceEnd];
        }

        return $this->sql(new IfExpr([
            'this' => new Is(['this' => $this_, 'expression' => Builder::null()]),
            'true' => Builder::null(),
            'false' => $this->funcWith('LIST_CONCAT', $concat),
        ]));
    }

    /**
     * ARRAY_REMOVE_AT via LIST_CONCAT and slicing; NULL arrays propagate NULL.
     */
    protected function arrayRemoveAtSqlHandler(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $position = $expression->argExpr('position');

        if ($position === null || ! $position->isInt() || $this_ === null) {
            $this->unsupported('ARRAY_REMOVE_AT can only be transpiled with a literal position');

            return $this->func('ARRAY_REMOVE_AT', $this_, $position);
        }

        $pos = (int) $position->toPhp();

        if ($pos === 0) {
            // arr[2:]
            $result = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => Literal::number(2)])]]);
        } elseif ($pos > 0) {
            // LIST_CONCAT(arr[1:pos], arr[pos+2:])
            $left = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => Literal::number(1), 'expression' => Literal::number($pos)])]]);
            $right = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => Literal::number($pos + 2)])]]);
            $result = $this->func('LIST_CONCAT', $left, $right);
        } elseif ($pos === -1) {
            // arr[1:LEN(arr)-1]
            $sliceEnd = new Add(['this' => new Length(['this' => $this_]), 'expression' => Literal::number(-1)]);
            $result = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => Literal::number(1), 'expression' => $sliceEnd])]]);
        } else {
            // LIST_CONCAT(arr[1:LEN(arr)+pos], arr[LEN(arr)+pos+2:])
            $sliceEndPos = new Add(['this' => new Length(['this' => $this_]), 'expression' => Literal::number($pos)]);
            $sliceStartPos = new Add(['this' => $sliceEndPos, 'expression' => Literal::number(2)]);
            $left = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => Literal::number(1), 'expression' => $sliceEndPos])]]);
            $right = new Bracket(['this' => $this_, 'expressions' => [new Slice(['this' => $sliceStartPos])]]);
            $result = $this->func('LIST_CONCAT', $left, $right);
        }

        return $this->sql(new IfExpr([
            'this' => new Is(['this' => $this_, 'expression' => Builder::null()]),
            'true' => Builder::null(),
            'false' => $result,
        ]));
    }

    protected function arraySortSqlHandler(Expression $expression): string
    {
        return $this->func('ARRAY_SORT', $expression->args['this'] ?? null);
    }

    protected function arrayContainsSqlHandler(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $expr = $expression->argExpr('expression');
        $func = $this->func('ARRAY_CONTAINS', $this_, $expr);

        if ($expression->flag('check_null') && $expr !== null) {
            $checkNullInArray = new Nullif([
                'this' => new NEQ(['this' => new ArraySize(['this' => $this_]), 'expression' => Builder::func('LIST_COUNT', [$this_ ?? Builder::null()])]),
                'expression' => Builder::false(),
            ]);

            return $this->sql(new IfExpr([
                'this' => new Is(['this' => $expr, 'expression' => Builder::null()]),
                'true' => $checkNullInArray,
                'false' => $func,
            ]));
        }

        return $func;
    }

    /**
     * Snowflake's NULL-safe ARRAYS_OVERLAP: (a && b) OR (both arrays contain a NULL).
     */
    protected function arrayOverlapsSqlHandler(Expression $expression): string
    {
        if (! $expression->flag('null_safe')) {
            return $this->binary($expression, '&&');
        }

        $arr1 = $expression->argExpr('this') ?? new NullExpr;
        $arr2 = $expression->argExpr('expression') ?? new NullExpr;

        $checkNulls = Builder::and([
            new NEQ(['this' => new ArraySize(['this' => $arr1->copy()]), 'expression' => Builder::func('LIST_COUNT', [$arr1->copy()])]),
            new NEQ(['this' => new ArraySize(['this' => $arr2->copy()]), 'expression' => Builder::func('LIST_COUNT', [$arr2->copy()])]),
        ], copy: false);

        $overlap = new ArrayOverlaps(['this' => $arr1->copy(), 'expression' => $arr2->copy()]);

        return $this->sql(Builder::or([
            Builder::paren($overlap, copy: false),
            Builder::paren($checkNulls, copy: false),
        ], copy: false, wrap: false));
    }

    protected function structSqlHandler(Expression $expression): string
    {
        $ancestorCast = $expression->findAncestor(Cast::class, Select::class);
        $ancestorCast = $ancestorCast instanceof Cast ? $ancestorCast : null;
        $expressions = $expression->argList('expressions');

        // Empty struct cast works with MAP() since DuckDB can't parse {}
        if ($expressions === [] && $ancestorCast !== null) {
            $to = $ancestorCast->argExpr('to');

            if ($to !== null && $to->isType(DType::MAP)) {
                return 'MAP()';
            }
        }

        // BigQuery inline STRUCT<a STRING, b INTEGER>('str', 1) becomes ROW('str', 1) :: STRUCT(...)
        $isBqInlineStruct = false;

        if ($expression->find(PropertyEQ::class) === null && $ancestorCast !== null) {
            foreach ($ancestorCast->findAll(DataType::class) as $castedType) {
                if ($castedType->isType(DType::STRUCT)) {
                    $isBqInlineStruct = true;

                    break;
                }
            }
        }

        $args = [];

        foreach ($expressions as $i => $expr) {
            $isPropertyEq = $expr instanceof PropertyEQ;
            $this_ = $expr->args['this'] ?? null;
            $value = $isPropertyEq ? ($expr->args['expression'] ?? null) : $expr;

            if ($isBqInlineStruct) {
                $args[] = $this->sql($value);
            } else {
                if ($this_ instanceof Identifier) {
                    $key = $this->sql(Literal::string($expr->name()));
                } elseif ($isPropertyEq) {
                    $key = $this->sql($this_);
                } else {
                    $key = $this->sql(Literal::string("_{$i}"));
                }

                $args[] = "{$key}: {$this->sql($value)}";
            }
        }

        $csvArgs = implode(', ', $args);

        return $isBqInlineStruct ? "ROW({$csvArgs})" : "{{$csvArgs}}";
    }

    protected function jsonFormatSqlHandler(Expression $expression): string
    {
        $sql = $this->func('TO_JSON', $expression->args['this'] ?? null, $expression->args['options'] ?? null);

        return "CAST({$sql} AS TEXT)";
    }

    /**
     * A SEQ expression with the given base, byte width and signedness.
     */
    protected function buildSeqExpression(Expression $base, int $byteWidth, bool $signed): Expression
    {
        // 2 ** 64 overflows PHP ints, so the powers of two are spelled out
        $powers = ['8' => '256', '16' => '65536', '32' => '4294967296', '64' => '18446744073709551616'];
        $halves = ['8' => '128', '16' => '32768', '32' => '2147483648', '64' => '9223372036854775808'];
        $bits = (string) ($byteWidth * 8);
        $values = ['base' => $base, 'max_val' => Literal::number($powers[$bits])];

        if ($signed) {
            $values['half'] = Literal::number($halves[$bits]);
            $template = DuckDBGeneratorSupport::seqSigned();
        } else {
            $template = DuckDBGeneratorSupport::seqUnsigned();
        }

        // exp.replace_placeholders
        $result = $template->transform(static function (Expression $node) use ($values): Expression {
            if ($node instanceof Placeholder) {
                $name = $node->name();

                if ($name !== '' && isset($values[$name])) {
                    return $values[$name]->copy();
                }
            }

            return $node;
        }, copy: false);

        return $result ?? $template;
    }

    /**
     * SEQ functions inside GENERATOR(ROWCOUNT => N) (which becomes RANGE(N)) are replaced by the
     * `range` column, avoiding nested window functions.
     */
    public function seqToRangeInGenerator(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $from = $expression->argExpr('from_');
        $source = $from?->argExpr('this');

        if (! $source instanceof TableFromRows || ! ($source->args['this'] ?? null) instanceof GeneratorExpr) {
            return $expression;
        }

        $result = $expression->transform(function (Expression $node): Expression {
            $byteWidth = DuckDBGeneratorSupport::SEQ_BYTE_WIDTH[$node::class] ?? null;

            if ($byteWidth !== null) {
                return $this->buildSeqExpression(Builder::column('range'), $byteWidth, signed: $node->name() === '1');
            }

            return $node;
        }, copy: false);

        return $result ?? $expression;
    }

    /**
     * Rewrites START WITH ... CONNECT BY PRIOR into WITH RECURSIVE; unchanged if there are no PRIORs.
     */
    public function connectByToRecursiveCte(Expression $expression): Expression
    {
        if (! $expression instanceof Select || ! $expression->flag('connect')) {
            return $expression;
        }

        $connect = $expression->argExpr('connect');
        $connectPred = $connect?->argExpr('connect');

        if ($connect === null || $connectPred === null || $connectPred->findAll(Prior::class) === []) {
            return $expression;
        }

        $from = $expression->argExpr('from_');

        if ($from === null || $expression->flag('joins')) {
            return $expression;
        }

        $sourceTable = $from->argExpr('this') ?? new NullExpr;
        $baseSelectExprs = $expression->argList('expressions');
        $baseWhere = $expression->argExpr('where');
        $baseWith = $expression->argExpr('with_');

        // LEVEL is a Snowflake pseudo-column: it's always computed as a depth counter in the CTE.
        $hasLevel = false;

        foreach ($baseSelectExprs as $e) {
            foreach ($e->findAll(Column::class) as $col) {
                if (strtoupper($col->name()) === 'LEVEL') {
                    $hasLevel = true;

                    break 2;
                }
            }
        }

        $hasStar = $expression->isStar();

        // CONNECT_BY_ROOT col is threaded through the CTE as an extra column: the anchor binds it to
        // the row's own value, the recursive arm forwards the parent's value unchanged.
        $rootColNames = [];
        $anchorRootCols = [];
        $innerRootCols = [];
        $roots = [];

        foreach ($baseSelectExprs as $e) {
            foreach ($e->findAll(ConnectByRoot::class) as $root) {
                $roots[] = $root;
            }
        }

        foreach ($roots as $i => $root) {
            $name = "_connect_by_root_{$i}";
            $rootColNames[] = $name;
            $anchorRootCols[] = Builder::alias($root->argExpr('this') ?? Builder::null(), $name);
            $innerRootCols[] = Builder::alias(Builder::column($name, '_parent_row'), $name);
            $root->replace(Builder::column($name));
        }

        // PRIOR(col) -> _parent_row.col, unqualified cols -> _child_row.col
        $qualifiedPred = $connectPred->copy();

        foreach (Scope::findAllInScope($qualifiedPred, Column::class) as $col) {
            $col->set('table', Builder::toIdentifier($col->parent instanceof Prior ? '_parent_row' : '_child_row'));
        }

        foreach (Scope::findAllInScope($qualifiedPred, Prior::class) as $prior) {
            $replacement = $prior->argExpr('this');

            if ($prior === $qualifiedPred) {
                $qualifiedPred = $replacement ?? $qualifiedPred;
            } elseif ($replacement !== null) {
                $prior->replace($replacement);
            }
        }

        // Avoid colliding with any CTE names already on the query.
        $taken = [];

        foreach ($baseWith?->argList('expressions') ?? [] as $cte) {
            $taken[] = $cte->alias();
        }

        $cteName = self::findNewName($taken, '_rootcte');

        // Anchor: all source columns + LEVEL seeded at 1 + each root column bound to its own value.
        $anchor = new Select([
            'expressions' => [new Star([]), Builder::alias(Literal::number(1), 'level'), ...$anchorRootCols],
            'from_' => new From(['this' => $sourceTable->copy()]),
        ]);

        $start = $connect->argExpr('start');

        if ($start !== null) {
            $anchor->set('where', new Where(['this' => $start->copy()]));
        }

        // Recursive arm: all child columns + incremented level + each forwarded root value.
        $innerQuery = new Select([
            'expressions' => [
                new Column(['this' => new Star([]), 'table' => Builder::toIdentifier('_child_row')]),
                Builder::alias(new Add(['this' => Builder::column('level', '_parent_row'), 'expression' => Literal::number(1)]), 'level'),
                ...$innerRootCols,
            ],
            'from_' => new From(['this' => Builder::alias($sourceTable->copy(), '_child_row', table: true)]),
            'joins' => [new Join(['this' => Builder::table($cteName, alias: '_parent_row'), 'on' => $qualifiedPred])],
        ]);

        // Synthetic level/root columns are excluded from star expansion (level only when unreferenced).
        if ($hasStar) {
            $exceptCols = $hasLevel ? [] : [Builder::column('level')];

            foreach ($rootColNames as $name) {
                $exceptCols[] = Builder::column($name);
            }

            $outerSelectExprs = [$exceptCols !== [] ? new Star(['except_' => $exceptCols]) : new Star([])];

            foreach ($baseSelectExprs as $e) {
                if (! $e->isStar()) {
                    $outerSelectExprs[] = $e;
                }
            }
        } else {
            $outerSelectExprs = $baseSelectExprs;
        }

        $outerQuery = new Select([
            'expressions' => $outerSelectExprs,
            'from_' => new From(['this' => Builder::table($cteName)]),
        ]);

        if ($baseWhere !== null) {
            $outerQuery->set('where', new Where(['this' => $baseWhere->argExpr('this')]));
        }

        // Attach the CTE, marking the WITH clause recursive.
        $with = $baseWith ?? new With(['expressions' => []]);
        $cte = Builder::alias(new CTE(['this' => new Union(['this' => $anchor, 'expression' => $innerQuery, 'distinct' => false])]), $cteName, table: true);
        $with->append('expressions', $cte);
        $with->set('recursive', true);
        $outerQuery->set('with_', $with);

        foreach ($expression->args as $arg => $val) {
            if ($this->truthy($val) && ! isset(DuckDBGeneratorSupport::CONNECT_BY_ARGS_TO_SKIP[$arg])) {
                $outerQuery->set($arg, $val);
            }
        }

        // Strip stale source table qualifiers; CTEs are child scopes so this stays in the outer query.
        foreach (Scope::findAllInScope($outerQuery, Column::class) as $col) {
            $col->set('table', null);
        }

        return $outerQuery;
    }

    /**
     * Snowflake SEQ1/SEQ2/SEQ4/SEQ8: monotonically increasing integers from 0, wrapping at
     * 2^bits (unsigned) or 2^(bits-1) then going negative (signed).
     */
    protected function seqSql(Expression $expression, int $byteWidth): string
    {
        // Warn if SEQ is in a restricted context (Select stops search at current scope)
        $ancestor = $expression->findAncestor(...DuckDBGeneratorSupport::SEQ_RESTRICTED);

        if (
            $ancestor !== null
            && ((! $ancestor instanceof Order && ! $ancestor instanceof Select) || ($ancestor instanceof Order && $ancestor->parent instanceof Window))
        ) {
            $this->unsupported('SEQ in restricted context is not supported - use CTE or subquery');
        }

        return $this->sql($this->buildSeqExpression(DuckDBGeneratorSupport::seqBase(), $byteWidth, signed: $expression->name() === '1'));
    }

    protected function unixToTimeSqlHandler(Expression $expression): string
    {
        $scale = $expression->args['scale'] ?? null;
        $timestamp = $expression->args['this'] ?? null;
        $targetType = $expression->args['target_type'] ?? null;

        // Check if we need NTZ (naive timestamp in UTC)
        $isNtz = $targetType instanceof Expression && in_array($targetType->args['this'] ?? null, [DType::TIMESTAMP, DType::TIMESTAMPNTZ], true);

        if ($scale instanceof Literal && $scale->equals(Literal::number(3))) {
            // EPOCH_MS already returns TIMESTAMP (naive, UTC)
            return $this->func('EPOCH_MS', $timestamp);
        }

        if ($scale instanceof Literal && $scale->equals(Literal::number(6))) {
            // MAKE_TIMESTAMP already returns TIMESTAMP (naive, UTC)
            return $this->func('MAKE_TIMESTAMP', $timestamp);
        }

        // Other scales: divide and use TO_TIMESTAMP
        if ($scale instanceof Expression && ! ($scale instanceof Literal && $scale->equals(Literal::number(0)))) {
            $timestamp = new Div(['this' => $timestamp, 'expression' => Builder::func('POW', [Literal::number(10), $scale])]);
        }

        $toTimestamp = new Anonymous(['this' => 'TO_TIMESTAMP', 'expressions' => [$timestamp]]);

        if ($isNtz) {
            $toTimestamp = new AtTimeZone(['this' => $toTimestamp, 'zone' => Literal::string('UTC')]);
        }

        return $this->sql($toTimestamp);
    }

    protected function arrowJsonExtractSqlDuckdb(Expression $expression): string
    {
        $arrowSql = $this->arrowJsonExtractSql($expression);
        $parent = $expression->parent;

        // WRAPPED_JSON_EXTRACT_EXPRESSIONS
        if (
            ! ($parent !== null && $parent::class === $expression::class)
            && ($parent instanceof Binary || $parent instanceof Bracket || $parent instanceof In || $parent instanceof Not)
        ) {
            $arrowSql = $this->wrap($arrowSql);
        }

        return $arrowSql;
    }

    protected function implicitDatetimeCast(?Expression $arg, string $type = DType::DATE): ?Expression
    {
        if ($arg instanceof Literal && $arg->isString()) {
            $ts = $arg->name();

            if ($type === DType::DATE && str_contains($ts, ':')) {
                $type = preg_match(DuckDBGeneratorSupport::TIMEZONE_PATTERN, $ts) === 1 ? DType::TIMESTAMPTZ : DType::TIMESTAMP;
            }

            $arg = Builder::cast($arg, $type);
        }

        return $arg;
    }

    protected function weekTruncStartDow(?Expression $unit): ?int
    {
        // DuckDB's weeks are ISO 8601, so ISOWEEK maps to its plain WEEK unit
        if ($unit instanceof Literal && strtoupper($unit->name()) === 'ISOWEEK') {
            return 1;
        }

        return static::weekUnitToDow($unit);
    }

    /**
     * DATE_TRUNC for week boundaries with a custom ISO start day (Monday=1, ..., Sunday=7): DuckDB's
     * DATE_TRUNC('WEEK', ...) always returns Monday, so the date is shifted before truncating.
     * $preserveStartDay reverses the shift afterwards; $castToDate casts that result back to DATE.
     */
    protected function buildWeekTruncExpression(Expression $dateExpr, int $startDow, bool $preserveStartDay = false, bool $castToDate = true): Expression
    {
        $shiftDays = $startDow === 7 ? 1 : 1 - $startDow;
        $truncated = new DateTrunc(['unit' => Builder::var('WEEK'), 'this' => $dateExpr]);

        if ($shiftDays === 0) {
            return $truncated;
        }

        $shift = new Interval(['this' => Literal::string((string) $shiftDays), 'unit' => Builder::var('DAY')]);
        $truncated->set('this', new DateAdd(['this' => $dateExpr, 'expression' => $shift]));

        if ($preserveStartDay) {
            $interval = new Interval(['this' => Literal::string((string) -$shiftDays), 'unit' => Builder::var('DAY')]);
            $shiftedBack = new DateAdd(['this' => $truncated, 'expression' => $interval]);

            return $castToDate ? Builder::cast($shiftedBack, DType::DATE, copy: false) : $shiftedBack;
        }

        return $truncated;
    }

    protected function dateDiffSqlHandler(Expression $expression): string
    {
        $unit = $expression->argExpr('unit');

        if ($this->isNanosecondUnit($unit)) {
            $this_ = $expression->argExpr('this') ?? new NullExpr;
            $other = $expression->argExpr('expression') ?? new NullExpr;

            return $this->handleNanosecondDiff($this_, $other);
        }

        $this_ = $this->implicitDatetimeCast($expression->argExpr('this'));
        $expr = $this->implicitDatetimeCast($expression->argExpr('expression'));

        // DuckDB's WEEK diff doesn't respect week boundaries, it checks (end_day - start_day) / 7,
        // so both dates are truncated to week boundaries to respect the input dialect semantics.
        $weekStart = static::weekUnitToDow($unit);

        if ($expression->flag('date_part_boundary') && $weekStart !== null && $weekStart !== 0 && $this_ !== null && $expr !== null) {
            $expression->set('unit', Literal::string('WEEK'));
            $this_ = $this->buildWeekTruncExpression($this_, $weekStart);
            $expr = $this->buildWeekTruncExpression($expr, $weekStart);
        }

        return $this->func('DATE_DIFF', Builder::unitToStr($expression), $expr, $this_);
    }

    protected function generateDatetimeArraySql(Expression $expression): string
    {
        $isGenerateDateArray = $expression instanceof GenerateDateArray;
        $type = $isGenerateDateArray ? DType::DATE : DType::TIMESTAMP;

        // BQ's GENERATE_DATE_ARRAY & GENERATE_TIMESTAMP_ARRAY become DuckDB's GENERATE_SERIES
        $genSeries = new GenerateSeries([
            'start' => $this->implicitDatetimeCast($expression->argExpr('start'), $type),
            'end' => $this->implicitDatetimeCast($expression->argExpr('end'), $type),
            'step' => $expression->args['step'] ?? null,
        ]);

        if ($isGenerateDateArray) {
            // GENERATE_SERIES returns a TIMESTAMP array, cast back to a DATE array
            return $this->sql(Builder::cast($genSeries, DataType::fromStr('ARRAY<DATE>')));
        }

        return $this->sql($genSeries);
    }

    protected function jsonExtractValueArraySql(Expression $expression): string
    {
        $jsonExtract = new JSONExtract(['this' => $expression->args['this'] ?? null, 'expression' => $expression->args['expression'] ?? null]);
        $dataType = $expression instanceof JSONValueArray ? 'ARRAY<STRING>' : 'ARRAY<JSON>';

        return $this->sql(Builder::cast($jsonExtract, DataType::fromStr($dataType)));
    }

    protected function castToVarchar(?Expression $arg): ?Expression
    {
        if ($arg !== null && $arg->type() !== null && ! $arg->isType(...DataType::TEXT_TYPES, ...[DType::UNKNOWN])) {
            return Builder::cast($arg, DType::VARCHAR);
        }

        return $arg;
    }

    protected function castToBoolean(?Expression $arg): ?Expression
    {
        if ($arg !== null && ! $arg->isType(DType::BOOLEAN)) {
            return Builder::cast($arg, DType::BOOLEAN);
        }

        return $arg;
    }

    protected function isBinary(Expression $arg): bool
    {
        return $arg->isType(DType::BINARY, DType::VARBINARY, DType::BLOB);
    }

    protected function genWithCastToBlob(Expression $expression, string $resultSql): string
    {
        if ($this->isBinary($expression)) {
            $blob = DataType::fromStr('BLOB', dialect: 'duckdb');
            $resultSql = $this->sql(new Cast(['this' => $resultSql, 'to' => $blob]));
        }

        return $resultSql;
    }

    protected function castToBit(Expression $arg): Expression
    {
        if (! $this->isBinary($arg)) {
            return $arg;
        }

        if ($arg instanceof HexString) {
            $arg = new Unhex(['this' => Literal::string($arg->name())]);
        }

        return Builder::cast($arg, DType::BIT);
    }

    protected function prepareBinaryBitwiseArgs(Expression $expression): void
    {
        $this_ = $expression->argExpr('this');
        $other = $expression->argExpr('expression');

        if ($this_ !== null && $this->isBinary($this_)) {
            $expression->set('this', $this->castToBit($this_));
        }

        if ($other !== null && $this->isBinary($other)) {
            $expression->set('expression', $this->castToBit($other));
        }
    }
}
