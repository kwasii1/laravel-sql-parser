<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\BigQuery;

use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Exists;
use LaravelSqlParser\LaravelSqlParser\Expressions\ExplodingGenerateSeries;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Kwarg;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lower;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\ReturnsProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDatetime;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToTimestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Update;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;

/**
 * BigQuery generator (sqlglot generators/bigquery.py).
 */
class BigQueryGenerator extends Generator
{
    public const string TABLES = BigQueryGeneratorTables::class;

    /** JSON functions whose path keys are escaped with double quotes rather than brackets. */
    private const array DQUOTES_ESCAPING_JSON_FUNCTIONS = ['JSON_QUERY' => true, 'JSON_VALUE' => true, 'JSON_QUERY_ARRAY' => true];

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function derivedTableValuesToUnnest(Expression $expression): string
    {
        if ($expression->findAncestor(From::class, Join::class) === null) {
            return $this->valuesSql($expression);
        }

        $structs = [];
        $alias = $expression->args['alias'] ?? null;
        $aliasColumns = $alias instanceof Expression ? $alias->argList('columns') : [];

        foreach ($expression->findAll(Tuple::class) as $tuple) {
            $fields = [];

            foreach ($tuple->argList('expressions') as $i => $field) {
                if ($aliasColumns !== [] && ! isset($aliasColumns[$i])) {
                    break;
                }

                $name = $aliasColumns !== [] ? $aliasColumns[$i]->name() : "_c{$i}";
                $fields[] = new PropertyEQ(['this' => Builder::toIdentifier($name), 'expression' => $field]);
            }

            $structs[] = new Struct(['expressions' => $fields]);
        }

        // Because of UNNEST_COLUMN_ONLY, the table alias is expected in the columns expression
        $aliasNameOnly = $alias instanceof Expression ? new TableAlias(['columns' => [$alias->args['this'] ?? null]]) : null;

        return $this->unnestSql(new Unnest(['expressions' => [new ArrayExpr(['expressions' => $structs])], 'alias' => $aliasNameOnly]));
    }

    protected function returnspropertySqlHandler(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $this_ = $this_ instanceof Schema ? $this->sql($this_, 'this').' <'.$this->expressions($this_).'>' : $this->sql($this_);

        return "RETURNS {$this_}";
    }

    protected function createSqlHandler(Expression $expression): string
    {
        $returns = $expression->find(ReturnsProperty::class);

        if ($expression->text('kind') === 'FUNCTION' && $returns !== null && $returns->flag('is_table')) {
            $expression->set('kind', 'TABLE FUNCTION');
            $body = $expression->args['expression'] ?? null;

            if ($body instanceof Subquery || $body instanceof Literal) {
                $expression->set('expression', $body->args['this'] ?? null);
            }
        }

        return $this->createSql($expression);
    }

    /**
     * Workaround for a BigQuery bug when grouping by an expression and then ordering
     * (https://issuetracker.google.com/issues/162294746): the grouped expression is replaced by
     * its projection's alias.
     */
    protected function aliasOrderedGroup(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $group = $expression->args['group'] ?? null;
        $order = $expression->args['order'] ?? null;

        if (! $group instanceof Expression || ! $order instanceof Expression) {
            return $expression;
        }

        $aliases = [];

        foreach ($expression->selects() as $select) {
            if ($select instanceof Alias && ($select->args['this'] ?? null) instanceof Expression) {
                $aliases[] = [$select->args['this'], $select->args['alias'] ?? null];
            }
        }

        foreach ($group->argList('expressions') as $grouped) {
            if ($grouped->isInt()) {
                continue;
            }

            foreach ($aliases as [$aliased, $alias]) {
                if ($aliased->equals($grouped) && $alias instanceof Identifier) {
                    $grouped->replace(Builder::column($alias));

                    break;
                }
            }
        }

        return $expression;
    }

    /**
     * BigQuery doesn't allow column names when defining a CTE, so they're pushed down.
     */
    protected function pushdownCteColumnNames(Expression $expression): Expression
    {
        if (! $expression instanceof CTE || $expression->aliasColumnNames() === []) {
            return $expression;
        }

        $cteQuery = $expression->args['this'] ?? null;

        if (! $cteQuery instanceof Query || ! $cteQuery instanceof Expression || $cteQuery->isStar()) {
            // Star projections can't be renamed until they're expanded (qualify)
            return $expression;
        }

        $columnNames = $expression->aliasColumnNames();
        $expression->args['alias']->set('columns', null);
        $selects = $cteQuery->selects();

        foreach ($columnNames as $i => $name) {
            if (! isset($selects[$i])) {
                break;
            }

            $toReplace = $selects[$i];
            $select = $toReplace instanceof Alias ? $toReplace->args['this'] : $toReplace;

            // Inner aliases are shadowed by the CTE column names
            $toReplace->replace(Builder::alias($select, $name));
        }

        return $expression;
    }

    /**
     * Exploding GENERATE_SERIES projections become table references, since BigQuery can't explode
     * in the projection:
     *
     *     SELECT GENERATE_SERIES(1, 2) AS x           -> SELECT x FROM GENERATE_SERIES(1, 2) AS x
     *     SELECT y, GENERATE_SERIES(1, 2) AS x FROM t -> SELECT y, x FROM t CROSS JOIN GENERATE_SERIES(1, 2) AS x
     *
     * The table reference is unnested later by unnestGenerateSeries().
     */
    protected function unnestExplodeGenerateSeries(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        foreach ($expression->selects() as $projection) {
            $series = $projection instanceof Alias ? ($projection->args['this'] ?? null) : $projection;

            if (! $series instanceof ExplodingGenerateSeries) {
                continue;
            }

            $columnName = $projection->outputName() !== '' ? $projection->outputName() : '_gen_series_value';
            $projection->replace(Builder::column($columnName));
            $table = new Table(['this' => $series, 'alias' => new TableAlias(['this' => Builder::toIdentifier($columnName)])]);

            if (($expression->args['from_'] ?? null) instanceof From) {
                $expression->append('joins', new Join(['this' => $table, 'kind' => 'CROSS']));
            } else {
                $expression->set('from_', new From(['this' => $table]));
            }
        }

        return $expression;
    }

    protected function arrayContainsSqlHandler(Expression $expression): string
    {
        $unnest = Builder::alias(new Unnest(['expressions' => [$expression->args['this'] ?? null]]), '_unnest', table: ['_col']);
        $select = new Select([
            'expressions' => [Literal::number(1)],
            'from_' => new From(['this' => $unnest]),
            'where' => new Where(['this' => new EQ(['this' => Builder::column('_col'), 'expression' => $expression->args['expression'] ?? null])]),
        ]);

        return $this->sql(new Exists(['this' => $select]));
    }

    protected function tsOrDsAddSqlHandler(Expression $expression): string
    {
        return $this->dateAddIntervalSql(self::tsOrDsAddCast($expression), 'DATE', 'ADD');
    }

    protected function tsOrDsDiffSqlHandler(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $other = $expression->args['expression'] ?? null;

        if ($this_ instanceof Expression) {
            $this_ = $this_->replace(Builder::cast($this_, DType::TIMESTAMP));
        }

        if ($other instanceof Expression) {
            $other = $other->replace(Builder::cast($other, DType::TIMESTAMP));
        }

        return $this->func('DATE_DIFF', $expression->args['this'] ?? null, $expression->args['expression'] ?? null, Builder::unitToVar($expression));
    }

    protected function unixToTimeSqlHandler(Expression $expression): string
    {
        $scale = $expression->args['scale'] ?? null;
        $timestamp = $expression->args['this'] ?? null;

        if ($scale === null || ($scale instanceof Literal && $scale->equals(Literal::number(0)))) {
            return $this->func('TIMESTAMP_SECONDS', $timestamp);
        }

        if ($scale instanceof Literal && $scale->equals(Literal::number(3))) {
            return $this->func('TIMESTAMP_MILLIS', $timestamp);
        }

        if ($scale instanceof Literal && $scale->equals(Literal::number(6))) {
            return $this->func('TIMESTAMP_MICROS', $timestamp);
        }

        $unixSeconds = Builder::cast(new Div(['this' => $timestamp, 'expression' => Builder::func('POW', [Literal::number(10), $scale])]), DType::BIGINT);

        return $this->func('TIMESTAMP_SECONDS', $unixSeconds);
    }

    protected function strToDatetimeSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $dtype = $expression instanceof StrToDate ? 'DATE' : 'TIMESTAMP';

        if ($expression->flag('safe')) {
            $fmt = $this->formatTime($expression, $this->dialect->settings['INVERSE_FORMAT_MAPPING'], $this->dialect->settings['INVERSE_FORMAT_TRIE']);

            return "SAFE_CAST({$this_} AS {$dtype} FORMAT {$fmt})";
        }

        return $this->func("PARSE_{$dtype}", $this->formatTime($expression), $this_, $expression->args['zone'] ?? null);
    }

    protected function levenshteinSqlHandler(Expression $expression): string
    {
        $maxDist = $expression->args['max_dist'] ?? null;

        if ($this->truthy($maxDist)) {
            $maxDist = new Kwarg(['this' => Builder::var('max_distance'), 'expression' => $maxDist]);
        }

        return $this->func('EDIT_DISTANCE', $expression->args['this'] ?? null, $expression->args['expression'] ?? null, $maxDist);
    }

    protected function jsonExtractSqlHandler(Expression $expression): string
    {
        $name = $expression->metaGet('name');
        $upper = strtoupper(is_string($name) && $name !== '' ? $name : $expression::sqlName());
        $dquoteEscaping = isset(self::DQUOTES_ESCAPING_JSON_FUNCTIONS[$upper]);

        if ($dquoteEscaping) {
            $this->quoteJsonPathKeyUsingBrackets = false;
        }

        $sql = $this->renameFunc($expression, $upper);

        if ($dquoteEscaping) {
            $this->quoteJsonPathKeyUsingBrackets = true;
        }

        return $sql;
    }

    protected function transformCollateProperty(Expression $e): string
    {
        return ($e->flag('default') ? 'DEFAULT COLLATE ' : 'COLLATE ').$this->sql($e, 'this');
    }

    protected function transformCommit(Expression $e): string
    {
        return 'COMMIT TRANSACTION';
    }

    protected function transformRollback(Expression $e): string
    {
        return 'ROLLBACK TRANSACTION';
    }

    protected function transformTransaction(Expression $e): string
    {
        return 'BEGIN TRANSACTION';
    }

    protected function transformDateDiff(Expression $e): string
    {
        return $this->func('DATE_DIFF', $e->args['this'] ?? null, $e->args['expression'] ?? null, Builder::unitToVar($e));
    }

    protected function transformFromTimeZone(Expression $e): string
    {
        return $this->func('DATETIME', $this->func('TIMESTAMP', $e->args['this'] ?? null, $e->args['zone'] ?? null), "'UTC'");
    }

    protected function transformGroupConcat(Expression $e): string
    {
        return $this->groupconcatSqlHandler($e, funcName: 'STRING_AGG', sep: null, withinGroup: false);
    }

    protected function transformHex(Expression $e): string
    {
        return $this->func('UPPER', $this->func('TO_HEX', $this->sql($e, 'this')));
    }

    protected function transformHexString(Expression $e): string
    {
        return $this->hexstringSql($e, binaryFunctionRepr: 'FROM_HEX');
    }

    protected function transformJSONFormat(Expression $e): string
    {
        return $this->func($e->flag('to_json') ? 'TO_JSON' : 'TO_JSON_STRING', $e->args['this'] ?? null, $e->args['options'] ?? null);
    }

    protected function transformMd5(Expression $e): string
    {
        return $this->func('TO_HEX', $this->func('MD5', $e->args['this'] ?? null));
    }

    protected function transformNormalize(Expression $e): string
    {
        return $this->func($e->flag('is_casefold') ? 'NORMALIZE_AND_CASEFOLD' : 'NORMALIZE', $e->args['this'] ?? null, $e->args['form'] ?? null);
    }

    protected function transformPartitionedByProperty(Expression $e): string
    {
        return 'PARTITION BY '.$this->sql($e, 'this');
    }

    protected function transformRegexpExtract(Expression $e): string
    {
        return $this->func('REGEXP_EXTRACT', $e->args['this'] ?? null, $e->args['expression'] ?? null, $e->args['position'] ?? null, $e->args['occurrence'] ?? null);
    }

    protected function transformRegexpExtractAll(Expression $e): string
    {
        return $this->func('REGEXP_EXTRACT_ALL', $e->args['this'] ?? null, $e->args['expression'] ?? null);
    }

    protected function transformParseTime(Expression $e): string
    {
        return $this->func('PARSE_TIME', $this->formatTime($e), $e->args['this'] ?? null);
    }

    protected function transformParseDatetime(Expression $e): string
    {
        return $this->func('PARSE_DATETIME', $this->formatTime($e), $e->args['this'] ?? null);
    }

    protected function transformStabilityProperty(Expression $e): string
    {
        return $e->name() === 'IMMUTABLE' ? 'DETERMINISTIC' : 'NOT DETERMINISTIC';
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'INSTR', supportsPosition: true, supportsOccurrence: true);
    }

    protected function transformSessionUser(Expression $e): string
    {
        return 'SESSION_USER()';
    }

    protected function transformUuid(Expression $e): string
    {
        return 'GENERATE_UUID()';
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function weekstartSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        // WEEK(SUNDAY) == WEEK in BigQuery
        if ($this_ instanceof Expression && strtoupper($this_->name()) === 'SUNDAY') {
            return 'WEEK';
        }

        return $this->func('WEEK', $this_);
    }

    protected function datetruncSql(Expression $expression): string
    {
        $unit = $expression->args['unit'] ?? null;
        $unitSql = $unit instanceof Expression && $unit->isString() ? $unit->name() : $this->sql($unit);

        return $this->func('DATE_TRUNC', $expression->args['this'] ?? null, $unitSql, $expression->args['zone'] ?? null);
    }

    protected function modSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $expr = $expression->args['expression'] ?? null;

        return $this->func(
            'MOD',
            $this_ instanceof Paren ? $this_->unnest() : $this_,
            $expr instanceof Paren ? $expr->unnest() : $expr,
        );
    }

    protected function columnParts(Column $expression): string
    {
        if ($expression->metaGet('quoted_column') === true) {
            // A `dataset.table`.name reference keeps its quoted table path, or it breaks
            $parts = $expression->parts();
            $tableParts = implode('.', array_map(static fn (Expression $p): string => $p->name(), array_slice($parts, 0, -1)));
            $tablePath = $this->sql(new Identifier(['this' => $tableParts, 'quoted' => true]));

            return "{$tablePath}.".$this->sql($expression, 'this');
        }

        return parent::columnParts($expression);
    }

    /**
     * x.y may not resolve to the same data source as `x`.`y`: if a CTE x clashes with a schema
     * name, the former is table y of that schema while the latter is the CTE's y column, e.g.
     *
     * - WITH x AS (SELECT [1, 2] AS y) SELECT * FROM x, `x.y`   -> cross join
     * - WITH x AS (SELECT [1, 2] AS y) SELECT * FROM x, `x`.`y` -> implicit unnest
     */
    protected function tableParts(Expression $expression): string
    {
        if ($expression instanceof Table && $expression->metaGet('quoted_table') === true) {
            $tableParts = implode('.', array_map(static fn (Expression $p): string => $p->name(), $expression->parts()));

            return $this->sql(new Identifier(['this' => $tableParts, 'quoted' => true]));
        }

        return parent::tableParts($expression);
    }

    protected function timetostrSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $funcName = match (true) {
            $this_ instanceof TsOrDsToDatetime => 'FORMAT_DATETIME',
            $this_ instanceof TsOrDsToTimestamp => 'FORMAT_TIMESTAMP',
            $this_ instanceof TsOrDsToTime => 'FORMAT_TIME',
            default => 'FORMAT_DATE',
        };
        $isTsOrDs = $this_ instanceof TsOrDsToDatetime || $this_ instanceof TsOrDsToTimestamp || $this_ instanceof TsOrDsToTime || $this_ instanceof TsOrDsToDate;
        $timeExpr = $isTsOrDs ? $this_ : $expression;

        return $this->func($funcName, $this->formatTime($expression), $timeExpr->args['this'] ?? null, $expression->args['zone'] ?? null);
    }

    protected function eqSql(Expression $expression): string
    {
        // Operands of = can't be NULL in BigQuery
        if ((($expression->args['this'] ?? null) instanceof NullExpr || ($expression->args['expression'] ?? null) instanceof NullExpr) && ! $expression->parent instanceof Update) {
            return 'NULL';
        }

        return $this->binary($expression, '=');
    }

    protected function attimezoneSql(Expression $expression): string
    {
        $parent = $expression->parent;

        // CAST(.. AS {STRING|TIMESTAMP} [FORMAT <fmt> [AT TIME ZONE <tz>]]): only the TIMESTAMP form
        // converts with AT TIME ZONE
        $to = $parent instanceof Cast ? ($parent->args['to'] ?? null) : null;

        if (! $parent instanceof Cast || ! ($to instanceof DataType && $to->isType(DType::TEXT))) {
            return $this->func('TIMESTAMP', $this->func('DATETIME', $expression->args['this'] ?? null, $expression->args['zone'] ?? null));
        }

        return parent::attimezoneSql($expression);
    }

    protected function trycastSql(Expression $expression): string
    {
        return $this->castSql($expression, safePrefix: 'SAFE_');
    }

    protected function bracketSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $expressions = $expression->argList('expressions');

        if (count($expressions) === 1 && $this_ instanceof Expression && $this_->isType(DType::STRUCT)) {
            $arg = $expressions[0];

            if ($arg->type() === null) {
                $this->dialect->annotateTypes($arg);
            }

            $argType = $arg->type();

            if ($argType !== null && in_array($argType->args['this'] ?? null, DataType::TEXT_TYPES, true)) {
                // BigQuery doesn't support bracket syntax with string values for structs
                return $this->sql($this_).'.'.$arg->name();
            }
        }

        $expressionsSql = $this->expressions($expression, flat: true);
        $offset = $expression->args['offset'] ?? null;

        if ($offset === 0) {
            $expressionsSql = "OFFSET({$expressionsSql})";
        } elseif ($offset === 1) {
            $expressionsSql = "ORDINAL({$expressionsSql})";
        } elseif ($offset !== null) {
            $this->unsupported('Unsupported array offset: '.(is_scalar($offset) ? (string) $offset : 'expression'));
        }

        if ($expression->flag('safe')) {
            $expressionsSql = "SAFE_{$expressionsSql}";
        }

        return $this->sql($this_)."[{$expressionsSql}]";
    }

    public function inUnnestOp(Expression $expression): string
    {
        return $this->sql($expression);
    }

    protected function versionSql(Expression $expression): string
    {
        if ($expression->name() === 'TIMESTAMP') {
            $expression->set('this', 'SYSTEM_TIME');
        }

        return parent::versionSql($expression);
    }

    protected function containsSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $expr = $expression->args['expression'] ?? null;

        if ($this_ instanceof Lower && $expr instanceof Lower) {
            $this_ = $this_->args['this'] ?? null;
            $expr = $expr->args['this'] ?? null;
        }

        return $this->func('CONTAINS_SUBSTR', $this_, $expr, $expression->args['json_scope'] ?? null);
    }

    protected function parsejsonSql(Expression $expression): string
    {
        if ($expression->flag('is_literal')) {
            return 'JSON '.$this->sql($expression, 'this');
        }

        return parent::parsejsonSql($expression);
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        $this_ = $expression->args['this'] ?? null;

        // Inline type-annotated ARRAY literals like ARRAY<INT64>[1, 2, 3] round-trip unaffected;
        // ARRAY(SELECT ...) isn't a literal, so that syntax would be invalid for it
        if ($this_ instanceof ArrayExpr) {
            $element = $this_->argList('expressions')[0] ?? null;

            if (! ($element !== null && $element->find(Query::class) !== null)) {
                return $this->sql($expression, 'to').$this->sql($this_);
            }
        }

        return parent::castSql($expression, safePrefix: $safePrefix);
    }

    protected function clusterpropertySql(Expression $expression): string
    {
        if ($this->truthy($expression->args['this'] ?? null)) {
            $this->unsupported('Unsupported CLUSTER BY '.$this->sql($expression, 'this'));

            return '';
        }

        return $this->opExpressions('CLUSTER BY', $expression);
    }
}
