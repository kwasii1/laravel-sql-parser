<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Spark2;

use Kwasii\LaravelSqlParser\Dialects\Hive\HiveGenerator;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Create;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\Div;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\FileFormatProperty;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\JSONExtract;
use Kwasii\LaravelSqlParser\Expressions\JSONExtractScalar;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Neg;
use Kwasii\LaravelSqlParser\Expressions\ParseJSON;
use Kwasii\LaravelSqlParser\Expressions\Subquery;
use Kwasii\LaravelSqlParser\Expressions\TemporaryProperty;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * Spark 2 generator (sqlglot generators/spark2.py).
 */
class Spark2Generator extends HiveGenerator
{
    public const string TABLES = Spark2GeneratorTables::class;

    protected static function isParseJson(mixed $expression): bool
    {
        return $expression instanceof ParseJSON || ($expression instanceof Cast && $expression->isType('json'));
    }

    // ---------------------------------------------------------------------------------------
    // Transforms (preprocess)
    // ---------------------------------------------------------------------------------------

    /**
     * ctas_with_tmp_tables_to_create_tmp_view(e, temporary_storage_provider).
     */
    public function transformCreateTransforms(Expression $expression): Expression
    {
        $properties = $expression->argExpr('properties');
        $temporary = false;

        foreach ($properties !== null ? $properties->argList('expressions') : [] as $prop) {
            if ($prop instanceof TemporaryProperty) {
                $temporary = true;

                break;
            }
        }

        if ($expression->text('kind') === 'TABLE' && $temporary) {
            if ($expression->argExpr('expression') !== null) {
                return new Create([
                    'kind' => 'TEMPORARY VIEW',
                    'this' => $expression->args['this'] ?? null,
                    'expression' => $expression->args['expression'],
                ]);
            }

            // spark2, spark, Databricks require a storage provider for temporary tables
            $properties?->append('expressions', new FileFormatProperty(['this' => Literal::string('parquet')]));
        }

        return $expression;
    }

    /**
     * sqlglot _unalias_pivot: Spark doesn't allow PIVOT aliases, so the pivoted source is
     * wrapped in a subquery with the same alias.
     */
    public function unaliasPivot(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;

        if ($expression instanceof From && $this_ instanceof Expression && $this_->argList('pivots') !== []) {
            $pivot = $this_->argList('pivots')[0];

            if ($pivot->alias() !== '') {
                $alias = $pivot->argExpr('alias')?->pop();

                return new From([
                    'this' => $this_->replace(new Subquery([
                        'this' => Builder::selectStarFrom($this_->copy()),
                        'alias' => $alias,
                    ])),
                ]);
            }
        }

        return $expression;
    }

    public function unnestToExplode(Expression $expression, bool $unnestUsingArraysZip = true): Expression
    {
        return Generator::unnestToExplode($expression, $unnestUsingArraysZip);
    }

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function jsonFormatSqlHandler(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if (self::isParseJson($this_) && $this_ instanceof Expression) {
            $inner = $this_->args['this'] ?? null;

            if ($inner instanceof Expression && $inner->isString()) {
                // Since FROM_JSON requires a nested type, we always wrap the json string with
                // an array to ensure that "naked" strings like "'a'" will be handled correctly
                $wrappedJson = Literal::string('['.$inner->name().']');

                $fromJson = $this->func('FROM_JSON', $wrappedJson, $this->func('SCHEMA_OF_JSON', $wrappedJson));
                $toJson = $this->func('TO_JSON', $fromJson);

                // This strips the [, ] delimiters of the dummy array printed by TO_JSON
                return $this->func('REGEXP_EXTRACT', $toJson, "'^.(.*).$'", '1');
            }

            return $this->sql($this_);
        }

        return $this->func('TO_JSON', $this_, $expression->args['options'] ?? null);
    }

    protected function mapSqlHandler(Expression $expression): string
    {
        $keys = $expression->args['keys'] ?? null;
        $values = $expression->args['values'] ?? null;

        if (! $this->truthy($keys) || ! $this->truthy($values)) {
            return $this->func('MAP');
        }

        return $this->func('MAP_FROM_ARRAYS', $keys, $values);
    }

    protected function strToDate(Expression $expression): string
    {
        $timeFormat = $this->formatTime($expression);

        if ($timeFormat === self::HIVE_DATE_FORMAT) {
            return $this->func('TO_DATE', $expression->args['this'] ?? null);
        }

        return $this->func('TO_DATE', $expression->args['this'] ?? null, $timeFormat);
    }

    protected function transformStrToTime(Expression $e): string
    {
        return $this->func('TO_TIMESTAMP', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function unixToTimeSqlHandler(Expression $expression): string
    {
        $scale = $expression->args['scale'] ?? null;
        $timestamp = $expression->args['this'] ?? null;

        if ($scale === null) {
            return $this->sql(Builder::cast(Builder::func('from_unixtime', $timestamp instanceof Expression ? [$timestamp] : []), DType::TIMESTAMP));
        }

        foreach ([0 => 'TIMESTAMP_SECONDS', 3 => 'TIMESTAMP_MILLIS', 6 => 'TIMESTAMP_MICROS'] as $value => $name) {
            if ($scale instanceof Literal && $scale->equals(Literal::number($value))) {
                return $this->func($name, $timestamp);
            }
        }

        $unixSeconds = new Div(['this' => $timestamp, 'expression' => Builder::func('POW', [Literal::number(10), $scale instanceof Expression ? $scale : Literal::number(0)])]);

        return $this->func('TIMESTAMP_SECONDS', $unixSeconds);
    }

    protected function transformRegexpReplace(Expression $e): string
    {
        return $this->func('REGEXP_REPLACE', $e->args['this'] ?? null, $e->args['expression'] ?? null, $e->args['replacement'] ?? null, $e->args['position'] ?? null);
    }

    protected function transformArraySum(Expression $e): string
    {
        return 'AGGREGATE('.$this->sql($e, 'this').', 0, (acc, x) -> acc + x, acc -> acc)';
    }

    protected function transformAtTimeZone(Expression $e): string
    {
        return $this->func('FROM_UTC_TIMESTAMP', $e->args['this'] ?? null, $e->args['zone'] ?? null);
    }

    protected function transformDateTrunc(Expression $e): string
    {
        return $this->func('TRUNC', $e->args['this'] ?? null, $this->weekstartUnitToStr($e));
    }

    protected function transformDayOfWeekIso(Expression $e): string
    {
        return '(('.$this->func('DAYOFWEEK', $e->args['this'] ?? null).' % 7) + 1)';
    }

    protected function transformFromTimeZone(Expression $e): string
    {
        return $this->func('TO_UTC_TIMESTAMP', $e->args['this'] ?? null, $e->args['zone'] ?? null);
    }

    protected function transformSHA2Digest(Expression $e): string
    {
        $length = $e->args['length'] ?? null;

        return $this->func('SHA2', $e->args['this'] ?? null, $this->truthy($length) ? $length : Literal::number(256));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function structSql(Expression $expression): string
    {
        return Generator::structSql($expression);
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        $arg = $expression->args['this'] ?? null;
        $to = $expression->args['to'] ?? null;
        $toParams = $to instanceof DataType ? $to->argList('expressions') : [];

        if (
            $arg instanceof Literal
            && $arg->isNumber()
            && $to instanceof DataType
            && $to->isType(DType::DECIMAL)
            && count($toParams) === 2
            && ($toParams[1]->args['this'] ?? null) instanceof Neg
        ) {
            // Spark 2 supports negative scales in literals, but not in DECIMAL type syntax.
            return $this->sql($arg).'BD';
        }

        $isJsonExtract = ($arg instanceof JSONExtract || $arg instanceof JSONExtractScalar) && ! $arg->flag('variant_extract');

        // We can't use a non-nested type (eg. STRING) as a schema
        if ($to instanceof DataType && $to->flag('nested') && (self::isParseJson($arg) || $isJsonExtract)) {
            $schema = "'".$this->sql($expression, 'to')."'";

            return $this->func('FROM_JSON', $isJsonExtract ? $arg : ($arg instanceof Expression ? ($arg->args['this'] ?? null) : null), $schema);
        }

        if (self::isParseJson($expression)) {
            return $this->func('TO_JSON', $arg);
        }

        return Generator::castSql($expression, $safePrefix);
    }

    protected function fileformatpropertySql(Expression $expression): string
    {
        if ($expression->flag('hive_format')) {
            return parent::fileformatpropertySql($expression);
        }

        return 'USING '.strtoupper($expression->name());
    }

    protected function altercolumnSql(Expression $expression): string
    {
        if ($expression->flag('exists')) {
            $this->unsupported('ALTER COLUMN IF EXISTS is not supported by this dialect');
        }

        $this_ = $this->sql($expression, 'this');
        $newName = $this->sql($expression, 'rename_to');
        $newName = $newName !== '' ? $newName : $this_;
        $comment = $this->sql($expression, 'comment');

        if ($newName === $this_) {
            if ($comment !== '') {
                return "ALTER COLUMN {$this_} COMMENT {$comment}";
            }

            return Generator::altercolumnSql($expression);
        }

        return "RENAME COLUMN {$this_} TO {$newName}";
    }

    protected function renamecolumnSql(Expression $expression): string
    {
        return Generator::renamecolumnSql($expression);
    }

    protected function bracketSql(Expression $expression): string
    {
        if (array_key_exists('safe', $expression->args) && $expression->args['safe'] === false) {
            return $this->bracketToElementAtSql($expression);
        }

        return parent::bracketSql($expression);
    }

    /**
     * sqlglot bracket_to_element_at_sql.
     */
    protected function bracketToElementAtSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $offset = $expression->args['offset'] ?? 0;
        $expressions = $expression->argList('expressions');
        $index = ($this_ !== null ? Builder::applyIndexOffset($this_, $expressions, 1 - (is_int($offset) ? $offset : 0), $this->dialect) : $expressions)[0] ?? null;

        return $this->func('ELEMENT_AT', $this_, $index);
    }
}
