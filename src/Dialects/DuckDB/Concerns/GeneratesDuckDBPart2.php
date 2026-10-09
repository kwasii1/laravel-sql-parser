<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\DuckDBGeneratorSupport;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseAndAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseLeftShift;
use LaravelSqlParser\LaravelSqlParser\Expressions\BitwiseOrAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Corr;
use LaravelSqlParser\LaravelSqlParser\Expressions\CountIf;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Filter;
use LaravelSqlParser\LaravelSqlParser\Expressions\HavingMax;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Inline;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Kwarg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lateral;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mod;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\NextDay;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pow;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegrValx;
use LaravelSqlParser\LaravelSqlParser\Expressions\Round;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sub;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Upper;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;
use Throwable;

/**
 * DuckDB generator module functions, sqlglot generators/duckdb.py lines 1044-2298.
 */
trait GeneratesDuckDBPart2
{
    /**
     * Snowflake's NEXT_DAY / PREVIOUS_DAY as DuckDB date arithmetic.
     */
    protected function dayNavigationSql(Expression $expression): string
    {
        $dateExpr = $expression->args['this'] ?? null;
        $dayNameExpr = $expression->args['expression'] ?? null;

        $isodowCall = Builder::func('ISODOW', [$dateExpr]);

        if ($dayNameExpr instanceof Literal) {
            $dayNameStr = strtoupper($dayNameExpr->name());
            $matchingDay = null;
            foreach (self::WEEK_START_DAY_TO_DOW as $day => $_) {
                if (str_starts_with($day, $dayNameStr)) {
                    $matchingDay = $day;

                    break;
                }
            }

            if ($matchingDay === null) {
                return $this->functionFallbackSql($expression);
            }

            $targetDow = Literal::number(self::WEEK_START_DAY_TO_DOW[$matchingDay]);
        } else {
            $upperDayName = new Upper(['this' => $dayNameExpr]);
            $ifs = [];
            foreach (self::WEEK_START_DAY_TO_DOW as $day => $dowNum) {
                $ifs[] = new IfExpr([
                    'this' => Builder::func('STARTS_WITH', [$upperDayName->copy(), Literal::string(substr($day, 0, 2))]),
                    'true' => Literal::number($dowNum),
                ]);
            }
            $targetDow = new CaseExpr(['ifs' => $ifs]);
        }

        if ($expression instanceof NextDay) {
            $inner = Builder::binop(Add::class, Builder::binop(Sub::class, $targetDow, $isodowCall), 6);
            $daysOffset = Builder::binop(Add::class, Builder::binop(Mod::class, Builder::paren($inner, false), 7), 1);
            $dateWithOffset = Builder::binop(Add::class, $dateExpr, new Interval(['this' => $daysOffset, 'unit' => Builder::var('DAY')]));
        } else {
            $inner = Builder::binop(Add::class, Builder::binop(Sub::class, $isodowCall, $targetDow), 6);
            $daysOffset = Builder::binop(Add::class, Builder::binop(Mod::class, Builder::paren($inner, false), 7), 1);
            $dateWithOffset = Builder::binop(Sub::class, $dateExpr, new Interval(['this' => $daysOffset, 'unit' => Builder::var('DAY')]));
        }

        return $this->sql(Builder::cast($dateWithOffset, DType::DATE));
    }

    protected function anyvalueSqlHandler(Expression $expression): string
    {
        // Transform ANY_VALUE(expr HAVING MAX/MIN having_expr) to ARG_MAX_NULL/ARG_MIN_NULL
        $having = $expression->args['this'] ?? null;

        if ($having instanceof HavingMax) {
            $funcName = $having->flag('max') ? 'ARG_MAX_NULL' : 'ARG_MIN_NULL';

            return $this->func($funcName, $having->args['this'] ?? null, $having->args['expression'] ?? null);
        }

        return $this->functionFallbackSql($expression);
    }

    /**
     * DuckDB's bitwise aggregates only accept integer types.
     */
    protected function bitwiseAggSql(Expression $expression): string
    {
        if ($expression instanceof BitwiseOrAgg) {
            $funcName = 'BIT_OR';
        } elseif ($expression instanceof BitwiseAndAgg) {
            $funcName = 'BIT_AND';
        } else {
            $funcName = 'BIT_XOR';
        }

        $arg = $expression->argExpr('this');

        if ($arg === null) {
            return $this->func($funcName);
        }

        if ($arg->type() === null) {
            $arg = $this->dialect->annotateTypes($arg);
        }

        if ($arg->isType(...DataType::REAL_TYPES, ...DataType::TEXT_TYPES)) {
            if ($arg->isType(...DataType::FLOAT_TYPES)) {
                // float types need to be rounded first due to precision loss
                $arg = Builder::func('ROUND', [$arg]);
            }

            $arg = Builder::cast($arg, DType::INT);
        }

        return $this->func($funcName, $arg);
    }

    protected function literalSqlWithWsChr(string $literal): string
    {
        // DuckDB does not support \uXXXX escapes, so we must use CHR() instead of replacing them directly
        $map = DuckDBGeneratorSupport::WS_CONTROL_CHARS_TO_DUCK;
        $chars = mb_str_split($literal);

        $hasWs = false;
        foreach ($chars as $ch) {
            if (isset($map[$ch])) {
                $hasWs = true;

                break;
            }
        }

        if (! $hasWs) {
            return $this->sql(Literal::string($literal));
        }

        $sqlSegments = [];
        $group = '';
        foreach ($chars as $ch) {
            if (isset($map[$ch])) {
                if ($group !== '') {
                    $sqlSegments[] = $this->sql(Literal::string($group));
                    $group = '';
                }
                $sqlSegments[] = $this->func('CHR', Literal::number((string) $map[$ch]));
            } else {
                $group .= $ch;
            }
        }

        if ($group !== '') {
            $sqlSegments[] = $this->sql(Literal::string($group));
        }

        $sql = implode(' || ', $sqlSegments);

        return count($sqlSegments) === 1 ? $sql : "({$sql})";
    }

    /**
     * Escapes regex metacharacters \ - ^ [ ] for use in character classes.
     */
    protected function escapeRegexMetachars(?Expression $delimiters, string $delimitersSql): string
    {
        if ($delimiters === null) {
            return $delimitersSql;
        }

        if ($delimiters->isString()) {
            $escaped = '';
            foreach (mb_str_split((string) $delimiters->args['this']) as $ch) {
                $escaped .= DuckDBGeneratorSupport::REGEX_ESCAPE_REPLACEMENTS[$ch] ?? $ch;
            }

            return $this->literalSqlWithWsChr($escaped);
        }

        $escapedSql = $delimitersSql;
        foreach (DuckDBGeneratorSupport::REGEX_ESCAPE_REPLACEMENTS as $raw => $escapedChar) {
            $escapedSql = $this->func(
                'REPLACE',
                $escapedSql,
                $this->sql(Literal::string((string) $raw)),
                $this->sql(Literal::string($escapedChar)),
            );
        }

        return $escapedSql;
    }

    protected function buildCapitalizationSql(string $valueToSplit, string $delimitersSql): string
    {
        // empty string delimiter --> treat value as one word, no need to split
        if ($delimitersSql === "''") {
            return "UPPER(LEFT({$valueToSplit}, 1)) || LOWER(SUBSTRING({$valueToSplit}, 2))";
        }

        $delimRegexSql = "CONCAT('[', {$delimitersSql}, ']')";
        $splitRegexSql = "CONCAT('([', {$delimitersSql}, ']+|[^', {$delimitersSql}, ']+)')";

        $case = new CaseExpr([
            'ifs' => [new IfExpr([
                'this' => Builder::maybeParse("REGEXP_MATCHES(LEFT({$valueToSplit}, 1), {$delimRegexSql})"),
                'true' => Builder::maybeParse($this->func(
                    'LIST_TRANSFORM',
                    $this->func('REGEXP_EXTRACT_ALL', $valueToSplit, $splitRegexSql),
                    '(seg, idx) -> CASE WHEN idx % 2 = 0 THEN UPPER(LEFT(seg, 1)) || LOWER(SUBSTRING(seg, 2)) ELSE seg END',
                )),
            ])],
            'default' => Builder::maybeParse($this->func(
                'LIST_TRANSFORM',
                $this->func('REGEXP_EXTRACT_ALL', $valueToSplit, $splitRegexSql),
                '(seg, idx) -> CASE WHEN idx % 2 = 1 THEN UPPER(LEFT(seg, 1)) || LOWER(SUBSTRING(seg, 2)) ELSE seg END',
            )),
        ]);

        return $this->func('ARRAY_TO_STRING', $case, "''");
    }

    protected function initcapSqlHandler(Expression $expression): string
    {
        $thisSql = $this->sql($expression, 'this');
        $delimiters = $expression->args['expression'] ?? null;

        if ($delimiters === null) {
            // fallback for manually created exp.Initcap w/o delimiters arg
            $delimiters = Literal::string($this->dialect->settings['INITCAP_DEFAULT_DELIMITER_CHARS']);
        }
        $delimitersSql = $this->sql($delimiters);

        $escapedDelimitersSql = $this->escapeRegexMetachars($delimiters, $delimitersSql);

        return $this->buildCapitalizationSql($thisSql, $escapedDelimitersSql);
    }

    /**
     * Snowflake's BOOLXOR_AGG(col) as COUNT_IF(col) = 1.
     */
    protected function boolxorAggSqlHandler(Expression $expression): string
    {
        return $this->sql(new EQ([
            'this' => new CountIf(['this' => $this->castToBoolean($expression->this())]),
            'expression' => Literal::number(1),
        ]));
    }

    /**
     * Bitshifts with BIT/INT128 casts, since DuckDB can't shift BLOBs.
     */
    protected function bitshiftSql(Expression $expression): string
    {
        $operator = $expression instanceof BitwiseLeftShift ? '<<' : '>>';
        $resultIsBlob = false;
        $this_ = $expression->this();

        if ($this->isBinary($this_)) {
            $resultIsBlob = true;
            $expression->set('this', Builder::cast($this_, DType::BIT));
        } elseif ($expression->flag('requires_int128')) {
            $this_->replace(Builder::cast($this_, DType::INT128));
        }

        $resultSql = $this->binary($expression, $operator);

        // Wrap in parentheses if parent is a bitwise operator to "fix" DuckDB precedence issue
        if ($expression->parent instanceof Binary) {
            $resultSql = "({$resultSql})";
        }

        if ($resultIsBlob) {
            $resultSql = $this->sql(new Cast(['this' => $resultSql, 'to' => DataType::fromStr('BLOB', 'duckdb')]));
        }

        return $resultSql;
    }

    /**
     * FUNC(x, n) as ROUND(FUNC(x * 10^n) / 10^n, n) when the scale arg is unsupported.
     *
     * @param  class-string<Expression>  $roundingFunc
     */
    protected function scaleRoundingSql(Expression $expression, string $roundingFunc): ?string
    {
        $decimals = $expression->args['decimals'] ?? null;

        if (! $decimals instanceof Expression || ($expression->args['to'] ?? null) !== null) {
            return null;
        }

        $this_ = $expression->this();

        if ($this_ instanceof Binary) {
            $this_ = new Paren(['this' => $this_]);
        }

        $nInt = $decimals;

        if (! ($decimals->isInt() || $decimals->isType(...DataType::INTEGER_TYPES))) {
            $nInt = Builder::cast($decimals, DType::INT);
        }

        $pow = new Pow(['this' => Literal::number('10'), 'expression' => $nInt]);
        $rounded = new $roundingFunc(['this' => new Mul(['this' => $this_, 'expression' => $pow])]);
        $result = new Div(['this' => $rounded, 'expression' => $pow->copy()]);

        return $this->roundSql(new Round(['this' => $result, 'decimals' => $decimals, 'casts_non_integer_decimals' => true]));
    }

    protected function ceilFloorHandler(Expression $expression): string
    {
        $scaledSql = $this->scaleRoundingSql($expression, $expression::class);

        if ($scaledSql !== null) {
            return $scaledSql;
        }

        return $this->ceilFloor($expression);
    }

    /**
     * Snowflake's REGR_VALX / REGR_VALY.
     */
    protected function regrValSql(Expression $expression): string
    {
        $y = $expression->this();
        $x = $expression->expression();

        if ($expression instanceof RegrValx) {
            $checkForNull = $y;
            $returnValue = $x;
            $returnValueAttr = 'expression';
        } else {
            $checkForNull = $x;
            $returnValue = $y;
            $returnValueAttr = 'this';
        }

        $resultType = $returnValue->type();

        if ($resultType === null || ($resultType->args['this'] ?? null) === DType::UNKNOWN) {
            try {
                $annotated = $this->dialect->annotateTypes($expression->copy());
                $resultType = $annotated->argExpr($returnValueAttr)?->type();
            } catch (Throwable) {
            }
        }

        if ($resultType === null || ($resultType->args['this'] ?? null) === DType::UNKNOWN) {
            $resultType = DataType::build(DType::DOUBLE);
        }

        // Cast NULL to the same type as return_value to avoid DuckDB type inference issues
        $typedNull = new Cast(['this' => new NullExpr, 'to' => $resultType]);

        return $this->sql(new IfExpr([
            'this' => new Is(['this' => $checkForNull->copy(), 'expression' => new NullExpr]),
            'true' => $typedNull,
            'false' => $returnValue->copy(),
        ]));
    }

    public function maybeCorrNullToFalse(Expression $expression): ?Expression
    {
        $corr = $expression;
        while ($corr instanceof Window || $corr instanceof Filter) {
            $corr = $corr->this();
        }

        if (! $corr instanceof Corr || ! $corr->flag('null_on_zero_variance')) {
            return null;
        }

        $corr->set('null_on_zero_variance', false);

        return $expression;
    }

    /**
     * Snowflake's DATE_FROM_PARTS allows out-of-range month/day values; use interval arithmetic.
     */
    protected function dateFromPartsSqlHandler(Expression $expression): string
    {
        $yearExpr = $expression->args['year'] ?? null;
        $monthExpr = $expression->args['month'] ?? null;
        $dayExpr = $expression->args['day'] ?? null;

        if ($expression->flag('allow_overflow')) {
            $baseDate = Builder::func('MAKE_DATE', [$yearExpr, Literal::number(1), Literal::number(1)]);

            if ($monthExpr instanceof Expression) {
                $baseDate = Builder::binop(Add::class, $baseDate, new Interval(['this' => Builder::binop(Sub::class, $monthExpr, 1), 'unit' => Builder::var('MONTH')]));
            }

            if ($dayExpr instanceof Expression) {
                $baseDate = Builder::binop(Add::class, $baseDate, new Interval(['this' => Builder::binop(Sub::class, $dayExpr, 1), 'unit' => Builder::var('DAY')]));
            }

            return $this->sql(Builder::cast($baseDate, DType::DATE));
        }

        return $this->func('MAKE_DATE', $yearExpr, $monthExpr, $dayExpr);
    }

    protected function roundArg(Expression $arg, mixed $roundInput = null): Expression
    {
        if ($this->truthy($roundInput)) {
            return Builder::func('ROUND', [$arg, Literal::number(0)]);
        }

        return $arg;
    }

    protected function boolnotSqlHandler(Expression $expression): string
    {
        $arg = $this->roundArg($expression->this(), $expression->args['round_input'] ?? null);

        return $this->sql(Builder::not(Builder::paren($arg)));
    }

    protected function boolandSqlHandler(Expression $expression): string
    {
        $roundInput = $expression->args['round_input'] ?? null;
        $left = $this->roundArg($expression->this(), $roundInput);
        $right = $this->roundArg($expression->expression(), $roundInput);

        return $this->sql(Builder::paren(Builder::and([Builder::paren($left), Builder::paren($right)], wrap: false)));
    }

    protected function boolorSqlHandler(Expression $expression): string
    {
        $roundInput = $expression->args['round_input'] ?? null;
        $left = $this->roundArg($expression->this(), $roundInput);
        $right = $this->roundArg($expression->expression(), $roundInput);

        return $this->sql(Builder::paren(Builder::or([Builder::paren($left), Builder::paren($right)], wrap: false)));
    }

    protected function xorSqlHandler(Expression $expression): string
    {
        $roundInput = $expression->args['round_input'] ?? null;
        $left = $this->roundArg($expression->this(), $roundInput);
        $right = $this->roundArg($expression->expression(), $roundInput);

        return $this->sql(Builder::or([
            Builder::paren(Builder::and([$left->copy(), Builder::paren(Builder::not($right))], wrap: false)),
            Builder::paren(Builder::and([Builder::paren(Builder::not($left)), $right->copy()], wrap: false)),
        ], wrap: false));
    }

    /**
     * LATERAL VIEW EXPLODE/INLINE as UNNEST for DuckDB.
     */
    public function explodeToUnnestSql(Expression $expression): string
    {
        $explode = $expression->args['this'] ?? null;

        if ($explode instanceof Inline) {
            // For INLINE, create CROSS JOIN LATERAL (SELECT UNNEST(..., max_depth => 2))
            $unnestExpr = new Unnest([
                'expressions' => [
                    $explode->args['this'] ?? null,
                    new Kwarg(['this' => Builder::var('max_depth'), 'expression' => Literal::number(2)]),
                ],
            ]);
            $selectExpr = (new Select(['expressions' => [$unnestExpr]]))->subquery();

            $aliasExpr = $expression->args['alias'] ?? null;

            if ($aliasExpr instanceof Expression && ! $this->truthy($aliasExpr->args['this'] ?? null)) {
                // we need to provide a table name if not present
                $aliasExpr->set('this', Builder::toIdentifier('_u_'.($expression->index ?? 'None')));
            }

            $transformedLateralExpr = new Lateral(['this' => $selectExpr, 'alias' => $aliasExpr]);
            $crossJoinLateralExpr = new Join(['this' => $transformedLateralExpr, 'kind' => 'CROSS']);

            return $this->sql($crossJoinLateralExpr);
        }

        // For other cases, use the standard conversion
        return parent::explodeToUnnestSql($expression);
    }

    protected function shaSqlDuckdb(Expression $expression, string $hashFunc, bool $isBinary = false): string
    {
        $arg = $expression->this();

        // For SHA2 variants, check digest length (DuckDB only supports SHA256)
        if ($hashFunc === 'SHA256') {
            $length = $expression->text('length');
            $length = $length !== '' ? $length : '256';

            if ($length !== '256') {
                $this->unsupported('DuckDB only supports SHA256 hashing algorithm.');
            }
        }

        // Cast if type is incompatible with DuckDB
        $type = $arg->type();

        if (
            $type !== null
            && ($type->args['this'] ?? null) !== DType::UNKNOWN
            && ! $arg->isType(...DataType::TEXT_TYPES)
            && ! $this->isBinary($arg)
        ) {
            $arg = Builder::cast($arg, DType::VARCHAR);
        }

        $result = $this->func($hashFunc, $arg);

        return $isBinary ? $this->func('UNHEX', $result) : $result;
    }
}
