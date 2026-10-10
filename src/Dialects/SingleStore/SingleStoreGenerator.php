<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\SingleStore;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Dialects\MySql\MySqlGenerator;
use Kwasii\LaravelSqlParser\Expressions\Add;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\CurrentTimestamp;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\Div;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\JSONExtractScalar;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Lower;
use Kwasii\LaravelSqlParser\Expressions\Mul;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\PercentileCont;
use Kwasii\LaravelSqlParser\Expressions\PercentileDisc;
use Kwasii\LaravelSqlParser\Expressions\Pow;
use Kwasii\LaravelSqlParser\Expressions\RegexpLike;
use Kwasii\LaravelSqlParser\Expressions\Sub;

/**
 * SingleStore generator (sqlglot generators/singlestore.py).
 */
class SingleStoreGenerator extends MySqlGenerator
{
    public const string TABLES = SingleStoreGeneratorTables::class;

    /**
     * format_time() with MySQL's inverse time mapping (SingleStore.MYSQL_INVERSE_TIME_MAPPING / _TRIE,
     * which are MySQL's own INVERSE_TIME_MAPPING / INVERSE_TIME_TRIE).
     */
    private function mysqlFormatTime(Expression $expression): ?string
    {
        $mysql = Dialect::get('mysql');
        /** @var array<string, string> $mapping */
        $mapping = $mysql->settings['INVERSE_TIME_MAPPING'];
        /** @var array<string, mixed> $trie */
        $trie = $mysql->settings['INVERSE_TIME_TRIE'];

        return $this->formatTime($expression, $mapping, $trie);
    }

    private function dateintFormat(): string
    {
        return (string) $this->dialect->settings['DATEINT_FORMAT'];
    }

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function transformTsOrDsToDate(Expression $e): string
    {
        return $this->truthy($e->args['format'] ?? null)
            ? $this->func('TO_DATE', $e->args['this'] ?? null, $this->formatTime($e))
            : $this->func('DATE', $e->args['this'] ?? null);
    }

    protected function transformStrToTime(Expression $e): string
    {
        return $this->func('TO_TIMESTAMP', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformToChar(Expression $e): string
    {
        return $this->func('TO_CHAR', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformStrToDate(Expression $e): string
    {
        return $this->func('STR_TO_DATE', $e->args['this'] ?? null, $this->mysqlFormatTime($e));
    }

    protected function transformTimeToStr(Expression $e): string
    {
        return $this->func('DATE_FORMAT', $e->args['this'] ?? null, $this->mysqlFormatTime($e));
    }

    protected function transformCast(Expression $e): string
    {
        return $this->sql($e, 'this').' :> '.$this->sql($e, 'to');
    }

    protected function transformTryCast(Expression $e): string
    {
        return $this->sql($e, 'this').' !:> '.$this->sql($e, 'to');
    }

    protected function transformCastToStrType(Expression $e): string
    {
        $to = $e->args['to'] ?? null;

        return $this->sql(Builder::cast($e->args['this'] ?? null, DataType::fromStr($to instanceof Expression ? $to->name() : '')));
    }

    protected function transformUnixToStr(Expression $e): string
    {
        return $this->func('FROM_UNIXTIME', $e->args['this'] ?? null, $this->mysqlFormatTime($e));
    }

    protected function transformUnixToTime(Expression $e): string
    {
        return $this->func('FROM_UNIXTIME', $e->args['this'] ?? null, $this->mysqlFormatTime($e));
    }

    protected function transformUnixToTimeStr(Expression $e): string
    {
        return 'FROM_UNIXTIME('.$this->sql($e, 'this').') :> TEXT';
    }

    protected function transformDateBin(Expression $e): string
    {
        return $this->func('TIME_BUCKET', $e->args['this'] ?? null, $e->args['expression'] ?? null, $e->args['origin'] ?? null);
    }

    protected function transformTimeStrToDate(Expression $e): string
    {
        return $this->sql(Builder::cast($e->args['this'] ?? null, new DataType(['this' => DType::DATE])));
    }

    protected function transformFromTimeZone(Expression $e): string
    {
        return $this->func('CONVERT_TZ', $e->args['this'] ?? null, $e->args['zone'] ?? null, "'UTC'");
    }

    protected function transformDiToDate(Expression $e): string
    {
        return 'STR_TO_DATE('.$this->sql($e, 'this').', '.$this->dateintFormat().')';
    }

    protected function transformDateToDi(Expression $e): string
    {
        return '(DATE_FORMAT('.$this->sql($e, 'this').', '.$this->dateintFormat().') :> INT)';
    }

    protected function transformTsOrDiToDi(Expression $e): string
    {
        return '(DATE_FORMAT('.$this->sql($e, 'this').', '.$this->dateintFormat().') :> INT)';
    }

    protected function transformTime(Expression $e): string
    {
        return $this->sql($e, 'this').' :> TIME';
    }

    protected function transformDateDiff(Expression $e): string
    {
        return ($e->args['unit'] ?? null) !== null
            ? $this->timestampdiffSqlHandler($e)
            : $this->func('DATEDIFF', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformTsOrDsDiff(Expression $e): string
    {
        return $this->transformDateDiff($e);
    }

    protected function transformCurrentDatetime(Expression $e): string
    {
        return $this->sql(SingleStore::castToTime6(new CurrentTimestamp(['this' => Literal::number(6)]), DType::DATETIME));
    }

    protected function transformJSONPathSubscript(Expression $e): string
    {
        return $this->jsonPathPart($e->args['this'] ?? null);
    }

    protected function transformJSONPathRoot(Expression $e): string
    {
        return '';
    }

    protected function transformJSONArrayAgg(Expression $e): string
    {
        return $this->funcWith('JSON_AGG', [$e->args['this'] ?? null], suffix: $this->sql($e, 'order').')');
    }

    protected function transformJSONBExists(Expression $e): string
    {
        return $this->func('BSON_MATCH_ANY_EXISTS', $e->args['this'] ?? null, $e->args['path'] ?? null);
    }

    protected function transformJSONExists(Expression $e): string
    {
        if ($this->truthy($e->args['from_dcolonqmark'] ?? null)) {
            return $this->sql($e->args['this'] ?? null).'::?'.$this->sql($e, 'path');
        }

        return $this->func('JSON_MATCH_ANY_EXISTS', $e->args['this'] ?? null, $e->args['path'] ?? null);
    }

    protected function transformDayOfWeekIso(Expression $e): string
    {
        return '(('.$this->func('DAYOFWEEK', $e->args['this'] ?? null).' % 7) + 1)';
    }

    protected function transformLogicalOr(Expression $e): string
    {
        return 'MAX(ABS('.$this->sql($e, 'this').'))';
    }

    protected function transformLogicalAnd(Expression $e): string
    {
        return 'MIN(ABS('.$this->sql($e, 'this').'))';
    }

    protected function transformApproxQuantile(Expression $e): string
    {
        return $this->func('APPROX_PERCENTILE', $e->args['this'] ?? null, $e->args['quantile'] ?? null, $e->args['error_tolerance'] ?? null);
    }

    protected function transformCbrt(Expression $e): string
    {
        return $this->sql(new Pow([
            'this' => $e->args['this'] ?? null,
            'expression' => Builder::binop(Div::class, Literal::number(1), Literal::number(3)),
        ]));
    }

    protected function transformRegexpLike(Expression $e): string
    {
        return $this->binary($e, 'RLIKE');
    }

    protected function transformRepeat(Expression $e): string
    {
        return $this->func(
            'LPAD',
            Literal::string(''),
            new Mul(['this' => $this->func('LENGTH', $e->args['this'] ?? null), 'expression' => $e->args['times'] ?? null]),
            $e->args['this'] ?? null,
        );
    }

    protected function transformIsAscii(Expression $e): string
    {
        return '('.$this->sql($e, 'this')." RLIKE '^[\x00-\x7f]*$')";
    }

    protected function transformMD5Digest(Expression $e): string
    {
        return $this->func('UNHEX', $this->func('MD5', $e->args['this'] ?? null));
    }

    protected function transformRegexpExtractAll(Expression $e): string
    {
        return $this->func('REGEXP_MATCH', $e->args['this'] ?? null, $e->args['expression'] ?? null, $e->args['parameters'] ?? null);
    }

    protected function transformRegexpExtract(Expression $e): string
    {
        return $this->func(
            'REGEXP_SUBSTR',
            $e->args['this'] ?? null,
            $e->args['expression'] ?? null,
            $e->args['position'] ?? null,
            $e->args['occurrence'] ?? null,
            $e->args['parameters'] ?? null,
        );
    }

    protected function transformStartsWith(Expression $e): string
    {
        return $this->func('REGEXP_INSTR', $e->args['this'] ?? null, $this->func('CONCAT', Literal::string('^'), $e->args['expression'] ?? null));
    }

    protected function transformFromBase(Expression $e): string
    {
        return $this->func('CONV', $e->args['this'] ?? null, $e->args['expression'] ?? null, Literal::number(10));
    }

    protected function transformRegexpILike(Expression $e): string
    {
        return $this->binary(new RegexpLike([
            'this' => new Lower(['this' => $e->args['this'] ?? null]),
            'expression' => new Lower(['this' => $e->args['expression'] ?? null]),
        ]), 'RLIKE');
    }

    protected function transformStuff(Expression $e): string
    {
        $start = $e->args['start'] ?? null;

        return $this->func(
            'CONCAT',
            $this->func('SUBSTRING', $e->args['this'] ?? null, Literal::number(1), Builder::binop(Sub::class, $start, 1)),
            $e->args['expression'] ?? null,
            $this->func('SUBSTRING', $e->args['this'] ?? null, Builder::binop(Add::class, $start, $e->args['length'] ?? null)),
        );
    }

    protected function transformNational(Expression $e): string
    {
        return $this->nationalSql($e, '');
    }

    protected function transformReduce(Expression $e): string
    {
        return $this->func('REDUCE', $e->args['initial'] ?? null, $e->args['this'] ?? null, $e->args['merge'] ?? null);
    }

    protected function transformMatchAgainst(Expression $e): string
    {
        return $this->matchagainstSql($e);
    }

    protected function transformShow(Expression $e): string
    {
        return $this->showSql($e);
    }

    protected function transformDescribe(Expression $e): string
    {
        return $this->describeSql($e);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    /**
     * UNICODE_SUBSTITUTE (sqlglot _unicode_substitute): escaped code points become the characters.
     */
    protected function unicodestringSql(Expression $expression): string
    {
        $unicodeStart = $this->dialect->settings['UNICODE_START'];

        if (is_string($unicodeStart) && $unicodeStart !== '') {
            return parent::unicodestringSql($expression);
        }

        $this_ = $this->sql($expression, 'this');
        $escape = $expression->args['escape'] ?? null;
        $escapePattern = $escape instanceof Expression ? '/'.preg_quote($escape->name(), '/').'(\\d+)/' : '/\\\\(\\d+)/';

        $this_ = (string) preg_replace_callback(
            $escapePattern,
            static fn (array $m): string => mb_chr((int) hexdec($m[1]), 'UTF-8'),
            $this_,
        );

        $quote = (string) $this->dialect->settings['QUOTE_START'];

        return $quote.$this->escapeStr($this_, escapeBackslash: false).$this->dialect->settings['QUOTE_END'];
    }

    protected function jsonextractscalarSql(Expression $expression): string
    {
        $jsonType = $expression->args['json_type'] ?? null;

        return $this->jsonExtractSegments($expression, $jsonType === null ? 'JSON_EXTRACT_JSON' : 'JSON_EXTRACT_'.$this->sql($jsonType));
    }

    protected function jsonbextractscalarSql(Expression $expression): string
    {
        $jsonType = $expression->args['json_type'] ?? null;

        return $this->jsonExtractSegments($expression, $jsonType === null ? 'BSON_EXTRACT_BSON' : 'BSON_EXTRACT_'.$this->sql($jsonType));
    }

    protected function jsonextractarraySql(Expression $expression): string
    {
        $this->unsupported('Arrays are not supported in SingleStore');

        return $this->functionFallbackSql($expression);
    }

    protected function jsonvalueSql(Expression $expression): string
    {
        if ($this->truthy($expression->args['on_condition'] ?? null)) {
            $this->unsupported('JSON_VALUE does not support on_condition');
        }

        $res = new JSONExtractScalar([
            'this' => $expression->args['this'] ?? null,
            'expression' => $expression->args['path'] ?? null,
            'json_type' => 'STRING',
        ]);

        $returning = $expression->args['returning'] ?? null;

        if ($returning !== null) {
            $res = new Cast(['this' => $res, 'to' => $returning]);
        }

        return $this->sql($res);
    }

    protected function withingroupSql(Expression $expression): string
    {
        // SingleStore includes trailing NULLs in percentile calculations, so we sort them first
        $this_ = $expression->args['this'] ?? null;

        if (($this_ instanceof PercentileCont || $this_ instanceof PercentileDisc) && ($expression->args['expression'] ?? null) instanceof Order) {
            $expression = $expression->copy();
            $order = $expression->args['expression'];

            if ($order instanceof Expression) {
                foreach ($order->argList('expressions') as $ordered) {
                    $ordered->set('nulls_first', true);
                }
            }
        }

        return parent::withingroupSql($expression);
    }

    protected function allSql(Expression $expression): string
    {
        $this->unsupported('ALL subquery predicate is not supported in SingleStore');

        return parent::allSql($expression);
    }

    protected function jsonarraycontainsSql(Expression $expression): string
    {
        $jsonType = strtoupper($expression->text('json_type'));

        if ($jsonType !== '') {
            return $this->func("JSON_ARRAY_CONTAINS_{$jsonType}", $expression->args['expression'] ?? null, $expression->args['this'] ?? null);
        }

        return $this->func('JSON_ARRAY_CONTAINS_JSON', $expression->args['expression'] ?? null, $this->func('TO_JSON', $expression->args['this'] ?? null));
    }

    protected function datatypeSql(DataType $expression): string
    {
        foreach (['kind', 'values'] as $argName) {
            if ($this->truthy($expression->args[$argName] ?? null)) {
                $this->unsupported("DATATYPE does not support {$argName}");
            }
        }

        if ($this->truthy($expression->args['nested'] ?? null) && ! $expression->isType(DType::STRUCT)) {
            $type = $expression->args['this'] ?? null;
            $typeName = is_scalar($type) ? (string) $type : '';
            $this->unsupported("Argument 'nested' is not supported for representation of '{$typeName}' in SingleStore");
        }

        if ($expression->isType(DType::VARBINARY) && $expression->argList('expressions') === []) {
            // `VARBINARY` must always have a size - if it doesn't, we always generate `BLOB`
            return 'BLOB';
        }

        if ($expression->isType(DType::DECIMAL32, DType::DECIMAL64, DType::DECIMAL128, DType::DECIMAL256)) {
            $scale = $this->expressions($expression, flat: true);

            if ($expression->isType(DType::DECIMAL32)) {
                $precision = '9';
            } elseif ($expression->isType(DType::DECIMAL64)) {
                $precision = '18';
            } elseif ($expression->isType(DType::DECIMAL128)) {
                $precision = '38';
            } else {
                // 65 is a maximum precision supported in SingleStore
                $precision = '65';
            }

            return "DECIMAL({$precision}, ".substr($scale, 0, 1).')';
        }

        if ($expression->isType(DType::VECTOR)) {
            $expressions = $expression->argList('expressions');

            if (count($expressions) === 2) {
                $typeName = $this->sql($expressions[0]);
                $aliases = $this->dialect->settings['INVERSE_VECTOR_TYPE_ALIASES'];

                if (is_array($aliases) && isset($aliases[$typeName]) && is_string($aliases[$typeName])) {
                    $typeName = $aliases[$typeName];
                }

                return 'VECTOR('.$this->sql($expressions[1]).", {$typeName})";
            }
        }

        return parent::datatypeSql($expression);
    }

    protected function collateSql(Expression $expression): string
    {
        // SingleStore does not support setting a collation for column in the SELECT query,
        // so we cast column to a LONGTEXT type with specific collation.
        $this_ = $expression->args['this'] ?? null;
        $to = $this_ instanceof Cast ? ($this_->args['to'] ?? null) : null;

        if ($to instanceof Expression && ($to->args['this'] ?? null) === DType::LONGTEXT) {
            return $this->binary($expression, 'COLLATE');
        }

        return $this->binary($expression, ':> LONGTEXT COLLATE');
    }

    protected function currentdateSql(Expression $expression): string
    {
        $timezone = $expression->args['this'] ?? null;

        if ($this->truthy($timezone)) {
            if ($timezone instanceof Literal && strtolower($timezone->name()) === 'utc') {
                return $this->func('UTC_DATE');
            }

            $this->unsupported('CurrentDate with timezone is not supported in SingleStore');
        }

        return $this->func('CURRENT_DATE');
    }

    protected function currenttimeSql(Expression $expression): string
    {
        return $this->currentTimeLike($expression, 'CURRENT_TIME', 'UTC_TIME', 'CurrentTime');
    }

    protected function currenttimestampSql(Expression $expression): string
    {
        return $this->currentTimeLike($expression, 'CURRENT_TIMESTAMP', 'UTC_TIMESTAMP', 'CurrentTimestamp');
    }

    private function currentTimeLike(Expression $expression, string $name, string $utcName, string $label): string
    {
        $arg = $expression->args['this'] ?? null;

        if ($this->truthy($arg)) {
            if ($arg instanceof Literal && strtolower($arg->name()) === 'utc') {
                return $this->func($utcName);
            }

            if ($arg instanceof Literal && $arg->isNumber()) {
                return $this->func($name, $arg);
            }

            $this->unsupported("{$label} with timezone is not supported in SingleStore");
        }

        return $this->func($name);
    }

    protected function standardhashSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $hashFunction = $expression->args['expression'] ?? null;

        if ($hashFunction === null) {
            return $this->func('SHA', $this_);
        }

        if ($hashFunction instanceof Literal) {
            if (strtolower($hashFunction->name()) === 'sha') {
                return $this->func('SHA', $this_);
            }

            if (strtolower($hashFunction->name()) === 'md5') {
                return $this->func('MD5', $this_);
            }

            $this->unsupported($hashFunction->name().' hash method is not supported in SingleStore');

            return $this->func('SHA', $this_);
        }

        $this->unsupported('STANDARD_HASH function is not supported in SingleStore');

        return $this->func('SHA', $this_);
    }

    protected function truncatetableSql(Expression $expression): string
    {
        foreach (['is_database', 'exists', 'cluster', 'identity', 'option', 'partition'] as $argName) {
            if ($this->truthy($expression->args[$argName] ?? null)) {
                $this->unsupported("TRUNCATE TABLE does not support {$argName}");
            }
        }

        $statements = [];

        foreach ($expression->argList('expressions') as $table) {
            $statements[] = 'TRUNCATE '.$this->sql($table);
        }

        return implode('; ', $statements);
    }

    protected function renamecolumnSql(Expression $expression): string
    {
        if ($this->truthy($expression->args['exists'] ?? null)) {
            $this->unsupported('RENAME COLUMN does not support exists');
        }

        return 'CHANGE '.$this->sql($expression, 'this').' '.$this->sql($expression, 'to');
    }

    protected function altercolumnSql(Expression $expression): string
    {
        foreach (['drop', 'comment', 'visible', 'using'] as $argName) {
            if ($this->truthy($expression->args[$argName] ?? null)) {
                $this->unsupported("ALTER COLUMN does not support {$argName}");
            }
        }

        $alter = parent::altercolumnSql($expression);
        $collate = $this->sql($expression, 'collate');

        return $alter.($collate !== '' ? " COLLATE {$collate}" : '');
    }

    protected function computedcolumnconstraintSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $notNull = $this->truthy($expression->args['not_null'] ?? null) ? ' NOT NULL' : '';
        $type = $this->sql($expression, 'data_type');

        return "AS {$this_} PERSISTED ".($type !== '' ? $type : 'AUTO').$notNull;
    }
}
