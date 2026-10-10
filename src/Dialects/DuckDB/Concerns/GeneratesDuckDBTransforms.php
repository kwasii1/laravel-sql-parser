<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\DuckDB\Concerns;

use Kwasii\LaravelSqlParser\Expressions\ArrayCompact;
use Kwasii\LaravelSqlParser\Expressions\ArrayExpr;
use Kwasii\LaravelSqlParser\Expressions\ArrayPosition;
use Kwasii\LaravelSqlParser\Expressions\AtTimeZone;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\Decode;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Extract;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\NullSafeEQ;
use Kwasii\LaravelSqlParser\Expressions\Schema;
use Kwasii\LaravelSqlParser\Expressions\Sub;
use Kwasii\LaravelSqlParser\Expressions\Unhex;
use Kwasii\LaravelSqlParser\Expressions\VarExpr;

/**
 * The lambdas of DuckDBGenerator.TRANSFORMS (sqlglot generators/duckdb.py).
 */
trait GeneratesDuckDBTransforms
{
    protected function transformArrayConstructCompact(Expression $e): string
    {
        return $this->sql(new ArrayCompact(['this' => new ArrayExpr(['expressions' => $e->argList('expressions')])]));
    }

    protected function transformArrayPosition(Expression $e): string
    {
        if ($e->flag('zero_based')) {
            return $this->sql(new Sub([
                'this' => new ArrayPosition(['this' => $e->args['this'] ?? null, 'expression' => $e->args['expression'] ?? null]),
                'expression' => Literal::number(1),
            ]));
        }

        return $this->func('ARRAY_POSITION', $e->argExpr('this'), $e->argExpr('expression'));
    }

    protected function transformBase64DecodeBinary(Expression $e): string
    {
        return $this->base64DecodeSql($e, false);
    }

    protected function transformBase64DecodeString(Expression $e): string
    {
        return $this->base64DecodeSql($e, true);
    }

    protected function transformBitwiseAnd(Expression $e): string
    {
        return $this->bitwiseOp($e, '&');
    }

    protected function transformBitwiseOr(Expression $e): string
    {
        return $this->bitwiseOp($e, '|');
    }

    protected function transformCorr(Expression $e): string
    {
        return $this->corrSqlInternal($e);
    }

    protected function transformCurrentTime(Expression $e): string
    {
        return 'CURRENT_TIME';
    }

    protected function transformCurrentSchemas(Expression $e): string
    {
        return $this->func('current_schemas', $e->argExpr('this') ?? Builder::true());
    }

    protected function transformCurrentTimestamp(Expression $e): string
    {
        if ($e->flag('sysdate')) {
            return $this->sql(new AtTimeZone(['this' => Builder::var('CURRENT_TIMESTAMP'), 'zone' => Literal::string('UTC')]));
        }

        return 'CURRENT_TIMESTAMP';
    }

    protected function transformLocaltime(Expression $e): string
    {
        $this->unsupportedArgs($e, 'this');

        return 'LOCALTIME';
    }

    protected function transformDayname(Expression $e): string
    {
        return $e->flag('abbreviated')
            ? $this->func('STRFTIME', $e->argExpr('this'), Literal::string('%a'))
            : $this->func('DAYNAME', $e->argExpr('this'));
    }

    protected function transformMonthname(Expression $e): string
    {
        return $e->flag('abbreviated')
            ? $this->func('STRFTIME', $e->argExpr('this'), Literal::string('%b'))
            : $this->func('MONTHNAME', $e->argExpr('this'));
    }

    protected function transformDateToDi(Expression $e): string
    {
        return "CAST(STRFTIME({$this->sql($e, 'this')}, {$this->dialect->settings['DATEINT_FORMAT']}) AS INT)";
    }

    protected function transformDecode(Expression $e): string
    {
        return $this->encodeDecodeSql($e, 'DECODE', replace: false);
    }

    protected function transformHexDecodeString(Expression $e): string
    {
        return $this->sql(new Decode(['this' => new Unhex(['this' => $e->args['this'] ?? null])]));
    }

    protected function transformDiToDate(Expression $e): string
    {
        return "CAST(STRPTIME(CAST({$this->sql($e, 'this')} AS TEXT), {$this->dialect->settings['DATEINT_FORMAT']}) AS DATE)";
    }

    protected function transformEncode(Expression $e): string
    {
        return $this->encodeDecodeSql($e, 'ENCODE', replace: false);
    }

    protected function transformEqualNull(Expression $e): string
    {
        return $this->sql(new NullSafeEQ(['this' => $e->args['this'] ?? null, 'expression' => $e->args['expression'] ?? null]));
    }

    protected function transformGroupConcat(Expression $e): string
    {
        return $this->groupconcatSqlHandler($e, withinGroup: false);
    }

    protected function transformIntDiv(Expression $e): string
    {
        return $this->binary($e, '//');
    }

    protected function transformIsNullValue(Expression $e): string
    {
        return $this->sql(Builder::binop(EQ::class, Builder::func('JSON_TYPE', [$e->argExpr('this')]), Literal::string('NULL')));
    }

    protected function transformIsArray(Expression $e): string
    {
        return $this->sql(Builder::binop(EQ::class, Builder::func('JSON_TYPE', [$e->argExpr('this')]), Literal::string('ARRAY')));
    }

    protected function transformLogicalOr(Expression $e): string
    {
        return $this->func('BOOL_OR', $this->castToBoolean($e->argExpr('this')));
    }

    protected function transformLogicalAnd(Expression $e): string
    {
        return $this->func('BOOL_AND', $this->castToBoolean($e->argExpr('this')));
    }

    protected function transformSeq1(Expression $e): string
    {
        return $this->seqSql($e, 1);
    }

    protected function transformSeq2(Expression $e): string
    {
        return $this->seqSql($e, 2);
    }

    protected function transformSeq4(Expression $e): string
    {
        return $this->seqSql($e, 4);
    }

    protected function transformSeq8(Expression $e): string
    {
        return $this->seqSql($e, 8);
    }

    protected function transformMakeInterval(Expression $e): string
    {
        return $this->noMakeIntervalSql($e, ' ');
    }

    protected function transformMD5Digest(Expression $e): string
    {
        return $this->func('UNHEX', $this->func('MD5', $e->argExpr('this')));
    }

    protected function transformReturnsProperty(Expression $e): string
    {
        return ($e->args['this'] ?? null) instanceof Schema ? 'TABLE' : '';
    }

    protected function transformSha(Expression $e): string
    {
        return $this->shaSqlDuckdb($e, 'SHA1');
    }

    protected function transformSha2(Expression $e): string
    {
        return $this->shaSqlDuckdb($e, 'SHA256');
    }

    protected function transformSHA1Digest(Expression $e): string
    {
        return $this->shaSqlDuckdb($e, 'SHA1', isBinary: true);
    }

    protected function transformSHA2Digest(Expression $e): string
    {
        return $this->shaSqlDuckdb($e, 'SHA256', isBinary: true);
    }

    protected function transformRegexpILike(Expression $e): string
    {
        return $this->func('REGEXP_MATCHES', $e->argExpr('this'), $e->argExpr('expression'), Literal::string('i'));
    }

    protected function transformReturn(Expression $e): string
    {
        return $this->sql($e, 'this');
    }

    protected function transformStrToUnix(Expression $e): string
    {
        return $this->func('EPOCH', $this->func('STRPTIME', $e->argExpr('this'), $this->formatTime($e)));
    }

    protected function transformTimestampDiff(Expression $e): string
    {
        return $this->func('DATE_DIFF', Literal::string($e->text('unit')), $e->argExpr('expression'), $e->argExpr('this'));
    }

    protected function transformTimeStrToDate(Expression $e): string
    {
        return $this->sql(Builder::cast($e->argExpr('this') ?? Builder::null(), DType::DATE));
    }

    protected function transformTimeStrToUnix(Expression $e): string
    {
        return $this->func('EPOCH', Builder::cast($e->argExpr('this') ?? Builder::null(), DType::TIMESTAMP));
    }

    protected function transformTimeToStr(Expression $e): string
    {
        return $this->func('STRFTIME', $e->argExpr('this'), $this->formatTime($e));
    }

    protected function transformToVariant(Expression $e): string
    {
        return $this->sql(Builder::cast($e->argExpr('this') ?? Builder::null(), DataType::fromStr('VARIANT', dialect: 'duckdb')));
    }

    protected function transformTsOrDiToDi(Expression $e): string
    {
        return "CAST(SUBSTR(REPLACE(CAST({$this->sql($e, 'this')} AS TEXT), '-', ''), 1, 8) AS INT)";
    }

    protected function transformTsOrDsDiff(Expression $e): string
    {
        $unit = $e->args['unit'] ?? null;
        $unit = $this->truthy($unit) ? (string) $unit : 'DAY';

        return $this->func(
            'DATE_DIFF',
            "'{$unit}'",
            Builder::cast($e->argExpr('expression') ?? Builder::null(), DType::TIMESTAMP),
            Builder::cast($e->argExpr('this') ?? Builder::null(), DType::TIMESTAMP),
        );
    }

    protected function transformUnixMicros(Expression $e): string
    {
        return $this->func('EPOCH_US', $this->implicitDatetimeCast($e->argExpr('this')));
    }

    protected function transformUnixMillis(Expression $e): string
    {
        return $this->func('EPOCH_MS', $this->implicitDatetimeCast($e->argExpr('this')));
    }

    protected function transformUnixSeconds(Expression $e): string
    {
        return $this->sql(Builder::cast($this->func('EPOCH', $this->implicitDatetimeCast($e->argExpr('this'))), DType::BIGINT));
    }

    protected function transformUnixToStr(Expression $e): string
    {
        return $this->func('STRFTIME', $this->func('TO_TIMESTAMP', $e->argExpr('this')), $this->formatTime($e));
    }

    protected function transformUnixToTimeStr(Expression $e): string
    {
        return "CAST(TO_TIMESTAMP({$this->sql($e, 'this')}) AS TEXT)";
    }

    protected function transformYearOfWeek(Expression $e): string
    {
        return $this->sql(new Extract(['this' => new VarExpr(['this' => 'ISOYEAR']), 'expression' => $e->args['this'] ?? null]));
    }

    protected function transformYearOfWeekIso(Expression $e): string
    {
        return $this->transformYearOfWeek($e);
    }
}
