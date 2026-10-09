<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Snowflake;

use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayAgg;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayCompact;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\ConvertTimezone;
use LaravelSqlParser\LaravelSqlParser\Expressions\CopyGrantsProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Count;
use LaravelSqlParser\LaravelSqlParser\Expressions\CurrentTimestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateDiff;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateDateArray;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateSeries;
use LaravelSqlParser\LaravelSqlParser\Expressions\GroupConcat;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\IgnoreNulls;
use LaravelSqlParser\LaravelSqlParser\Expressions\ILike;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONValueArray;
use LaravelSqlParser\LaravelSqlParser\Expressions\Kwarg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lambda;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lateral;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Map;
use LaravelSqlParser\LaravelSqlParser\Expressions\MaterializedProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mode;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\ParseJSON;
use LaravelSqlParser\LaravelSqlParser\Expressions\PercentileCont;
use LaravelSqlParser\LaravelSqlParser\Expressions\PercentileDisc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pivot;
use LaravelSqlParser\LaravelSqlParser\Expressions\PivotAny;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Rand;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\RespectNulls;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\StarMap;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarMap;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithinGroup;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;
use SplObjectStorage;

/**
 * Snowflake generator (sqlglot generators/snowflake.py).
 */
class SnowflakeGenerator extends Generator
{
    public const string TABLES = SnowflakeGeneratorTables::class;

    /** TO_TIMESTAMP* function names by timestamp type. */
    private const array TIMESTAMP_TYPES = [
        DType::TIMESTAMP => 'TO_TIMESTAMP',
        DType::TIMESTAMPLTZ => 'TO_TIMESTAMP_LTZ',
        DType::TIMESTAMPNTZ => 'TO_TIMESTAMP_NTZ',
        DType::TIMESTAMPTZ => 'TO_TIMESTAMP_TZ',
    ];

    /** VALUES with these can't be a table in Snowflake. */
    private const array UNSUPPORTED_VALUES_EXPRESSIONS = [Map::class, StarMap::class, Struct::class, VarMap::class];

    private static function tryPrefix(Expression $e): string
    {
        return $e->flag('safe') ? 'TRY_' : '';
    }

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS lambdas and handlers
    // ---------------------------------------------------------------------------------------

    protected function transformArrayContains(Expression $e): string
    {
        $value = $e->args['expression'] ?? null;

        return $this->func(
            'ARRAY_CONTAINS',
            ($e->args['ensure_variant'] ?? null) === false ? $value : Builder::cast($value, DType::VARIANT, copy: false),
            $e->args['this'] ?? null,
        );
    }

    protected function transformArrayPosition(Expression $e): string
    {
        return $this->func('ARRAY_POSITION', $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformAtTimeZone(Expression $e): string
    {
        return $this->func('CONVERT_TIMEZONE', $e->args['zone'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformCurrentTimestamp(Expression $e): string
    {
        return $e->flag('sysdate') ? $this->func('SYSDATE') : $this->functionFallbackSql($e);
    }

    protected function transformCurrentSchemas(Expression $e): string
    {
        return $this->func('CURRENT_SCHEMAS');
    }

    protected function transformLocaltime(Expression $e): string
    {
        $this_ = $e->args['this'] ?? null;

        return $this->truthy($this_) ? $this->func('CURRENT_TIME', $this_) : 'CURRENT_TIME';
    }

    protected function transformLocaltimestamp(Expression $e): string
    {
        $this_ = $e->args['this'] ?? null;

        return $this->truthy($this_) ? $this->func('CURRENT_TIMESTAMP', $this_) : 'CURRENT_TIMESTAMP';
    }

    protected function transformDecrypt(Expression $e): string
    {
        return $this->func(self::tryPrefix($e).'DECRYPT', $e->args['this'] ?? null, $e->args['passphrase'] ?? null, $e->args['aad'] ?? null, $e->args['encryption_method'] ?? null);
    }

    protected function transformDecryptRaw(Expression $e): string
    {
        return $this->func(
            self::tryPrefix($e).'DECRYPT_RAW',
            $e->args['this'] ?? null, $e->args['key'] ?? null, $e->args['iv'] ?? null,
            $e->args['aad'] ?? null, $e->args['encryption_method'] ?? null, $e->args['aead'] ?? null,
        );
    }

    protected function transformExtract(Expression $e): string
    {
        return $this->func('DATE_PART', $this->mapDatePart($e->args['this'] ?? null), $e->args['expression'] ?? null);
    }

    protected function transformHandlerProperty(Expression $e): string
    {
        return 'HANDLER = '.$this->sql($e, 'this');
    }

    protected function transformFileFormatProperty(Expression $e): string
    {
        return 'FILE_FORMAT=('.$this->expressions($e, key: 'expressions', sep: ' ').')';
    }

    protected function transformFromTimeZone(Expression $e): string
    {
        return $this->func('CONVERT_TIMEZONE', $e->args['zone'] ?? null, "'UTC'", $e->args['this'] ?? null);
    }

    protected function transformGenerateSeries(Expression $e): string
    {
        $end = $e->args['end'] ?? null;

        return $this->func(
            'ARRAY_GENERATE_RANGE',
            $e->args['start'] ?? null,
            $e->flag('is_end_exclusive') ? $end : Builder::binop(Add::class, $end, Literal::number(1)),
            $e->args['step'] ?? null,
        );
    }

    protected function transformGroupConcat(Expression $e): string
    {
        return $this->groupconcatSqlHandler($e, sep: '');
    }

    protected function transformJSONArray(Expression $e): string
    {
        return $this->func('TO_VARIANT', $this->funcWith('ARRAY_CONSTRUCT', $e->argList('expressions')));
    }

    protected function jsonExtractValueArraySql(Expression $expression): string
    {
        $jsonExtract = new JSONExtract(['this' => $expression->args['this'] ?? null, 'expression' => $expression->args['expression'] ?? null]);
        $ident = Builder::toIdentifier('x');
        $this_ = $expression instanceof JSONValueArray
            ? Builder::cast($ident, DType::VARCHAR)
            : new ParseJSON(['this' => 'TO_JSON('.$this->sql($ident).')']);

        return $this->func('TRANSFORM', $jsonExtract, new Lambda(['expressions' => [$ident], 'this' => $this_]));
    }

    protected function transformJSONExtractScalar(Expression $e): string
    {
        return $this->func('JSON_EXTRACT_PATH_TEXT', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformJSONObject(Expression $e): string
    {
        return $this->funcWith('OBJECT_CONSTRUCT_KEEP_NULL', $e->argList('expressions'));
    }

    protected function transformJSONPathRoot(Expression $e): string
    {
        return '';
    }

    protected function transformLocationProperty(Expression $e): string
    {
        return 'LOCATION='.$this->sql($e, 'this');
    }

    protected function transformMap(Expression $e): string
    {
        return $this->varMapSql($e, 'OBJECT_CONSTRUCT');
    }

    protected function transformVarMap(Expression $e): string
    {
        return $this->varMapSql($e, 'OBJECT_CONSTRUCT');
    }

    protected function transformParseJSON(Expression $e): string
    {
        return $this->func(self::tryPrefix($e).'PARSE_JSON', $e->args['this'] ?? null);
    }

    protected function transformToBinary(Expression $e): string
    {
        return $this->func(self::tryPrefix($e).'TO_BINARY', $e->args['this'] ?? null, $e->args['format'] ?? null);
    }

    protected function transformToBoolean(Expression $e): string
    {
        return $this->func(self::tryPrefix($e).'TO_BOOLEAN', $e->args['this'] ?? null);
    }

    protected function transformToDouble(Expression $e): string
    {
        return $this->func(self::tryPrefix($e).'TO_DOUBLE', $e->args['this'] ?? null, $e->args['format'] ?? null);
    }

    protected function transformToFile(Expression $e): string
    {
        return $this->func(self::tryPrefix($e).'TO_FILE', $e->args['this'] ?? null, $e->args['path'] ?? null);
    }

    protected function transformPartitionedByProperty(Expression $e): string
    {
        return 'PARTITION BY '.$this->sql($e, 'this');
    }

    protected function transformRowAccessProperty(Expression $e): string
    {
        return $this->rowaccesspropertySql($e);
    }

    protected function transformRand(Expression $e): string
    {
        return $this->func('RANDOM', $e->args['this'] ?? null);
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'CHARINDEX', supportsPosition: true);
    }

    protected function transformStrToDate(Expression $e): string
    {
        return $this->func('DATE', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformTimeSlice(Expression $e): string
    {
        return $this->func('TIME_SLICE', $e->args['this'] ?? null, $e->args['expression'] ?? null, Builder::unitToStr($e), $e->args['kind'] ?? null);
    }

    protected function transformTimestampDiff(Expression $e): string
    {
        return $this->func('TIMESTAMPDIFF', $e->args['unit'] ?? null, $e->args['expression'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformTimeToUnix(Expression $e): string
    {
        return 'EXTRACT(epoch_second FROM '.$this->sql($e, 'this').')';
    }

    protected function transformToChar(Expression $e): string
    {
        return $this->functionFallbackSql($e);
    }

    protected function transformTsOrDsToDate(Expression $e): string
    {
        return $this->func(self::tryPrefix($e).'TO_DATE', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformTsOrDsToTime(Expression $e): string
    {
        return $this->func(self::tryPrefix($e).'TO_TIME', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformUnixToTime(Expression $e): string
    {
        return $this->func('TO_TIMESTAMP', $e->args['this'] ?? null, $e->args['scale'] ?? null);
    }

    protected function transformArrayConcatAgg(Expression $e): string
    {
        return $this->func('ARRAY_FLATTEN', new ArrayAgg(['this' => $e->args['this'] ?? null]));
    }

    protected function transformSHA2Digest(Expression $e): string
    {
        $length = $e->args['length'] ?? null;

        return $this->func('SHA2_BINARY', $e->args['this'] ?? null, $this->truthy($length) ? $length : Literal::number(256));
    }

    protected function regexpilikeSqlHandler(Expression $expression): string
    {
        $flag = $expression->text('flag');

        if (! str_contains($flag, 'i')) {
            $flag .= 'i';
        }

        return $this->func('REGEXP_LIKE', $expression->args['this'] ?? null, $expression->args['expression'] ?? null, Literal::string($flag));
    }

    /**
     * Other dialects don't support all of these parameters, so defaults are generated as needed
     * to keep the transpilation correct.
     */
    protected function regexpextractSqlSnowflake(Expression $expression): string
    {
        $group = $expression->args['group'] ?? null;

        // A group of 0 is the default, and needs none of the following defaults
        if ($group instanceof Expression && $group->name() === '0') {
            $group = null;
        }

        $parameters = $expression->args['parameters'] ?? null;
        $parameters = $this->truthy($parameters) ? $parameters : ($this->truthy($group) ? Literal::string('c') : null);
        $occurrence = $expression->args['occurrence'] ?? null;
        $occurrence = $this->truthy($occurrence) ? $occurrence : ($this->truthy($parameters) ? Literal::number(1) : null);
        $position = $expression->args['position'] ?? null;
        $position = $this->truthy($position) ? $position : ($this->truthy($occurrence) ? Literal::number(1) : null);

        return $this->func(
            $expression instanceof RegexpExtract ? 'REGEXP_SUBSTR' : 'REGEXP_SUBSTR_ALL',
            $expression->args['this'] ?? null,
            $expression->args['expression'] ?? null,
            $position,
            $occurrence,
            $parameters,
            $group,
        );
    }

    // ---------------------------------------------------------------------------------------
    // Transforms
    // ---------------------------------------------------------------------------------------

    /**
     * Snowflake doesn't allow qualified columns in UNPIVOT, nor in ANY ORDER BY <column>.
     */
    protected function unqualifyPivotColumns(Expression $expression): Expression
    {
        if (! $expression instanceof Pivot) {
            return $expression;
        }

        if ($expression->flag('unpivot')) {
            return $this->unqualifyColumns($expression);
        }

        foreach ($expression->argList('fields') as $field) {
            $fieldExpr = $field->argList('expressions')[0] ?? null;

            if ($fieldExpr instanceof PivotAny) {
                $expressions = $field->argList('expressions');
                $expressions[0] = $this->unqualifyColumns($fieldExpr);
                $field->set('expressions', $expressions);
            }
        }

        return $expression;
    }

    protected function transformGenerateDateArray(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        foreach ($expression->findAll(GenerateDateArray::class) as $generateDateArray) {
            $parent = $generateDateArray->parent;

            // GENERATE_DATE_ARRAY used directly as an array (e.g. in ARRAY_LENGTH) becomes
            // ref((SELECT ARRAY_AGG(*) FROM UNNEST(GENERATE_DATE_ARRAY(...)))), unnested properly
            // on the next iteration thanks to the copy
            if (! $parent instanceof Unnest) {
                $unnest = new Unnest(['expressions' => [$generateDateArray->copy()]]);
                $generateDateArray->replace(new Subquery([
                    'this' => new Select(['expressions' => [new ArrayAgg(['this' => new Star])], 'from_' => new From(['this' => $unnest])]),
                ]));
            }

            if ($parent instanceof Unnest && ($parent->parent instanceof From || $parent->parent instanceof Join) && count($parent->argList('expressions')) === 1) {
                $this->unnestGenerateDateArray($parent);
            }
        }

        return $expression;
    }

    private function unnestGenerateDateArray(Unnest $unnest): void
    {
        $generateDateArray = $unnest->argList('expressions')[0];
        $start = $generateDateArray->args['start'] ?? null;
        $end = $generateDateArray->args['end'] ?? null;
        $step = $generateDateArray->args['step'] ?? null;

        if (! $start instanceof Expression || ! $end instanceof Expression || ! $step instanceof Interval || $step->name() !== '1') {
            return;
        }

        $unit = $step->args['unit'] ?? null;
        $unnestAlias = $unnest->args['alias'] ?? null;

        if ($unnestAlias instanceof Expression) {
            $unnestAlias = $unnestAlias->copy();
            $sequenceValueName = $unnestAlias->argList('columns')[0] ?? 'value';
        } else {
            $sequenceValueName = 'value';
        }

        $sequenceName = $sequenceValueName instanceof Expression ? $sequenceValueName->name() : $sequenceValueName;

        // The next sequence value is added to the start date and projected
        $dateAdd = new DateAdd([
            'this' => Builder::cast($start, 'date'),
            'expression' => Builder::cast($sequenceValueName instanceof Expression ? $sequenceValueName->copy() : Builder::column($sequenceName), 'int'),
            'unit' => $this->mapDatePart($unit instanceof Expression ? $unit : null),
        ]);

        // DATEDIFF counts the sequence values needed
        $dateDiff = new DateDiff(['this' => $end, 'expression' => $start->copy(), 'unit' => $this->mapDatePart($unit instanceof Expression ? $unit->copy() : null), 'date_part_boundary' => true]);
        $numberSequence = new GenerateSeries([
            'start' => Literal::number(0),
            'end' => Builder::binop(Add::class, $dateDiff, Literal::number(1)),
            'is_end_exclusive' => true,
        ]);

        $unnest->set('expressions', [$numberSequence]);
        $unnestParent = $unnest->parent;

        if ($unnestParent instanceof Join) {
            $select = $unnestParent->parent;

            if ($select instanceof Select) {
                $scope = Scope::build($select);

                foreach ($scope?->columns() ?? [] as $column) {
                    if (strtolower($column->name()) === strtolower($sequenceName)) {
                        $column->replace($column->parent instanceof Select ? Builder::alias($dateAdd->copy(), $sequenceName) : $dateAdd->copy());
                    }
                }

                $inner = $unnestParent->args['this'] ?? null;
                $lateral = new Lateral(['this' => $inner instanceof Expression ? $inner->pop() : null]);
                $unnestParent->replace(new Join(['this' => $lateral]));
            }
        } else {
            $unnest->replace(new Subquery([
                'this' => new Select(['expressions' => [Builder::alias($dateAdd, $sequenceName)], 'from_' => new From(['this' => $unnest->copy()])]),
                'alias' => $unnestAlias,
            ]));
        }
    }

    protected function qualifyUnnestedColumns(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $scope = Scope::build($expression);

        if ($scope === null) {
            return $expression;
        }

        $unnests = array_values(array_filter(Scope::walkInScope($scope->expression), static fn (Expression $n): bool => $n instanceof Unnest));

        if ($unnests === []) {
            return $expression;
        }

        $takenSourceNames = array_fill_keys(array_map('strval', array_keys($scope->sources)), true);
        $columnSource = [];
        $unnestToIdentifier = new SplObjectStorage;
        $unnestIdentifier = null;
        $origExpression = $expression->copy();

        foreach ($unnests as $unnest) {
            if (! $unnest->parent instanceof From && ! $unnest->parent instanceof Join) {
                continue;
            }

            // The column names an unnest produces can be inferred when its value is statically known
            $unnestColumns = [];

            foreach ($unnest->argList('expressions') as $unnestExpr) {
                if (! $unnestExpr instanceof ArrayExpr) {
                    continue;
                }

                foreach ($unnestExpr->argList('expressions') as $arrayExpr) {
                    $fields = $arrayExpr instanceof Struct ? $arrayExpr->argList('expressions') : [];

                    if ($fields === [] || array_filter($fields, static fn (Expression $f): bool => ! $f instanceof PropertyEQ) !== []) {
                        continue;
                    }

                    foreach ($fields as $field) {
                        $unnestColumns[strtolower($field->args['this']->name())] = true;
                    }

                    break;
                }

                if ($unnestColumns !== []) {
                    break;
                }
            }

            $unnestAlias = $unnest->args['alias'] ?? null;

            if (! $unnestAlias instanceof Expression) {
                $aliasName = self::findNewName(array_keys($takenSourceNames), 'value');
                $takenSourceNames[$aliasName] = true;

                // A TableAlias like BigQuery's; it's corrected when the Unnest is generated
                $aliasedUnnest = Builder::alias($unnest->copy(), null, table: [$aliasName]);
                $scope->replace($unnest, $aliasedUnnest);
                $unnest = $aliasedUnnest;
                $unnestIdentifier = $aliasedUnnest->args['alias']->argList('columns')[0] ?? null;
            } else {
                $unnestIdentifier = $unnestAlias->args['this'] ?? ($unnestAlias->argList('columns')[0] ?? null);
            }

            if (! $unnestIdentifier instanceof Identifier) {
                return $origExpression;
            }

            $unnestToIdentifier[$unnest] = $unnestIdentifier;

            foreach (array_keys($unnestColumns) as $name) {
                $columnSource[$name] = $unnestIdentifier;
            }
        }

        foreach ($scope->columns() as $column) {
            if ($column->text('table') !== '') {
                continue;
            }

            $table = $columnSource[strtolower($column->name())] ?? null;

            if (
                $unnestIdentifier !== null
                && $table === null
                && count($scope->sources) === 1
                && strtolower($column->name()) !== strtolower($unnestIdentifier->name())
            ) {
                $unnestAncestor = $column->findAncestor(Unnest::class, Select::class);

                if ($unnestAncestor instanceof Unnest && isset($unnestToIdentifier[$unnestAncestor])) {
                    $ancestorIdentifier = $unnestToIdentifier[$unnestAncestor];

                    if (strtolower($ancestorIdentifier->name()) === strtolower($unnestIdentifier->name())) {
                        continue;
                    }
                }

                $table = $unnestIdentifier;
            }

            $column->set('table', $table?->copy());
        }

        return $expression;
    }

    /**
     * BigQuery UNNEST aliases (a TableAlias with only one column) become bracket lookups. Unnest
     * nodes can't come from Snowflake's parser, so this doesn't affect round trips.
     */
    protected function eliminateDotVariantLookup(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $unnestAliases = [];

        foreach (Scope::findAllInScope($expression, Unnest::class) as $unnest) {
            $alias = $unnest->args['alias'] ?? null;

            if ($alias instanceof TableAlias && ! $this->truthy($alias->args['this'] ?? null) && count($alias->argList('columns')) === 1) {
                $unnestAliases[$alias->argList('columns')[0]->name()] = true;
            }
        }

        if ($unnestAliases === []) {
            return $expression;
        }

        foreach (Scope::findAllInScope($expression, Column::class) as $column) {
            if (! isset($unnestAliases[$column->text('table')])) {
                continue;
            }

            $bracket = new Bracket(['this' => $column->args['table'], 'expressions' => [Literal::string($column->name())]]);

            if ($column->parent === $expression) {
                // Projection names are kept with aliases
                $column->replace(Builder::alias($bracket, $column->args['this']->copy()));
            } else {
                $column->replace($bracket);
            }
        }

        return $expression;
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function dynamicidentifierSql(Expression $expression): string
    {
        $this_ = $this->func('IDENTIFIER', $expression->args['this'] ?? null);

        // IDENTIFIER(...) invoked as a function, e.g. IDENTIFIER('my_func')(1, 2)
        if (array_key_exists('expressions', $expression->args)) {
            return $this->funcWith($this_, $expression->argList('expressions'), normalize: false);
        }

        return $this_;
    }

    protected function sortarraySql(Expression $expression): string
    {
        $asc = $expression->args['asc'] ?? null;
        $nullsFirst = $expression->args['nulls_first'] ?? null;

        if ($asc instanceof Boolean && $asc->equals(Builder::false()) && $nullsFirst instanceof Boolean && $nullsFirst->equals(Builder::true())) {
            $nullsFirst = null;
        }

        return $this->func('ARRAY_SORT', $expression->args['this'] ?? null, $asc, $nullsFirst);
    }

    protected function withProperties(Properties $properties): string
    {
        return $this->properties($properties, prefix: $this->sep(''), sep: ' ', wrapped: false);
    }

    protected function valuesSql(Expression $expression, bool $valuesAsTable = true): string
    {
        if ($expression->find(...self::UNSUPPORTED_VALUES_EXPRESSIONS) !== null) {
            $valuesAsTable = false;
        }

        return parent::valuesSql($expression, valuesAsTable: $valuesAsTable);
    }

    protected function datatypeSql(DataType $expression): string
    {
        // VECTOR only takes FLOAT (not its synonym DOUBLE), INT and STRING elements
        // https://docs.snowflake.com/en/sql-reference/data-types-vector
        if ($expression->isType(DType::DOUBLE) && $expression->parent instanceof DataType && $expression->parent->isType(DType::VECTOR)) {
            return 'FLOAT';
        }

        if ($expression->argList('expressions') !== [] && $expression->isType(...DataType::STRUCT_TYPES)) {
            foreach ($expression->argList('expressions') as $fieldType) {
                // OBJECT [ (<key> <value_type> [NOT NULL] [, ...]) ]
                if ($fieldType instanceof DataType) {
                    return 'OBJECT';
                }

                $key = $fieldType->args['this'] ?? null;

                // OBJECT('foo' VARCHAR) is invalid: the key becomes a quoted identifier, quoted
                // because keys are case-sensitive
                if ($fieldType instanceof ColumnDef && $key instanceof Expression && $key->isString()) {
                    $key->replace(Builder::toIdentifier($fieldType->name(), quoted: true));
                }
            }
        }

        return parent::datatypeSql($expression);
    }

    protected function tonumberSql(Expression $expression): string
    {
        $precision = $expression->args['precision'] ?? null;
        $scale = $expression->args['scale'] ?? null;
        $defaultPrecision = $precision instanceof Literal && $precision->name() === '38';
        $defaultScale = $scale instanceof Literal && $scale->name() === '0';

        if ($defaultPrecision && $defaultScale) {
            $precision = null;
            $scale = null;
        } elseif ($defaultScale) {
            $scale = null;
        }

        return $this->func($expression->flag('safe') ? 'TRY_TO_NUMBER' : 'TO_NUMBER', $expression->args['this'] ?? null, $expression->args['format'] ?? null, $precision, $scale);
    }

    protected function timestampfrompartsSql(Expression $expression): string
    {
        $milli = $expression->args['milli'] ?? null;

        if ($milli instanceof Expression) {
            $expression->set('nano', Builder::binop(Mul::class, $milli->pop(), Literal::number(1000000)));
        }

        return $this->renameFunc($expression, 'TIMESTAMP_FROM_PARTS');
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        if ($expression->isType(DType::GEOGRAPHY)) {
            return $this->func('TO_GEOGRAPHY', $expression->args['this'] ?? null);
        }

        if ($expression->isType(DType::GEOMETRY)) {
            return $this->func('TO_GEOMETRY', $expression->args['this'] ?? null);
        }

        return parent::castSql($expression, safePrefix: $safePrefix);
    }

    protected function trycastSql(Expression $expression): string
    {
        $value = $expression->args['this'] ?? null;

        if ($value instanceof Expression && $value->type() === null) {
            $this->dialect->annotateTypes($value);
        }

        // Snowflake's TRY_CAST takes a string, so TRY_CAST is kept when round-tripping (only
        // Snowflake sets requires_string) or when the value is known to be a string
        if ($expression->flag('requires_string') || ($value instanceof Expression && $value->isType(...DataType::TEXT_TYPES))) {
            return parent::trycastSql($expression);
        }

        return $this->castSql($expression);
    }

    protected function logSql(Expression $expression): string
    {
        if (! $this->truthy($expression->args['expression'] ?? null)) {
            return $this->func('LN', $expression->args['this'] ?? null);
        }

        return parent::logSql($expression);
    }

    protected function greatestSql(Expression $expression): string
    {
        return $this->funcWith($expression->flag('ignore_nulls') ? 'GREATEST_IGNORE_NULLS' : 'GREATEST', [$expression->args['this'] ?? null, ...$expression->argList('expressions')]);
    }

    protected function leastSql(Expression $expression): string
    {
        return $this->funcWith($expression->flag('ignore_nulls') ? 'LEAST_IGNORE_NULLS' : 'LEAST', [$expression->args['this'] ?? null, ...$expression->argList('expressions')]);
    }

    protected function generatorSql(Expression $expression): string
    {
        $args = [];

        foreach (['rowcount' => 'ROWCOUNT', 'timelimit' => 'TIMELIMIT'] as $key => $name) {
            $value = $expression->args[$key] ?? null;

            if ($this->truthy($value)) {
                $args[] = new Kwarg(['this' => Builder::var($name), 'expression' => $value]);
            }
        }

        return $this->funcWith('GENERATOR', $args);
    }

    protected function unnestSql(Expression $expression): string
    {
        $unnestAlias = $expression->args['alias'] ?? null;
        $offset = $expression->args['offset'] ?? null;
        $aliasColumns = $unnestAlias instanceof Expression ? $unnestAlias->argList('columns') : [];
        $value = $aliasColumns[0] ?? Builder::toIdentifier('value');

        $columns = [
            Builder::toIdentifier('seq'),
            Builder::toIdentifier('key'),
            Builder::toIdentifier('path'),
            $offset instanceof Expression ? $offset->pop() : Builder::toIdentifier('index'),
            $value,
            Builder::toIdentifier('this'),
        ];

        if ($unnestAlias instanceof Expression) {
            $unnestAlias->set('columns', $columns);
        } else {
            $unnestAlias = new TableAlias(['this' => '_u', 'columns' => $columns]);
        }

        $tableInput = $this->sql($expression->argList('expressions')[0] ?? null);

        if (! str_starts_with($tableInput, 'INPUT =>')) {
            $tableInput = "INPUT => {$tableInput}";
        }

        $parent = $expression->parent;
        $explode = $parent instanceof Lateral ? "FLATTEN({$tableInput})" : "TABLE(FLATTEN({$tableInput}))";
        $alias = $this->sql($unnestAlias);
        $alias = $alias !== '' ? " AS {$alias}" : '';
        $valueSql = $parent instanceof From || $parent instanceof Join || $parent instanceof Lateral ? '' : $this->sql($value).' FROM ';

        return "{$valueSql}{$explode}{$alias}";
    }

    protected function undropSql(Expression $expression): string
    {
        $rename = $this->sql($expression, 'rename');
        $rename = $rename !== '' ? " RENAME TO {$rename}" : '';

        return 'UNDROP '.$expression->text('kind').' '.$this->sql($expression, 'this').$rename;
    }

    protected function showSql(Expression $expression): string
    {
        $terse = $expression->flag('terse') ? 'TERSE ' : '';
        $iceberg = $expression->flag('iceberg') ? 'ICEBERG ' : '';
        $history = $expression->flag('history') ? ' HISTORY' : '';
        $like = $this->sql($expression, 'like');
        $like = $like !== '' ? " LIKE {$like}" : '';
        $scope = $this->sql($expression, 'scope');
        $scope = $scope !== '' ? " {$scope}" : '';
        $scopeKind = $this->sql($expression, 'scope_kind');
        $scopeKind = $scopeKind !== '' ? " IN {$scopeKind}" : '';
        $startsWith = $this->sql($expression, 'starts_with');
        $startsWith = $startsWith !== '' ? " STARTS WITH {$startsWith}" : '';
        $limit = $this->sql($expression, 'limit');
        $from = $this->sql($expression, 'from_');
        $from = $from !== '' ? " FROM {$from}" : '';
        $privileges = $this->expressions($expression, key: 'privileges', flat: true);
        $privileges = $privileges !== '' ? " WITH PRIVILEGES {$privileges}" : '';

        return "SHOW {$terse}{$iceberg}".$expression->name()."{$history}{$like}{$scopeKind}{$scope}{$startsWith}{$limit}{$from}{$privileges}";
    }

    public function rowaccesspropertySql(Expression $expression): string
    {
        if (! $this->truthy($expression->args['this'] ?? null)) {
            return 'ROW ACCESS';
        }

        $on = $expression->argList('expressions') !== [] ? ' ON ('.$this->expressions($expression, flat: true).')' : '';

        return 'WITH ROW ACCESS POLICY '.$this->sql($expression, 'this').$on;
    }

    protected function describeSql(Expression $expression): string
    {
        $kindValue = $expression->args['kind'] ?? null;
        $kindValue = $this->truthy($kindValue) ? (is_string($kindValue) ? $kindValue : $this->sql($kindValue)) : 'TABLE';
        $properties = $expression->args['properties'] ?? null;
        $kind = $properties instanceof Expression ? ' '.$this->expressions($properties, sep: ' ')." {$kindValue}" : " {$kindValue}";
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? " {$expressions}" : '';

        return "DESCRIBE{$kind} ".$this->sql($expression, 'this').$expressions;
    }

    protected function generatedasidentitycolumnconstraintSql(Expression $expression): string
    {
        $start = $expression->args['start'] ?? null;
        $start = $this->truthy($start) ? ' START '.$this->sql($start) : '';
        $increment = $expression->args['increment'] ?? null;
        $increment = $this->truthy($increment) ? ' INCREMENT '.$this->sql($increment) : '';
        $order = $expression->args['order'] ?? null;
        $orderClause = $order !== null ? ($this->truthy($order) ? ' ORDER' : ' NOORDER') : '';

        return "AUTOINCREMENT{$start}{$increment}{$orderClause}";
    }

    protected function structSql(Expression $expression): string
    {
        $expressions = $expression->argList('expressions');

        if (count($expressions) === 1) {
            $arg = $expressions[0];
            $left = $arg->args['this'] ?? null;

            // Wildcard syntax: https://docs.snowflake.com/en/sql-reference/data-types-semistructured#object
            if ($arg->isStar() || ($arg instanceof ILike && $left instanceof Expression && $left->isStar())) {
                return '{'.$this->sql($arg).'}';
            }
        }

        $args = [];

        foreach ($expressions as $i => $e) {
            if ($e instanceof PropertyEQ) {
                $key = $e->args['this'] ?? null;
                $args[] = $key instanceof Identifier ? Literal::string($e->name()) : $key;
                $args[] = $e->args['expression'] ?? null;
            } else {
                $args[] = Literal::string("_{$i}");
                $args[] = $e;
            }
        }

        return $this->funcWith('OBJECT_CONSTRUCT', $args);
    }

    protected function approxquantileSql(Expression $expression): string
    {
        foreach (['weight', 'accuracy'] as $arg) {
            if ($expression->flag($arg)) {
                $this->unsupported("Argument '{$arg}' is not supported for expression 'ApproxQuantile' when targeting Snowflake.");
            }
        }

        return $this->func('APPROX_PERCENTILE', $expression->args['this'] ?? null, $expression->args['quantile'] ?? null);
    }

    protected function altersetSql(Expression $expression): string
    {
        $exprs = $this->expressions($expression, flat: true);
        $exprs = $exprs !== '' ? " {$exprs}" : '';
        $fileFormat = $this->expressions($expression, key: 'file_format', flat: true, sep: ' ');
        $fileFormat = $fileFormat !== '' ? " STAGE_FILE_FORMAT = ({$fileFormat})" : '';
        $copyOptions = $this->expressions($expression, key: 'copy_options', flat: true, sep: ' ');
        $copyOptions = $copyOptions !== '' ? " STAGE_COPY_OPTIONS = ({$copyOptions})" : '';
        $tag = $this->expressions($expression, key: 'tag', flat: true);
        $tag = $tag !== '' ? " TAG {$tag}" : '';

        return "SET{$exprs}{$fileFormat}{$copyOptions}{$tag}";
    }

    protected function strtotimeSql(Expression $expression): string
    {
        $targetType = $expression->args['target_type'] ?? null;

        if ($targetType instanceof DataType) {
            $typeName = (string) ($targetType->args['this'] ?? DType::TIMESTAMP);
        } elseif ($expression->type() !== null) {
            $typeName = (string) ($expression->type()->args['this'] ?? DType::TIMESTAMP);
        } else {
            $typeName = DType::TIMESTAMP;
        }

        $funcName = self::TIMESTAMP_TYPES[$typeName] ?? 'TO_TIMESTAMP';

        return $this->func(self::tryPrefix($expression).$funcName, $expression->args['this'] ?? null, $this->formatTime($expression));
    }

    protected function timestampsubSql(Expression $expression): string
    {
        return $this->sql(new TimestampAdd([
            'this' => $expression->args['this'] ?? null,
            'expression' => Builder::binop(Mul::class, $expression->args['expression'] ?? null, Literal::number(-1)),
            'unit' => $expression->args['unit'] ?? null,
        ]));
    }

    protected function jsonextractSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        // JSON strings from other dialects such as BigQuery are parsed preemptively
        if (! $this_ instanceof ParseJSON && ! $this_ instanceof JSONExtract && ! $expression->flag('requires_json')) {
            $this_ = new ParseJSON(['this' => $this_]);
        }

        return $this->func('GET_PATH', $this_, $expression->args['expression'] ?? null);
    }

    protected function timetostrSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Expression && $this_->isString()) {
            $this_ = Builder::cast($this_, DType::TIMESTAMP);
        }

        return $this->func('TO_CHAR', $this_, $this->formatTime($expression));
    }

    protected function datesubSql(Expression $expression): string
    {
        $value = $expression->args['expression'] ?? null;

        if ($value instanceof Expression) {
            $value->replace(Builder::binop(Mul::class, $value, Literal::number(-1)));
        } else {
            $this->unsupported('DateSub cannot be transpiled if the subtracted count is unknown');
        }

        return $this->dateDeltaSql($expression, 'DATEADD');
    }

    protected function selectSql(Select $expression): string
    {
        if ($this->truthy($expression->args['offset'] ?? null) && ! $this->truthy($expression->args['limit'] ?? null)) {
            $expression->set('limit', new Limit(['expression' => new NullExpr]));
        }

        return parent::selectSql($expression);
    }

    protected function createableSql(Expression $expression, array &$locations): string
    {
        $isMaterialized = $expression->find(MaterializedProperty::class);
        $copyGrants = $expression->find(CopyGrantsProperty::class);

        if ($expression->text('kind') === 'VIEW' && $isMaterialized !== null && $copyGrants !== null) {
            // COPY GRANTS comes before the column list for materialized views, after it for others
            // https://docs.snowflake.com/en/sql-reference/sql/create-materialized-view#syntax
            // The property moves out of POST_SCHEMA, which the caller still generates
            $locations['POST_SCHEMA'] = array_values(array_filter($locations['POST_SCHEMA'] ?? [], static fn (Expression $p): bool => $p !== $copyGrants));
            $schema = $expression->args['this'] ?? null;
            $thisName = $this->sql($schema, 'this');
            $thisSchema = $schema instanceof Expression ? $this->schemaColumnsSql($schema) : '';
            $thisSchema = $thisSchema !== '' ? $this->sep().$thisSchema : '';

            return $thisName.$this->sep().$this->sql($copyGrants).$thisSchema;
        }

        return parent::createableSql($expression, $locations);
    }

    protected function arrayaggSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        // ORDER BY moves from ARRAY_AGG into a WITHIN GROUP clause
        $order = $this_ instanceof Order ? $this_ : null;

        if ($order !== null && ($order->args['this'] ?? null) instanceof Expression) {
            $expression->set('this', $order->args['this']->pop());
        }

        $sql = parent::arrayaggSql($expression);

        return $order !== null ? $this->sql(new WithinGroup(['this' => $sql, 'expression' => $order])) : $sql;
    }

    protected function arraydistinctSql(Expression $expression): string
    {
        if ($expression->flag('check_null')) {
            return $this->func('ARRAY_DISTINCT', $expression->args['this'] ?? null);
        }

        return $this->func('ARRAY_DISTINCT', new ArrayCompact(['this' => $expression->args['this'] ?? null]));
    }

    protected function arraytostringSql(Expression $expression): string
    {
        return $this->func('ARRAY_TO_STRING', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
    }

    protected function arraySql(Expression $expression): string
    {
        $first = $expression->argList('expressions')[0] ?? null;

        // SELECT AS STRUCT foo AS alias_foo -> ARRAY_AGG(OBJECT_CONSTRUCT('alias_foo', foo))
        if ($first instanceof Select && strtoupper($first->text('kind')) === 'STRUCT') {
            $args = [];

            foreach ($first->argList('expressions') as $projection) {
                $args[] = Literal::string($projection->aliasOrName());
                $args[] = $projection instanceof Alias ? ($projection->args['this'] ?? null) : $projection;
            }

            $first->set('kind', null);
            $first->set('expressions', [new ArrayAgg(['this' => $this->objectConstruct($args)])]);

            return $this->sql(new Subquery(['this' => $first]));
        }

        return $this->inlineArraySql($expression);
    }

    /**
     * sqlglot build_object_construct: OBJECT_CONSTRUCT(k1, v1, ...) as a Struct of k => v pairs.
     *
     * @param  list<mixed>  $args
     */
    private function objectConstruct(array $args): Expression
    {
        $pairs = [];

        for ($i = 0; $i + 1 < count($args); $i += 2) {
            $pairs[] = new PropertyEQ(['this' => $args[$i], 'expression' => $args[$i + 1]]);
        }

        return new Struct(['expressions' => $pairs]);
    }

    protected function currentdateSql(Expression $expression): string
    {
        $zone = $this->sql($expression, 'this');

        if ($zone === '') {
            return parent::currentdateSql($expression);
        }

        return $this->sql(new Cast([
            'this' => new ConvertTimezone(['target_tz' => $expression->args['this'], 'timestamp' => new CurrentTimestamp]),
            'to' => new DataType(['this' => DType::DATE]),
        ]));
    }

    protected function dotSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Expression && $this_->type() === null) {
            $this->dialect->annotateTypes($this_);
        }

        // Colon notation for a top-level STRUCT
        if ($this_ instanceof Expression && ! $this_ instanceof Dot && $this_->isType(DType::STRUCT)) {
            return $this->sql($this_).':'.$this->sql($expression, 'expression');
        }

        return parent::dotSql($expression);
    }

    protected function modelattributeSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').'!'.$this->sql($expression, 'expression');
    }

    protected function formatSql(Expression $expression): string
    {
        $expressions = $expression->argList('expressions');

        if (strtolower($expression->name()) === '%s' && count($expressions) === 1) {
            return $this->func('TO_CHAR', $expressions[0]);
        }

        return $this->functionFallbackSql($expression);
    }

    protected function splitpartSql(Expression $expression): string
    {
        if (! $this->truthy($expression->args['delimiter'] ?? null)) {
            $expression->set('delimiter', Literal::string(' '));
        }

        if (! $this->truthy($expression->args['part_index'] ?? null)) {
            $expression->set('part_index', Literal::number(1));
        }

        return $this->renameFunc($expression, 'SPLIT_PART');
    }

    protected function uniformSql(Expression $expression): string
    {
        $gen = $expression->args['gen'] ?? null;
        $seed = $expression->args['seed'] ?? null;

        // Databricks' UNIFORM(min, max, seed) wraps the seed in RANDOM(seed)
        if ($this->truthy($seed)) {
            $gen = new Rand(['this' => $seed]);
        }

        // Databricks' 2-argument UNIFORM(min, max) gets RANDOM()
        if (! $this->truthy($gen)) {
            $gen = new Rand;
        }

        return $this->func('UNIFORM', $expression->args['this'] ?? null, $expression->args['expression'] ?? null, $gen);
    }

    protected function windowSql(Expression $expression): string
    {
        $spec = $expression->args['spec'] ?? null;
        $this_ = $expression->args['this'] ?? null;
        $inner = ($this_ instanceof RespectNulls || $this_ instanceof IgnoreNulls) ? ($this_->args['this'] ?? null) : $this_;
        $isRanking = false;

        foreach (SnowflakeParser::RANKING_WINDOW_FUNCTIONS_WITH_FRAME as $class) {
            if ($inner instanceof $class) {
                $isRanking = true;
            }
        }

        // The default window of ranking window functions is omitted
        if (
            $isRanking
            && $spec instanceof Expression
            && strtoupper($spec->text('kind')) === 'ROWS'
            && strtoupper($spec->text('start')) === 'UNBOUNDED'
            && strtoupper($spec->text('start_side')) === 'PRECEDING'
            && strtoupper($spec->text('end')) === 'UNBOUNDED'
            && strtoupper($spec->text('end_side')) === 'FOLLOWING'
        ) {
            $expression->set('spec', null);
        }

        return parent::windowSql($expression);
    }

    /**
     * Snowflake doesn't support FILTER (WHERE cond): it becomes conditional aggregation, with the
     * input values wrapped in an IFF.
     */
    protected function filterSql(Expression $expression): string
    {
        $agg = $expression->args['this'] ?? null;
        $where = $expression->args['expression'] ?? null;
        $cond = $where instanceof Expression ? ($where->args['this'] ?? null) : null;

        if (! $agg instanceof Expression || ! $cond instanceof Expression) {
            return parent::filterSql($expression);
        }

        $aggArg = $agg instanceof Anonymous ? ($agg->argList('expressions')[0] ?? null) : ($agg->args['this'] ?? null);

        if ($agg instanceof WithinGroup) {
            // Ordered-set aggregates take their input from the ORDER BY key, so the condition
            // wraps that instead of the aggregate's own argument
            if ($aggArg instanceof Mode || $aggArg instanceof PercentileCont || $aggArg instanceof PercentileDisc) {
                $order = $agg->args['expression'] ?? null;

                foreach ($order instanceof Expression ? $order->argList('expressions') : [] as $ordered) {
                    $key = $ordered->args['this'] ?? null;

                    if ($key instanceof Expression) {
                        $key->replace(new IfExpr(['this' => $cond->copy(), 'true' => $key->copy()]));
                    }
                }

                return $this->sql($agg);
            }

            // Besides the percentiles, these are the only functions Snowflake accepts WITHIN GROUP for
            if ($aggArg instanceof ArrayAgg || $aggArg instanceof GroupConcat) {
                $aggArg = $aggArg->args['this'] ?? null;
            } else {
                $this->unsupported("Unable to rewrite FILTER into the aggregate's arguments");

                return $this->sql($agg);
            }
        }

        // COUNT(*) FILTER (WHERE cond) counts qualifying rows; a star can't be an IFF argument
        if ($agg instanceof Count && $aggArg instanceof Expression && $aggArg->isStar()) {
            return $this->func('COUNT_IF', $cond);
        }

        // DISTINCT and ORDER BY belong to the argument list, so the condition wraps the values
        // under them: IFF(cond, DISTINCT x, NULL) isn't valid anywhere
        if ($aggArg instanceof Order) {
            $aggArg = $aggArg->args['this'] ?? null;
        }

        $targets = $aggArg instanceof Distinct ? $aggArg->argList('expressions') : [$aggArg];

        foreach ($targets as $target) {
            if ($target instanceof Expression) {
                $target->replace(new IfExpr(['this' => $cond->copy(), 'true' => $target->copy()]));
            }
        }

        return $this->sql($agg);
    }

    protected function withingroupSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        // Snowflake's MODE takes the value as an argument, not the ordered-set syntax
        if ($this_ instanceof Mode && ! $this->truthy($this_->args['this'] ?? null)) {
            $order = $expression->args['expression'] ?? null;

            if ($order instanceof Order && count($order->argList('expressions')) === 1) {
                return $this->sql(new Mode(['this' => $order->argList('expressions')[0]->args['this'] ?? null]));
            }
        }

        return parent::withingroupSql($expression);
    }
}
