<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Generator\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\All;
use LaravelSqlParser\LaravelSqlParser\Expressions\Any;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\AutoRefreshProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\ConcatWs;
use LaravelSqlParser\LaravelSqlParser\Expressions\Condition;
use LaravelSqlParser\LaravelSqlParser\Expressions\Connector;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Escape;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ILike;
use LaravelSqlParser\LaravelSqlParser\Expressions\IntervalSpan;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONObject;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPathPart;
use LaravelSqlParser\LaravelSqlParser\Expressions\Like;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Nullif;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pivot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\WeekStart;
use ReflectionClass;

/**
 * Predicates, operators, casts, JSON constructs, ALTER and other expressions
 * (sqlglot generator.py, from withingroup_sql to use_sql).
 */
trait GeneratesExpressions
{
    protected function withingroupSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        // order has a leading space
        $expressionSql = substr($this->sql($expression, 'expression'), 1);

        return "{$this_} WITHIN GROUP ({$expressionSql})";
    }

    protected function betweenSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $low = $this->sql($expression, 'low');
        $high = $this->sql($expression, 'high');
        $symmetric = $expression->args['symmetric'] ?? null;

        if ($this->truthy($symmetric) && $this->t['SUPPORTS_BETWEEN_FLAGS'] !== true) {
            return "({$this_} BETWEEN {$low} AND {$high} OR {$this_} BETWEEN {$high} AND {$low})";
        }

        if ($this->truthy($symmetric)) {
            $flag = ' SYMMETRIC';
        } elseif ($symmetric === false && $this->t['SUPPORTS_BETWEEN_FLAGS'] === true) {
            $flag = ' ASYMMETRIC';
        } else {
            // silently drop ASYMMETRIC – semantics identical
            $flag = '';
        }

        return "{$this_} BETWEEN{$flag} {$low} AND {$high}";
    }

    /**
     * @return list<Expression>
     */
    public function bracketOffsetExpressions(Expression $expression, ?int $indexOffset = null): array
    {
        $expressions = $expression->argList('expressions');

        if ($expression->flag('json_access')) {
            return $expressions;
        }

        $offset = $expression->args['offset'] ?? 0;
        $offset = is_int($offset) ? $offset : (int) $this->truthy($offset);
        $base = $indexOffset !== null && $indexOffset !== 0 ? $indexOffset : (int) $this->dialect->settings['INDEX_OFFSET'];

        return Builder::applyIndexOffset($expression->argExpr('this') ?? new NullExpr, $expressions, $base - $offset, $this->dialect);
    }

    protected function bracketSql(Expression $expression): string
    {
        $expressionsSql = implode(', ', array_map(fn (Expression $e): string => $this->sql($e), $this->bracketOffsetExpressions($expression)));

        return $this->sql($expression, 'this')."[{$expressionsSql}]";
    }

    protected function allSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $inner = $expression->args['this'] ?? null;

        if (! $inner instanceof Tuple && ! $inner instanceof Paren) {
            $this_ = $this->wrap($this_);
        }

        return "ALL {$this_}";
    }

    protected function anySql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $inner = $expression->args['this'] ?? null;

        if ($inner instanceof Select || $inner instanceof SetOperation || $inner instanceof Paren) {
            if (! $inner instanceof Paren) {
                $this_ = $this->wrap($this_);
            }

            return "ANY{$this_}";
        }

        return "ANY {$this_}";
    }

    protected function existsSql(Expression $expression): string
    {
        return 'EXISTS'.$this->wrap($expression);
    }

    protected function caseSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $statements = [$this_ !== '' ? "CASE {$this_}" : 'CASE'];

        foreach ($expression->argList('ifs') as $e) {
            $statements[] = 'WHEN '.$this->sql($e, 'this');
            $statements[] = 'THEN '.$this->sql($e, 'true');
        }

        $default = $this->sql($expression, 'default');

        if ($default !== '') {
            $statements[] = "ELSE {$default}";
        }

        $statements[] = 'END';

        if ($this->pretty && $this->tooWide($statements)) {
            return $this->indent(implode("\n", $statements), skipFirst: true, skipLast: true);
        }

        return implode(' ', $statements);
    }

    protected function constraintSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expressions = $this->expressions($expression, flat: true);

        return "CONSTRAINT {$this_} {$expressions}";
    }

    protected function nextvalueforSql(Expression $expression): string
    {
        $order = $expression->args['order'] ?? null;
        $order = $order instanceof Expression ? ' OVER ('.$this->orderSql($order, flat: true).')' : '';

        return 'NEXT VALUE FOR '.$this->sql($expression, 'this').$order;
    }

    protected function extractSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this->t['NORMALIZE_EXTRACT_DATE_PARTS'] === true && $this_ instanceof Expression) {
            $this_ = $this->mapDatePart($this_);
        }

        if ($this->t['EXTRACT_ALLOWS_QUOTES'] === true) {
            $thisSql = $this->sql($this_);
        } elseif ($this_ instanceof WeekStart) {
            $thisSql = $this->weekstartName($this_);
        } else {
            $thisSql = $this_ instanceof Expression ? $this_->name() : '';
        }

        $expressionSql = $this->sql($expression, 'expression');

        return "EXTRACT({$thisSql} FROM {$expressionSql})";
    }

    protected function trimSql(Expression $expression): string
    {
        $trimType = $this->sql($expression, 'position');

        if ($trimType === 'LEADING') {
            $funcName = 'LTRIM';
        } elseif ($trimType === 'TRAILING') {
            $funcName = 'RTRIM';
        } else {
            $funcName = 'TRIM';
        }

        return $this->func($funcName, $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
    }

    /**
     * @return list<Expression>
     */
    public function convertConcatArgs(Expression $expression): array
    {
        $args = $expression->argList('expressions');

        if ($expression instanceof ConcatWs) {
            // Skip the delimiter
            $args = array_slice($args, 1);
        }

        if ($this->dialect->settings['STRICT_STRING_CONCAT'] === true && $expression->flag('safe')) {
            $args = array_map(static fn (Expression $e): Expression => Builder::cast($e, DType::TEXT), $args);
        }

        $concatCoalesce = $expression instanceof ConcatWs
            ? $this->dialect->settings['CONCAT_WS_COALESCE']
            : $this->dialect->settings['CONCAT_COALESCE'];

        if ($concatCoalesce !== true && $expression->flag('coalesce')) {
            $args = array_map(function (Expression $e): Expression {
                if ($e->type() === null) {
                    $this->dialect->annotateTypes($e);
                }

                $type = $e->type();

                if ($e->isString() || $e instanceof ArrayExpr || ($type !== null && $type->isType(DType::ARRAY))) {
                    return $e;
                }

                return Builder::func('coalesce', [$e, Literal::string('')]);
            }, $args);
        }

        return $args;
    }

    protected function concatSql(Expression $expression): string
    {
        if ($this->dialect->settings['CONCAT_COALESCE'] === true && ! $expression->flag('coalesce')) {
            // Dialect's CONCAT function coalesces NULLs to empty strings, but the expression does not.
            // Transpile to double pipe operators, which typically returns NULL if any args are NULL
            // instead of coalescing them to empty string.
            return $this->concatToDpipeSql($expression);
        }

        $expressions = $this->convertConcatArgs($expression);

        // Some dialects don't allow a single-argument CONCAT call
        if ($this->t['SUPPORTS_SINGLE_ARG_CONCAT'] !== true && count($expressions) === 1) {
            return $this->sql($expressions[0]);
        }

        return $this->funcWith('CONCAT', $expressions);
    }

    protected function concatwsSql(Expression $expression): string
    {
        if ($this->dialect->settings['CONCAT_WS_COALESCE'] === true && ! $expression->flag('coalesce')) {
            // Dialect's CONCAT_WS function skips NULL args, but the expression does not.
            // Wrap the entire call in a CASE expression that returns NULL if any input IS NULL.
            $allArgs = $expression->argList('expressions');
            $expression->set('coalesce', true);
            $condition = Builder::or(array_map(static fn (Expression $arg): Expression => new Is(['this' => $arg->copy(), 'expression' => new NullExpr]), $allArgs));

            return $this->sql(new CaseExpr([
                'ifs' => [new IfExpr(['this' => $condition, 'true' => new NullExpr])],
                'default' => $expression,
            ]));
        }

        return $this->funcWith('CONCAT_WS', [$expression->argList('expressions')[0] ?? null, ...$this->convertConcatArgs($expression)]);
    }

    protected function checkSql(Expression $expression): string
    {
        return 'CHECK ('.$this->sql($expression, 'this').')';
    }

    protected function foreignkeySql(Expression $expression): string
    {
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? " ({$expressions})" : '';
        $reference = $this->sql($expression, 'reference');
        $reference = $reference !== '' ? " {$reference}" : '';
        $delete = $this->sql($expression, 'delete');
        $delete = $delete !== '' ? " ON DELETE {$delete}" : '';
        $update = $this->sql($expression, 'update');
        $update = $update !== '' ? " ON UPDATE {$update}" : '';
        $options = $this->expressions($expression, key: 'options', flat: true, sep: ' ');
        $options = $options !== '' ? " {$options}" : '';

        return "FOREIGN KEY{$expressions}{$reference}{$delete}{$update}{$options}";
    }

    protected function primarykeySql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $expressions = $this->expressions($expression, flat: true);
        $include = $this->sql($expression, 'include');
        $options = $this->expressions($expression, key: 'options', flat: true, sep: ' ');
        $options = $options !== '' ? " {$options}" : '';

        return "PRIMARY KEY{$this_} ({$expressions}){$include}{$options}";
    }

    protected function timeserieskeySql(Expression $expression): string
    {
        $this->unsupported('TIMESERIES primary key columns are not supported');

        return $this->sql($expression, 'this');
    }

    protected function ifSql(Expression $expression): string
    {
        return $this->caseSql(new CaseExpr(['ifs' => [$expression], 'default' => $expression->args['false'] ?? null]));
    }

    protected function matchagainstSql(Expression $expression): string
    {
        $expressions = [];

        foreach ($expression->argList('expressions') as $expr) {
            $expressions[] = $this->t['MATCH_AGAINST_TABLE_PREFIX'] !== null && $this->t['MATCH_AGAINST_TABLE_PREFIX'] !== '' && $expr instanceof Table
                ? 'TABLE '.$this->sql($expr)
                : $expr;
        }

        $modifier = $expression->args['modifier'] ?? null;
        $modifier = is_string($modifier) && $modifier !== '' ? " {$modifier}" : '';

        return $this->funcWith('MATCH', $expressions).' AGAINST('.$this->sql($expression, 'this').$modifier.')';
    }

    protected function jsonkeyvalueSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').$this->t['JSON_KEY_VALUE_PAIR_SEP'].' '.$this->sql($expression, 'expression');
    }

    protected function jsonpathSql(Expression $expression): string
    {
        $path = ltrim($this->expressions($expression, sep: '', flat: true), '.');

        if ($this->t['QUOTE_JSON_PATH'] === true) {
            $path = $this->escapeStr($path);
            $path = $this->dialect->settings['QUOTE_START'].$path.$this->dialect->settings['QUOTE_END'];
        }

        return $path;
    }

    public function jsonPathPart(mixed $expression): string
    {
        if ($expression instanceof JSONPathPart) {
            $transform = $this->t['TRANSFORMS'][$expression::class] ?? null;

            if ($transform === null) {
                $this->unsupported('Unsupported JSONPathPart type '.(new ReflectionClass($expression))->getShortName());

                return '';
            }

            return $this->callTransform($transform, $expression);
        }

        if (is_int($expression)) {
            return (string) $expression;
        }

        $expression = is_scalar($expression) ? (string) $expression : '';

        if ($this->quoteJsonPathKeyUsingBrackets && $this->t['JSON_PATH_SINGLE_QUOTE_ESCAPE'] === true) {
            return "'".str_replace("'", "\\'", $expression)."'";
        }

        return '"'.str_replace('"', '\\"', $expression).'"';
    }

    protected function formatjsonSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' FORMAT JSON';
    }

    protected function formatphraseSql(Expression $expression): string
    {
        // Output the Teradata column FORMAT override.
        return $this->sql($expression, 'this').' (FORMAT '.$this->sql($expression, 'format').')';
    }

    public function jsonobjectSqlInternal(Expression $expression, string $name = ''): string
    {
        $nullHandling = $expression->args['null_handling'] ?? null;
        $nullHandling = is_string($nullHandling) && $nullHandling !== '' ? " {$nullHandling}" : '';

        $uniqueKeys = $expression->args['unique_keys'] ?? null;
        $uniqueKeys = $uniqueKeys !== null ? ' '.($this->truthy($uniqueKeys) ? 'WITH' : 'WITHOUT').' UNIQUE KEYS' : '';

        $returnType = $this->sql($expression, 'return_type');
        $returnType = $returnType !== '' ? " RETURNING {$returnType}" : '';
        $encoding = $this->sql($expression, 'encoding');
        $encoding = $encoding !== '' ? " ENCODING {$encoding}" : '';

        if ($name === '') {
            $name = $expression instanceof JSONObject ? 'JSON_OBJECT' : 'JSON_OBJECTAGG';
        }

        return $this->funcWith($name, $expression->argList('expressions'), suffix: "{$nullHandling}{$uniqueKeys}{$returnType}{$encoding})");
    }

    protected function jsonarraySql(Expression $expression): string
    {
        $nullHandling = $expression->args['null_handling'] ?? null;
        $nullHandling = is_string($nullHandling) && $nullHandling !== '' ? " {$nullHandling}" : '';
        $returnType = $this->sql($expression, 'return_type');
        $returnType = $returnType !== '' ? " RETURNING {$returnType}" : '';
        $strict = $expression->flag('strict') ? ' STRICT' : '';

        return $this->funcWith('JSON_ARRAY', $expression->argList('expressions'), suffix: "{$nullHandling}{$returnType}{$strict})");
    }

    protected function jsonarrayaggSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $order = $this->sql($expression, 'order');
        $nullHandling = $expression->args['null_handling'] ?? null;
        $nullHandling = is_string($nullHandling) && $nullHandling !== '' ? " {$nullHandling}" : '';
        $returnType = $this->sql($expression, 'return_type');
        $returnType = $returnType !== '' ? " RETURNING {$returnType}" : '';
        $strict = $expression->flag('strict') ? ' STRICT' : '';

        return $this->funcWith('JSON_ARRAYAGG', [$this_], suffix: "{$order}{$nullHandling}{$returnType}{$strict})");
    }

    protected function jsoncolumndefSql(Expression $expression): string
    {
        $path = $this->sql($expression, 'path');
        $path = $path !== '' ? " PATH {$path}" : '';
        $nestedSchema = $this->sql($expression, 'nested_schema');

        if ($nestedSchema !== '') {
            return "NESTED{$path} {$nestedSchema}";
        }

        $this_ = $this->sql($expression, 'this');
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? " {$kind}" : '';
        $formatJson = $expression->flag('format_json') ? ' FORMAT JSON' : '';
        $ordinality = $expression->flag('ordinality') ? ' FOR ORDINALITY' : '';

        return "{$this_}{$kind}{$formatJson}{$path}{$ordinality}";
    }

    protected function jsonschemaSql(Expression $expression): string
    {
        return $this->funcWith('COLUMNS', $expression->argList('expressions'));
    }

    protected function jsontableSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $path = $this->sql($expression, 'path');
        $path = $path !== '' ? ", {$path}" : '';
        $errorHandling = $expression->args['error_handling'] ?? null;
        $errorHandling = is_string($errorHandling) && $errorHandling !== '' ? " {$errorHandling}" : '';
        $emptyHandling = $expression->args['empty_handling'] ?? null;
        $emptyHandling = is_string($emptyHandling) && $emptyHandling !== '' ? " {$emptyHandling}" : '';
        $schema = $this->sql($expression, 'schema');

        return $this->funcWith('JSON_TABLE', [$this_], suffix: "{$path}{$errorHandling}{$emptyHandling} {$schema})");
    }

    protected function openjsoncolumndefSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $kind = $this->sql($expression, 'kind');
        $path = $this->sql($expression, 'path');
        $path = $path !== '' ? " {$path}" : '';
        $asJson = $expression->flag('as_json') ? ' AS JSON' : '';

        return "{$this_} {$kind}{$path}{$asJson}";
    }

    protected function openjsonSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $path = $this->sql($expression, 'path');
        $path = $path !== '' ? ", {$path}" : '';
        $expressions = $this->expressions($expression);
        $with = $expressions !== ''
            ? ' WITH ('.$this->seg($this->indent($expressions), sep: '').$this->seg(')', sep: '')
            : '';

        return "OPENJSON({$this_}{$path}){$with}";
    }

    protected function inSql(Expression $expression): string
    {
        $query = $expression->args['query'] ?? null;
        $unnest = $expression->args['unnest'] ?? null;
        $field = $expression->args['field'] ?? null;
        $isGlobal = $expression->flag('is_global') ? ' GLOBAL' : '';

        if ($query instanceof Expression) {
            $inSql = $this->sql($query);
        } elseif ($unnest instanceof Expression) {
            $inSql = $this->inUnnestOp($unnest);
        } elseif ($field instanceof Expression) {
            $inSql = $this->sql($field);
        } else {
            $inSql = '('.$this->expressions($expression, dynamic: true, newLine: true, skipFirst: true, skipLast: true).')';
        }

        return $this->sql($expression, 'this')."{$isGlobal} IN {$inSql}";
    }

    public function inUnnestOp(Expression $unnest): string
    {
        return '(SELECT '.$this->sql($unnest).')';
    }

    protected function intervalSql(Expression $expression): string
    {
        $includeKeyword = $this->t['AUTO_REFRESH_BARE_INTERVALS'] !== true
            || ! $expression->findAncestor(AutoRefreshProperty::class, Select::class) instanceof AutoRefreshProperty;

        $intervalKeyword = $includeKeyword ? 'INTERVAL' : '';
        $unitExpression = $expression->args['unit'] ?? null;
        $unit = $unitExpression instanceof Expression ? $this->sql($unitExpression) : '';

        if ($this->t['INTERVAL_ALLOWS_PLURAL_FORM'] !== true) {
            $unit = $this->t['TIME_PART_SINGULARS'][$unit] ?? $unit;
        }

        $unit = $unit !== '' ? " {$unit}" : '';
        $value = $expression->args['this'] ?? null;

        if ($this->t['SINGLE_STRING_INTERVAL'] === true) {
            $this_ = $value instanceof Expression ? $value->name() : '';

            if ($this_ !== '') {
                $intervalKeyword = $intervalKeyword !== '' ? "{$intervalKeyword} " : '';

                if ($unitExpression instanceof IntervalSpan) {
                    return "{$intervalKeyword}'{$this_}'{$unit}";
                }

                return "{$intervalKeyword}'{$this_}{$unit}'";
            }

            return "{$intervalKeyword}{$unit}";
        }

        $this_ = $this->sql($expression, 'this');

        if ($this_ !== '' && $value instanceof Expression) {
            if (! $includeKeyword && $value->isString()) {
                $this_ = $value->name();
            }

            if (! $this->isInstanceOfAny($value, $this->t['UNWRAPPED_INTERVAL_VALUES'])) {
                $this_ = "({$this_})";
            }

            if ($includeKeyword) {
                $this_ = " {$this_}";
            }
        }

        return "{$intervalKeyword}{$this_}{$unit}";
    }

    protected function returnSql(Expression $expression): string
    {
        return 'RETURN '.$this->sql($expression, 'this');
    }

    protected function referenceSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? "({$expressions})" : '';
        $options = $this->expressions($expression, key: 'options', flat: true, sep: ' ');
        $options = $options !== '' ? " {$options}" : '';

        return "REFERENCES {$this_}{$expressions}{$options}";
    }

    protected function anonymousSql(Expression $expression): string
    {
        // We don't normalize qualified functions such as a.b.foo(), because they can be case-sensitive
        $parent = $expression->parent;
        $isQualified = $parent instanceof Dot && ($parent->args['expression'] ?? null) === $expression;

        $args = $expression->args['expressions'] ?? [];

        return $this->funcWith($this->sql($expression, 'this'), is_array($args) ? array_values($args) : [], normalize: ! $isQualified);
    }

    protected function parenSql(Expression $expression): string
    {
        $sql = $this->seg($this->indent($this->sql($expression, 'this')), sep: '');

        return "({$sql}".$this->seg(')', sep: '');
    }

    protected function negSql(Expression $expression): string
    {
        // This makes sure we don't convert "- - 5" to "--5", which is a comment
        $thisSql = $this->sql($expression, 'this');
        $sep = str_starts_with($thisSql, '-') ? ' ' : '';

        return "-{$sep}{$thisSql}";
    }

    protected function notSql(Expression $expression): string
    {
        return 'NOT '.$this->sql($expression, 'this');
    }

    protected function aliasSql(Expression $expression): string
    {
        $alias = $this->sql($expression, 'alias');
        $alias = $alias !== '' ? " AS {$alias}" : '';

        return $this->sql($expression, 'this').$alias;
    }

    protected function pivotaliasSql(Expression $expression): string
    {
        $alias = $expression->args['alias'] ?? null;
        $pivot = $expression->parent?->parent;

        if ($pivot instanceof Pivot && $pivot->flag('unpivot') && $alias instanceof Expression) {
            $identifierAlias = $alias instanceof Identifier;
            $literalAlias = $alias instanceof Literal;

            if ($identifierAlias && $this->t['UNPIVOT_ALIASES_ARE_IDENTIFIERS'] !== true) {
                $alias->replace(Literal::string($alias->outputName()));
            } elseif (! $identifierAlias && $literalAlias && $this->t['UNPIVOT_ALIASES_ARE_IDENTIFIERS'] === true) {
                $alias->replace(Builder::toIdentifier($alias->outputName()));
            }
        }

        return $this->aliasSql($expression);
    }

    protected function aliasesSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' AS ('.$this->expressions($expression, flat: true).')';
    }

    protected function atindexSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' AT '.$this->sql($expression, 'expression');
    }

    protected function attimezoneSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' AT TIME ZONE '.$this->sql($expression, 'zone');
    }

    protected function fromtimezoneSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' AT TIME ZONE '.$this->sql($expression, 'zone')." AT TIME ZONE 'UTC'";
    }

    protected function fromiso8601dateSql(Expression $expression): string
    {
        return $this->sql(Builder::cast($expression->argExpr('this') ?? new NullExpr, DType::DATE));
    }

    protected function fromiso8601timestampSql(Expression $expression): string
    {
        return $this->sql(Builder::cast($expression->argExpr('this') ?? new NullExpr, DType::TIMESTAMPTZ));
    }

    protected function fromiso8601timestampnanosSql(Expression $expression): string
    {
        return $this->sql(Builder::cast($expression->argExpr('this') ?? new NullExpr, DType::TIMESTAMPTZ));
    }

    protected function addSql(Expression $expression): string
    {
        return $this->binary($expression, '+');
    }

    /**
     * @param  list<mixed>|null  $stack
     *
     * @param-out list<mixed> $stack
     */
    protected function andSql(Expression $expression, ?array &$stack = null): string
    {
        return $this->connectorSql($expression, 'AND', $stack);
    }

    /**
     * @param  list<mixed>|null  $stack
     *
     * @param-out list<mixed> $stack
     */
    protected function orSql(Expression $expression, ?array &$stack = null): string
    {
        return $this->connectorSql($expression, 'OR', $stack);
    }

    /**
     * @param  list<mixed>|null  $stack
     *
     * @param-out list<mixed> $stack
     */
    protected function xorSql(Expression $expression, ?array &$stack = null): string
    {
        return $this->connectorSql($expression, 'XOR', $stack);
    }

    /**
     * Generate AND / OR / XOR chains iteratively. When a stack is given, the connector pushes its
     * operands onto it (right, operator, left) and returns its operator.
     *
     * @param  list<mixed>|null  $stack
     *
     * @param-out list<mixed> $stack
     */
    public function connectorSql(Expression $expression, string $op, ?array &$stack = null): string
    {
        if ($stack !== null) {
            $stack[] = $expression->args['expression'] ?? null;

            if ($expression->comments !== null && $expression->comments !== [] && $this->comments) {
                $op = $this->maybeComment($op, comments: $expression->comments);
            }

            $stack[] = $op;
            $stack[] = $expression->args['this'] ?? null;

            return $op;
        }

        $stack = [$expression];
        $sqls = [];
        $ops = [];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node instanceof Connector) {
                $method = $node::KEY.'Sql';
                $ops[$this->{$method}($node, $stack)] = true;
            } else {
                $sql = $this->sql($node);
                $last = count($sqls) - 1;

                if ($last >= 0 && isset($ops[$sqls[$last]])) {
                    $sqls[$last] .= " {$sql}";
                } else {
                    $sqls[] = $sql;
                }
            }
        }

        $sep = $this->pretty && $this->tooWide($sqls) ? "\n" : ' ';

        return implode($sep, $sqls);
    }

    protected function bitwiseandSql(Expression $expression): string
    {
        return $this->binary($expression, '&');
    }

    protected function bitwiseleftshiftSql(Expression $expression): string
    {
        return $this->binary($expression, '<<');
    }

    protected function bitwisenotSql(Expression $expression): string
    {
        return '~'.$this->sql($expression, 'this');
    }

    protected function bitwiseorSql(Expression $expression): string
    {
        return $this->binary($expression, '|');
    }

    protected function bitwiserightshiftSql(Expression $expression): string
    {
        return $this->binary($expression, '>>');
    }

    protected function bitwisexorSql(Expression $expression): string
    {
        return $this->binary($expression, '^');
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        $formatSql = $this->sql($expression, 'format');
        $formatSql = $formatSql !== '' ? " FORMAT {$formatSql}" : '';
        $toSql = $this->sql($expression, 'to');
        $toSql = $toSql !== '' ? " {$toSql}" : '';
        $action = $this->sql($expression, 'action');
        $action = $action !== '' ? " {$action}" : '';
        $default = $this->sql($expression, 'default');
        $default = $default !== '' ? " DEFAULT {$default} ON CONVERSION ERROR" : '';

        return ($safePrefix ?? '').'CAST('.$this->sql($expression, 'this')." AS{$toSql}{$default}{$formatSql}{$action})";
    }

    // Base implementation that excludes safe, zone, and target_type metadata args
    protected function strtotimeSql(Expression $expression): string
    {
        return $this->func('STR_TO_TIME', $expression->args['this'] ?? null, $expression->args['format'] ?? null);
    }

    // Base implementation that excludes the safe and default_year metadata args
    protected function strtodateSql(Expression $expression): string
    {
        return $this->func('STR_TO_DATE', $expression->args['this'] ?? null, $expression->args['format'] ?? null);
    }

    protected function parsedatetimeSql(Expression $expression): string
    {
        return $this->func('PARSE_DATETIME', $expression->args['this'] ?? null, $expression->args['format'] ?? null, $expression->args['zone'] ?? null);
    }

    protected function currentdateSql(Expression $expression): string
    {
        $zone = $this->sql($expression, 'this');

        return $zone !== '' ? "CURRENT_DATE({$zone})" : 'CURRENT_DATE';
    }

    protected function collateSql(Expression $expression): string
    {
        if ($this->t['COLLATE_IS_FUNC'] === true) {
            return $this->functionFallbackSql($expression);
        }

        return $this->binary($expression, 'COLLATE');
    }

    protected function commandSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' '.trim($expression->text('expression'));
    }

    protected function commentSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $kind = $this->sql($expression, 'kind');
        $materialized = $expression->flag('materialized') ? ' MATERIALIZED' : '';
        $existsSql = $expression->flag('exists') ? ' IF EXISTS ' : ' ';
        $expressionSql = $this->sql($expression, 'expression');

        return "COMMENT{$existsSql}ON{$materialized} {$kind} {$this_} IS {$expressionSql}";
    }

    protected function mergetreettlactionSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $delete = $expression->flag('delete') ? ' DELETE' : '';
        $recompress = $this->sql($expression, 'recompress');
        $recompress = $recompress !== '' ? " RECOMPRESS {$recompress}" : '';
        $toDisk = $this->sql($expression, 'to_disk');
        $toDisk = $toDisk !== '' ? " TO DISK {$toDisk}" : '';
        $toVolume = $this->sql($expression, 'to_volume');
        $toVolume = $toVolume !== '' ? " TO VOLUME {$toVolume}" : '';

        return "{$this_}{$delete}{$recompress}{$toDisk}{$toVolume}";
    }

    protected function mergetreettlSql(Expression $expression): string
    {
        $where = $this->sql($expression, 'where');
        $group = $this->sql($expression, 'group');
        $aggregates = $this->expressions($expression, key: 'aggregates');
        $aggregates = $aggregates !== '' ? $this->seg('SET').$this->seg($aggregates) : '';

        if ($where === '' && $group === '' && $aggregates === '' && count($expression->argList('expressions')) === 1) {
            return 'TTL '.$this->expressions($expression, flat: true);
        }

        return 'TTL'.$this->seg($this->expressions($expression)).$where.$group.$aggregates;
    }

    protected function transactionSql(Expression $expression): string
    {
        $modes = $this->expressions($expression, key: 'modes');
        $modes = $modes !== '' ? " {$modes}" : '';

        return "BEGIN{$modes}";
    }

    protected function commitSql(Expression $expression): string
    {
        $chain = $expression->args['chain'] ?? null;
        $chainSql = '';

        if ($chain !== null) {
            $chainSql = $this->truthy($chain) ? ' AND CHAIN' : ' AND NO CHAIN';
        }

        return "COMMIT{$chainSql}";
    }

    protected function rollbackSql(Expression $expression): string
    {
        $savepoint = $this->sql($expression, 'savepoint');
        $savepoint = $savepoint !== '' ? " TO {$savepoint}" : '';

        return "ROLLBACK{$savepoint}";
    }

    protected function altercolumnSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');

        $exists = '';

        if ($expression->flag('exists')) {
            if ($this->t['SUPPORTS_ALTER_COLUMN_IF_EXISTS'] === true) {
                $exists = ' IF EXISTS';
            } else {
                $this->unsupported('ALTER COLUMN IF EXISTS is not supported by this dialect');
            }
        }

        $dtype = $this->sql($expression, 'dtype');

        if ($dtype !== '') {
            $collate = $this->sql($expression, 'collate');
            $collate = $collate !== '' ? " COLLATE {$collate}" : '';
            $using = $this->sql($expression, 'using');
            $using = $using !== '' ? " USING {$using}" : '';
            $alterSetType = $this->t['ALTER_SET_TYPE'] !== '' ? $this->t['ALTER_SET_TYPE'].' ' : '';
            $nullConstraint = $this->alterColumnNullConstraintSqlInternal($expression);

            return "ALTER COLUMN{$exists} {$this_} {$alterSetType}{$dtype}{$collate}{$using}{$nullConstraint}";
        }

        $default = $this->sql($expression, 'default');

        if ($default !== '') {
            return "ALTER COLUMN{$exists} {$this_} SET DEFAULT {$default}";
        }

        $comment = $this->sql($expression, 'comment');

        if ($comment !== '') {
            return "ALTER COLUMN{$exists} {$this_} COMMENT {$comment}";
        }

        $visible = $expression->args['visible'] ?? null;

        if ($this->truthy($visible)) {
            return "ALTER COLUMN{$exists} {$this_} SET ".$this->sql($expression, 'visible');
        }

        $allowNull = $expression->args['allow_null'] ?? null;
        $drop = $expression->flag('drop');

        if (! $drop && ! $this->truthy($allowNull)) {
            $this->unsupported('Unsupported ALTER COLUMN syntax');
        }

        if ($allowNull !== null) {
            $keyword = $drop ? 'DROP' : 'SET';

            return "ALTER COLUMN{$exists} {$this_} {$keyword} NOT NULL";
        }

        return "ALTER COLUMN{$exists} {$this_} DROP DEFAULT";
    }

    protected function alterColumnNullConstraintSqlInternal(Expression $expression): string
    {
        $allowNull = $expression->args['allow_null'] ?? null;

        if ($allowNull === null) {
            return '';
        }

        if ($this->t['SUPPORTS_ALTER_COLUMN_NULLABILITY'] !== true) {
            $this->unsupported('ALTER COLUMN cannot set nullability along with a type');

            return '';
        }

        return $this->truthy($allowNull) ? ' NULL' : ' NOT NULL';
    }

    protected function modifycolumnSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $renameFrom = $this->sql($expression, 'rename_from');

        if ($renameFrom !== '') {
            if ($this->t['SUPPORTS_CHANGE_COLUMN'] !== true) {
                $this->unsupported('CHANGE COLUMN is not supported in this dialect');
            }

            return "CHANGE COLUMN {$renameFrom} {$this_}";
        }

        if ($this->t['SUPPORTS_MODIFY_COLUMN'] !== true) {
            $this->unsupported('MODIFY COLUMN is not supported in this dialect');
        }

        return "MODIFY COLUMN {$this_}";
    }

    protected function alterindexSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $visibleSql = $expression->flag('visible') ? 'VISIBLE' : 'INVISIBLE';

        return "ALTER INDEX {$this_} {$visibleSql}";
    }

    protected function alterdiststyleSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');

        if (! ($expression->args['this'] ?? null) instanceof VarExpr) {
            $this_ = "KEY DISTKEY {$this_}";
        }

        return "ALTER DISTSTYLE {$this_}";
    }

    protected function altersortkeySql(Expression $expression): string
    {
        $compound = $expression->flag('compound') ? ' COMPOUND' : '';
        $this_ = $this->sql($expression, 'this');
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? "({$expressions})" : '';

        return "ALTER{$compound} SORTKEY ".($this_ !== '' ? $this_ : $expressions);
    }

    protected function alterrenameSql(Expression $expression, bool $includeTo = true): string
    {
        if ($this->t['RENAME_TABLE_WITH_DB'] !== true) {
            // Remove db from tables
            $transformed = $expression->transform(static fn (Expression $n): Expression => $n instanceof Table
                ? new Table(['this' => ($n->args['this'] ?? null) instanceof Expression ? $n->args['this']->copy() : null])
                : $n);
            $expression = $transformed ?? $expression;
        }

        $this_ = $this->sql($expression, 'this');
        $toKw = $includeTo ? ' TO' : '';

        return "RENAME{$toKw} {$this_}";
    }

    protected function renamecolumnSql(Expression $expression): string
    {
        $exists = $expression->flag('exists') ? ' IF EXISTS' : '';
        $oldColumn = $this->sql($expression, 'this');
        $newColumn = $this->sql($expression, 'to');

        return "RENAME COLUMN{$exists} {$oldColumn} TO {$newColumn}";
    }

    protected function altersetSql(Expression $expression): string
    {
        $exprs = $this->expressions($expression, flat: true);

        if ($this->t['ALTER_SET_WRAPPED'] === true) {
            $exprs = "({$exprs})";
        }

        return "SET {$exprs}";
    }

    protected function alterSql(Expression $expression): string
    {
        $actions = $expression->argList('actions');

        if ($this->dialect->settings['ALTER_TABLE_ADD_REQUIRED_FOR_EACH_COLUMN'] !== true && ($actions[0] ?? null) instanceof ColumnDef) {
            $actionsSql = 'ADD '.$this->expressions($expression, key: 'actions', flat: true);
        } else {
            $actionsList = [];

            foreach ($actions as $action) {
                if ($action instanceof ColumnDef || $action instanceof Schema) {
                    $actionSql = $this->addColumnSql($action);
                } else {
                    $actionSql = $this->sql($action);

                    if ($action instanceof Query) {
                        $actionSql = "AS {$actionSql}";
                    }
                }

                $actionsList[] = $actionSql;
            }

            $actionsSql = ltrim($this->formatArgs($actionsList), "\n");
        }

        $iceberg = $expression->flag('iceberg') && $this->t['SUPPORTS_DROP_ALTER_ICEBERG_PROPERTY'] === true ? 'ICEBERG ' : '';
        $exists = $expression->flag('exists') ? ' IF EXISTS' : '';
        $onCluster = $this->sql($expression, 'cluster');
        $onCluster = $onCluster !== '' ? " {$onCluster}" : '';
        $only = $expression->flag('only') ? ' ONLY' : '';
        $options = $this->expressions($expression, key: 'options');
        $options = $options !== '' ? ", {$options}" : '';
        $kind = $this->sql($expression, 'kind');
        $notValid = $expression->flag('not_valid') ? ' NOT VALID' : '';
        $check = $expression->flag('check') ? ' WITH CHECK' : '';
        $cascade = $expression->flag('cascade') && $this->dialect->settings['ALTER_TABLE_SUPPORTS_CASCADE'] === true ? ' CASCADE' : '';
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';

        return "ALTER {$iceberg}{$kind}{$exists}{$only}{$this_}{$onCluster}{$check}".$this->sep()."{$actionsSql}{$notValid}{$options}{$cascade}";
    }

    protected function altersessionSql(Expression $expression): string
    {
        $itemsSql = $this->expressions($expression, flat: true);
        $keyword = $expression->flag('unset') ? 'UNSET' : 'SET';

        return "{$keyword} {$itemsSql}";
    }

    public function addColumnSql(Expression $expression): string
    {
        $sql = $this->sql($expression);

        if ($expression instanceof Schema) {
            $columnText = ' COLUMNS';
        } elseif ($expression instanceof ColumnDef && $this->t['ALTER_TABLE_INCLUDE_COLUMN_KEYWORD'] === true) {
            $columnText = ' COLUMN';
        } else {
            $columnText = '';
        }

        return "ADD{$columnText} {$sql}";
    }

    protected function droppartitionSql(Expression $expression): string
    {
        $expressions = $this->expressions($expression);
        $exists = $expression->flag('exists') ? ' IF EXISTS ' : ' ';

        return "DROP{$exists}{$expressions}";
    }

    protected function dropprimarykeySql(Expression $expression): string
    {
        return 'DROP PRIMARY KEY';
    }

    protected function addconstraintSql(Expression $expression): string
    {
        return 'ADD '.$this->expressions($expression, indent: false);
    }

    protected function addpartitionSql(Expression $expression): string
    {
        $exists = $expression->flag('exists') ? 'IF NOT EXISTS ' : '';
        $location = $this->sql($expression, 'location');
        $location = $location !== '' ? " {$location}" : '';

        return "ADD {$exists}".$this->sql($expression, 'this').$location;
    }

    protected function distinctSql(Expression $expression): string
    {
        $this_ = $this->expressions($expression, flat: true);
        $args = $expression->argList('expressions');

        if ($this->t['MULTI_ARG_DISTINCT'] !== true && count($args) > 1) {
            $ifs = array_map(static fn (Expression $arg): IfExpr => new IfExpr([
                'this' => new Is(['this' => $arg->copy(), 'expression' => new NullExpr]),
                'true' => new NullExpr,
            ]), $args);

            $this_ = $this->sql(new CaseExpr(['ifs' => $ifs, 'default' => new VarExpr(['this' => "({$this_})"])]));
        }

        $this_ = $this_ !== '' ? " {$this_}" : '';
        $on = $this->sql($expression, 'on');
        $on = $on !== '' ? " ON {$on}" : '';

        return "DISTINCT{$this_}{$on}";
    }

    protected function ignorenullsSql(Expression $expression): string
    {
        return $this->embedIgnoreNulls($expression, 'IGNORE NULLS');
    }

    protected function respectnullsSql(Expression $expression): string
    {
        return $this->embedIgnoreNulls($expression, 'RESPECT NULLS');
    }

    protected function havingmaxSql(Expression $expression): string
    {
        $thisSql = $this->sql($expression, 'this');
        $expressionSql = $this->sql($expression, 'expression');
        $kind = $expression->flag('max') ? 'MAX' : 'MIN';

        return "{$thisSql} HAVING {$kind} {$expressionSql}";
    }

    protected function intdivSql(Expression $expression): string
    {
        return $this->sql(new Cast([
            'this' => new Div(['this' => $expression->args['this'] ?? null, 'expression' => $expression->args['expression'] ?? null]),
            'to' => new DataType(['this' => DType::INT]),
        ]));
    }

    protected function dpipeSql(Expression $expression): string
    {
        if ($this->dialect->settings['STRICT_STRING_CONCAT'] === true && $expression->flag('safe')) {
            return $this->funcWith('CONCAT', array_map(static fn (Expression $e): Expression => Builder::cast($e, DType::TEXT), $expression->flatten()));
        }

        return $this->binary($expression, '||');
    }

    protected function divSql(Expression $expression): string
    {
        $l = $expression->args['this'] ?? null;
        $r = $expression->args['expression'] ?? null;

        if (! $l instanceof Expression || ! $r instanceof Expression) {
            return $this->binary($expression, '/');
        }

        if ($this->dialect->settings['SAFE_DIVISION'] !== true && $expression->flag('safe')) {
            $r->replace(new Nullif(['this' => $r->copy(), 'expression' => Literal::number(0)]));
        }

        if ($this->dialect->settings['TYPED_DIVISION'] === true && ! $expression->flag('typed')) {
            if (! $l->isType(...DataType::REAL_TYPES) && ! $r->isType(...DataType::REAL_TYPES)) {
                $l->replace(Builder::cast($l->copy(), DType::DOUBLE));
            }
        } elseif ($this->dialect->settings['TYPED_DIVISION'] !== true && $expression->flag('typed')) {
            if ($l->isType(...DataType::INTEGER_TYPES) && $r->isType(...DataType::INTEGER_TYPES)) {
                return $this->sql(Builder::cast(new Div(['this' => $l->copy(), 'expression' => $r->copy()]), DType::BIGINT));
            }
        }

        return $this->binary($expression, '/');
    }

    protected function safedivideSql(Expression $expression): string
    {
        $n = Builder::wrap($expression->argExpr('this'), Binary::class);
        $d = Builder::wrap($expression->argExpr('expression'), Binary::class);

        return $this->sql(new IfExpr([
            'this' => new NEQ(['this' => $d, 'expression' => Literal::number(0)]),
            'true' => new Div(['this' => $n?->copy(), 'expression' => $d?->copy()]),
            'false' => new NullExpr,
        ]));
    }

    protected function overlapsSql(Expression $expression): string
    {
        return $this->binary($expression, 'OVERLAPS');
    }

    protected function distanceSql(Expression $expression): string
    {
        return $this->binary($expression, '<->');
    }

    protected function distancendSql(Expression $expression): string
    {
        return $this->binary($expression, '<<->>');
    }

    protected function dotSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').'.'.$this->sql($expression, 'expression');
    }

    protected function eqSql(Expression $expression): string
    {
        return $this->binary($expression, '=');
    }

    protected function propertyeqSql(Expression $expression): string
    {
        return $this->binary($expression, ':=');
    }

    protected function escapeSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if (
            ($this_ instanceof Like || $this_ instanceof ILike)
            && (($this_->args['expression'] ?? null) instanceof All || ($this_->args['expression'] ?? null) instanceof Any)
            && $this->t['SUPPORTS_LIKE_QUANTIFIERS'] !== true
        ) {
            return $this->likeSqlInternal($this_, escape: $expression);
        }

        return $this->binary($expression, 'ESCAPE');
    }

    protected function globSql(Expression $expression): string
    {
        return $this->binary($expression, 'GLOB');
    }

    protected function gtSql(Expression $expression): string
    {
        return $this->binary($expression, '>');
    }

    protected function gteSql(Expression $expression): string
    {
        return $this->binary($expression, '>=');
    }

    protected function isSql(Expression $expression): string
    {
        $negate = $expression->flag('negate');
        $rhs = $expression->args['expression'] ?? null;

        if ($this->t['IS_BOOL_ALLOWED'] !== true && $rhs instanceof Boolean) {
            $positive = $this->truthy($rhs->args['this'] ?? null) !== $negate;
            $this_ = $expression->argExpr('this') ?? new NullExpr;

            return $this->sql($positive ? $this_ : Builder::not($this_));
        }

        return $this->binary($expression, $negate ? 'IS NOT' : 'IS');
    }

    public function likeSqlInternal(Expression $expression, ?Expression $escape = null): string
    {
        $this_ = $expression->args['this'] ?? null;
        $rhs = $expression->args['expression'] ?? null;
        $expClass = $expression instanceof Like ? Like::class : ILike::class;
        $op = $expression instanceof Like ? 'LIKE' : 'ILIKE';
        $negate = $expression->args['negate'] ?? null;

        if ($this->truthy($negate)) {
            $op = "NOT {$op}";
        }

        if (($rhs instanceof All || $rhs instanceof Any) && $this->t['SUPPORTS_LIKE_QUANTIFIERS'] !== true) {
            $exprs = ($rhs->argExpr('this') ?? new NullExpr)->unnest();
            $exprs = $exprs instanceof Tuple ? $exprs->argList('expressions') : [$exprs];

            $makeLike = static function (Expression $expr) use ($expClass, $this_, $negate, $escape): Expression {
                $like = new $expClass(['this' => $this_, 'expression' => $expr, 'negate' => $negate]);

                if ($escape !== null) {
                    $like = new Escape(['this' => $like, 'expression' => ($escape->argExpr('expression') ?? new NullExpr)->copy()]);
                }

                return $like;
            };

            $likeExpr = $makeLike($exprs[0]);

            foreach (array_slice($exprs, 1) as $expr) {
                $likeExpr = $rhs instanceof Any
                    ? Builder::or([$likeExpr, $makeLike($expr)], copy: false)
                    : Builder::and([$likeExpr, $makeLike($expr)], copy: false);
            }

            $parent = $escape !== null ? $escape->parent : $expression->parent;

            if (! ($parent instanceof $likeExpr || $parent instanceof Paren) && $parent instanceof Condition) {
                $likeExpr = Builder::paren($likeExpr, copy: false);
            }

            return $this->sql($likeExpr);
        }

        return $this->binary($expression, $op);
    }

    protected function likeSql(Expression $expression): string
    {
        return $this->likeSqlInternal($expression);
    }

    protected function ilikeSql(Expression $expression): string
    {
        return $this->likeSqlInternal($expression);
    }

    protected function matchSql(Expression $expression): string
    {
        return $this->binary($expression, 'MATCH');
    }

    protected function similartoSql(Expression $expression): string
    {
        return $this->binary($expression, 'SIMILAR TO');
    }

    protected function ltSql(Expression $expression): string
    {
        return $this->binary($expression, '<');
    }

    protected function lteSql(Expression $expression): string
    {
        return $this->binary($expression, '<=');
    }

    protected function modSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expr = $this->sql($expression, 'expression');
        $sql = "{$this_} ".$this->maybeComment($this->t['MOD_OPERATOR'], comments: $expression->comments)." {$expr}";

        $parent = $expression->parent;

        if ($parent !== null && $this->isInstanceOfAny($parent, $this->t['MOD_PAREN_PARENT_TYPES']) && ($parent->args['expression'] ?? null) === $expression) {
            return "({$sql})";
        }

        return $sql;
    }

    protected function mulSql(Expression $expression): string
    {
        return $this->binary($expression, '*');
    }

    protected function neqSql(Expression $expression): string
    {
        return $this->binary($expression, '<>');
    }

    protected function nullsafeeqSql(Expression $expression): string
    {
        return $this->binary($expression, 'IS NOT DISTINCT FROM');
    }

    protected function nullsafeneqSql(Expression $expression): string
    {
        return $this->binary($expression, 'IS DISTINCT FROM');
    }

    protected function subSql(Expression $expression): string
    {
        return $this->binary($expression, '-');
    }

    protected function trycastSql(Expression $expression): string
    {
        return $this->castSql($expression, safePrefix: 'TRY_');
    }

    protected function jsoncastSql(Expression $expression): string
    {
        return $this->castSql($expression);
    }

    protected function trySql(Expression $expression): string
    {
        if ($this->t['TRY_SUPPORTED'] !== true) {
            $this->unsupported('Unsupported TRY function');

            return $this->sql($expression, 'this');
        }

        return $this->func('TRY', $expression->args['this'] ?? null);
    }

    protected function logSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $expr = $expression->args['expression'] ?? null;
        $logBaseFirst = $this->dialect->settings['LOG_BASE_FIRST'];

        if ($logBaseFirst === false) {
            [$this_, $expr] = [$expr, $this_];
        } elseif ($logBaseFirst === null && $expr !== null) {
            $base = $this_ instanceof Expression ? $this_->name() : '';

            if ($base === '2' || $base === '10') {
                return $this->func("LOG{$base}", $expr);
            }

            $this->unsupported('Unsupported logarithm with base '.$this->sql($this_));
        }

        return $this->func('LOG', $this_, $expr);
    }

    protected function useSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? " {$kind}" : '';
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? $this_ : $this->expressions($expression, flat: true);
        $this_ = $this_ !== '' ? " {$this_}" : '';

        return "USE{$kind}{$this_}";
    }
}
