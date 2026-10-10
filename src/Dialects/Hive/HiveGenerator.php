<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Hive;

use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataTypeParam;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateSub;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\InputOutputFormat;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPathWildcard;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\Ordered;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetItem;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToUnix;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeStrToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDate;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;
use LaravelSqlParser\LaravelSqlParser\Support\TimeFormat;

/**
 * Apache Hive generator (sqlglot generators/hive.py).
 */
class HiveGenerator extends Generator
{
    public const string TABLES = HiveGeneratorTables::class;

    // These constants mirror Hive.TIME_FORMAT, Hive.DATE_FORMAT and Hive.DATEINT_FORMAT.
    protected const string HIVE_TIME_FORMAT = "'yyyy-MM-dd HH:mm:ss'";

    protected const string HIVE_DATE_FORMAT = "'yyyy-MM-dd'";

    protected const string HIVE_DATEINT_FORMAT = "'yyyyMMdd'";

    /** The default formats above, as rendered by the lenient rewrite (non-padded month/day/time). */
    protected const array HIVE_NON_PADDED_TIME_FORMATS = ["'yyyy-M-d H:m:s'", "'yyyy-M-d'"];

    /** Expressions that parse a string with a format (vs. formatting one, like TimeToStr). */
    protected const array PARSE_TIME_EXPRESSIONS = [StrToTime::class, StrToDate::class, StrToUnix::class, TsOrDsToDate::class];

    protected const string CANONICAL_TIME_FORMAT = '/%(?:[mdHIMS]strict|[-:].|.)/';

    protected const array LAX_TO_NON_PADDED_FORMATS = [
        '%m' => '%-m',
        '%d' => '%-d',
        '%H' => '%-H',
        '%I' => '%-I',
        '%M' => '%-M',
        '%S' => '%-S',
    ];

    /** (FuncType, Multiplier) */
    protected const array DATE_DELTA_INTERVAL = [
        'YEAR' => ['ADD_MONTHS', 12],
        'MONTH' => ['ADD_MONTHS', 1],
        'QUARTER' => ['ADD_MONTHS', 3],
        'WEEK' => ['DATE_ADD', 7],
        'DAY' => ['DATE_ADD', 1],
    ];

    protected const array TIME_DIFF_FACTOR = [
        'MILLISECOND' => ' * 1000',
        'SECOND' => '',
        'MINUTE' => ' / 60',
        'HOUR' => ' / 3600',
    ];

    protected const array DIFF_MONTH_SWITCH = ['YEAR', 'QUARTER', 'MONTH'];

    /**
     * sqlglot _lenient_parse_format: lax month/day/hour/minute/second specifiers that are
     * delimited become non-padded (e.g. %m -> %-m), which java.time parses with or without
     * a leading zero.
     */
    protected static function lenientParseFormat(string $fmt): string
    {
        $parts = preg_split(self::CANONICAL_TIME_FORMAT, $fmt);
        preg_match_all(self::CANONICAL_TIME_FORMAT, $fmt, $matches);
        $parts = $parts === false ? [$fmt] : $parts;
        $formats = $matches[0];
        $count = count($formats);

        foreach ($formats as $i => $format) {
            if (! isset(self::LAX_TO_NON_PADDED_FORMATS[$format])) {
                continue;
            }

            $left = $parts[$i] ?? '';
            $right = $parts[$i + 1] ?? '';
            $leftAdjacent = ($left === '' && $i > 0) || ($left !== '' && ctype_digit(substr($left, -1)));
            $rightAdjacent = ($right === '' && $i < $count - 1) || ($right !== '' && ctype_digit($right[0]));

            if (! $leftAdjacent && ! $rightAdjacent) {
                $formats[$i] = self::LAX_TO_NON_PADDED_FORMATS[$format];
            }
        }

        $result = '';

        foreach ($parts as $i => $part) {
            $result .= $part.($formats[$i] ?? '');
        }

        return $result;
    }

    /**
     * time_format("hive"): the format, unless it is Hive's default time format.
     */
    protected function hiveTimeFormat(Expression $expression): ?string
    {
        $timeFormat = $this->formatTime($expression);

        return $timeFormat !== self::HIVE_TIME_FORMAT ? $timeFormat : null;
    }

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function varMapSqlHandler(Expression $expression): string
    {
        return $this->varMapSql($expression);
    }

    protected function arraySortSqlHandler(Expression $expression): string
    {
        return $this->func('SORT_ARRAY', $expression->args['this'] ?? null);
    }

    protected function transformArrayToString(Expression $e): string
    {
        return $this->func('CONCAT_WS', $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function addDateSql(Expression $expression): string
    {
        if ($expression instanceof TsOrDsAdd && ! $expression->flag('unit')) {
            return $this->func('DATE_ADD', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
        }

        $unit = strtoupper($expression->text('unit'));
        [$func, $multiplier] = self::DATE_DELTA_INTERVAL[$unit] ?? ['DATE_ADD', 1];

        if ($expression instanceof DateSub) {
            $multiplier *= -1;
        }

        $increment = $expression->args['expression'] ?? null;

        if ($increment instanceof Literal) {
            $value = $increment->isNumber() ? $increment->toPhp() : (int) $increment->name();
            $increment = Literal::number((is_int($value) ? $value : (is_numeric($value) ? $value + 0 : 0)) * $multiplier);
        } elseif ($multiplier !== 1) {
            $increment = Builder::binop(Mul::class, $increment, Literal::number($multiplier));
        }

        return $this->func($func, $expression->args['this'] ?? null, $increment);
    }

    protected function dateDiffSqlHandler(Expression $expression): string
    {
        $unit = strtoupper($expression->text('unit'));
        $factor = self::TIME_DIFF_FACTOR[$unit] ?? null;

        if ($factor !== null) {
            $left = $this->sql($expression, 'this');
            $right = $this->sql($expression, 'expression');
            $secDiff = "UNIX_TIMESTAMP({$left}) - UNIX_TIMESTAMP({$right})";

            return $factor !== '' ? "({$secDiff}){$factor}" : $secDiff;
        }

        $monthsBetween = in_array($unit, self::DIFF_MONTH_SWITCH, true);
        $sqlFunc = $monthsBetween ? 'MONTHS_BETWEEN' : 'DATEDIFF';
        $multiplier = (self::DATE_DELTA_INTERVAL[$unit] ?? ['', 1])[1];
        $multiplierSql = $multiplier > 1 ? " / {$multiplier}" : '';
        $diffSql = "{$sqlFunc}(".$this->formatArgs([$expression->args['this'] ?? null, $expression->args['expression'] ?? null]).')';

        if ($monthsBetween || $multiplierSql !== '') {
            // MONTHS_BETWEEN returns a float, so we need to truncate the fractional part.
            $diffSql = "CAST({$diffSql}{$multiplierSql} AS INT)";
        }

        return $diffSql;
    }

    protected function transformDateToDi(Expression $e): string
    {
        return 'CAST(DATE_FORMAT('.$this->sql($e, 'this').', '.self::HIVE_DATEINT_FORMAT.') AS INT)';
    }

    protected function transformDiToDate(Expression $e): string
    {
        return 'TO_DATE(CAST('.$this->sql($e, 'this').' AS STRING), '.self::HIVE_DATEINT_FORMAT.')';
    }

    protected function transformStorageHandlerProperty(Expression $e): string
    {
        return 'STORED BY '.$this->sql($e, 'this');
    }

    protected function transformIntDiv(Expression $e): string
    {
        return $this->binary($e, 'DIV');
    }

    protected function transformJSONExtract(Expression $e): string
    {
        return $this->func('GET_JSON_OBJECT', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformJSONExtractScalar(Expression $e): string
    {
        return $this->func('GET_JSON_OBJECT', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformMD5Digest(Expression $e): string
    {
        return $this->func('UNHEX', $this->func('MD5', $e->args['this'] ?? null));
    }

    protected function transformMonthsBetween(Expression $e): string
    {
        return $this->func('MONTHS_BETWEEN', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformNotNullColumnConstraint(Expression $e): string
    {
        return $e->flag('allow_null') ? '' : 'NOT NULL';
    }

    protected function transformRegexpLike(Expression $e): string
    {
        return $this->binary($e, 'RLIKE');
    }

    protected function transformSchemaCommentProperty(Expression $e): string
    {
        return $this->nakedProperty($e);
    }

    protected function transformSplit(Expression $e): string
    {
        return $this->func('SPLIT', $e->args['this'] ?? null, $this->func('CONCAT', "'\\\\Q'", $e->args['expression'] ?? null, "'\\\\E'"));
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'LOCATE', supportsPosition: true);
    }

    protected function transformTimestampTrunc(Expression $e): string
    {
        return $this->func('TRUNC', $e->args['this'] ?? null, $this->weekstartUnitToStr($e));
    }

    protected function transformTsOrDiToDi(Expression $e): string
    {
        return 'CAST(SUBSTR(REPLACE(CAST('.$this->sql($e, 'this')." AS STRING), '-', ''), 1, 8) AS INT)";
    }

    protected function transformUnixToStr(Expression $e): string
    {
        return $this->func('FROM_UNIXTIME', $e->args['this'] ?? null, $this->hiveTimeFormat($e));
    }

    protected function transformPartitionedByProperty(Expression $e): string
    {
        return 'PARTITIONED BY '.$this->sql($e, 'this');
    }

    protected function transformNational(Expression $e): string
    {
        return $this->nationalSql($e, prefix: '');
    }

    protected function transformPrimaryKeyColumnConstraint(Expression $e): string
    {
        return 'PRIMARY KEY';
    }

    protected function transformClusteredColumnConstraint(Expression $e): string
    {
        return '('.$this->expressions($e, key: 'this', indent: false).')';
    }

    protected function transformNonClusteredColumnConstraint(Expression $e): string
    {
        return '('.$this->expressions($e, key: 'this', indent: false).')';
    }

    protected function transformNotForReplicationColumnConstraint(Expression $e): string
    {
        return '';
    }

    protected function transformOnProperty(Expression $e): string
    {
        return '';
    }

    protected function transformPartitionedByBucket(Expression $e): string
    {
        return $this->func('BUCKET', $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformPartitionByTruncate(Expression $e): string
    {
        return $this->func('TRUNCATE', $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function strToUnixSqlHandler(Expression $expression): string
    {
        return $this->func('UNIX_TIMESTAMP', $expression->args['this'] ?? null, $this->hiveTimeFormat($expression));
    }

    protected function unixToTimeSqlHandler(Expression $expression): string
    {
        $timestamp = $this->sql($expression, 'this');
        $scale = $expression->args['scale'] ?? null;

        if ($scale === null || ($scale instanceof Literal && $scale->equals(Literal::number(0)))) {
            return $this->renameFunc($expression, 'FROM_UNIXTIME');
        }

        return "FROM_UNIXTIME({$timestamp} / POW(10, ".$this->sql($scale).'))';
    }

    /**
     * sqlglot _is_cast_time_format: whether CAST subsumes the expression's parse format.
     */
    protected function isCastTimeFormat(Expression $expression, string $timeFormat): bool
    {
        if (in_array($timeFormat, [self::HIVE_TIME_FORMAT, self::HIVE_DATE_FORMAT], true)) {
            return true;
        }

        if (in_array($timeFormat, self::HIVE_NON_PADDED_TIME_FORMATS, true)) {
            // The base render skips the lenient rewrite: a lax specifier pads back (e.g. %m -> MM),
            // an explicit non-padded specifier (e.g. %-m) doesn't
            $paddedFormat = $this->baseFormatTime($expression);

            return in_array($paddedFormat, [self::HIVE_TIME_FORMAT, self::HIVE_DATE_FORMAT], true);
        }

        return false;
    }

    protected function strToDateSqlHandler(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $timeFormat = $this->formatTime($expression);

        if ($timeFormat !== null && $timeFormat !== '' && ! $this->isCastTimeFormat($expression, $timeFormat)) {
            $this_ = "FROM_UNIXTIME(UNIX_TIMESTAMP({$this_}, {$timeFormat}))";
        }

        return "CAST({$this_} AS DATE)";
    }

    protected function strToTimeSqlHandler(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $timeFormat = $this->formatTime($expression);

        if ($timeFormat !== null && $timeFormat !== '' && ! $this->isCastTimeFormat($expression, $timeFormat)) {
            $this_ = "FROM_UNIXTIME(UNIX_TIMESTAMP({$this_}, {$timeFormat}))";
        }

        return "CAST({$this_} AS TIMESTAMP)";
    }

    protected function toDateSql(Expression $expression): string
    {
        $timeFormat = $this->formatTime($expression);

        if ($timeFormat !== null && $timeFormat !== '' && ! $this->isCastTimeFormat($expression, $timeFormat)) {
            return $this->func('TO_DATE', $expression->args['this'] ?? null, $timeFormat);
        }

        $parent = $expression->parent;

        foreach ($this->t['TS_OR_DS_EXPRESSIONS'] as $class) {
            if ($parent instanceof $class) {
                return $this->sql($expression, 'this');
            }
        }

        return $this->func('TO_DATE', $expression->args['this'] ?? null);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function baseFormatTime(Expression $expression): ?string
    {
        return parent::formatTime($expression);
    }

    public function formatTime(Expression $expression, ?array $inverseTimeMapping = null, ?array $inverseTimeTrie = null): ?string
    {
        // Inferred property because this method is reused by other dialects under Hive
        $isDialectStrict = ($this->dialect->settings['TIME_MAPPING']['MM'] ?? null) === '%mstrict';
        $isParse = false;

        foreach (self::PARSE_TIME_EXPRESSIONS as $class) {
            if ($expression instanceof $class) {
                $isParse = true;

                break;
            }
        }

        if ($isDialectStrict && $inverseTimeMapping === null && $isParse) {
            // Render a lenient %m/%d non-padded (M/d) so single-digit sources stay parseable
            $format = $this->sql($expression, 'format');

            return TimeFormat::convert(
                $format !== '' ? self::lenientParseFormat($format) : null,
                $this->dialect->settings['INVERSE_TIME_MAPPING'],
                $this->dialect->settings['INVERSE_TIME_TRIE'],
            );
        }

        return parent::formatTime($expression, $inverseTimeMapping, $inverseTimeTrie);
    }

    protected function ignorenullsSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Expression) {
            foreach ($this->t['IGNORE_NULLS_FUNCS'] as $class) {
                if ($this_ instanceof $class) {
                    return $this->func($this_::sqlName(), $this_->args['this'] ?? null, Builder::true());
                }
            }
        }

        return parent::ignorenullsSql($expression);
    }

    protected function unnestSql(Expression $expression): string
    {
        return $this->renameFunc($expression, 'EXPLODE');
    }

    public function jsonpathkeySqlInternal(Expression $expression): string
    {
        if (($expression->args['this'] ?? null) instanceof JSONPathWildcard) {
            $this->unsupported('Unsupported wildcard in JSONPathKey expression');

            return '';
        }

        return parent::jsonpathkeySqlInternal($expression);
    }

    protected function parameterSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expressionSql = $this->sql($expression, 'expression');

        $parent = $expression->parent;
        $this_ = $expressionSql !== '' ? "{$this_}:{$expressionSql}" : $this_;

        if ($parent instanceof EQ && $parent->parent instanceof SetItem) {
            // We need to produce SET key = value instead of SET ${key} = value
            return $this_;
        }

        return '${'.$this_.'}';
    }

    protected function schemaSql(Expression $expression): string
    {
        foreach ($expression->findAll(Ordered::class) as $ordered) {
            if (array_key_exists('desc', $ordered->args) && $ordered->args['desc'] === false) {
                $ordered->set('desc', null);
            }
        }

        return parent::schemaSql($expression);
    }

    protected function constraintSql(Expression $expression): string
    {
        foreach ($expression->findAll(Properties::class) as $prop) {
            $prop->pop();
        }

        $this_ = $this->sql($expression, 'this');
        $expressions = $this->expressions($expression, sep: ' ', flat: true);

        return "CONSTRAINT {$this_} {$expressions}";
    }

    protected function rowformatserdepropertySql(Expression $expression): string
    {
        $serdeProps = $this->sql($expression, 'serde_properties');
        $serdeProps = $serdeProps !== '' ? " {$serdeProps}" : '';

        return 'ROW FORMAT SERDE '.$this->sql($expression, 'this').$serdeProps;
    }

    protected function arrayaggSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        return $this->func('COLLECT_LIST', $this_ instanceof Order ? ($this_->args['this'] ?? null) : $this_);
    }

    /**
     * Hive/Spark lack native numeric TRUNC. CAST to BIGINT truncates toward zero (not rounds).
     */
    protected function truncSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'decimals');

        return $this->sql(Builder::cast($expression->argExpr('this') ?? Builder::null(), DType::BIGINT));
    }

    protected function datatypeSql(DataType $expression): string
    {
        $type = $expression->args['this'] ?? null;
        $expressions = $expression->argList('expressions');

        if (is_string($type) && isset($this->t['PARAMETERIZABLE_TEXT_TYPES'][$type]) && ($expressions === [] || $expressions[0]->name() === 'MAX')) {
            $expression->set('this', DType::TEXT);
            $expression->set('expressions', null);
        } elseif ($expression->isType(DType::TEXT) && $expressions !== []) {
            $expression->set('this', DType::VARCHAR);
        } elseif (is_string($type) && in_array($type, DataType::TEMPORAL_TYPES, true)) {
            $expression->set('expressions', null);
        } elseif ($expression->isType('float')) {
            $sizeExpression = $expression->find(DataTypeParam::class);

            if ($sizeExpression !== null) {
                $size = (int) $sizeExpression->name();
                $expression->set('this', $size <= 32 ? DType::FLOAT : DType::DOUBLE);
                $expression->set('expressions', null);
            }
        }

        return parent::datatypeSql($expression);
    }

    protected function versionSql(Expression $expression): string
    {
        $sql = parent::versionSql($expression);
        $pos = strpos($sql, 'FOR ');

        return $pos === false ? $sql : substr_replace($sql, '', $pos, 4);
    }

    protected function structSql(Expression $expression): string
    {
        $values = [];

        foreach ($expression->argList('expressions') as $e) {
            if ($e instanceof PropertyEQ) {
                $this->unsupported('Hive does not support named structs.');
                $values[] = $e->args['expression'] ?? null;
            } else {
                $values[] = $e;
            }
        }

        return $this->funcWith('STRUCT', $values);
    }

    protected function columndefSql(Expression $expression, string $sep = ' '): string
    {
        $parent = $expression->parent;

        return parent::columndefSql(
            $expression,
            $parent instanceof DataType && $parent->isType('struct') ? ': ' : $sep,
        );
    }

    protected function altercolumnSql(Expression $expression): string
    {
        if ($expression->flag('exists')) {
            $this->unsupported('ALTER COLUMN IF EXISTS is not supported by this dialect');
        }

        $this_ = $this->sql($expression, 'this');
        $newName = $this->sql($expression, 'rename_to');
        $newName = $newName !== '' ? $newName : $this_;
        $dtype = $this->sql($expression, 'dtype');
        $comment = $this->sql($expression, 'comment');
        $comment = $comment !== '' ? " COMMENT {$comment}" : '';
        $default = $this->sql($expression, 'default');
        $allowNull = $expression->args['allow_null'] ?? null;

        if ($default !== '' || $expression->flag('drop') || $expression->flag('visible') || $allowNull !== null) {
            $this->unsupported('Unsupported CHANGE COLUMN syntax');
        }

        if ($dtype === '') {
            $this->unsupported('CHANGE COLUMN without a type is not supported');
        }

        return "CHANGE COLUMN {$this_} {$newName} {$dtype}{$comment}";
    }

    protected function renamecolumnSql(Expression $expression): string
    {
        $this->unsupported('Cannot rename columns without data type defined in Hive');

        return '';
    }

    protected function altersetSql(Expression $expression): string
    {
        $exprs = $this->expressions($expression, flat: true);
        $exprs = $exprs !== '' ? " {$exprs}" : '';
        $location = $this->sql($expression, 'location');
        $location = $location !== '' ? " LOCATION {$location}" : '';
        $fileFormat = $this->expressions($expression, key: 'file_format', flat: true, sep: ' ');
        $fileFormat = $fileFormat !== '' ? " FILEFORMAT {$fileFormat}" : '';
        $serde = $this->sql($expression, 'serde');
        $serde = $serde !== '' ? " SERDE {$serde}" : '';
        $tags = $this->expressions($expression, key: 'tag', flat: true, sep: '');
        $tags = $tags !== '' ? " TAGS {$tags}" : '';

        return "SET{$serde}{$exprs}{$location}{$fileFormat}{$tags}";
    }

    protected function serdepropertiesSql(Expression $expression): string
    {
        $prefix = $expression->flag('with_') ? 'WITH ' : '';
        $exprs = $this->expressions($expression, flat: true);

        return "{$prefix}SERDEPROPERTIES ({$exprs})";
    }

    protected function existsSql(Expression $expression): string
    {
        if ($expression->argExpr('expression') !== null) {
            return $this->functionFallbackSql($expression);
        }

        return parent::existsSql($expression);
    }

    protected function timetostrSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof TimeStrToTime) {
            $this_ = $this_->args['this'] ?? null;
        }

        return $this->func('DATE_FORMAT', $this_, $this->formatTime($expression));
    }

    protected function usingpropertySql(Expression $expression): string
    {
        $kind = $expression->args['kind'] ?? null;
        $kind = is_scalar($kind) ? (string) $kind : '';

        return "USING {$kind} ".$this->sql($expression, 'this');
    }

    protected function fileformatpropertySql(Expression $expression): string
    {
        $this_ = ($expression->args['this'] ?? null) instanceof InputOutputFormat
            ? $this->sql($expression, 'this')
            : strtoupper($expression->name());

        return "STORED AS {$this_}";
    }

    public function unnestToExplode(Expression $expression, bool $unnestUsingArraysZip = false): Expression
    {
        return parent::unnestToExplode($expression, $unnestUsingArraysZip);
    }
}
