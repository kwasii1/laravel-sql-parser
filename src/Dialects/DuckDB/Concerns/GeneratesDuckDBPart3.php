<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Dialects\DuckDB\DuckDBGeneratorSupport;
use LaravelSqlParser\LaravelSqlParser\Expressions\Abs;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\ByteLength;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Collate;
use LaravelSqlParser\LaravelSqlParser\Expressions\Concat;
use LaravelSqlParser\LaravelSqlParser\Expressions\CountIf;
use LaravelSqlParser\LaravelSqlParser\Expressions\CurrentTimestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\DPipe;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Extract;
use LaravelSqlParser\LaravelSqlParser\Expressions\Filter;
use LaravelSqlParser\LaravelSqlParser\Expressions\Floor;
use LaravelSqlParser\LaravelSqlParser\Expressions\Greatest;
use LaravelSqlParser\LaravelSqlParser\Expressions\GT;
use LaravelSqlParser\LaravelSqlParser\Expressions\Hex;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\IntDiv;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Least;
use LaravelSqlParser\LaravelSqlParser\Expressions\Length;
use LaravelSqlParser\LaravelSqlParser\Expressions\Levenshtein;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\LT;
use LaravelSqlParser\LaravelSqlParser\Expressions\LTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mod;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\Ordered;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\PercentileCont;
use LaravelSqlParser\LaravelSqlParser\Expressions\PercentileDisc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Placeholder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Rand;
use LaravelSqlParser\LaravelSqlParser\Expressions\Repeat;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrPosition;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sub;
use LaravelSqlParser\LaravelSqlParser\Expressions\Substring;
use LaravelSqlParser\LaravelSqlParser\Expressions\TryCast;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Upper;

/**
 * DuckDB generator methods, part 3 (sqlglot generators/duckdb.py, DuckDBGenerator lines 2299–3499).
 */
trait GeneratesDuckDBPart3
{
    /** SQL text of the class-level templates used in this part. */
    private const array PART3_TEMPLATES = [
        'ZIPF' => 'WITH rand AS (SELECT :random_expr AS r), weights AS (SELECT i, 1.0 / POWER(i, :s) AS w FROM RANGE(1, :n + 1) AS t(i)), cdf AS (SELECT i, SUM(w) OVER (ORDER BY i) / SUM(w) OVER () AS p FROM weights) SELECT MIN(i) FROM cdf WHERE p >= (SELECT r FROM rand)',
        'NORMAL' => ':mean + (:stddev * SQRT(-2 * LN(GREATEST(:u1, 1e-10))) * COS(2 * PI() * :u2))',
        'SEEDED_RANDOM' => '(ABS(HASH(:seed)) % 1000000) / 1000000.0',
        'BITMAP_CONSTRUCT_AGG' => "SELECT CASE WHEN l IS NULL OR LENGTH(l) = 0 THEN NULL WHEN LENGTH(l) != LENGTH(LIST_FILTER(l, __v -> __v BETWEEN 0 AND 32767)) THEN NULL WHEN LENGTH(l) < 5 THEN UNHEX(PRINTF('%04X', LENGTH(l)) || h || REPEAT('00', GREATEST(0, 4 - LENGTH(l)) * 2)) ELSE UNHEX('08000000000000000000' || h) END FROM (SELECT l, COALESCE(LIST_REDUCE(LIST_TRANSFORM(l, __x -> PRINTF('%02X%02X', CAST(__x AS INT) & 255, (CAST(__x AS INT) >> 8) & 255)), (__a, __b) -> __a || __b, ''), '') AS h FROM (SELECT LIST_SORT(LIST_DISTINCT(LIST(:arg) FILTER(NOT :arg IS NULL))) AS l))",
        'RANDSTR' => "SELECT LISTAGG(SUBSTRING('".DuckDBGeneratorSupport::RANDSTR_CHAR_POOL."', 1 + CAST(FLOOR(random_value * 62) AS INT), 1), '') FROM (SELECT (ABS(HASH(i + :seed)) % 1000) / 1000.0 AS random_value FROM RANGE(:length) AS t(i))",
        'MINHASH' => "SELECT JSON_OBJECT('state', LIST(min_h ORDER BY seed), 'type', 'minhash', 'version', 1) FROM (SELECT seed, LIST_MIN(LIST_TRANSFORM(vals, __v -> HASH(CAST(__v AS VARCHAR) || CAST(seed AS VARCHAR)))) AS min_h FROM (SELECT LIST(:expr) AS vals), RANGE(0, :k) AS t(seed))",
        'MINHASH_COMBINE' => "SELECT JSON_OBJECT('state', LIST(min_h ORDER BY idx), 'type', 'minhash', 'version', 1) FROM (SELECT pos AS idx, MIN(val) AS min_h FROM UNNEST(LIST(:expr)) AS _(sig), UNNEST(CAST(sig -> 'state' AS UBIGINT[])) WITH ORDINALITY AS t(val, pos) GROUP BY pos)",
        'APPROXIMATE_SIMILARITY' => "SELECT CAST(SUM(CASE WHEN num_distinct = 1 THEN 1 ELSE 0 END) AS DOUBLE) / COUNT(*) FROM (SELECT pos, COUNT(DISTINCT h) AS num_distinct FROM (SELECT h, pos FROM UNNEST(LIST(:expr)) AS _(sig), UNNEST(CAST(sig -> 'state' AS UBIGINT[])) WITH ORDINALITY AS s(h, pos)) GROUP BY pos)",
        'ARRAY_BAG' => 'CASE WHEN :arr1 IS NULL OR :arr2 IS NULL THEN NULL ELSE LIST_TRANSFORM(LIST_FILTER(LIST_ZIP(:arr1, GENERATE_SERIES(1, LEN(:arr1))), pair -> :cond), pair -> pair[0]) END',
        'IN_UNNEST' => 'CASE WHEN :arr IS NULL OR ARRAY_LENGTH(:arr) = 0 THEN FALSE WHEN ARRAY_CONTAINS(:arr, :value) THEN TRUE WHEN :value IS NULL OR ARRAY_LENGTH(:arr) <> LIST_COUNT(:arr) THEN NULL ELSE FALSE END',
    ];

    /**
     * A template of this part with its named placeholders replaced (exp.replace_placeholders).
     *
     * @param  array<string, Expression>  $replacements
     */
    private function part3Template(string $name, array $replacements): Expression
    {
        /** @var array<string, Expression> $cache */
        static $cache = [];

        $template = $cache[$name] ??= Dialect::get()->parseOne(self::PART3_TEMPLATES[$name]);

        return $this->replacePart3Placeholders($template, $replacements);
    }

    /**
     * @param  array<string, Expression>  $replacements
     */
    private function replacePart3Placeholders(Expression $template, array $replacements): Expression
    {
        $result = $template->transform(static function (Expression $node) use ($replacements): Expression {
            if ($node instanceof Placeholder) {
                $name = $node->args['this'] ?? null;

                if (is_string($name) && $name !== '' && isset($replacements[$name])) {
                    return $replacements[$name]->copy();
                }
            }

            return $node;
        });

        return $result ?? $template->copy();
    }

    private function part3Case(Expression $condition, Expression $then, Expression|string|null $default = null): CaseExpr
    {
        $case = new CaseExpr(['ifs' => [new IfExpr(['this' => $condition, 'true' => $then])]]);

        if ($default !== null) {
            $case->set('default', $default);
        }

        return $case;
    }

    protected function datatypeSql(DataType $expression): string
    {
        if ($expression->isType(DType::ARRAY)) {
            return $this->expressions($expression, flat: true).'['.$this->expressions($expression, key: 'values', flat: true).']';
        }

        // Modifiers are not supported for TIME, [TIME | TIMESTAMP] WITH TIME ZONE
        if ($expression->isType(DType::TIME, DType::TIMETZ, DType::TIMESTAMPTZ, DType::TIMESTAMPLTZ)) {
            $expression->set('expressions', null);
        }

        return parent::datatypeSql($expression);
    }

    protected function arrayBagSqlInternal(Expression $condition, Expression $arr1, Expression $arr2): string
    {
        $cond = new Paren(['this' => $this->replacePart3Placeholders($condition, ['arr1' => $arr1, 'arr2' => $arr2])]);

        return $this->sql($this->part3Template('ARRAY_BAG', ['arr1' => $arr1, 'arr2' => $arr2, 'cond' => $cond]));
    }

    protected function timesliceSql(Expression $expression): string
    {
        $dateExpr = $expression->args['this'] ?? null;
        $sliceLength = $expression->args['expression'] ?? null;
        $unit = $expression->args['unit'] ?? null;
        $kind = strtoupper($expression->text('kind'));

        // Create INTERVAL expression: INTERVAL 'N' UNIT
        $intervalExpr = new Interval(['this' => $sliceLength, 'unit' => $unit]);

        // Create base time_bucket expression
        $timeBucketExpr = Builder::func('time_bucket', [$intervalExpr, $dateExpr]);

        // Check if we need the end of the slice (default is start)
        if ($kind !== 'END') {
            return $this->sql($timeBucketExpr);
        }

        // For 'END', add the interval to get end of slice
        $addExpr = new Add(['this' => $timeBucketExpr, 'expression' => $intervalExpr->copy()]);

        // If input is DATE type, cast result back to DATE to preserve type
        if ($dateExpr instanceof Expression && $dateExpr->isType(DType::DATE)) {
            return $this->sql(Builder::cast($addExpr, DType::DATE));
        }

        return $this->sql($addExpr);
    }

    protected function bitmapbucketnumberSql(Expression $expression): string
    {
        $value = $expression->args['this'] ?? null;

        $positiveFormula = Builder::binop(Add::class, Builder::binop(IntDiv::class, Builder::binop(Sub::class, $value, 1), 32768), 1);
        $nonPositiveFormula = Builder::binop(IntDiv::class, $value, 32768);

        // CASE WHEN value > 0 THEN ((value - 1) // 32768) + 1 ELSE value // 32768 END
        return $this->sql($this->part3Case(new GT(['this' => $value, 'expression' => Literal::number(0)]), $positiveFormula, $nonPositiveFormula));
    }

    protected function bitmapbitpositionSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        return $this->sql(new Mod([
            'this' => new Paren([
                'this' => new IfExpr([
                    'this' => new GT(['this' => $this_, 'expression' => Literal::number(0)]),
                    'true' => Builder::binop(Sub::class, $this_, Literal::number(1)),
                    'false' => new Abs(['this' => $this_]),
                ]),
            ]),
            'expression' => DuckDBGeneratorSupport::maxBitPosition(),
        ]));
    }

    protected function bitmapconstructaggSql(Expression $expression): string
    {
        $arg = $expression->argExpr('this');
        $replacements = $arg !== null ? ['arg' => $arg] : [];

        return '('.$this->sql($this->part3Template('BITMAP_CONSTRUCT_AGG', $replacements)).')';
    }

    protected function getignorecaseSql(Expression $expression): string
    {
        $this->unsupported('DuckDB does not support the GET_IGNORE_CASE() function');

        return $this->functionFallbackSql($expression);
    }

    protected function compressSql(Expression $expression): string
    {
        $this->unsupported('DuckDB does not support the COMPRESS() function');

        return $this->functionFallbackSql($expression);
    }

    protected function encryptSql(Expression $expression): string
    {
        $this->unsupported('ENCRYPT is not supported in DuckDB');

        return $this->functionFallbackSql($expression);
    }

    protected function decryptSql(Expression $expression): string
    {
        $funcName = $expression->flag('safe') ? 'TRY_DECRYPT' : 'DECRYPT';
        $this->unsupported("{$funcName} is not supported in DuckDB");

        return $this->functionFallbackSql($expression);
    }

    protected function decryptrawSql(Expression $expression): string
    {
        $funcName = $expression->flag('safe') ? 'TRY_DECRYPT_RAW' : 'DECRYPT_RAW';
        $this->unsupported("{$funcName} is not supported in DuckDB");

        return $this->functionFallbackSql($expression);
    }

    protected function encryptrawSql(Expression $expression): string
    {
        $this->unsupported('ENCRYPT_RAW is not supported in DuckDB');

        return $this->functionFallbackSql($expression);
    }

    protected function parseurlSql(Expression $expression): string
    {
        $this->unsupported('PARSE_URL is not supported in DuckDB');

        return $this->functionFallbackSql($expression);
    }

    protected function parseipSql(Expression $expression): string
    {
        $this->unsupported('PARSE_IP is not supported in DuckDB');

        return $this->functionFallbackSql($expression);
    }

    protected function decompressstringSql(Expression $expression): string
    {
        $this->unsupported('DECOMPRESS_STRING is not supported in DuckDB');

        return $this->functionFallbackSql($expression);
    }

    protected function decompressbinarySql(Expression $expression): string
    {
        $this->unsupported('DECOMPRESS_BINARY is not supported in DuckDB');

        return $this->functionFallbackSql($expression);
    }

    protected function jarowinklersimilaritySql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $expr = $expression->args['expression'] ?? null;

        if ($expression->flag('case_insensitive')) {
            $this_ = new Upper(['this' => $this_]);
            $expr = new Upper(['this' => $expr]);
        }

        $result = Builder::func('JARO_WINKLER_SIMILARITY', array_values(array_filter([$this_, $expr], static fn (mixed $a): bool => $a instanceof Expression)));

        if ($expression->flag('integer_scale')) {
            $result = Builder::cast(Builder::binop(Mul::class, $result, 100), 'INTEGER');
        }

        return $this->sql($result);
    }

    protected function randstrSql(Expression $expression): string
    {
        $length = $expression->argExpr('this');
        $generator = $expression->argExpr('generator');

        if ($generator !== null) {
            // If it's RANDOM(), use its seed if available, otherwise use RANDOM() itself
            $seedValue = $generator instanceof Rand ? ($generator->argExpr('this') ?? $generator) : $generator;
        } else {
            // No generator specified, use default seed (arbitrary but deterministic)
            $seedValue = Literal::number(DuckDBGeneratorSupport::RANDSTR_SEED);
        }

        $replacements = ['seed' => $seedValue];

        if ($length !== null) {
            $replacements['length'] = $length;
        }

        return '('.$this->sql($this->part3Template('RANDSTR', $replacements)).')';
    }

    protected function reduceSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'finish');

        $arrayArg = $expression->args['this'] ?? null;
        $initialValue = $expression->args['initial'] ?? null;
        $mergeLambda = $expression->argExpr('merge');

        $mergeLambda?->set('colon', true);

        return $this->func('list_reduce', $arrayArg, $mergeLambda, $initialValue);
    }

    protected function zipfSql(Expression $expression): string
    {
        $s = $expression->argExpr('this');
        $n = $expression->argExpr('elementcount');
        $gen = $expression->argExpr('gen');

        if (! $gen instanceof Rand) {
            // (ABS(HASH(seed)) % 1000000) / 1000000.0
            $randomExpr = new Div([
                'this' => new Paren([
                    'this' => new Mod([
                        'this' => new Abs(['this' => new Anonymous(['this' => 'HASH', 'expressions' => $gen !== null ? [$gen->copy()] : []])]),
                        'expression' => Literal::number(1000000),
                    ]),
                ]),
                'expression' => Literal::number(1000000.0),
            ]);
        } else {
            // Use RANDOM() for non-deterministic output
            $randomExpr = new Rand;
        }

        $replacements = array_filter(['s' => $s, 'n' => $n, 'random_expr' => $randomExpr], static fn (?Expression $e): bool => $e !== null);

        return '('.$this->sql($this->part3Template('ZIPF', $replacements)).')';
    }

    protected function tobinarySql(Expression $expression): string
    {
        $value = $expression->args['this'] ?? null;
        $formatArg = $expression->argExpr('format');
        $isSafe = $expression->flag('safe');
        $isBinary = $this->isBinary($expression);

        if ($formatArg === null && ! $isBinary) {
            return $this->func($isSafe ? 'TRY_TO_BINARY' : 'TO_BINARY', $value);
        }

        // Snowflake defaults to HEX encoding when no format is specified
        $fmt = $formatArg !== null ? strtoupper($formatArg->name()) : 'HEX';

        if ($fmt === 'UTF-8' || $fmt === 'UTF8') {
            // DuckDB ENCODE always uses UTF-8, no charset parameter needed
            $result = $this->func('ENCODE', $value);
        } elseif ($fmt === 'BASE64') {
            $result = $this->func('FROM_BASE64', $value);
        } elseif ($fmt === 'HEX') {
            $result = $this->func('UNHEX', $value);
        } else {
            if ($isSafe) {
                return $this->sql(Builder::null());
            }

            $this->unsupported("format {$fmt} is not supported");
            $result = $this->func('TO_BINARY', $value);
        }

        return $isSafe ? "TRY({$result})" : $result;
    }

    protected function tonumberSql(Expression $expression): string
    {
        $fmt = $expression->args['format'] ?? null;
        $precision = $expression->argExpr('precision');
        $scale = $expression->argExpr('scale');

        if (! $this->truthy($fmt) && $precision !== null && $scale !== null) {
            $to = DataType::build("DECIMAL({$precision->name()}, {$scale->name()})", 'duckdb');

            if ($expression->flag('safe')) {
                return $this->sql(new TryCast(['this' => $expression->args['this'] ?? null, 'to' => $to]));
            }

            return $this->sql(Builder::cast($expression->argExpr('this') ?? '', $to, dialect: 'duckdb'));
        }

        return parent::tonumberSql($expression);
    }

    protected function greatestLeastSqlInternal(Expression $expression): string
    {
        $allArgs = [$expression->argExpr('this'), ...$expression->argList('expressions')];
        $fallbackSql = $this->functionFallbackSql($expression);

        if ($expression->flag('ignore_nulls')) {
            // DuckDB/PostgreSQL behavior: use native GREATEST/LEAST (ignores NULLs)
            return $this->sql($fallbackSql);
        }

        // return NULL if any argument is NULL
        $conditions = [];
        foreach ($allArgs as $arg) {
            if ($arg !== null) {
                $conditions[] = Builder::binop(Is::class, $arg, Builder::null());
            }
        }

        $caseExpr = $this->part3Case(Builder::or($conditions, copy: false), Builder::null(), $fallbackSql);

        return $this->sql($caseExpr);
    }

    protected function generatorSql(Expression $expression): string
    {
        // Transpile Snowflake GENERATOR to DuckDB range()
        $rowcount = $expression->args['rowcount'] ?? null;

        if ($this->truthy($expression->args['time_limit'] ?? null)) {
            $this->unsupported('GENERATOR TIMELIMIT parameter is not supported in DuckDB');
        }

        if (! $this->truthy($rowcount)) {
            $this->unsupported('GENERATOR without ROWCOUNT is not supported in DuckDB');

            return $this->func('range', Literal::number(0));
        }

        return $this->func('range', $rowcount);
    }

    protected function greatestSql(Expression $expression): string
    {
        return $this->greatestLeastSqlInternal($expression);
    }

    protected function leastSql(Expression $expression): string
    {
        return $this->greatestLeastSqlInternal($expression);
    }

    protected function lambdaSql(Expression $expression, string $arrowSep = '->', bool $wrap = true): string
    {
        if ($expression->flag('colon')) {
            $prefix = 'LAMBDA ';
            $arrowSep = ':';
            $wrap = false;
        } else {
            $prefix = '';
        }

        return $prefix.parent::lambdaSql($expression, arrowSep: $arrowSep, wrap: $wrap);
    }

    protected function showSql(Expression $expression): string
    {
        $from = $this->sql($expression, 'from_');
        $from = $from !== '' ? " FROM {$from}" : '';

        return "SHOW {$expression->name()}{$from}";
    }

    protected function soundexSql(Expression $expression): string
    {
        $this->unsupported('SOUNDEX is not supported in DuckDB');

        return $this->func('SOUNDEX', $expression->args['this'] ?? null);
    }

    protected function sortarraySql(Expression $expression): string
    {
        $arr = $expression->args['this'] ?? null;
        $asc = $expression->args['asc'] ?? null;
        $nullsFirst = $expression->args['nulls_first'] ?? null;

        if (! $asc instanceof Boolean && ! $nullsFirst instanceof Boolean) {
            return $this->func('LIST_SORT', $arr, $asc, $nullsFirst);
        }

        $nullsAreFirst = $nullsFirst instanceof Expression && $nullsFirst->equals(Builder::true());
        $nullsFirstSql = $nullsAreFirst ? Literal::string('NULLS FIRST') : null;

        if (! $asc instanceof Boolean) {
            return $this->func('LIST_SORT', $arr, $asc, $nullsFirstSql);
        }

        $descending = $asc->equals(Builder::false());

        if (! $descending && ! $nullsAreFirst) {
            return $this->func('LIST_SORT', $arr);
        }

        if (! $nullsAreFirst) {
            return $this->func('ARRAY_REVERSE_SORT', $arr);
        }

        return $this->func('LIST_SORT', $arr, Literal::string($descending ? 'DESC' : 'ASC'), Literal::string('NULLS FIRST'));
    }

    protected function installSql(Expression $expression): string
    {
        $force = $expression->flag('force') ? 'FORCE ' : '';
        $this_ = $this->sql($expression, 'this');
        $fromClause = $expression->args['from_'] ?? null;
        $fromClause = $this->truthy($fromClause) && ($fromClause instanceof Expression || is_string($fromClause)) ? " FROM {$fromClause}" : '';

        return "{$force}INSTALL {$this_}{$fromClause}";
    }

    protected function approxtopkSql(Expression $expression): string
    {
        $this->unsupported('APPROX_TOP_K cannot be transpiled to DuckDB due to incompatible return types. ');

        return $this->functionFallbackSql($expression);
    }

    protected function strpositionSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $substr = $expression->args['substr'] ?? null;
        $position = $expression->argExpr('position');

        // For BINARY/BLOB: DuckDB's STRPOS doesn't support BLOB types
        // Convert to HEX strings, use STRPOS, then convert hex position to byte position
        if ($this_ !== null && $this->isBinary($this_)) {
            $hexStrpos = new StrPosition([
                'this' => new Hex(['this' => $this_]),
                'substr' => new Hex(['this' => $substr]),
            ]);

            return $this->sql(Builder::cast(Builder::binop(Div::class, Builder::binop(Add::class, $hexStrpos, 1), 2), DType::INT));
        }

        // For VARCHAR: handle clamp_position
        if ($expression->flag('clamp_position') && $position !== null) {
            $expression = $expression->copy();
            $expression->set('position', new IfExpr([
                'this' => new LTE(['this' => $position, 'expression' => Literal::number(0)]),
                'true' => Literal::number(1),
                'false' => $position->copy(),
            ]));
        }

        return $this->strpositionSqlHandler($expression);
    }

    protected function substringSql(Expression $expression): string
    {
        if ($expression->flag('zero_start')) {
            $start = $expression->argExpr('start');
            $length = $expression->argExpr('length');

            if ($start !== null) {
                $start = new IfExpr(['this' => Builder::binop(EQ::class, $start, 0), 'true' => Literal::number(1), 'false' => $start]);
            }

            if ($length !== null) {
                $length = new IfExpr(['this' => Builder::binop(LT::class, $length, 0), 'true' => Literal::number(0), 'false' => $length]);
            }

            return $this->func('SUBSTRING', $expression->args['this'] ?? null, $start, $length);
        }

        return $this->functionFallbackSql($expression);
    }

    protected function strtotimeSql(Expression $expression): string
    {
        // Check if target_type requires TIMESTAMPTZ (for LTZ/TZ variants)
        $targetType = $expression->argExpr('target_type');
        $targetName = $targetType?->args['this'] ?? null;
        $needsTz = $targetName === DType::TIMESTAMPLTZ || $targetName === DType::TIMESTAMPTZ;

        [$value, $formattedTime] = $this->strptimeDefaultYear($expression);

        if ($expression->flag('safe')) {
            $castType = $needsTz ? DType::TIMESTAMPTZ : DType::TIMESTAMP;

            return $this->sql(Builder::cast($this->func('TRY_STRPTIME', $value, $formattedTime), $castType));
        }

        $baseSql = $this->func('STRPTIME', $value, $formattedTime);

        if ($needsTz) {
            return $this->sql(Builder::cast($baseSql, new DataType(['this' => DType::TIMESTAMPTZ])));
        }

        return $baseSql;
    }

    protected function strtodateSql(Expression $expression): string
    {
        [$value, $formattedTime] = $this->strptimeDefaultYear($expression);
        $functionName = ! $expression->flag('safe') ? 'STRPTIME' : 'TRY_STRPTIME';

        return $this->sql(Builder::cast($this->func($functionName, $value, $formattedTime), new DataType(['this' => DType::DATE])));
    }

    /**
     * @return array{0: Expression|string|null, 1: Expression|string|null}
     */
    protected function strptimeDefaultYear(Expression $expression): array
    {
        /** @var Expression|string|null $value */
        $value = $expression->args['this'] ?? null;
        $formattedTime = $this->formatTime($expression);

        $defaultYear = $expression->argExpr('default_year');

        if ($defaultYear !== null) {
            $value = new DPipe(['this' => Literal::string("{$defaultYear->name()} "), 'expression' => $value]);
            $formattedTime = new DPipe(['this' => Literal::string('%Y '), 'expression' => $formattedTime]);
        }

        return [$value, $formattedTime];
    }

    protected function parsedatetimeSql(Expression $expression): string
    {
        [$value, $formattedTime] = $this->strptimeDefaultYear($expression);

        return $this->func('STRPTIME', $value, $formattedTime);
    }

    protected function parsetimeSql(Expression $expression): string
    {
        $formattedTime = $this->formatTime($expression);

        return $this->sql(Builder::cast(
            $this->func('STRPTIME', $expression->args['this'] ?? null, $formattedTime),
            new DataType(['this' => DType::TIME]),
        ));
    }

    protected function tsordstotimeSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $timeFormat = $this->formatTime($expression);
        $safe = $expression->flag('safe');
        $timeType = DataType::fromStr('TIME', 'duckdb');

        if ($timeFormat !== null && $timeFormat !== '') {
            $funcName = $safe ? 'TRY_STRPTIME' : 'STRPTIME';
            $strptime = new Anonymous(['this' => $funcName, 'expressions' => [$this_, $timeFormat]]);

            return $this->sql($safe ? new TryCast(['this' => $strptime, 'to' => $timeType]) : new Cast(['this' => $strptime, 'to' => $timeType]));
        }

        if ($this_ instanceof TsOrDsToTime || ($this_ !== null && $this_->isType(DType::TIME))) {
            return $this->sql($this_);
        }

        return $this->sql($safe ? new TryCast(['this' => $this_, 'to' => $timeType]) : new Cast(['this' => $this_, 'to' => $timeType]));
    }

    protected function currentdateSql(Expression $expression): string
    {
        if (! $this->truthy($expression->args['this'] ?? null)) {
            return 'CURRENT_DATE';
        }

        $expr = new Cast([
            'this' => new AtTimeZone(['this' => new CurrentTimestamp, 'zone' => $expression->args['this']]),
            'to' => new DataType(['this' => DType::DATE]),
        ]);

        return $this->sql($expr);
    }

    protected function checkjsonSql(Expression $expression): string
    {
        $arg = $expression->argExpr('this') ?? Builder::null();

        return $this->sql($this->part3Case(
            Builder::or([
                Builder::binop(Is::class, $arg, Builder::null()),
                Builder::binop(EQ::class, $arg, ''),
                Builder::func('json_valid', [$arg]),
            ]),
            Builder::null(),
            Literal::string('Invalid JSON'),
        ));
    }

    protected function parsejsonSql(Expression $expression): string
    {
        $arg = $expression->argExpr('this');

        if ($arg !== null && $expression->flag('safe')) {
            return $this->sql($this->part3Case(
                Builder::func('json_valid', [$arg]),
                Builder::cast($arg->copy(), 'JSON'),
                Builder::null(),
            ));
        }

        return $this->func('JSON', $arg);
    }

    protected function unicodeSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ !== null && $expression->flag('empty_is_zero')) {
            return $this->sql($this->part3Case(
                Builder::binop(EQ::class, $this_, Literal::string('')),
                Literal::number(0),
                new Anonymous(['this' => 'UNICODE', 'expressions' => [$this_->copy()]]),
            ));
        }

        return $this->func('UNICODE', $this_);
    }

    protected function stripnullvalueSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? Builder::null();

        return $this->sql($this->part3Case(
            Builder::binop(EQ::class, Builder::func('json_type', [$this_]), 'NULL'),
            Builder::null(),
            $this_->copy(),
        ));
    }

    protected function truncSql(Expression $expression): string
    {
        $decimals = $expression->argExpr('decimals');

        if ($expression->flag('fractions_supported') && $decimals !== null && ! $decimals->isType(DType::INT)) {
            $decimals = Builder::cast($decimals, DType::INT, dialect: 'duckdb');
        }

        return $this->func('TRUNC', $expression->args['this'] ?? null, $decimals);
    }

    protected function normalSql(Expression $expression): string
    {
        $mean = $expression->argExpr('this');
        $stddev = $expression->argExpr('stddev');
        $gen = $expression->argExpr('gen');

        // Build two uniform random values [0, 1) for Box-Muller transform
        if ($gen instanceof Rand && ! $this->truthy($gen->args['this'] ?? null)) {
            $u1 = new Rand;
            $u2 = new Rand;
        } else {
            // Seeded: derive two values using HASH with different inputs
            $seed = $gen instanceof Rand ? $gen->argExpr('this') : $gen;
            $seed ??= Builder::null();
            $u1 = $this->part3Template('SEEDED_RANDOM', ['seed' => $seed]);
            $u2 = $this->part3Template('SEEDED_RANDOM', ['seed' => new Add(['this' => $seed->copy(), 'expression' => Literal::number(1)])]);
        }

        $replacements = array_filter(['mean' => $mean, 'stddev' => $stddev, 'u1' => $u1, 'u2' => $u2], static fn (?Expression $e): bool => $e !== null);

        return $this->sql($this->part3Template('NORMAL', $replacements));
    }

    protected function uniformSql(Expression $expression): string
    {
        $minVal = $expression->argExpr('this') ?? Builder::null();
        $maxVal = $expression->argExpr('expression') ?? Builder::null();
        $gen = $expression->argExpr('gen');

        // Determine if result should be integer (both bounds are integers).
        $isIntResult = $minVal->isInt() && $maxVal->isInt();

        // Build the random value expression [0, 1)
        if (! $gen instanceof Rand) {
            $randomExpr = new Div([
                'this' => new Paren([
                    'this' => new Mod([
                        'this' => new Abs(['this' => new Anonymous(['this' => 'HASH', 'expressions' => $gen !== null ? [$gen] : []])]),
                        'expression' => Literal::number(1000000),
                    ]),
                ]),
                'expression' => Literal::number(1000000.0),
            ]);
        } else {
            $randomExpr = new Rand;
        }

        // Build: min + random * (max - min [+ 1 for int])
        $rangeExpr = new Sub(['this' => $maxVal, 'expression' => $minVal]);

        if ($isIntResult) {
            $rangeExpr = new Add(['this' => $rangeExpr, 'expression' => Literal::number(1)]);
        }

        $result = new Add([
            'this' => $minVal->copy(),
            'expression' => new Mul(['this' => $randomExpr, 'expression' => new Paren(['this' => $rangeExpr])]),
        ]);

        if ($isIntResult) {
            $result = new Cast(['this' => new Floor(['this' => $result]), 'to' => new DataType(['this' => DType::BIGINT])]);
        }

        return $this->sql($result);
    }

    protected function timefrompartsSql(Expression $expression): string
    {
        $nano = $expression->argExpr('nano');

        // Snowflake's TIME_FROM_PARTS supports overflow
        if ($this->truthy($expression->args['overflow'] ?? null)) {
            $hour = $expression->argExpr('hour') ?? Builder::null();
            $minute = $expression->argExpr('min') ?? Builder::null();
            $sec = $expression->argExpr('sec') ?? Builder::null();

            // Check if values are within normal ranges - use MAKE_TIME for efficiency
            if ($nano === null && $hour->isInt() && $minute->isInt() && $sec->isInt()) {
                $h = $hour->toPhp();
                $m = $minute->toPhp();
                $s = $sec->toPhp();

                if (is_int($h) && is_int($m) && is_int($s) && $h >= 0 && $h <= 23 && $m >= 0 && $m <= 59 && $s >= 0 && $s <= 59) {
                    return $this->renameFunc($expression, 'MAKE_TIME');
                }
            }

            // Overflow or nanoseconds detected - use INTERVAL arithmetic
            if ($nano !== null) {
                $sec = Builder::binop(Add::class, $sec, Builder::binop(Div::class, $nano->pop(), Literal::number(1000000000.0)));
            }

            $totalSeconds = Builder::binop(
                Add::class,
                Builder::binop(Add::class, Builder::binop(Mul::class, $hour, Literal::number(3600)), Builder::binop(Mul::class, $minute, Literal::number(60))),
                $sec,
            );

            return $this->sql(new Add([
                'this' => new Cast(['this' => Literal::string('00:00:00'), 'to' => new DataType(['this' => DType::TIME])]),
                'expression' => new Interval(['this' => $totalSeconds, 'unit' => Builder::var('SECOND')]),
            ]));
        }

        // Default: MAKE_TIME
        if ($nano !== null) {
            $expression->set('sec', Builder::binop(Add::class, $expression->args['sec'] ?? null, Builder::binop(Div::class, $nano->pop(), Literal::number(1000000000.0))));
        }

        return $this->renameFunc($expression, 'MAKE_TIME');
    }

    protected function extractSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $datetimeExpr = $expression->argExpr('expression') ?? Builder::null();

        // TIMESTAMPTZ extractions may produce different results between Snowflake and DuckDB
        if ($datetimeExpr->isType(DType::TIMESTAMPTZ, DType::TIMESTAMPLTZ)) {
            $this->unsupported('EXTRACT from TIMESTAMPTZ / TIMESTAMPLTZ may produce different results due to timezone handling differences');
        }

        $partName = strtoupper($this_?->name() ?? '');

        /** @var array<string, array{0: string, 1: string}> $strftimeMappings */
        $strftimeMappings = $this->t['EXTRACT_STRFTIME_MAPPINGS'];

        if (isset($strftimeMappings[$partName])) {
            [$fmt, $castType] = $strftimeMappings[$partName];

            // strftime doesn't accept TIME and there's no NANOSECOND function,
            // so for NANOSECOND with TIME, fallback to MICROSECOND * 1000
            $isNanoTime = $partName === 'NANOSECOND' && $datetimeExpr->isType(DType::TIME, DType::TIMETZ);

            if ($isNanoTime) {
                $this->unsupported('Parameter NANOSECOND is not supported with TIME type in DuckDB');

                return $this->sql(Builder::cast(
                    new Mul([
                        'this' => new Extract(['this' => Builder::var('MICROSECOND'), 'expression' => $datetimeExpr]),
                        'expression' => Literal::number(1000),
                    ]),
                    DataType::fromStr($castType, 'duckdb'),
                ));
            }

            // For NANOSECOND, cast to TIMESTAMP_NS to preserve nanosecond precision
            $strftimeInput = $datetimeExpr;

            if ($partName === 'NANOSECOND') {
                $strftimeInput = Builder::cast($datetimeExpr, DType::TIMESTAMP_NS);
            }

            return $this->sql(Builder::cast(
                new Anonymous(['this' => 'STRFTIME', 'expressions' => [$strftimeInput, Literal::string($fmt)]]),
                DataType::fromStr($castType, 'duckdb'),
            ));
        }

        /** @var array<string, string> $epochMappings */
        $epochMappings = $this->t['EXTRACT_EPOCH_MAPPINGS'];

        if (isset($epochMappings[$partName])) {
            $result = new Anonymous(['this' => $epochMappings[$partName], 'expressions' => [$datetimeExpr]]);

            // EPOCH returns float, cast to BIGINT for integer result
            if ($partName === 'EPOCH_SECOND') {
                $result = Builder::cast($result, DataType::fromStr('BIGINT', 'duckdb'));
            }

            return $this->sql($result);
        }

        return parent::extractSql($expression);
    }

    protected function timestampfrompartsSql(Expression $expression): string
    {
        // Check if this is the date/time expression form: TIMESTAMP_FROM_PARTS(date_expr, time_expr)
        $dateExpr = $expression->argExpr('this');
        $timeExpr = $expression->argExpr('expression');

        if ($dateExpr !== null && $timeExpr !== null) {
            // In DuckDB, DATE + TIME produces TIMESTAMP
            return $this->sql(new Add(['this' => $dateExpr, 'expression' => $timeExpr]));
        }

        // Component-based form: TIMESTAMP_FROM_PARTS(year, month, day, hour, minute, second, ...)
        $sec = $expression->argExpr('sec');

        if ($sec === null) {
            return $this->renameFunc($expression, 'MAKE_TIMESTAMP');
        }

        $milli = $expression->argExpr('milli');

        if ($milli !== null) {
            $sec = Builder::binop(Add::class, $sec, Builder::binop(Div::class, $milli->pop(), Literal::number(1000.0)));
        }

        $nano = $expression->argExpr('nano');

        if ($nano !== null) {
            $sec = Builder::binop(Add::class, $sec, Builder::binop(Div::class, $nano->pop(), Literal::number(1000000000.0)));
        }

        if ($milli !== null || $nano !== null) {
            $expression->set('sec', $sec);
        }

        $zone = $expression->argExpr('zone');

        if ($zone !== null) {
            return $this->sql(new AtTimeZone(['this' => $expression, 'zone' => $zone->pop()]));
        }

        return $this->renameFunc($expression, 'MAKE_TIMESTAMP');
    }

    protected function timestampltzfrompartsSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'nano');

        // Pop nano so renameFunc only passes args that MAKE_TIMESTAMP accepts
        $expression->argExpr('nano')?->pop();

        $timestamp = $this->renameFunc($expression, 'MAKE_TIMESTAMP');

        return "CAST({$timestamp} AS TIMESTAMPTZ)";
    }

    protected function timestamptzfrompartsSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'nano');

        // Pop zone and nano so renameFunc only passes args that MAKE_TIMESTAMP accepts
        $zone = $expression->argExpr('zone')?->pop();
        $expression->argExpr('nano')?->pop();

        $timestamp = $this->renameFunc($expression, 'MAKE_TIMESTAMP');

        if ($zone !== null) {
            // Use AT TIME ZONE to apply the explicit timezone
            return "{$timestamp} AT TIME ZONE {$this->sql($zone)}";
        }

        return $timestamp;
    }

    protected function tablesampleSql(Expression $expression, ?string $tablesampleKeyword = null): string
    {
        if (! $expression->parent instanceof Select) {
            // This sample clause only applies to a single source, not the entire resulting relation
            $tablesampleKeyword = 'TABLESAMPLE';
        }

        if ($this->truthy($expression->args['size'] ?? null)) {
            $method = $expression->argExpr('method');

            if ($method !== null && strtoupper($method->name()) !== 'RESERVOIR') {
                $this->unsupported("Sampling method {$method} is not supported with a discrete sample count, defaulting to reservoir sampling");
                $expression->set('method', Builder::var('RESERVOIR'));
            }
        }

        return parent::tablesampleSql($expression, tablesampleKeyword: $tablesampleKeyword);
    }

    protected function inSql(Expression $expression): string
    {
        $unnest = $expression->argExpr('unnest');

        if ($unnest !== null) {
            $replacements = ['arr' => $unnest->argList('expressions')[0] ?? Builder::null(), 'value' => $expression->argExpr('this') ?? Builder::null()];

            return $this->sql($this->part3Template('IN_UNNEST', $replacements));
        }

        return parent::inSql($expression);
    }

    protected function joinSql(Join $expression): string
    {
        if (
            ! $this->truthy($expression->args['using'] ?? null)
            && ! $this->truthy($expression->args['on'] ?? null)
            && $expression->method() === ''
            && in_array($expression->kind(), ['', 'INNER', 'OUTER'], true)
        ) {
            // Some dialects support `LEFT/INNER JOIN UNNEST(...)` without an explicit ON clause
            // DuckDB doesn't, but we can just add a dummy ON clause that is always true
            if (($expression->args['this'] ?? null) instanceof Unnest) {
                $join = $expression->copy();
                $join->set('on', Builder::true());

                return parent::joinSql($join);
            }

            $expression->set('side', null);
            $expression->set('kind', null);
        }

        return parent::joinSql($expression);
    }

    protected function countifSql(Expression $expression): string
    {
        if ($this->dialect->version >= [1, 2, 0]) {
            $this_ = $expression->argExpr('this');

            if ($this_ !== null && $expression->flag('zero_on_all_null') && ! $this_ instanceof Distinct) {
                // DuckDB >= 1.2's COUNT_IF returns NULL when the condition is NULL on all rows,
                // so we wrap the condition in IS TRUE to preserve count-like semantics
                $expression = new CountIf(['this' => Builder::binop(Is::class, Builder::paren($this_), Builder::true())]);
            }

            return $this->functionFallbackSql($expression);
        }

        // https://github.com/tobymao/sqlglot/pull/4749
        return $this->countIfToSum($expression);
    }

    protected function bracketSql(Expression $expression): string
    {
        if ($this->dialect->version >= [1, 2, 0]) {
            return parent::bracketSql($expression);
        }

        // https://duckdb.org/2025/02/05/announcing-duckdb-120.html#breaking-changes
        $this_ = $expression->argExpr('this');

        if ($this_ instanceof ArrayExpr) {
            $this_->replace(Builder::paren($this_));
        }

        $bracket = parent::bracketSql($expression);

        if ($this_ !== null && ! $expression->flag('returns_list_for_maps')) {
            if ($this_->type() === null) {
                $this->dialect->annotateTypes($this_);
            }

            if ($this_->isType(DType::MAP)) {
                $bracket = "({$bracket})[1]";
            }
        }

        return $bracket;
    }

    protected function withingroupSql(Expression $expression): string
    {
        $func = $expression->argExpr('this');

        // For ARRAY_AGG, DuckDB requires ORDER BY inside the function, not in WITHIN GROUP
        // Transform: ARRAY_AGG(x) WITHIN GROUP (ORDER BY y) -> ARRAY_AGG(x ORDER BY y)
        if ($func instanceof ArrayAgg) {
            $order = $expression->argExpr('expression');

            if (! $order instanceof Order) {
                return $this->sql($func);
            }

            // Save the original column for FILTER clause (before wrapping with Order)
            $originalThis = $func->argExpr('this');

            // Move ORDER BY inside ARRAY_AGG by wrapping its argument with Order
            $func->set('this', new Order([
                'this' => $originalThis?->copy(),
                'expressions' => $order->argList('expressions'),
            ]));

            // Use original_this (not the Order-wrapped version) for the FILTER condition
            $arrayAggSql = $this->functionFallbackSql($func);

            return $this->addArrayaggNullFilter($arrayAggSql, $func, $originalThis);
        }

        // For other functions (like PERCENTILES), use existing logic
        $expressionSql = $this->sql($expression, 'expression');

        if ($func instanceof PercentileCont || $func instanceof PercentileDisc) {
            // Make the order key the first arg and slide the fraction to the right
            // https://duckdb.org/docs/sql/aggregates#ordered-set-aggregate-functions
            $orderCol = $expression->find(Ordered::class);

            if ($orderCol !== null) {
                $func->set('expression', $func->args['this'] ?? null);
                $func->set('this', $orderCol->args['this'] ?? null);
            }
        }

        $this_ = rtrim($this->sql($expression, 'this'), ')');

        return "{$this_}{$expressionSql})";
    }

    protected function lengthSql(Expression $expression): string
    {
        $arg = $expression->argExpr('this');

        // Dialects like BQ and Snowflake also accept binary values as args, so
        // DDB will attempt to infer the type or resort to case/when resolution
        if ($arg === null || ! $expression->flag('binary') || $arg->isString()) {
            return $this->func('LENGTH', $arg);
        }

        if ($arg->type() === null) {
            $this->dialect->annotateTypes($arg);
        }

        if ($arg->isType(...DataType::TEXT_TYPES)) {
            return $this->func('LENGTH', $arg);
        }

        // We need these casts to make duckdb's static type checker happy
        $blob = Builder::cast($arg, DType::VARBINARY);
        $varchar = Builder::cast($arg, DType::VARCHAR);

        $case = new CaseExpr([
            'this' => new Anonymous(['this' => 'TYPEOF', 'expressions' => [$arg->copy()]]),
            'ifs' => [new IfExpr(['this' => Literal::string('BLOB'), 'true' => new ByteLength(['this' => $blob])])],
            'default' => new Anonymous(['this' => 'LENGTH', 'expressions' => [$varchar]]),
        ]);

        return $this->sql($case);
    }

    protected function bitlengthSql(Expression $expression): string
    {
        $arg = $expression->argExpr('this');

        if ($arg === null || ! $this->isBinary($arg)) {
            return $this->func('BIT_LENGTH', $arg);
        }

        $blob = Builder::cast($arg, DType::VARBINARY);

        return $this->sql(Builder::binop(Mul::class, new ByteLength(['this' => $blob]), Literal::number(8)));
    }

    protected function chrSql(Expression $expression, string $name = 'CHR'): string
    {
        $arg = $expression->argList('expressions')[0] ?? null;

        if ($arg !== null && $arg->isType(...DataType::REAL_TYPES)) {
            $arg = Builder::cast($arg, DType::INT);
        }

        return $this->func('CHR', $arg);
    }

    protected function collationSql(Expression $expression): string
    {
        $this->unsupported('COLLATION function is not supported by DuckDB');

        return $this->functionFallbackSql($expression);
    }

    protected function collateSql(Expression $expression): string
    {
        $collation = $expression->argExpr('expression');

        if ($collation === null || ! $collation->isString()) {
            return parent::collateSql($expression);
        }

        $raw = $collation->name();

        if ($raw === '') {
            return $this->sql($expression, 'this');
        }

        $parts = [];
        foreach (explode('-', $raw) as $part) {
            $lower = strtolower($part);

            if (! isset(DuckDBGeneratorSupport::SNOWFLAKE_COLLATION_DEFAULTS[$lower])) {
                if (isset(DuckDBGeneratorSupport::SNOWFLAKE_COLLATION_UNSUPPORTED[$lower])) {
                    $this->unsupported("Snowflake collation specifier '{$part}' has no DuckDB equivalent");
                }
                $parts[] = $lower;
            }
        }

        if ($parts === []) {
            return $this->sql($expression, 'this');
        }

        return parent::collateSql(new Collate(['this' => $expression->args['this'] ?? null, 'expression' => Builder::var(implode('.', $parts))]));
    }

    /**
     * Validate and filter regexp flags for DuckDB compatibility; null when no valid flags remain.
     */
    protected function validateRegexpFlags(mixed $flags, string $supportedFlags): ?string
    {
        if (! $flags instanceof Expression) {
            return null;
        }

        if (! $flags->isString()) {
            $this->unsupported('Non-literal regexp flags are not fully supported in DuckDB');

            return null;
        }

        $flagStr = $flags->text('this');
        $chars = $flagStr === '' ? [] : mb_str_split($flagStr);
        $supported = $supportedFlags === '' ? [] : mb_str_split($supportedFlags);
        $unsupported = array_values(array_unique(array_diff($chars, $supported)));

        if ($unsupported !== []) {
            sort($unsupported);
            $this->unsupported('Regexp flags ['.implode(', ', array_map(static fn (string $f): string => "'{$f}'", $unsupported)).'] are not supported in this context');
        }

        $flagStr = implode('', array_filter($chars, static fn (string $f): bool => in_array($f, $supported, true)));

        return $flagStr !== '' ? $flagStr : null;
    }

    protected function regexpcountSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $pattern = $expression->argExpr('expression') ?? Builder::null();
        $position = $expression->argExpr('position');
        $parameters = $expression->args['parameters'] ?? null;

        // Validate flags - only "ims" flags are supported for embedded patterns
        $validatedFlags = $this->validateRegexpFlags($parameters, 'ims');

        if ($position !== null) {
            $this_ = new Substring(['this' => $this_, 'start' => $position]);
        }

        // Embed flags in pattern (REGEXP_EXTRACT_ALL doesn't support flags argument)
        if ($validatedFlags !== null) {
            $pattern = new Concat(['expressions' => [Literal::string("(?{$validatedFlags})"), $pattern]]);
        }

        // Handle empty pattern: Snowflake returns 0, DuckDB would match between every character
        $result = $this->part3Case(
            new EQ(['this' => $pattern, 'expression' => Literal::string('')]),
            Literal::number(0),
            new Length(['this' => new Anonymous(['this' => 'REGEXP_EXTRACT_ALL', 'expressions' => [$this_?->copy(), $pattern->copy()]])]),
        );

        return $this->sql($result);
    }

    protected function regexpreplaceSql(Expression $expression): string
    {
        $subject = $expression->argExpr('this');
        $pattern = $expression->argExpr('expression');
        $replacement = $expression->argExpr('replacement') ?? Literal::string('');
        $position = $expression->argExpr('position');
        $occurrence = $expression->argExpr('occurrence');
        $modifiers = $expression->args['modifiers'] ?? null;

        $validatedFlags = $this->validateRegexpFlags($modifiers, 'cimsg') ?? '';

        // Handle occurrence (only literals supported)
        if ($occurrence !== null && ! $occurrence->isInt()) {
            $this->unsupported('REGEXP_REPLACE with non-literal occurrence');
        } else {
            $occurrenceValue = $occurrence !== null ? (int) $occurrence->toPhp() : 0;

            if ($occurrenceValue > 1) {
                $this->unsupported("REGEXP_REPLACE occurrence={$occurrenceValue} not supported");
            } elseif ($occurrenceValue === 0 && ! str_contains($validatedFlags, 'g') && ! $expression->flag('single_replace')) {
                // flag duckdb to do either all or none, single_replace check is for duckdb round trip
                $validatedFlags .= 'g';
            }
        }

        // Handle position (only literals supported)
        $prefix = null;

        if ($position !== null && ! $position->isInt()) {
            $this->unsupported('REGEXP_REPLACE with non-literal position');
        } elseif ($position !== null && (int) $position->toPhp() > 1) {
            $pos = (int) $position->toPhp();
            $prefix = new Substring(['this' => $subject, 'start' => Literal::number(1), 'length' => Literal::number($pos - 1)]);
            $subject = new Substring(['this' => $subject?->copy(), 'start' => Literal::number($pos)]);
        }

        $args = [$subject, $pattern, $replacement];

        if ($validatedFlags !== '') {
            $args[] = Literal::string($validatedFlags);
        }

        $result = new Anonymous(['this' => 'REGEXP_REPLACE', 'expressions' => array_values(array_filter($args, static fn (?Expression $e): bool => $e !== null))]);

        if ($prefix !== null) {
            $result = new Concat(['expressions' => [$prefix, $result]]);
        }

        return $this->sql($result);
    }

    protected function regexplikeSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $pattern = $expression->args['expression'] ?? null;
        $flag = $expression->args['flag'] ?? null;

        if ($expression->flag('full_match')) {
            $validatedFlags = $this->validateRegexpFlags($flag, 'cims');
            $flag = $validatedFlags !== null ? Literal::string($validatedFlags) : null;

            return $this->func('REGEXP_FULL_MATCH', $this_, $pattern, $flag);
        }

        return $this->func('REGEXP_MATCHES', $this_, $pattern, $flag);
    }

    protected function levenshteinSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'ins_cost', 'del_cost', 'sub_cost');

        $this_ = $expression->args['this'] ?? null;
        $expr = $expression->args['expression'] ?? null;
        $maxDist = $expression->argExpr('max_dist');

        if ($maxDist === null) {
            return $this->func('LEVENSHTEIN', $this_, $expr);
        }

        // Emulate Snowflake semantics: if distance > max_dist, return max_dist
        $levenshtein = new Levenshtein(['this' => $this_, 'expression' => $expr]);

        return $this->sql(new Least(['this' => $levenshtein, 'expressions' => [$maxDist]]));
    }

    protected function padSql(Expression $expression): string
    {
        $stringArg = $expression->argExpr('this');
        $fillArg = $expression->argExpr('fill_pattern') ?? Literal::string(' ');

        if (($stringArg !== null && $this->isBinary($stringArg)) || $this->isBinary($fillArg)) {
            $lengthArg = $expression->argExpr('expression');

            $inputLen = new ByteLength(['this' => $stringArg]);
            $charsNeeded = Builder::binop(Sub::class, $lengthArg, $inputLen);
            $padCount = new Greatest(['this' => Literal::number(0), 'expressions' => [$charsNeeded], 'ignore_nulls' => true]);
            $repeatExpr = new Repeat(['this' => $fillArg, 'times' => $padCount]);

            [$left, $right] = [$stringArg?->copy(), $repeatExpr];

            if ($expression->flag('is_left')) {
                [$left, $right] = [$right, $left];
            }

            return $this->sql(new DPipe(['this' => $left, 'expression' => $right]));
        }

        // For VARCHAR: Delegate to parent class (handles PAD_FILL_PATTERN_IS_REQUIRED)
        return parent::padSql($expression);
    }

    protected function minhashSql(Expression $expression): string
    {
        $k = $expression->argExpr('this');
        $exprs = $expression->argList('expressions');

        if (count($exprs) !== 1 || $exprs[0] instanceof Star) {
            $this->unsupported('MINHASH with multiple expressions or * requires manual query restructuring');

            return $this->funcWith('MINHASH', [$k, ...$exprs]);
        }

        $replacements = ['expr' => $exprs[0]];

        if ($k !== null) {
            $replacements['k'] = $k;
        }

        return '('.$this->sql($this->part3Template('MINHASH', $replacements)).')';
    }

    protected function minhashcombineSql(Expression $expression): string
    {
        $expr = $expression->argExpr('this');

        return '('.$this->sql($this->part3Template('MINHASH_COMBINE', $expr !== null ? ['expr' => $expr] : [])).')';
    }

    protected function approximatesimilaritySql(Expression $expression): string
    {
        $expr = $expression->argExpr('this');

        return '('.$this->sql($this->part3Template('APPROXIMATE_SIMILARITY', $expr !== null ? ['expr' => $expr] : [])).')';
    }
}
