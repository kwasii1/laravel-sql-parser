<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Generator\Concerns;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Dialects\TSql\TSqlDialectTables;
use LaravelSqlParser\LaravelSqlParser\Expressions\AggFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\AndExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayFilter;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArraySize;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ConvertTimezone;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Filter;
use LaravelSqlParser\LaravelSqlParser\Expressions\Func;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateSeries;
use LaravelSqlParser\LaravelSqlParser\Expressions\HavingMax;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Insert;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPathWildcard;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\OrExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\PercentileCont;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Put;
use LaravelSqlParser\LaravelSqlParser\Expressions\Repeat;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampDiff;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeToStr;
use LaravelSqlParser\LaravelSqlParser\Expressions\TryCast;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDatetime;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToTimestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Update;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithTableHint;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;
use LaravelSqlParser\LaravelSqlParser\Support\TimeFormat;

/**
 * Functions, MERGE, COPY, GRANT, ANALYZE and the remaining statements
 * (sqlglot generator.py, from ceil_floor to the end).
 */
trait GeneratesFunctions
{
    public function ceilFloor(Expression $expression): string
    {
        $toClause = $this->sql($expression, 'to');

        if ($toClause !== '') {
            return $expression::sqlName().'('.$this->sql($expression, 'this')." TO {$toClause})";
        }

        return $this->functionFallbackSql($expression);
    }

    /**
     * The expression's format argument converted to this dialect's time format.
     *
     * @param  array<string, string>|null  $inverseTimeMapping
     * @param  array<string, mixed>|null  $inverseTimeTrie
     */
    public function formatTime(Expression $expression, ?array $inverseTimeMapping = null, ?array $inverseTimeTrie = null): ?string
    {
        $format = $this->sql($expression, 'format');

        return TimeFormat::convert(
            $format !== '' ? $format : null,
            $inverseTimeMapping !== null && $inverseTimeMapping !== [] ? $inverseTimeMapping : $this->dialect->settings['INVERSE_TIME_MAPPING'],
            $inverseTimeTrie !== null && $inverseTimeTrie !== [] ? $inverseTimeTrie : $this->dialect->settings['INVERSE_TIME_TRIE'],
        );
    }

    protected function tagSql(Expression $expression): string
    {
        return ((string) ($expression->args['prefix'] ?? '')).$this->sql($expression, 'this').((string) ($expression->args['postfix'] ?? ''));
    }

    protected function userdefinedfunctionSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expressions = $this->noIdentify(fn (): string => $this->expressions($expression));
        $expressions = $expression->flag('wrapped') ? $this->wrap($expressions) : " {$expressions}";

        return trim($expressions) !== '' ? "{$this_}{$expressions}" : $this_;
    }

    protected function macrooverloadsSql(Expression $expression): string
    {
        return $this->expressions($expression, flat: true);
    }

    protected function macrooverloadSql(Expression $expression): string
    {
        $params = $this->noIdentify(fn (): string => $this->expressions($expression, flat: true));
        $body = $this->sql($expression, 'this');
        $prefix = $expression->flag('is_table') ? 'TABLE ' : '';

        return "({$params}) AS {$prefix}{$body}";
    }

    protected function joinhintSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').'('.$this->expressions($expression, flat: true).')';
    }

    protected function kwargSql(Expression $expression): string
    {
        return $this->binary($expression, '=>');
    }

    protected function whenSql(Expression $expression): string
    {
        $matched = $expression->flag('matched') ? 'MATCHED' : 'NOT MATCHED';
        $source = $this->t['MATCHED_BY_SOURCE'] === true && $expression->flag('source') ? ' BY SOURCE' : '';
        $condition = $this->sql($expression, 'condition');
        $condition = $condition !== '' ? " AND {$condition}" : '';

        $thenExpression = $expression->args['then'] ?? null;

        if ($thenExpression instanceof Insert) {
            $this_ = $this->sql($thenExpression, 'this');
            $this_ = $this_ !== '' ? "INSERT {$this_}" : 'INSERT';
            $then = $this->sql($thenExpression, 'expression');
            $then = $then !== '' ? "{$this_} VALUES {$then}" : $this_;
        } elseif ($thenExpression instanceof Update) {
            if (($thenExpression->args['expressions'] ?? null) instanceof Star) {
                $then = 'UPDATE '.$this->sql($thenExpression, 'expressions');
            } else {
                $expressionsSql = $this->expressions($thenExpression);
                $then = $expressionsSql !== '' ? 'UPDATE SET'.$this->sep().$expressionsSql : 'UPDATE';
            }
        } else {
            $then = $this->sql($thenExpression);
        }

        if ($thenExpression instanceof Insert || $thenExpression instanceof Update) {
            $where = $this->sql($thenExpression, 'where');

            if ($where !== '' && $this->t['SUPPORTS_MERGE_WHERE'] !== true) {
                $kind = $thenExpression instanceof Insert ? 'INSERT' : 'UPDATE';
                $this->unsupported("WHERE clause in MERGE {$kind} is not supported");
                $where = '';
            }

            $then = "{$then}{$where}";
        }

        return "WHEN {$matched}{$source}{$condition} THEN {$then}";
    }

    protected function whensSql(Expression $expression): string
    {
        return $this->expressions($expression, sep: ' ', indent: false);
    }

    protected function mergeSql(Expression $expression): string
    {
        $table = $expression->args['this'] ?? null;
        $tableAlias = '';

        if ($table instanceof Expression) {
            $hints = $table->argList('hints');

            if ($hints !== [] && $table->alias() !== '' && $hints[0] instanceof WithTableHint) {
                // T-SQL syntax is MERGE ... <target_table> [WITH (<merge_hint>)] [[AS] table_alias]
                $tableAlias = ' AS '.$this->sql($table->args['alias']->pop());
            }
        }

        $this_ = $this->sql($table);
        $using = 'USING '.$this->sql($expression, 'using');
        $whens = $this->sql($expression, 'whens');

        $on = $this->sql($expression, 'on');
        $on = $on !== '' ? "ON {$on}" : '';

        if ($on === '') {
            $on = $this->expressions($expression, key: 'using_cond');
            $on = $on !== '' ? "USING ({$on})" : '';
        }

        $returning = $this->sql($expression, 'returning');

        if ($returning !== '') {
            $whens = "{$whens}{$returning}";
        }

        $sep = $this->sep();

        return $this->prependCtes($expression, "MERGE INTO {$this_}{$tableAlias}{$sep}{$using}{$sep}{$on}{$sep}{$whens}");
    }

    protected function tocharSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'format');

        return $this->sql(Builder::cast($expression->argExpr('this') ?? new NullExpr, DType::TEXT));
    }

    protected function tonumberSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'default');

        if ($this->t['SUPPORTS_TO_NUMBER'] !== true) {
            $this->unsupported('Unsupported TO_NUMBER function');

            return $this->tonumberCastSqlInternal($expression);
        }

        $fmt = $expression->args['format'] ?? null;

        if (! $this->truthy($fmt)) {
            $this->unsupported('Conversion format is required for TO_NUMBER');

            return $this->tonumberCastSqlInternal($expression);
        }

        return $this->func('TO_NUMBER', $expression->args['this'] ?? null, $fmt);
    }

    protected function tonumberCastSqlInternal(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;

        if ($expression->flag('safe')) {
            return $this->sql(new TryCast(['this' => $this_, 'to' => DataType::build('DOUBLE')]));
        }

        return $this->sql(Builder::cast($this_, DType::DOUBLE));
    }

    protected function dictpropertySql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $kind = $this->sql($expression, 'kind');
        $settingsSql = $this->expressions($expression, key: 'settings', sep: ' ');
        $args = $settingsSql !== '' ? '('.$this->sep('').$settingsSql.$this->seg(')', sep: '') : '()';

        return "{$this_}({$kind}{$args})";
    }

    protected function dictrangeSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $max = $this->sql($expression, 'max');
        $min = $this->sql($expression, 'min');

        return "{$this_}(MIN {$min} MAX {$max})";
    }

    protected function dictsubpropertySql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' '.$this->sql($expression, 'value');
    }

    protected function duplicatekeypropertySql(Expression $expression): string
    {
        return 'DUPLICATE KEY ('.$this->expressions($expression, flat: true).')';
    }

    protected function uniquekeypropertySql(Expression $expression, string $prefix = 'UNIQUE KEY'): string
    {
        return "{$prefix} (".$this->expressions($expression, flat: true).')';
    }

    protected function distributedbypropertySql(Expression $expression): string
    {
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? ' '.$this->wrap($expressions) : '';
        $buckets = $this->sql($expression, 'buckets');
        $kind = $this->sql($expression, 'kind');
        $buckets = $buckets !== '' ? " BUCKETS {$buckets}" : '';
        $order = $this->sql($expression, 'order');

        return "DISTRIBUTED BY {$kind}{$expressions}{$buckets}{$order}";
    }

    protected function onclusterSql(Expression $expression): string
    {
        return '';
    }

    protected function clusteredbypropertySql(Expression $expression): string
    {
        $expressions = $this->expressions($expression, key: 'expressions', flat: true);
        $sortedBy = $this->expressions($expression, key: 'sorted_by', flat: true);
        $sortedBy = $sortedBy !== '' ? " SORTED BY ({$sortedBy})" : '';
        $buckets = $this->sql($expression, 'buckets');

        return "CLUSTERED BY ({$expressions}){$sortedBy} INTO {$buckets} BUCKETS";
    }

    protected function anyvalueSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $having = $this->sql($expression, 'having');

        if ($having !== '') {
            $this_ = "{$this_} HAVING ".($expression->flag('max') ? 'MAX' : 'MIN')." {$having}";
        }

        return $this->func('ANY_VALUE', $this_);
    }

    protected function querytransformSql(Expression $expression): string
    {
        $transform = $this->funcWith('TRANSFORM', $expression->argList('expressions'));
        $rowFormatBefore = $this->sql($expression, 'row_format_before');
        $rowFormatBefore = $rowFormatBefore !== '' ? " {$rowFormatBefore}" : '';
        $recordWriter = $this->sql($expression, 'record_writer');
        $recordWriter = $recordWriter !== '' ? " RECORDWRITER {$recordWriter}" : '';
        $using = ' USING '.$this->sql($expression, 'command_script');
        $schema = $this->sql($expression, 'schema');
        $schema = $schema !== '' ? " AS {$schema}" : '';
        $rowFormatAfter = $this->sql($expression, 'row_format_after');
        $rowFormatAfter = $rowFormatAfter !== '' ? " {$rowFormatAfter}" : '';
        $recordReader = $this->sql($expression, 'record_reader');
        $recordReader = $recordReader !== '' ? " RECORDREADER {$recordReader}" : '';

        return "{$transform}{$rowFormatBefore}{$recordWriter}{$using}{$schema}{$rowFormatAfter}{$recordReader}";
    }

    protected function indexconstraintoptionSql(Expression $expression): string
    {
        $keyBlockSize = $this->sql($expression, 'key_block_size');

        if ($keyBlockSize !== '') {
            return "KEY_BLOCK_SIZE = {$keyBlockSize}";
        }

        $using = $this->sql($expression, 'using');

        if ($using !== '') {
            return "USING {$using}";
        }

        $parser = $this->sql($expression, 'parser');

        if ($parser !== '') {
            return "WITH PARSER {$parser}";
        }

        $comment = $this->sql($expression, 'comment');

        if ($comment !== '') {
            return "COMMENT {$comment}";
        }

        $visible = $expression->args['visible'] ?? null;

        if ($visible !== null) {
            return $this->truthy($visible) ? 'VISIBLE' : 'INVISIBLE';
        }

        $engineAttr = $this->sql($expression, 'engine_attr');

        if ($engineAttr !== '') {
            return "ENGINE_ATTRIBUTE = {$engineAttr}";
        }

        $secondaryEngineAttr = $this->sql($expression, 'secondary_engine_attr');

        if ($secondaryEngineAttr !== '') {
            return "SECONDARY_ENGINE_ATTRIBUTE = {$secondaryEngineAttr}";
        }

        $this->unsupported('Unsupported index constraint option.');

        return '';
    }

    protected function checkcolumnconstraintSql(Expression $expression): string
    {
        $enforced = $expression->flag('enforced') ? ' ENFORCED' : '';

        return 'CHECK ('.$this->sql($expression, 'this').')'.$enforced;
    }

    protected function indexcolumnconstraintSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? "{$kind} INDEX" : 'INDEX';
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $indexType = $this->sql($expression, 'index_type');
        $indexType = $indexType !== '' ? " USING {$indexType}" : '';
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? " ({$expressions})" : '';
        $options = $this->expressions($expression, key: 'options', sep: ' ');
        $options = $options !== '' ? " {$options}" : '';

        return "{$kind}{$this_}{$indexType}{$expressions}{$options}";
    }

    protected function nvl2Sql(Expression $expression): string
    {
        if ($this->t['NVL2_SUPPORTED'] === true) {
            return $this->functionFallbackSql($expression);
        }

        $condition = new Not(['this' => new Is(['this' => $expression->args['this'] ?? null, 'expression' => new NullExpr])]);
        $else = $expression->args['false'] ?? null;

        return $this->sql(new CaseExpr([
            'ifs' => [new IfExpr(['this' => $condition, 'true' => $expression->args['true'] ?? null])],
            'default' => $this->truthy($else) ? $else : null,
        ]));
    }

    protected function nthvalueSql(Expression $expression): string
    {
        if (($expression->args['from_first'] ?? null) === false) {
            $this->unsupported('NTH_VALUE FROM LAST is not supported');
        }

        return $this->functionFallbackSql($expression);
    }

    protected function comprehensionSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expr = $this->sql($expression, 'expression');
        $position = $this->sql($expression, 'position');
        $position = $position !== '' ? ", {$position}" : '';
        $iterator = $this->sql($expression, 'iterator');
        $condition = $this->sql($expression, 'condition');
        $condition = $condition !== '' ? " IF {$condition}" : '';

        return "{$this_} FOR {$expr}{$position} IN {$iterator}{$condition}";
    }

    protected function columnprefixSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').'('.$this->sql($expression, 'expression').')';
    }

    protected function opclassSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' '.$this->sql($expression, 'expression');
    }

    protected function mlSqlInternal(Expression $expression, string $name): string
    {
        $model = 'MODEL '.$this->sql($expression, 'this');
        $expr = $expression->args['expression'] ?? null;
        $exprSql = null;

        if ($this->truthy($expr)) {
            $exprSql = $this->sql($expression, 'expression');
            $exprSql = $expr instanceof Table ? "TABLE {$exprSql}" : $exprSql;
        }

        $parameters = $this->sql($expression, 'params_struct');

        return $this->func($name, $model, $exprSql, $parameters !== '' ? $parameters : null);
    }

    protected function predictSql(Expression $expression): string
    {
        return $this->mlSqlInternal($expression, 'PREDICT');
    }

    protected function generateembeddingSql(Expression $expression): string
    {
        return $this->mlSqlInternal($expression, $expression->flag('is_text') ? 'GENERATE_TEXT_EMBEDDING' : 'GENERATE_EMBEDDING');
    }

    protected function generatetextSql(Expression $expression): string
    {
        return $this->mlSqlInternal($expression, 'GENERATE_TEXT');
    }

    protected function generatetableSql(Expression $expression): string
    {
        return $this->mlSqlInternal($expression, 'GENERATE_TABLE');
    }

    protected function generateboolSql(Expression $expression): string
    {
        return $this->mlSqlInternal($expression, 'GENERATE_BOOL');
    }

    protected function generateintSql(Expression $expression): string
    {
        return $this->mlSqlInternal($expression, 'GENERATE_INT');
    }

    protected function generatedoubleSql(Expression $expression): string
    {
        return $this->mlSqlInternal($expression, 'GENERATE_DOUBLE');
    }

    protected function mltranslateSql(Expression $expression): string
    {
        return $this->mlSqlInternal($expression, 'TRANSLATE');
    }

    protected function mlforecastSql(Expression $expression): string
    {
        return $this->mlSqlInternal($expression, 'FORECAST');
    }

    protected function aiforecastSql(Expression $expression): string
    {
        $thisSql = $this->sql($expression, 'this');

        if (($expression->args['this'] ?? null) instanceof Table) {
            $thisSql = "TABLE {$thisSql}";
        }

        return $this->func(
            'FORECAST',
            $thisSql,
            $expression->args['data_col'] ?? null,
            $expression->args['timestamp_col'] ?? null,
            $expression->args['model'] ?? null,
            $expression->args['id_cols'] ?? null,
            $expression->args['horizon'] ?? null,
            $expression->args['forecast_end_timestamp'] ?? null,
            $expression->args['confidence_level'] ?? null,
            $expression->args['output_historical_time_series'] ?? null,
            $expression->args['context_window'] ?? null,
        );
    }

    protected function featuresattimeSql(Expression $expression): string
    {
        $thisSql = $this->sql($expression, 'this');

        if (($expression->args['this'] ?? null) instanceof Table) {
            $thisSql = "TABLE {$thisSql}";
        }

        return $this->func(
            'FEATURES_AT_TIME',
            $thisSql,
            $expression->args['time'] ?? null,
            $expression->args['num_rows'] ?? null,
            $expression->args['ignore_feature_nulls'] ?? null,
        );
    }

    protected function vectorsearchSql(Expression $expression): string
    {
        $thisSql = $this->sql($expression, 'this');

        if (($expression->args['this'] ?? null) instanceof Table) {
            $thisSql = "TABLE {$thisSql}";
        }

        $queryTable = $this->sql($expression, 'query_table');

        if (($expression->args['query_table'] ?? null) instanceof Table) {
            $queryTable = "TABLE {$queryTable}";
        }

        return $this->func(
            'VECTOR_SEARCH',
            $thisSql,
            $expression->args['column_to_search'] ?? null,
            $queryTable,
            $expression->args['query_column_to_search'] ?? null,
            $expression->args['top_k'] ?? null,
            $expression->args['distance_type'] ?? null,
            $expression->args['options'] ?? null,
        );
    }

    protected function forinSql(Expression $expression): string
    {
        return 'FOR '.$this->sql($expression, 'this').' DO '.$this->sql($expression, 'expression');
    }

    protected function refreshSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $kind = ($expression->args['this'] ?? null) instanceof Literal ? '' : $expression->text('kind').' ';

        return "REFRESH {$kind}{$this_}";
    }

    protected function toarraySql(Expression $expression): string
    {
        $arg = $expression->argExpr('this') ?? new NullExpr;

        // sqlglot annotates types here; without the optimizer only array constructors are known arrays
        if ($arg instanceof ArrayExpr || $arg->isType(DType::ARRAY)) {
            return $this->sql($arg);
        }

        $condForNull = new Is(['this' => $arg->copy(), 'expression' => new NullExpr]);

        return $this->sql(Builder::func('IF', [$condForNull, new NullExpr, new ArrayExpr(['expressions' => [$arg]])], copy: false));
    }

    protected function tsordstotimeSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $timeFormat = $this->formatTime($expression);

        if ($timeFormat !== null && $timeFormat !== '') {
            return $this->sql(Builder::cast(new StrToTime(['this' => $this_, 'format' => $expression->args['format'] ?? null]), DType::TIME));
        }

        if ($this_ instanceof TsOrDsToTime || $this_->isType(DType::TIME)) {
            return $this->sql($this_);
        }

        return $this->sql(Builder::cast($this_, DType::TIME));
    }

    protected function tsordstotimestampSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;

        if ($this_ instanceof TsOrDsToTimestamp || $this_->isType(DType::TIMESTAMP)) {
            return $this->sql($this_);
        }

        return $this->sql(Builder::cast($this_, DType::TIMESTAMP, dialect: $this->dialect));
    }

    protected function tsordstodatetimeSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;

        if ($this_ instanceof TsOrDsToDatetime || $this_->isType(DType::DATETIME)) {
            return $this->sql($this_);
        }

        return $this->sql(Builder::cast($this_, DType::DATETIME, dialect: $this->dialect));
    }

    protected function tsordstodateSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $timeFormat = $this->formatTime($expression);
        $safe = $expression->args['safe'] ?? null;

        if (
            $timeFormat !== null && $timeFormat !== ''
            && $timeFormat !== $this->dialect->settings['TIME_FORMAT']
            && $timeFormat !== $this->dialect->settings['DATE_FORMAT']
        ) {
            return $this->sql(Builder::cast(new StrToTime(['this' => $this_, 'format' => $expression->args['format'] ?? null, 'safe' => $safe]), DType::DATE));
        }

        if ($this_ instanceof TsOrDsToDate || $this_->isType(DType::DATE)) {
            return $this->sql($this_);
        }

        if ($this->truthy($safe)) {
            return $this->sql(new TryCast(['this' => $this_, 'to' => new DataType(['this' => DType::DATE])]));
        }

        return $this->sql(Builder::cast($this_, DType::DATE));
    }

    protected function unixdateSql(Expression $expression): string
    {
        return $this->sql(Builder::func('DATEDIFF', [
            $expression->argExpr('this') ?? new NullExpr,
            Builder::cast(Literal::string('1970-01-01'), DType::DATE),
            'day',
        ]));
    }

    protected function lastdaySql(Expression $expression): string
    {
        if ($this->t['LAST_DAY_SUPPORTS_DATE_PART'] === true) {
            return $this->functionFallbackSql($expression);
        }

        $unit = $expression->args['unit'] ?? null;

        if ($unit instanceof Expression && strtoupper($unit->name()) !== 'MONTH') {
            $this->unsupported('Date parts are not supported in LAST_DAY.');
        }

        return $this->func('LAST_DAY', $expression->args['this'] ?? null);
    }

    protected function dateaddSql(Expression $expression): string
    {
        return $this->func('DATE_ADD', $expression->args['this'] ?? null, $expression->args['expression'] ?? null, Builder::unitToStr($expression));
    }

    protected function arrayinsertSql(Expression $expression, int $indexOffset = 0): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $position = $expression->args['position'] ?? null;
        $offset = $indexOffset - (int) ($expression->args['offset'] ?? 0);

        if ($offset !== 0 && $position instanceof Expression) {
            if ($position->isInt()) {
                $value = (int) $position->toPhp();

                if ($value >= 0) {
                    $position = Literal::number($value + $offset);
                } elseif ($offset < 0 && $value === -1) {
                    // 1-based -1 appends, which a 0-based position can only express as the size
                    $position = new ArraySize(['this' => $this_->copy()]);
                } else {
                    // Negative positions count from the end, so they shift in the opposite
                    // direction, e.g. 0-based -1 (before the last element) is 1-based -2
                    $position = Literal::number($value - $offset);
                }
            } else {
                $this->unsupported("ARRAY_INSERT position can only be converted if it's a literal");
            }
        }

        return $this->func('ARRAY_INSERT', $this_, $position, $expression->args['expression'] ?? null);
    }

    protected function arrayanySql(Expression $expression): string
    {
        if ($this->t['CAN_IMPLEMENT_ARRAY_ANY'] === true) {
            $filtered = new ArrayFilter(['this' => $expression->args['this'] ?? null, 'expression' => $expression->args['expression'] ?? null]);
            $filteredNotEmpty = new NEQ(['this' => new ArraySize(['this' => $filtered]), 'expression' => Literal::number(0)]);
            $originalIsEmpty = new EQ(['this' => new ArraySize(['this' => ($expression->argExpr('this') ?? new NullExpr)->copy()]), 'expression' => Literal::number(0)]);

            return $this->sql(Builder::paren(new OrExpr(['this' => $originalIsEmpty, 'expression' => $filteredNotEmpty]), copy: false));
        }

        // SQLGlot's executor supports ARRAY_ANY, so we don't wanna warn for the SQLGlot dialect
        if ($this->dialect::class !== Dialect::class) {
            $this->unsupported('ARRAY_ANY is unsupported');
        }

        return $this->functionFallbackSql($expression);
    }

    protected function structSql(Expression $expression): string
    {
        $fields = [];

        foreach ($expression->argList('expressions') as $e) {
            if ($e instanceof PropertyEQ) {
                $key = $e->args['this'] ?? null;
                $fields[] = Builder::alias(
                    $e->argExpr('expression') ?? new NullExpr,
                    $key instanceof Expression && $key->isString() ? $e->name() : ($key instanceof Identifier ? $key : $e->name()),
                );
            } else {
                $fields[] = $e;
            }
        }

        $expression->set('expressions', $fields);

        return $this->functionFallbackSql($expression);
    }

    protected function partitionrangeSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' TO '.$this->sql($expression, 'expression');
    }

    protected function truncatetableSql(Expression $expression): string
    {
        $target = $expression->flag('is_database') ? 'DATABASE' : 'TABLE';
        $tables = ' '.$this->expressions($expression);
        $exists = $expression->flag('exists') ? ' IF EXISTS' : '';
        $onCluster = $this->sql($expression, 'cluster');
        $onCluster = $onCluster !== '' ? " {$onCluster}" : '';
        $identity = $this->sql($expression, 'identity');
        $identity = $identity !== '' ? " {$identity} IDENTITY" : '';
        $option = $this->sql($expression, 'option');
        $option = $option !== '' ? " {$option}" : '';
        $partition = $this->sql($expression, 'partition');
        $partition = $partition !== '' ? " {$partition}" : '';

        return "TRUNCATE {$target}{$exists}{$tables}{$onCluster}{$identity}{$option}{$partition}";
    }

    /**
     * T-SQL's CONVERT function.
     */
    protected function convertSql(Expression $expression): string
    {
        $to = $expression->args['this'] ?? null;
        $value = $expression->args['expression'] ?? null;
        $style = $expression->args['style'] ?? null;
        $safe = $expression->args['safe'] ?? null;
        $strict = $expression->flag('strict');

        if (! $to instanceof DataType || ! $value instanceof Expression) {
            return '';
        }

        $parameterizable = $this->t['PARAMETERIZABLE_TEXT_TYPES'];
        $toType = $to->args['this'] ?? null;
        $isParameterizable = is_string($toType) && (isset($parameterizable[$toType]) || in_array($toType, $parameterizable, true));

        // Retrieve length of datatype and override to default if not specified
        if ($to->argList('expressions') === [] && $isParameterizable) {
            $to = DataType::build($toType, args: ['expressions' => [Literal::number(30)], 'nested' => false]);
        }

        $transformed = null;
        $castClass = $strict ? Cast::class : TryCast::class;

        // Check whether a conversion with format (T-SQL calls this 'style') is applicable
        if ($style instanceof Literal && $style->isInt()) {
            $styleValue = $style->name();
            $convertedStyle = TSqlDialectTables::CONVERT_FORMAT_MAPPING[$styleValue] ?? null;

            if ($convertedStyle === null) {
                $this->unsupported("Unsupported T-SQL 'style' value: {$styleValue}");
            }

            $fmt = Literal::string((string) $convertedStyle);

            if ($toType === DType::DATE) {
                $transformed = new StrToDate(['this' => $value, 'format' => $fmt]);
            } elseif ($toType === DType::DATETIME || $toType === DType::DATETIME2) {
                $transformed = new StrToTime(['this' => $value, 'format' => $fmt]);
            } elseif ($isParameterizable) {
                $transformed = new $castClass(['this' => new TimeToStr(['this' => $value, 'format' => $fmt]), 'to' => $to, 'safe' => $safe]);
            } elseif ($toType === DType::TEXT) {
                $transformed = new TimeToStr(['this' => $value, 'format' => $fmt]);
            }
        }

        $transformed ??= new $castClass(['this' => $value, 'to' => $to, 'safe' => $safe]);

        return $this->sql($transformed);
    }

    public function jsonpathkeySqlInternal(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof JSONPathWildcard) {
            $this_ = $this->jsonPathPart($this_);

            return $this_ !== '' ? ".{$this_}" : '';
        }

        $key = is_scalar($this_) ? (string) $this_ : '';

        if (! ($expression->flag('quoted') && $this->t['JSON_PATH_KEY_QUOTED_FORCES_BRACKETS'] === true) && preg_match($this->t['SAFE_JSON_PATH_KEY_RE'], $key) === 1) {
            return ".{$key}";
        }

        $key = $this->jsonPathPart($key);

        return $this->quoteJsonPathKeyUsingBrackets && $this->t['JSON_PATH_BRACKETED_KEY_SUPPORTED'] === true
            ? "[{$key}]"
            : ".{$key}";
    }

    public function jsonpathsubscriptSqlInternal(Expression $expression): string
    {
        $this_ = $this->jsonPathPart($expression->args['this'] ?? '');

        return $this_ !== '' ? "[{$this_}]" : '';
    }

    protected function embedIgnoreNulls(Expression $expression, string $text): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Expression && $this->isInstanceOfAny($this_, $this->t['RESPECT_IGNORE_NULLS_UNSUPPORTED_EXPRESSIONS'])) {
            $this->unsupported('RESPECT/IGNORE NULLS is not supported for '.$this_::KEY.' in '.$this->dialectClassName());

            return $this->sql($this_);
        }

        if ($this->t['IGNORE_NULLS_IN_FUNC'] === true && $expression->metaGet('inline') !== true) {
            if ($this->t['IGNORE_NULLS_BEFORE_ORDER'] === true) {
                // The first modifier here will be the one closest to the AggFunc's arg
                $mods = Scope::findAllInScope($expression, HavingMax::class, Order::class, Limit::class);
                usort($mods, static fn (Expression $a, Expression $b): int => self::modifierRank($a) <=> self::modifierRank($b));

                if ($mods !== []) {
                    $mod = $mods[0];
                    $modThis = $mod->argExpr('this') ?? new NullExpr;
                    $inlined = new ($expression::class)(['this' => $modThis->copy()]);
                    $inlined->setMeta('inline', true);
                    $modThis->replace($inlined);

                    return $this->sql($expression->argExpr('this'));
                }
            }

            $aggFunc = $expression->find(AggFunc::class);

            if ($aggFunc !== null) {
                $aggFuncSql = substr($this->sql($aggFunc, comment: false), 0, -1)." {$text})";

                return $this->maybeComment($aggFuncSql, comments: $aggFunc->comments);
            }
        }

        return $this->sql($expression, 'this')." {$text}";
    }

    private static function modifierRank(Expression $modifier): int
    {
        return $modifier instanceof HavingMax ? 0 : ($modifier instanceof Order ? 1 : 2);
    }

    protected function copyparameterSql(Expression $expression): string
    {
        $option = $this->sql($expression, 'this');

        if ($expression->argList('expressions') !== []) {
            $upper = strtoupper($option);

            // Snowflake FILE_FORMAT options are separated by whitespace
            $sep = $upper === 'FILE_FORMAT' ? ' ' : ', ';

            // Databricks copy/format options do not set their list of values with EQ
            $op = $upper === 'COPY_OPTIONS' || $upper === 'FORMAT_OPTIONS' ? ' ' : ' = ';
            $values = $this->expressions($expression, flat: true, sep: $sep);

            return "{$option}{$op}({$values})";
        }

        $value = $this->sql($expression, 'expression');

        if ($value === '') {
            return $option;
        }

        $op = $this->t['COPY_PARAMS_EQ_REQUIRED'] === true ? ' = ' : ' ';

        return "{$option}{$op}{$value}";
    }

    protected function credentialsSql(Expression $expression): string
    {
        $credExpr = $expression->args['credentials'] ?? null;

        if ($credExpr instanceof Literal) {
            // Redshift case: CREDENTIALS <string>
            $credentials = $this->sql($expression, 'credentials');
            $credentials = $credentials !== '' ? "CREDENTIALS {$credentials}" : '';
        } else {
            // Snowflake case: CREDENTIALS = (...)
            $credentials = $this->expressions($expression, key: 'credentials', flat: true, sep: ' ');
            $credentials = $credExpr !== null ? "CREDENTIALS = ({$credentials})" : '';
        }

        $storage = $this->sql($expression, 'storage');
        $storage = $storage !== '' ? "STORAGE_INTEGRATION = {$storage}" : '';
        $encryption = $this->expressions($expression, key: 'encryption', flat: true, sep: ' ');
        $encryption = $encryption !== '' ? " ENCRYPTION = ({$encryption})" : '';
        $iamRole = $this->sql($expression, 'iam_role');
        $iamRole = $iamRole !== '' ? "IAM_ROLE {$iamRole}" : '';
        $region = $this->sql($expression, 'region');
        $region = $region !== '' ? " REGION {$region}" : '';

        return "{$credentials}{$storage}{$encryption}{$iamRole}{$region}";
    }

    protected function copySql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this->t['COPY_HAS_INTO_KEYWORD'] === true ? " INTO {$this_}" : " {$this_}";

        $credentials = $this->sql($expression, 'credentials');
        $credentials = $credentials !== '' ? $this->seg($credentials) : '';
        $files = $this->expressions($expression, key: 'files', flat: true);
        $kind = $files !== '' ? $this->seg($expression->flag('kind') ? 'FROM' : 'TO') : '';

        $sep = $this->dialect->settings['COPY_PARAMS_ARE_CSV'] === true ? ', ' : ' ';
        $params = $this->expressions(
            $expression,
            key: 'params',
            sep: $sep,
            newLine: true,
            skipLast: true,
            skipFirst: true,
            indent: $this->t['COPY_PARAMS_ARE_WRAPPED'] === true,
        );

        if ($params !== '') {
            if ($this->t['COPY_PARAMS_ARE_WRAPPED'] === true) {
                $params = " WITH ({$params})";
            } elseif (! $this->pretty && ($files !== '' || $credentials !== '')) {
                $params = " {$params}";
            }
        }

        return "COPY{$this_}{$kind} {$files}{$credentials}{$params}";
    }

    protected function semicolonSql(Expression $expression): string
    {
        return '';
    }

    protected function datadeletionpropertySql(Expression $expression): string
    {
        $onSql = $expression->flag('on') ? 'ON' : 'OFF';
        $filterCol = $this->sql($expression, 'filter_column');
        $filterCol = $filterCol !== '' ? "FILTER_COLUMN={$filterCol}" : null;
        $retentionPeriod = $this->sql($expression, 'retention_period');
        $retentionPeriod = $retentionPeriod !== '' ? "RETENTION_PERIOD={$retentionPeriod}" : null;

        if ($filterCol !== null || $retentionPeriod !== null) {
            $onSql = $this->func('ON', $filterCol, $retentionPeriod);
        }

        return "DATA_DELETION={$onSql}";
    }

    protected function maskingpolicycolumnconstraintSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? " USING ({$expressions})" : '';

        return "MASKING POLICY {$this_}{$expressions}";
    }

    protected function gapfillSql(Expression $expression): string
    {
        $args = ['TABLE '.$this->sql($expression, 'this')];

        foreach ($expression->args as $key => $value) {
            if ($key !== 'this') {
                $args[] = $value;
            }
        }

        return $this->funcWith('GAP_FILL', $args);
    }

    public function scopeResolution(string $rhs, string $scopeName): string
    {
        return $this->func('SCOPE_RESOLUTION', $scopeName !== '' ? $scopeName : null, $rhs);
    }

    protected function scoperesolutionSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expr = $expression->args['expression'] ?? null;

        if ($expr instanceof Func && $expr instanceof Expression) {
            // T-SQL's CLR functions are case sensitive
            $exprSql = $this->sql($expr, 'this').'('.$this->formatArgs($expr->argList('expressions')).')';
        } else {
            $exprSql = $this->sql($expression, 'expression');
        }

        return $this->scopeResolution($exprSql, $this_);
    }

    protected function parsejsonSql(Expression $expression): string
    {
        $name = $this->t['PARSE_JSON_NAME'];

        if ($name === null) {
            return $this->sql($expression, 'this');
        }

        return $this->func($name, $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
    }

    protected function randSql(Expression $expression): string
    {
        $lower = $this->sql($expression, 'lower');
        $upper = $this->sql($expression, 'upper');

        if ($lower !== '' && $upper !== '') {
            return "({$upper} - {$lower}) * ".$this->func('RAND', $expression->args['this'] ?? null)." + {$lower}";
        }

        return $this->func('RAND', $expression->args['this'] ?? null);
    }

    protected function changesSql(Expression $expression): string
    {
        $information = 'INFORMATION => '.$this->sql($expression, 'information');
        $atBefore = $this->sql($expression, 'at_before');
        $atBefore = $atBefore !== '' ? $this->seg('').$atBefore : '';
        $end = $this->sql($expression, 'end');
        $end = $end !== '' ? $this->seg('').$end : '';

        return "CHANGES ({$information}){$atBefore}{$end}";
    }

    protected function padSql(Expression $expression): string
    {
        $prefix = $expression->flag('is_left') ? 'L' : 'R';
        $fillPattern = $this->sql($expression, 'fill_pattern');
        $fillPattern = $fillPattern !== '' ? $fillPattern : null;

        if ($fillPattern === null && $this->t['PAD_FILL_PATTERN_IS_REQUIRED'] === true) {
            $fillPattern = "' '";
        }

        return $this->func("{$prefix}PAD", $expression->args['this'] ?? null, $expression->args['expression'] ?? null, $fillPattern);
    }

    protected function summarizeSql(Expression $expression): string
    {
        $table = $expression->flag('table') ? ' TABLE' : '';

        return "SUMMARIZE{$table} ".$this->sql($expression, 'this');
    }

    protected function explodinggenerateseriesSql(Expression $expression): string
    {
        $generateSeries = new GenerateSeries($expression->args);

        $parent = $expression->parent;

        if ($parent instanceof Alias || $parent instanceof TableAlias) {
            $parent = $parent->parent;
        }

        if ($this->t['SUPPORTS_EXPLODING_PROJECTIONS'] === true && ! ($parent instanceof Table || $parent instanceof Unnest)) {
            return $this->sql(new Unnest(['expressions' => [$generateSeries]]));
        }

        if ($parent instanceof Select) {
            $this->unsupported('GenerateSeries projection unnesting is not supported.');
        }

        return $this->sql($generateSeries);
    }

    protected function converttimezoneSql(Expression $expression): string
    {
        if ($this->t['SUPPORTS_CONVERT_TIMEZONE'] === true) {
            return $this->functionFallbackSql($expression);
        }

        $sourceTz = $expression->args['source_tz'] ?? null;
        $targetTz = $expression->args['target_tz'] ?? null;
        $timestamp = $expression->args['timestamp'] ?? null;

        if ($this->truthy($sourceTz) && $timestamp instanceof Expression) {
            $timestamp = new AtTimeZone(['this' => Builder::cast($timestamp, DType::TIMESTAMPNTZ), 'zone' => $sourceTz]);
        }

        return $this->sql(new AtTimeZone(['this' => $timestamp, 'zone' => $targetTz]));
    }

    protected function jsonSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $with = $expression->args['with_'] ?? null;

        if ($with === null) {
            $withSql = '';
        } elseif (! $this->truthy($with)) {
            $withSql = ' WITHOUT';
        } else {
            $withSql = ' WITH';
        }

        $uniqueSql = $expression->flag('unique') ? ' UNIQUE KEYS' : '';

        return "JSON{$this_}{$withSql}{$uniqueSql}";
    }

    protected function jsonvalueSql(Expression $expression): string
    {
        $path = $this->sql($expression, 'path');
        $returning = $this->sql($expression, 'returning');
        $returning = $returning !== '' ? " RETURNING {$returning}" : '';
        $onCondition = $this->sql($expression, 'on_condition');
        $onCondition = $onCondition !== '' ? " {$onCondition}" : '';

        return $this->func('JSON_VALUE', $expression->args['this'] ?? null, "{$path}{$returning}{$onCondition}");
    }

    protected function skipjsoncolumnSql(Expression $expression): string
    {
        $regexp = $expression->flag('regexp') ? ' REGEXP' : '';

        return "SKIP{$regexp} ".$this->sql($expression, 'expression');
    }

    protected function conditionalinsertSql(Expression $expression): string
    {
        $else = $expression->flag('else_') ? 'ELSE ' : '';
        $condition = $this->sql($expression, 'expression');
        $condition = $condition !== '' ? "WHEN {$condition} THEN " : $else;
        $insert = trim(substr($this->sql($expression, 'this'), strlen('INSERT')));

        return "{$condition}{$insert}";
    }

    protected function multitableinsertsSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $expressions = $this->seg($this->expressions($expression, sep: ' '));

        return "INSERT {$kind}{$expressions}".$this->seg($this->sql($expression, 'source'));
    }

    protected function onconditionSql(Expression $expression): string
    {
        // Static options like "NULL ON ERROR" are stored as strings, in contrast to "DEFAULT <expr> ON ERROR"
        $empty = $expression->args['empty'] ?? null;
        $empty = $empty instanceof Expression ? "DEFAULT {$empty} ON EMPTY" : $this->sql($expression, 'empty');

        $error = $expression->args['error'] ?? null;
        $error = $error instanceof Expression ? "DEFAULT {$error} ON ERROR" : $this->sql($expression, 'error');

        if ($error !== '' && $empty !== '') {
            $error = $this->dialect->settings['ON_CONDITION_EMPTY_BEFORE_ERROR'] === true ? "{$empty} {$error}" : "{$error} {$empty}";
            $empty = '';
        }

        $null = $this->sql($expression, 'null');

        return "{$empty}{$error}{$null}";
    }

    protected function jsonextractquoteSql(Expression $expression): string
    {
        $scalar = $expression->flag('scalar') ? ' ON SCALAR STRING' : '';

        return $this->sql($expression, 'option')." QUOTES{$scalar}";
    }

    protected function jsonexistsSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $path = $this->sql($expression, 'path');
        $passing = $this->expressions($expression, key: 'passing');
        $passing = $passing !== '' ? " PASSING {$passing}" : '';
        $onCondition = $this->sql($expression, 'on_condition');
        $onCondition = $onCondition !== '' ? " {$onCondition}" : '';

        return $this->func('JSON_EXISTS', $this_, "{$path}{$passing}{$onCondition}");
    }

    /**
     * Add a NULL filter to ARRAY_AGG to mimic the results going from a dialect that excludes nulls
     * on ARRAY_AGG (e.g. Spark) to one that doesn't (e.g. DuckDB).
     */
    protected function addArrayaggNullFilter(string $arrayAggSql, Expression $arrayAggExpr, Expression $columnExpr): string
    {
        if (! ($this->dialect->settings['ARRAY_AGG_INCLUDES_NULLS'] === true && $arrayAggExpr->flag('nulls_excluded'))) {
            return $arrayAggSql;
        }

        $parent = $arrayAggExpr->parent;

        if ($parent instanceof Filter) {
            $parentCond = $parent->argExpr('expression')?->argExpr('this');

            if ($parentCond !== null) {
                $parentCond->replace(new AndExpr([
                    'this' => $parentCond->copy(),
                    'expression' => new Not(['this' => new Is(['this' => $columnExpr->copy(), 'expression' => new NullExpr])]),
                ]));
            }
        } elseif ($columnExpr->find(Column::class) !== null) {
            // Do not add the filter if the input is not a column (e.g. literal, struct etc)
            // DISTINCT is already present in the agg function, do not propagate it to FILTER as well
            $thisSql = $columnExpr instanceof Distinct ? $this->expressions($columnExpr) : $this->sql($columnExpr);
            $arrayAggSql = "{$arrayAggSql} FILTER(WHERE {$thisSql} IS NOT NULL)";
        }

        return $arrayAggSql;
    }

    protected function arrayaggSql(Expression $expression): string
    {
        $arrayAgg = $this->functionFallbackSql($expression);
        $columnExpr = $expression->argExpr('this') ?? new NullExpr;

        if ($columnExpr instanceof Order) {
            $columnExpr = $columnExpr->argExpr('this') ?? new NullExpr;
        }

        return $this->addArrayaggNullFilter($arrayAgg, $expression, $columnExpr);
    }

    protected function sliceSql(Expression $expression): string
    {
        $step = $this->sql($expression, 'step');
        $end = $this->sql($expression, 'expression');
        $begin = $this->sql($expression, 'this');

        $sql = $step !== '' ? "{$end}:{$step}" : $end;

        return $sql !== '' ? "{$begin}:{$sql}" : "{$begin}:";
    }

    protected function applySql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' APPLY('.$this->sql($expression, 'expression').')';
    }

    protected function grantOrRevokeSqlInternal(Expression $expression, string $keyword, string $preposition, string $grantOptionPrefix = '', string $grantOptionSuffix = ''): string
    {
        $privilegesSql = $this->expressions($expression, key: 'privileges', flat: true);
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? " {$kind}" : '';
        $securable = $this->sql($expression, 'securable');
        $securable = $securable !== '' ? " {$securable}" : '';
        $principals = $this->expressions($expression, key: 'principals', flat: true);

        if (! $expression->flag('grant_option')) {
            $grantOptionPrefix = $grantOptionSuffix = '';
        }

        // cascade for revoke only
        $cascade = $this->sql($expression, 'cascade');
        $cascade = $cascade !== '' ? " {$cascade}" : '';

        return "{$keyword} {$grantOptionPrefix}{$privilegesSql} ON{$kind}{$securable} {$preposition} {$principals}{$grantOptionSuffix}{$cascade}";
    }

    protected function grantSql(Expression $expression): string
    {
        return $this->grantOrRevokeSqlInternal($expression, keyword: 'GRANT', preposition: 'TO', grantOptionSuffix: ' WITH GRANT OPTION');
    }

    protected function revokeSql(Expression $expression): string
    {
        return $this->grantOrRevokeSqlInternal($expression, keyword: 'REVOKE', preposition: 'FROM', grantOptionPrefix: 'GRANT OPTION FOR ');
    }

    protected function grantprivilegeSql(Expression $expression): string
    {
        $columns = $this->expressions($expression, flat: true);
        $columns = $columns !== '' ? "({$columns})" : '';

        return $this->sql($expression, 'this').$columns;
    }

    protected function grantprincipalSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? "{$kind} " : '';

        return $kind.$this->sql($expression, 'this');
    }

    protected function columnsSql(Expression $expression): string
    {
        $func = $this->functionFallbackSql($expression);

        return $expression->flag('unpack') ? "*{$func}" : $func;
    }

    protected function overlaySql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expr = $this->sql($expression, 'expression');
        $fromSql = $this->sql($expression, 'from_');
        $forSql = $this->sql($expression, 'for_');
        $forSql = $forSql !== '' ? " FOR {$forSql}" : '';

        return "OVERLAY({$this_} PLACING {$expr} FROM {$fromSql}{$forSql})";
    }

    protected function todoubleSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'format');
        $class = $expression->flag('safe') ? TryCast::class : Cast::class;

        return $this->sql(new $class(['this' => $expression->args['this'] ?? null, 'to' => new DataType(['this' => DType::DOUBLE])]));
    }

    protected function stringSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $zone = $expression->args['zone'] ?? null;

        if ($this->truthy($zone)) {
            // This is a BigQuery specific argument for STRING(<timestamp_expr>, <time_zone>)
            // BigQuery stores timestamps internally as UTC, so ConvertTimezone is used with UTC
            // set for source_tz to transpile the time conversion before the STRING cast
            $this_ = new ConvertTimezone(['source_tz' => Literal::string('UTC'), 'target_tz' => $zone, 'timestamp' => $this_]);
        }

        return $this->sql(Builder::cast($this_, DType::VARCHAR));
    }

    protected function medianSql(Expression $expression): string
    {
        if ($this->t['SUPPORTS_MEDIAN'] !== true) {
            return $this->sql(new PercentileCont(['this' => $expression->args['this'] ?? null, 'expression' => Literal::number('0.5')]));
        }

        return $this->functionFallbackSql($expression);
    }

    protected function overflowtruncatebehaviorSql(Expression $expression): string
    {
        $filler = $this->sql($expression, 'this');
        $filler = $filler !== '' ? " {$filler}" : '';
        $withCount = $expression->flag('with_count') ? 'WITH COUNT' : 'WITHOUT COUNT';

        return "TRUNCATE{$filler} {$withCount}";
    }

    protected function unixsecondsSql(Expression $expression): string
    {
        if ($this->t['SUPPORTS_UNIX_SECONDS'] === true) {
            return $this->functionFallbackSql($expression);
        }

        $startTs = Builder::cast(Literal::string('1970-01-01 00:00:00+00'), DType::TIMESTAMPTZ);

        return $this->sql(new TimestampDiff(['this' => $expression->args['this'] ?? null, 'expression' => $startTs, 'unit' => Builder::var('SECONDS')]));
    }

    protected function arraysizeSql(Expression $expression): string
    {
        $dim = $expression->args['expression'] ?? null;
        $dimRequired = $this->t['ARRAY_SIZE_DIM_REQUIRED'];

        // For dialects that don't support the dimension arg, we can safely transpile it's default value (1st dimension)
        if ($dim instanceof Expression && $dimRequired === null) {
            if (! ($dim->isInt() && $dim->name() === '1')) {
                $this->unsupported('Cannot transpile dimension argument for ARRAY_LENGTH');
            }

            $dim = null;
        }

        // If dimension is required but not specified, default initialize it
        if ($dimRequired === true && ! $dim instanceof Expression) {
            $dim = Literal::number(1);
        }

        return $this->func($this->t['ARRAY_SIZE_NAME'], $expression->args['this'] ?? null, $dim);
    }

    protected function attachSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $existsSql = $expression->flag('exists') ? ' IF NOT EXISTS' : '';
        $expressions = $this->expressions($expression);
        $expressions = $expressions !== '' ? " ({$expressions})" : '';

        return "ATTACH{$existsSql} {$this_}{$expressions}";
    }

    protected function detachSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? " {$kind}" : '';
        // the DATABASE keyword is required if IF EXISTS is set for DuckDB
        $exists = $expression->flag('exists') ? ' IF EXISTS' : '';

        if ($exists !== '' && $kind === '') {
            $kind = ' DATABASE';
        }

        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $cluster = $this->sql($expression, 'cluster');
        $cluster = $cluster !== '' ? " {$cluster}" : '';
        $permanent = $expression->flag('permanent') ? ' PERMANENTLY' : '';
        $sync = $expression->flag('sync') ? ' SYNC' : '';

        return "DETACH{$kind}{$exists}{$this_}{$cluster}{$permanent}{$sync}";
    }

    protected function attachoptionSql(Expression $expression): string
    {
        $value = $this->sql($expression, 'expression');
        $value = $value !== '' ? " {$value}" : '';

        return $this->sql($expression, 'this').$value;
    }

    protected function watermarkcolumnconstraintSql(Expression $expression): string
    {
        return 'WATERMARK FOR '.$this->sql($expression, 'this').' AS '.$this->sql($expression, 'expression');
    }

    protected function encodepropertySql(Expression $expression): string
    {
        $encode = ($expression->flag('key') ? 'KEY ENCODE' : 'ENCODE').' '.$this->sql($expression, 'this');
        $properties = $expression->args['properties'] ?? null;

        if ($properties instanceof Properties) {
            $encode = "{$encode} ".$this->properties($properties);
        }

        return $encode;
    }

    protected function includepropertySql(Expression $expression): string
    {
        $include = 'INCLUDE '.$this->sql($expression, 'this');
        $columnDef = $this->sql($expression, 'column_def');

        if ($columnDef !== '') {
            $include = "{$include} {$columnDef}";
        }

        $alias = $this->sql($expression, 'alias');

        if ($alias !== '') {
            $include = "{$include} AS {$alias}";
        }

        return $include;
    }

    protected function xmlelementSql(Expression $expression): string
    {
        $prefix = $expression->flag('evalname') ? 'EVALNAME' : 'NAME';

        return $this->funcWith('XMLELEMENT', [$prefix.' '.$this->sql($expression, 'this'), ...$expression->argList('expressions')]);
    }

    protected function xmlkeyvalueoptionSql(Expression $expression): string
    {
        $expr = $this->sql($expression, 'expression');
        $expr = $expr !== '' ? "({$expr})" : '';

        return $this->sql($expression, 'this').$expr;
    }

    protected function partitionbyrangepropertySql(Expression $expression): string
    {
        $partitions = $this->expressions($expression, key: 'partition_expressions');
        $create = $this->expressions($expression, key: 'create_expressions');

        return 'PARTITION BY RANGE '.$this->wrap($partitions).' '.$this->wrap($create);
    }

    protected function partitionbyrangepropertydynamicSql(Expression $expression): string
    {
        $start = $this->sql($expression, 'start');
        $end = $this->sql($expression, 'end');
        $every = $expression->args['every'] ?? null;

        if ($every instanceof Interval && ($value = $every->argExpr('this')) !== null && $value->isString()) {
            $value->replace(Literal::number($every->name()));
        }

        return 'START '.$this->wrap($start).' END '.$this->wrap($end).' EVERY '.$this->wrap($this->sql($every));
    }

    protected function unpivotcolumnsSql(Expression $expression): string
    {
        return 'NAME '.$this->sql($expression, 'this').' VALUE '.$this->expressions($expression, flat: true);
    }

    protected function analyzesampleSql(Expression $expression): string
    {
        return 'SAMPLE '.$this->sql($expression, 'sample').' '.$this->sql($expression, 'kind');
    }

    protected function analyzestatisticsSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $option = $this->sql($expression, 'option');
        $option = $option !== '' ? " {$option}" : '';
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $columns = $this->expressions($expression);
        $columns = $columns !== '' ? " {$columns}" : '';

        return "{$kind}{$option} STATISTICS{$this_}{$columns}";
    }

    protected function analyzehistogramSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $columns = $this->expressions($expression);
        $innerExpression = $this->sql($expression, 'expression');
        $innerExpression = $innerExpression !== '' ? " {$innerExpression}" : '';
        $updateOptions = $this->sql($expression, 'update_options');
        $updateOptions = $updateOptions !== '' ? " {$updateOptions} UPDATE" : '';

        return "{$this_} HISTOGRAM ON {$columns}{$innerExpression}{$updateOptions}";
    }

    protected function analyzedeleteSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? " {$kind}" : '';

        return "DELETE{$kind} STATISTICS";
    }

    protected function analyzelistchainedrowsSql(Expression $expression): string
    {
        return 'LIST CHAINED ROWS'.$this->sql($expression, 'expression');
    }

    protected function analyzevalidateSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';

        return "VALIDATE {$kind}{$this_}".$this->sql($expression, 'expression');
    }

    protected function analyzeSql(Expression $expression): string
    {
        $options = $this->expressions($expression, key: 'options', sep: ' ');
        $options = $options !== '' ? " {$options}" : '';
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? " {$kind}" : '';
        $tables = $this->expressions($expression, key: 'tables', flat: true);
        $tables = $tables !== '' ? " {$tables}" : '';
        $mode = $this->sql($expression, 'mode');
        $mode = $mode !== '' ? " {$mode}" : '';
        $properties = $this->sql($expression, 'properties');
        $properties = $properties !== '' ? " {$properties}" : '';
        $partition = $this->sql($expression, 'partition');
        $partition = $partition !== '' ? " {$partition}" : '';
        $innerExpression = $this->sql($expression, 'expression');
        $innerExpression = $innerExpression !== '' ? " {$innerExpression}" : '';

        return "ANALYZE{$options}{$kind}{$tables}{$partition}{$mode}{$innerExpression}{$properties}";
    }

    protected function xmltableSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $namespaces = $this->expressions($expression, key: 'namespaces');
        $namespaces = $namespaces !== '' ? "XMLNAMESPACES({$namespaces}), " : '';
        $passing = $this->expressions($expression, key: 'passing');
        $passing = $passing !== '' ? $this->sep().'PASSING'.$this->seg($passing) : '';
        $columns = $this->expressions($expression, key: 'columns');
        $columns = $columns !== '' ? $this->sep().'COLUMNS'.$this->seg($columns) : '';
        $byRef = $expression->flag('by_ref') ? $this->sep().'RETURNING SEQUENCE BY REF' : '';

        return 'XMLTABLE('.$this->sep('').$this->indent($namespaces.$this_.$passing.$byRef.$columns).$this->seg(')', sep: '');
    }

    protected function xmlnamespaceSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');

        return ($expression->args['this'] ?? null) instanceof Alias ? $this_ : "DEFAULT {$this_}";
    }

    protected function exportSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $connection = $this->sql($expression, 'connection');
        $connection = $connection !== '' ? "WITH CONNECTION {$connection} " : '';
        $options = $this->sql($expression, 'options');

        return "EXPORT DATA {$connection}{$options} AS {$this_}";
    }

    protected function declareSql(Expression $expression): string
    {
        $replace = $expression->flag('replace') ? 'OR REPLACE ' : '';

        return "DECLARE {$replace}".$this->expressions($expression, flat: true);
    }

    protected function declareitemSql(Expression $expression): string
    {
        $variables = $this->expressions($expression, key: 'this');
        $default = $this->sql($expression, 'default');
        $default = $default !== '' ? ' '.$this->t['DECLARE_DEFAULT_ASSIGNMENT']." {$default}" : '';
        $kind = $this->sql($expression, 'kind');

        if (($expression->args['kind'] ?? null) instanceof Schema) {
            $kind = "TABLE {$kind}";
        }

        $kind = $kind !== '' ? " {$kind}" : '';

        return "{$variables}{$kind}{$default}";
    }

    protected function recursivewithsearchSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $this_ = $this->sql($expression, 'this');
        $set = $this->sql($expression, 'expression');
        $using = $this->sql($expression, 'using');
        $using = $using !== '' ? " USING {$using}" : '';
        $kindSql = $kind === 'CYCLE' ? $kind : "SEARCH {$kind} FIRST BY";

        return "{$kindSql} {$this_} SET {$set}{$using}";
    }

    protected function parameterizedaggSql(Expression $expression): string
    {
        $params = $this->expressions($expression, key: 'params', flat: true);

        return $this->funcWith($expression->name(), $expression->argList('expressions'))."({$params})";
    }

    protected function anonymousaggfuncSql(Expression $expression): string
    {
        return $this->funcWith($expression->name(), $expression->argList('expressions'));
    }

    protected function combinedaggfuncSql(Expression $expression): string
    {
        return $this->anonymousaggfuncSql($expression);
    }

    protected function combinedparameterizedaggSql(Expression $expression): string
    {
        return $this->parameterizedaggSql($expression);
    }

    protected function showSql(Expression $expression): string
    {
        $this->unsupported('Unsupported SHOW statement');

        return '';
    }

    protected function installSql(Expression $expression): string
    {
        $this->unsupported('Unsupported INSTALL statement');

        return '';
    }

    public function getPutSql(Expression $expression): string
    {
        // Snowflake GET/PUT statements:
        //   PUT <file> <internalStage> <properties>
        //   GET <internalStage> <file> <properties>
        $props = $expression->args['properties'] ?? null;
        $propsSql = $props instanceof Properties ? $this->properties($props, prefix: ' ', sep: ' ', wrapped: false) : '';
        $this_ = $this->sql($expression, 'this');
        $target = $this->sql($expression, 'target');

        if ($expression instanceof Put) {
            return "PUT {$this_} {$target}{$propsSql}";
        }

        return "GET {$target} {$this_}{$propsSql}";
    }

    protected function translatecharactersSql(Expression $expression): string
    {
        $withError = $expression->flag('with_error') ? ' WITH ERROR' : '';

        return 'TRANSLATE('.$this->sql($expression, 'this').' USING '.$this->sql($expression, 'expression')."{$withError})";
    }

    protected function decodecaseSql(Expression $expression): string
    {
        $args = $expression->argList('expressions');

        if ($this->t['SUPPORTS_DECODE_CASE'] === true) {
            return $this->funcWith('DECODE', $args);
        }

        $decodeExpr = array_shift($args) ?? new NullExpr;
        $ifs = [];

        for ($i = 0; $i + 1 < count($args); $i += 2) {
            $search = $args[$i];
            $result = $args[$i + 1];

            if ($search instanceof Literal) {
                $ifs[] = new IfExpr(['this' => new EQ(['this' => $decodeExpr->copy(), 'expression' => $search]), 'true' => $result]);
            } elseif ($search instanceof NullExpr) {
                $ifs[] = new IfExpr(['this' => new Is(['this' => $decodeExpr->copy(), 'expression' => new NullExpr]), 'true' => $result]);
            } else {
                if ($search instanceof Binary) {
                    $search = Builder::paren($search);
                }

                $cond = Builder::or([
                    new EQ(['this' => $decodeExpr->copy(), 'expression' => $search->copy()]),
                    Builder::and([
                        new Is(['this' => $decodeExpr->copy(), 'expression' => new NullExpr]),
                        new Is(['this' => $search->copy(), 'expression' => new NullExpr]),
                    ], copy: false),
                ], copy: false);
                $ifs[] = new IfExpr(['this' => $cond, 'true' => $result]);
            }
        }

        $default = count($args) % 2 === 1 ? $args[count($args) - 1] : null;

        return $this->sql(new CaseExpr(['ifs' => $ifs, 'default' => $default]));
    }

    protected function semanticviewSql(Expression $expression): string
    {
        $this_ = $this->seg($this->sql($expression, 'this'), sep: '');
        $dimensions = $this->expressions($expression, key: 'dimensions', dynamic: true, skipFirst: true, skipLast: true);
        $dimensions = $dimensions !== '' ? $this->seg("DIMENSIONS {$dimensions}") : '';
        $metrics = $this->expressions($expression, key: 'metrics', dynamic: true, skipFirst: true, skipLast: true);
        $metrics = $metrics !== '' ? $this->seg("METRICS {$metrics}") : '';
        $facts = $this->expressions($expression, key: 'facts', dynamic: true, skipFirst: true, skipLast: true);
        $facts = $facts !== '' ? $this->seg("FACTS {$facts}") : '';
        $where = $this->sql($expression, 'where');
        $where = $where !== '' ? $this->seg("WHERE {$where}") : '';
        $body = $this->indent($this_.$metrics.$dimensions.$facts.$where, skipFirst: true);

        return "SEMANTIC_VIEW({$body}".$this->seg(')', sep: '');
    }

    protected function getextractSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $expr = $expression->args['expression'] ?? null;

        if ($this_->type() === null || $expression->type() === null) {
            $this_ = $this->dialect->annotateTypes($this_);
        }

        if ($this_->isType(DType::ARRAY, DType::MAP)) {
            return $this->sql(new Bracket(['this' => $this_, 'expressions' => [$expr]]));
        }

        return $this->sql(new JSONExtract(['this' => $this_, 'expression' => $this->dialect->toJsonPath($expr instanceof Expression ? $expr : null)]));
    }

    protected function datefromunixdateSql(Expression $expression): string
    {
        return $this->sql(new DateAdd([
            'this' => Builder::cast(Literal::string('1970-01-01'), DType::DATE),
            'expression' => $expression->args['this'] ?? null,
            'unit' => Builder::var('DAY'),
        ]));
    }

    protected function spaceSql(Expression $expression): string
    {
        return $this->sql(new Repeat(['this' => Literal::string(' '), 'times' => $expression->args['this'] ?? null]));
    }

    protected function buildpropertySql(Expression $expression): string
    {
        return 'BUILD '.$this->sql($expression, 'this');
    }

    protected function refreshtriggerpropertySql(Expression $expression): string
    {
        $method = $this->sql($expression, 'method');
        $kind = $expression->args['kind'] ?? null;

        if (! $this->truthy($kind)) {
            return "REFRESH {$method}";
        }

        $every = $this->sql($expression, 'every');
        $unit = $this->sql($expression, 'unit');
        $every = $every !== '' ? " EVERY {$every} {$unit}" : '';
        $starts = $this->sql($expression, 'starts');
        $starts = $starts !== '' ? " STARTS {$starts}" : '';

        return "REFRESH {$method} ON ".$this->sql($expression, 'kind')."{$every}{$starts}";
    }

    protected function modelattributeSql(Expression $expression): string
    {
        $this->unsupported('The model!attribute syntax is not supported');

        return '';
    }

    protected function directorystageSql(Expression $expression): string
    {
        return $this->func('DIRECTORY', $expression->args['this'] ?? null);
    }

    protected function uuidSql(Expression $expression): string
    {
        $uuidFuncSql = $this->func('UUID');

        if ($expression->flag('is_string') && $this->dialect->settings['UUID_IS_STRING_TYPE'] !== true) {
            return $this->sql(Builder::cast($uuidFuncSql, DType::VARCHAR, dialect: $this->dialect));
        }

        return $uuidFuncSql;
    }

    protected function initcapSql(Expression $expression): string
    {
        $delimiters = $expression->args['expression'] ?? null;

        if ($delimiters instanceof Expression) {
            // do not generate delimiters arg if we are round-tripping from default delimiters
            if ($delimiters->isString() && $delimiters->args['this'] === $this->dialect->settings['INITCAP_DEFAULT_DELIMITER_CHARS']) {
                $delimiters = null;
            } elseif ($this->dialect->settings['INITCAP_SUPPORTS_CUSTOM_DELIMITERS'] !== true) {
                $this->unsupported('INITCAP does not support custom delimiters');
                $delimiters = null;
            }
        }

        return $this->func('INITCAP', $expression->args['this'] ?? null, $delimiters);
    }

    protected function localtimeSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        return $this->truthy($this_) ? $this->func('LOCALTIME', $this_) : 'LOCALTIME';
    }

    protected function localtimestampSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        return $this->truthy($this_) ? $this->func('LOCALTIMESTAMP', $this_) : 'LOCALTIMESTAMP';
    }

    public function weekstartName(Expression $expression): string
    {
        // WEEK(<day>) is BigQuery-only syntax, so it degrades to the plain WEEK unit
        $this_ = strtoupper(($expression->argExpr('this') ?? new NullExpr)->name());

        $dowFromWeekStartDay = self::WEEK_START_DAY_TO_DOW[$this_] ?? null;
        $dowFromWeekOffset = self::weekOffsetToDow((int) $this->dialect->settings['WEEK_OFFSET']);

        if ($dowFromWeekStartDay !== $dowFromWeekOffset) {
            $this->unsupported("WEEK({$this_}) is not supported; falling back to the default week start day");
        }

        return 'WEEK';
    }

    protected function weekstartSql(Expression $expression): string
    {
        $name = $this->weekstartName($expression);

        // DateTrunc stores string literal units, whereas TimeUnit expressions store keywords
        if ($expression->parent instanceof DateTrunc) {
            return $this->sql(Literal::string($name));
        }

        return $name;
    }

    protected function chrSql(Expression $expression, string $name = 'CHR'): string
    {
        $this_ = $this->expressions($expression);
        $charset = $this->sql($expression, 'charset');
        $using = $charset !== '' ? " USING {$charset}" : '';

        return $this->func($name, $this_.$using);
    }

    protected function blockSql(Expression $expression): string
    {
        $expressions = $this->expressions($expression, sep: '; ', flat: true);
        $begin = $expression->flag('begin') ? 'BEGIN ' : '';

        return $expressions !== '' ? "{$begin}{$expressions}" : '';
    }

    protected function functionspecificationSql(Expression $expression): string
    {
        $this->unsupported('Unsupported Inline UDFs syntax');

        return '';
    }

    protected function storedprocedureSql(Expression $expression): string
    {
        $this->unsupported('Unsupported Stored Procedure syntax');

        return '';
    }

    protected function ifblockSql(Expression $expression): string
    {
        $this->unsupported('Unsupported If block syntax');

        return '';
    }

    protected function casestatementSql(Expression $expression): string
    {
        $this->unsupported('Unsupported Case statement syntax');

        return '';
    }

    protected function whileblockSql(Expression $expression): string
    {
        $this->unsupported('Unsupported While block syntax');

        return '';
    }

    protected function loopblockSql(Expression $expression): string
    {
        $this->unsupported('Unsupported Loop block syntax');

        return '';
    }

    protected function repeatblockSql(Expression $expression): string
    {
        $this->unsupported('Unsupported Repeat block syntax');

        return '';
    }

    protected function leaveSql(Expression $expression): string
    {
        $this->unsupported('Unsupported Leave syntax');

        return '';
    }

    protected function iterateSql(Expression $expression): string
    {
        $this->unsupported('Unsupported Iterate syntax');

        return '';
    }

    protected function executeSql(Expression $expression): string
    {
        $this->unsupported('Unsupported Execute syntax');

        return '';
    }

    protected function executesqlSql(Expression $expression): string
    {
        $this->unsupported('Unsupported Execute syntax');

        return '';
    }

    protected function altermodifysqlsecuritySql(Expression $expression): string
    {
        return 'MODIFY '.$this->expressions($expression, sep: ' ');
    }

    protected function usingpropertySql(Expression $expression): string
    {
        return 'USING '.((string) ($expression->args['kind'] ?? '')).' '.$this->sql($expression, 'this');
    }

    protected function renameindexSql(Expression $expression): string
    {
        return 'RENAME INDEX '.$this->sql($expression, 'this').' TO '.$this->sql($expression, 'to');
    }
}
