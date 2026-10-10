<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Presto;

use LaravelSqlParser\LaravelSqlParser\Dialects\Hive\HiveDialectTables;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Encode;
use LaravelSqlParser\LaravelSqlParser\Expressions\Explode;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Func;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Inline;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\MatchRecognize;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionedByProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Property;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeToUnix;
use LaravelSqlParser\LaravelSqlParser\Expressions\WeekStart;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;

/**
 * Presto generator (sqlglot generators/presto.py).
 */
class PrestoGenerator extends Generator
{
    public const string TABLES = PrestoGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS lambdas
    // ---------------------------------------------------------------------------------------

    protected function transformApproxQuantile(Expression $e): string
    {
        return $this->func('APPROX_PERCENTILE', $e->args['this'] ?? null, $e->args['weight'] ?? null, $e->args['quantile'] ?? null, $e->args['accuracy'] ?? null);
    }

    protected function transformArrayGenerator(Expression $e): string
    {
        return 'ARRAY['.$this->expressions($e, flat: true).']';
    }

    protected function transformBitwiseAnd(Expression $e): string
    {
        return $this->func('BITWISE_AND', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformBitwiseNot(Expression $e): string
    {
        return $this->func('BITWISE_NOT', $e->args['this'] ?? null);
    }

    protected function transformBitwiseOr(Expression $e): string
    {
        return $this->func('BITWISE_OR', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformBitwiseXor(Expression $e): string
    {
        return $this->func('BITWISE_XOR', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformCurrentTime(Expression $e): string
    {
        return 'CURRENT_TIME';
    }

    protected function transformCurrentTimestamp(Expression $e): string
    {
        return 'CURRENT_TIMESTAMP';
    }

    protected function transformCurrentUser(Expression $e): string
    {
        return 'CURRENT_USER';
    }

    protected function transformDateToDi(Expression $e): string
    {
        return 'CAST(DATE_FORMAT('.$this->sql($e, 'this').', '.$this->dialect->settings['DATEINT_FORMAT'].') AS INT)';
    }

    protected function transformDayOfWeek(Expression $e): string
    {
        return '(('.$this->func('DAY_OF_WEEK', $e->args['this'] ?? null).' % 7) + 1)';
    }

    protected function transformDecode(Expression $e): string
    {
        return $this->encodeDecodeSql($e, 'FROM_UTF8');
    }

    protected function transformDiToDate(Expression $e): string
    {
        return 'CAST(DATE_PARSE(CAST('.$this->sql($e, 'this').' AS VARCHAR), '.$this->dialect->settings['DATEINT_FORMAT'].') AS DATE)';
    }

    protected function transformEncode(Expression $e): string
    {
        return $this->encodeDecodeSql($e, 'TO_UTF8');
    }

    protected function transformFileFormatProperty(Expression $e): string
    {
        return 'format='.$this->sql(Literal::string($e->name()));
    }

    protected function transformFromTimeZone(Expression $e): string
    {
        return 'WITH_TIMEZONE('.$this->sql($e, 'this').', '.$this->sql($e, 'zone').") AT TIME ZONE 'UTC'";
    }

    protected function transformLastDay(Expression $e): string
    {
        return $this->func('LAST_DAY_OF_MONTH', $e->args['this'] ?? null);
    }

    protected function transformSqlSecurityProperty(Expression $e): string
    {
        return 'SECURITY '.$this->sql($e->args['this'] ?? null);
    }

    protected function transformSchemaCommentProperty(Expression $e): string
    {
        return $this->nakedProperty($e);
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, supportsOccurrence: true);
    }

    protected function transformStrToDate(Expression $e): string
    {
        return 'CAST('.$this->strToTimeSqlHandler($e).' AS DATE)';
    }

    protected function transformTimeStrToUnix(Expression $e): string
    {
        return $this->func('TO_UNIXTIME', $this->func('DATE_PARSE', $e->args['this'] ?? null, $this->dialect->settings['TIME_FORMAT']));
    }

    protected function transformTimeToStr(Expression $e): string
    {
        return $this->func('DATE_FORMAT', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformToChar(Expression $e): string
    {
        return $this->func('DATE_FORMAT', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformTsOrDiToDi(Expression $e): string
    {
        return 'CAST(SUBSTR(REPLACE(CAST('.$this->sql($e, 'this')." AS VARCHAR), '-', ''), 1, 8) AS INT)";
    }

    protected function transformUnixToStr(Expression $e): string
    {
        return 'DATE_FORMAT(FROM_UNIXTIME('.$this->sql($e, 'this').'), '.$this->formatTime($e).')';
    }

    protected function transformUnixToTimeStr(Expression $e): string
    {
        return 'CAST(FROM_UNIXTIME('.$this->sql($e, 'this').') AS VARCHAR)';
    }

    // ---------------------------------------------------------------------------------------
    // Module helpers
    // ---------------------------------------------------------------------------------------

    protected function sha2DigestSqlPresto(Expression $expression): string
    {
        $length = $expression->text('length');
        $length = $length !== '' ? $length : '256';

        if (! in_array($length, ['256', '512'], true)) {
            $this->unsupported("SHA{$length} is not supported in Presto");
        }

        $this_ = $expression->argExpr('this');

        if ($this_ !== null && $this_->isType(...DataType::TEXT_TYPES)) {
            // the native digest takes VARBINARY, so a text-typed argument needs encoding
            $this_ = new Encode(['this' => $this_, 'charset' => Literal::string('utf-8')]);
        }

        return $this->func("SHA{$length}", $this_);
    }

    protected function initcapSqlHandler(Expression $expression): string
    {
        $delimiters = $expression->argExpr('expression');

        if ($delimiters !== null && ! ($delimiters->isString() && $delimiters->name() === $this->dialect->settings['INITCAP_DEFAULT_DELIMITER_CHARS'])) {
            $this->unsupported('INITCAP does not support custom delimiters');
        }

        $regex = '(\w)(\w*)';

        return 'REGEXP_REPLACE('.$this->sql($expression, 'this').", '{$regex}', x -> UPPER(x[1]) || LOWER(x[2]))";
    }

    protected function noSortArray(Expression $expression): string
    {
        $asc = $expression->args['asc'] ?? null;
        $comparator = $asc instanceof Expression && $asc->equals(Builder::false())
            ? '(a, b) -> CASE WHEN a < b THEN 1 WHEN a > b THEN -1 ELSE 0 END'
            : null;

        return $this->func('ARRAY_SORT', $expression->args['this'] ?? null, $comparator);
    }

    protected function schemaSqlHandler(Expression $expression): string
    {
        $parent = $expression->parent;

        if ($parent instanceof PartitionedByProperty) {
            // Any columns in the ARRAY[] string literals should not be quoted
            foreach ($expression->findAll(Identifier::class) as $identifier) {
                if ($identifier->parent !== null && $identifier->argKey !== null) {
                    $identifier->parent->set($identifier->argKey, $identifier->name(), $identifier->index);
                }
            }

            $partitionExprs = [];
            foreach ((array) ($expression->args['expressions'] ?? []) as $c) {
                if (is_string($c)) {
                    $partitionExprs[] = $c;
                } elseif ($c instanceof Expression) {
                    $partitionExprs[] = $c instanceof Func || $c instanceof Property ? $this->sql($c) : $this->sql($c, 'this');
                }
            }

            return $this->sql(new ArrayExpr([
                'expressions' => array_map(static fn (string $c): Literal => Literal::string($c), $partitionExprs),
            ]));
        }

        if ($parent !== null) {
            foreach ($parent->findAll(Schema::class) as $schema) {
                if ($schema === $expression) {
                    continue;
                }

                $columnDefs = $schema->findAll(ColumnDef::class);

                if ($schema->parent instanceof Property) {
                    foreach ($columnDefs as $columnDef) {
                        $expression->append('expressions', $columnDef);
                    }
                }
            }
        }

        return $this->schemaSql($expression);
    }

    protected function quantileSqlHandler(Expression $expression): string
    {
        $this->unsupported('Presto does not support exact quantiles');

        return $this->func('APPROX_PERCENTILE', $expression->args['this'] ?? null, $expression->args['quantile'] ?? null);
    }

    protected function strToTimeSqlHandler(Expression $expression): string
    {
        return $this->func('DATE_PARSE', $expression->args['this'] ?? null, $this->formatTime($expression));
    }

    protected function tsOrDsToDateSqlHandler(Expression $expression): string
    {
        $timeFormat = $this->formatTime($expression);

        if ($timeFormat !== null && $timeFormat !== '' && ! in_array($timeFormat, [$this->dialect->settings['TIME_FORMAT'], $this->dialect->settings['DATE_FORMAT']], true)) {
            return $this->sql(Builder::cast($this->strToTimeSqlHandler($expression), DType::DATE));
        }

        return $this->sql(Builder::cast(Builder::cast($expression->args['this'] ?? '', DType::TIMESTAMP), DType::DATE));
    }

    protected function tsOrDsAddSqlHandler(Expression $expression): string
    {
        $expression = self::tsOrDsAddCast($expression);

        return $this->func('DATE_ADD', Builder::unitToStr($expression), $expression->args['expression'] ?? null, $expression->args['this'] ?? null);
    }

    protected function tsOrDsDiffSqlHandler(Expression $expression): string
    {
        $this_ = Builder::cast($expression->args['this'] ?? '', DType::TIMESTAMP);
        $expr = Builder::cast($expression->args['expression'] ?? '', DType::TIMESTAMP);

        return $this->func('DATE_DIFF', Builder::unitToStr($expression), $expr, $this_);
    }

    /**
     * Trino doesn't support FIRST / LAST as functions, but they're valid in the context of
     * MATCH_RECOGNIZE, so we need to preserve them in that case. In all other cases they're
     * converted into an ARBITRARY call.
     */
    protected function firstLastSql(Expression $expression): string
    {
        if ($expression->findAncestor(MatchRecognize::class, Select::class) instanceof MatchRecognize) {
            return $this->functionFallbackSql($expression);
        }

        return $this->renameFunc($expression, 'ARBITRARY');
    }

    protected function unixToTimeSqlHandler(Expression $expression): string
    {
        $scale = $expression->args['scale'] ?? null;
        $timestamp = $this->sql($expression, 'this');

        if ($scale === null || ($scale instanceof Expression && $scale->equals(Literal::number(0)))) {
            return $this->renameFunc($expression, 'FROM_UNIXTIME');
        }

        return "FROM_UNIXTIME(CAST({$timestamp} AS DOUBLE) / POW(10, ".$this->sql($scale).'))';
    }

    private function toInt(?Expression $expression): ?Expression
    {
        if ($expression === null) {
            return null;
        }

        if ($expression->type() === null) {
            $this->dialect->annotateTypes($expression);
        }

        $type = $expression->type();

        if ($type !== null && ! $type->isType(...DataType::INTEGER_TYPES)) {
            return Builder::cast($expression, DType::BIGINT);
        }

        return $expression;
    }

    protected function dateDeltaSqlPresto(Expression $expression, string $name, bool $negateInterval = false): string
    {
        $interval = $this->toInt($expression->argExpr('expression'));

        return $this->func(
            $name,
            Builder::unitToStr($expression),
            $negateInterval ? Builder::binop(Mul::class, $interval, -1) : $interval,
            $expression->args['this'] ?? null,
        );
    }

    /**
     * Presto/Trino only expose date_diff(unit, ts1, ts2); it returns ts2 - ts1, so the operands
     * are emitted as (expression, this) to preserve `this - expression` semantics.
     */
    protected function dateDiffSqlHandler(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $expr = $expression->argExpr('expression');
        $unit = Builder::unitToStr($expression);

        // DATE_DIFF counts complete units between its operands, whereas dialects that set
        // date_part_boundary count unit boundary crossings, so the operands are truncated
        // down to the unit to make the two coincide
        if ($unit !== null && $expression->flag('date_part_boundary')) {
            $rawUnit = $expression->argExpr('unit');
            $dow = self::weekUnitToDow($rawUnit);

            if ($dow !== null) {
                $unit = Literal::string('WEEK');

                // DATE_TRUNC('WEEK', ...) is Monday-based; shifting both operands by the same
                // delta realigns it to the requested week start without changing the diff
                $shiftDays = $dow === 7 ? 1 : 1 - $dow;

                if ($shiftDays !== 0) {
                    $delta = new Interval(['this' => Literal::string((string) $shiftDays), 'unit' => Builder::var('DAY')]);
                    $this_ = new Add(['this' => $this_, 'expression' => $delta]);
                    $expr = new Add(['this' => $expr, 'expression' => $delta->copy()]);
                }
            }

            if (! $rawUnit instanceof WeekStart || $dow !== null) {
                $this_ = new DateTrunc(['unit' => $unit->copy(), 'this' => $this_]);
                $expr = new DateTrunc(['unit' => $unit->copy(), 'this' => $expr]);
            }
        }

        return $this->func('DATE_DIFF', $unit, $expr, $this_);
    }

    public function explodeToUnnestSql(Expression $expression): string
    {
        $explode = $expression->argExpr('this');

        if ($explode instanceof Explode) {
            $explodedType = $explode->argExpr('this')?->type();
            $alias = $expression->args['alias'] ?? null;

            // This attempts a best-effort transpilation of LATERAL VIEW EXPLODE on a struct array
            $first = $explodedType?->argList('expressions')[0] ?? null;

            if (
                $alias instanceof TableAlias
                && $explodedType instanceof DataType
                && $explodedType->isType(DType::ARRAY)
                && $first instanceof DataType
                && $first->isType(DType::STRUCT)
            ) {
                // When unnesting a ROW in Presto, it produces N columns, so we need to fix the alias
                $alias->set('columns', array_map(
                    static fn (Expression $c): mixed => $c->argExpr('this')?->copy(),
                    $first->argList('expressions'),
                ));
            }
        } elseif ($explode instanceof Inline) {
            $explode->replace(new Explode(['this' => $explode->argExpr('this')?->copy()]));
        }

        return parent::explodeToUnnestSql($expression);
    }

    public function amendExplodedColumnTable(Expression $expression): Expression
    {
        // We check for expression.type because the columns can be amended only if types were inferred
        if (! $expression instanceof Select || $expression->type() === null) {
            return $expression;
        }

        foreach ($expression->argList('laterals') as $lateral) {
            $alias = $lateral->args['alias'] ?? null;

            if (! $lateral->argExpr('this') instanceof Explode || ! $alias instanceof TableAlias || count($alias->argList('columns')) !== 1) {
                continue;
            }

            $newTable = $alias->argExpr('this');
            $oldTable = strtolower($alias->argList('columns')[0]->name());

            // When transpiling a LATERAL VIEW EXPLODE Spark query, the exploded fields may be
            // qualified with the struct column, resulting in invalid Presto references that need to be amended
            foreach (Scope::findAllInScope($expression, Column::class) as $column) {
                if (strtolower($column->text('db')) === $oldTable) {
                    $column->set('table', $column->argExpr('db')?->pop());
                } elseif (strtolower($column->text('table')) === $oldTable) {
                    $column->set('table', $newTable?->copy());
                } elseif (strtolower($column->name()) === $oldTable && $column->parent instanceof Dot) {
                    $field = $column->parent->argExpr('expression');
                    $column->parent->replace(Builder::column(
                        $field instanceof Identifier || $field instanceof Star ? $field : ($field?->name() ?? ''),
                        $newTable instanceof Identifier ? $newTable : $newTable?->name(),
                    ));
                }
            }
        }

        return $expression;
    }

    // ---------------------------------------------------------------------------------------
    // Generator method overrides
    // ---------------------------------------------------------------------------------------

    protected function extractSql(Expression $expression): string
    {
        $datePart = $expression->name();

        if (! str_starts_with($datePart, 'EPOCH')) {
            return parent::extractSql($expression);
        }

        $scale = match ($datePart) {
            'EPOCH_MILLISECOND' => 10 ** 3,
            'EPOCH_MICROSECOND' => 10 ** 6,
            'EPOCH_NANOSECOND' => 10 ** 9,
            default => null,
        };

        $ts = Builder::cast($expression->args['expression'] ?? '', DType::TIMESTAMP);
        $toUnix = new TimeToUnix(['this' => $ts]);

        if ($scale !== null) {
            $toUnix = new Mul(['this' => $toUnix, 'expression' => Literal::number($scale)]);
        }

        return $this->sql($toUnix);
    }

    protected function jsonformatSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');
        $isJson = $expression->flag('is_json');

        if ($this_ !== null && ! $isJson && $this_->type() === null) {
            $this->dialect->annotateTypes($this_);
        }

        if ($this_ !== null && ! ($isJson || $this_->isType(DType::JSON))) {
            $this_->replace(Builder::cast($this_, DType::JSON));
        }

        return $this->functionFallbackSql($expression);
    }

    protected function md5Sql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ !== null && $this_->type() === null) {
            $this->dialect->annotateTypes($this_);
        }

        if ($this_ !== null && $this_->isType(...DataType::TEXT_TYPES)) {
            $this_ = new Encode(['this' => $this_, 'charset' => Literal::string('utf-8')]);
        }

        return $this->func('LOWER', $this->func('TO_HEX', $this->func('MD5', $this->sql($this_))));
    }

    protected function sha2Sql(Expression $expression): string
    {
        $length = $expression->text('length');
        $length = $length !== '' ? $length : '256';

        if (! in_array($length, ['256', '512'], true)) {
            $this->unsupported("SHA{$length} is not supported in Presto");
        }

        $this_ = $expression->argExpr('this');

        if ($this_ !== null && $this_->isType(...DataType::TEXT_TYPES)) {
            $this_ = new Encode(['this' => $this_, 'charset' => Literal::string('utf-8')]);
        }

        return $this->func('LOWER', $this->func('TO_HEX', $this->func("SHA{$length}", $this->sql($this_))));
    }

    /**
     * Since TO_UNIXTIME requires a TIMESTAMP, we first try to DATE_PARSE the argument, falling
     * back to PARSE_DATETIME (Hive / Joda time mapping) when a timezone is involved.
     */
    protected function strtounixSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? Builder::null();
        $valueAsText = Builder::cast($this_, DType::TEXT);
        $valueAsTimestamp = $this_->isString() ? Builder::cast($this_, DType::TIMESTAMP) : $this_;

        $parseWithoutTz = $this->func('DATE_PARSE', $valueAsText, $this->formatTime($expression));

        $formattedValue = $this->func('DATE_FORMAT', $valueAsTimestamp, $this->formatTime($expression));
        $parseWithTz = $this->func(
            'PARSE_DATETIME',
            $formattedValue,
            $this->formatTime($expression, HiveDialectTables::INVERSE_TIME_MAPPING, HiveDialectTables::INVERSE_TIME_TRIE),
        );
        $coalesced = $this->func('COALESCE', $this->func('TRY', $parseWithoutTz), $parseWithTz);

        return $this->func('TO_UNIXTIME', $coalesced);
    }

    protected function bracketSql(Expression $expression): string
    {
        if ($expression->flag('safe')) {
            // sqlglot bracket_to_element_at_sql
            $this_ = $expression->argExpr('this');
            $offset = $expression->args['offset'] ?? 0;
            $index = $this_ !== null
                ? (Builder::applyIndexOffset($this_, $expression->argList('expressions'), 1 - (is_int($offset) ? $offset : 0), $this->dialect)[0] ?? null)
                : null;

            return $this->func('ELEMENT_AT', $this_, $index);
        }

        return parent::bracketSql($expression);
    }

    protected function structSql(Expression $expression): string
    {
        if ($expression->type() === null) {
            $this->dialect->annotateTypes($expression);
        }

        $values = [];
        $schema = [];
        $unknownType = false;

        foreach ($expression->argList('expressions') as $e) {
            if ($e instanceof PropertyEQ) {
                $type = $e->type();

                if ($type !== null && $type->isType(DType::UNKNOWN)) {
                    $unknownType = true;
                } else {
                    $schema[] = $this->sql($e, 'this').' '.$this->sql($type);
                }
                $values[] = $this->sql($e, 'expression');
            } else {
                $values[] = $this->sql($e);
            }
        }

        $size = count($expression->argList('expressions'));

        if ($size === 0 || count($schema) !== $size) {
            if ($unknownType) {
                $this->unsupported('Cannot convert untyped key-value definitions (try annotate_types).');
            }

            return $this->funcWith('ROW', $values);
        }

        return 'CAST(ROW('.implode(', ', $values).') AS ROW('.implode(', ', $schema).'))';
    }

    protected function intervalSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this');

        if ($this_ !== null && str_starts_with(strtoupper($expression->text('unit')), 'WEEK')) {
            return '('.$this_->name()." * INTERVAL '7' DAY)";
        }

        return parent::intervalSql($expression);
    }

    protected function transactionSql(Expression $expression): string
    {
        $modes = $expression->args['modes'] ?? null;
        $modes = is_array($modes) && $modes !== [] ? ' '.implode(', ', array_map(static fn (mixed $m): string => (string) $m, $modes)) : '';

        return "START TRANSACTION{$modes}";
    }

    protected function offsetLimitModifiers(Expression $expression, bool $fetch, ?Expression $limit): array
    {
        return [
            $this->sql($expression, 'offset'),
            $this->sql($limit),
        ];
    }

    /**
     * Presto doesn't support CREATE VIEW with expressions (ex: `CREATE VIEW x (cola)`), so we
     * need to remove them.
     */
    protected function createSql(Expression $expression): string
    {
        $kind = $expression->args['kind'] ?? null;
        $schema = $expression->argExpr('this');

        if ($kind === 'VIEW' && $schema !== null && $schema->argList('expressions') !== []) {
            $schema->set('expressions', null);
        }

        return parent::createSql($expression);
    }

    /**
     * Presto only supports DELETE FROM for a single table without an alias.
     */
    protected function deleteSql(Expression $expression): string
    {
        $tables = $expression->argList('tables');

        if ($tables === []) {
            $tables = [$expression->args['this'] ?? null];
        }

        if (count($tables) > 1) {
            return parent::deleteSql($expression);
        }

        $table = $tables[0];
        $expression->set('this', $table);
        $expression->set('tables', null);

        if ($table instanceof Table) {
            $tableAlias = $table->argExpr('alias');

            if ($tableAlias !== null) {
                $tableAlias->pop();
                $expression = $expression->transform(fn (Expression $n): Expression => $this->unqualifyColumns($n)) ?? $expression;
            }
        }

        return parent::deleteSql($expression);
    }

    protected function jsonextractSql(Expression $expression): string
    {
        // Generate JSON_EXTRACT unless a Snowflake / Databricks VARIANT extract should map to
        // dot notation (ROW access); the variant_extract_is_json_extract setting defaults to true
        return $this->func('JSON_EXTRACT', $expression->args['this'] ?? null, $expression->args['expression'] ?? null, ...$expression->argList('expressions'));
    }

    protected function groupconcatSql(Expression $expression): string
    {
        return $this->func('ARRAY_JOIN', $this->func('ARRAY_AGG', $expression->args['this'] ?? null), $expression->args['separator'] ?? null);
    }
}
