<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Generator\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ComputedColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataTypeParam;
use LaravelSqlParser\LaravelSqlParser\Expressions\Directory;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Except;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Intersect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lateral;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\MacroOverloads;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionBoundSpec;
use LaravelSqlParser\LaravelSqlParser\Expressions\PivotAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Property;
use LaravelSqlParser\LaravelSqlParser\Expressions\ReturnExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TriggerProperties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Union;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;
use ReflectionClass;

/**
 * DDL, properties, DML, tables, joins, limits and literals
 * (sqlglot generator.py, from cache_sql to loaddata_sql).
 */
trait GeneratesStatements
{
    protected function uncacheSql(Expression $expression): string
    {
        $table = $this->sql($expression, 'this');
        $exists = $expression->flag('exists') ? ' IF EXISTS' : '';

        return "UNCACHE TABLE{$exists} {$table}";
    }

    protected function cacheSql(Expression $expression): string
    {
        $lazy = $expression->flag('lazy') ? ' LAZY' : '';
        $table = $this->sql($expression, 'this');
        $options = $expression->args['options'] ?? null;
        $options = is_array($options) && $options !== [] ? ' OPTIONS('.$this->sql($options[0]).' = '.$this->sql($options[1]).')' : '';
        $sql = $this->sql($expression, 'expression');
        $sql = $sql !== '' ? ' AS'.$this->sep().$sql : '';
        $sql = "CACHE{$lazy} TABLE {$table}{$options}{$sql}";

        return $this->prependCtes($expression, $sql);
    }

    protected function charactersetSql(Expression $expression): string
    {
        $default = $expression->flag('default') ? 'DEFAULT ' : '';

        return "{$default}CHARACTER SET=".$this->sql($expression, 'this');
    }

    protected function columnParts(Column $expression): string
    {
        if ($expression->flag('shadow') && $this->dialect->settings['PROJECTION_ALIASES_SHADOW_SOURCE_NAMES'] === true) {
            // The qualifier would be captured by a colliding projection alias (see qualify_columns)
            return $this->sql($expression, 'this');
        }

        $parts = [];
        foreach (['catalog', 'db', 'table', 'this'] as $key) {
            $part = $expression->args[$key] ?? null;

            if ($part !== null && $part !== '' && $part !== false) {
                $parts[] = $this->sql($part);
            }
        }

        return implode('.', $parts);
    }

    protected function columnSql(Column $expression): string
    {
        $joinMark = $expression->flag('join_mark') ? ' (+)' : '';

        if ($joinMark !== '' && $this->dialect->settings['SUPPORTS_COLUMN_JOIN_MARKS'] !== true) {
            $joinMark = '';
            $this->unsupported('Outer join syntax using the (+) operator is not supported.');
        }

        return $this->columnParts($expression).$joinMark;
    }

    protected function pseudocolumnSql(Column $expression): string
    {
        return $this->columnSql($expression);
    }

    protected function columnpositionSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $position = $this->sql($expression, 'position');

        return "{$position}{$this_}";
    }

    protected function columndefSql(Expression $expression, string $sep = ' '): string
    {
        $column = $this->sql($expression, 'this');
        $kind = $this->sql($expression, 'kind');
        $constraints = $this->expressions($expression, key: 'constraints', sep: ' ', flat: true);
        $exists = $expression->flag('exists') ? 'IF NOT EXISTS ' : '';
        $kind = $kind !== '' ? "{$sep}{$kind}" : '';
        $constraints = $constraints !== '' ? " {$constraints}" : '';
        $position = $this->sql($expression, 'position');
        $position = $position !== '' ? " {$position}" : '';

        if ($expression->find(ComputedColumnConstraint::class) !== null && $this->t['COMPUTED_COLUMN_WITH_TYPE'] !== true) {
            $kind = '';
        }

        return "{$exists}{$column}{$kind}{$constraints}{$position}";
    }

    protected function columnconstraintSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $kindSql = trim($this->sql($expression, 'kind'));

        return $this_ !== '' ? "CONSTRAINT {$this_} {$kindSql}" : $kindSql;
    }

    protected function computedcolumnconstraintSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');

        if ($expression->flag('not_null')) {
            $persisted = ' PERSISTED NOT NULL';
        } elseif ($expression->flag('persisted')) {
            $persisted = ' PERSISTED';
        } else {
            $persisted = '';
        }

        return "AS {$this_}{$persisted}";
    }

    protected function autoincrementcolumnconstraintSql(Expression $expression): string
    {
        return $this->tokenSql(TokenType::AUTO_INCREMENT);
    }

    protected function compresscolumnconstraintSql(Expression $expression): string
    {
        if (is_array($expression->args['this'] ?? null)) {
            $this_ = $this->wrap($this->expressions($expression, key: 'this', flat: true));
        } else {
            $this_ = $this->sql($expression, 'this');
        }

        return "COMPRESS {$this_}";
    }

    protected function generatedasidentitycolumnconstraintSql(Expression $expression): string
    {
        $this_ = '';
        $thisArg = $expression->args['this'] ?? null;

        if ($thisArg !== null) {
            $onNull = $expression->flag('on_null') ? ' ON NULL' : '';
            $this_ = $this->truthy($thisArg) ? ' ALWAYS' : " BY DEFAULT{$onNull}";
        }

        $start = $expression->args['start'] ?? null;
        $start = $this->truthy($start) ? "START WITH {$start}" : '';
        $increment = $expression->args['increment'] ?? null;
        $increment = $this->truthy($increment) ? " INCREMENT BY {$increment}" : '';
        $minvalue = $expression->args['minvalue'] ?? null;
        $minvalue = $this->truthy($minvalue) ? " MINVALUE {$minvalue}" : '';
        $maxvalue = $expression->args['maxvalue'] ?? null;
        $maxvalue = $this->truthy($maxvalue) ? " MAXVALUE {$maxvalue}" : '';
        $cycle = $expression->args['cycle'] ?? null;
        $cycleSql = '';

        if ($cycle !== null) {
            $cycleSql = (! $this->truthy($cycle) ? ' NO' : '').' CYCLE';
            $cycleSql = $start === '' && $increment === '' ? trim($cycleSql) : $cycleSql;
        }

        $sequenceOpts = '';

        if ($start !== '' || $increment !== '' || $cycleSql !== '') {
            $sequenceOpts = "{$start}{$increment}{$minvalue}{$maxvalue}{$cycleSql}";
            $sequenceOpts = ' ('.trim($sequenceOpts).')';
        }

        $expr = $this->sql($expression, 'expression');
        $expr = $expr !== '' ? "({$expr})" : 'IDENTITY';

        return "GENERATED{$this_} AS {$expr}{$sequenceOpts}";
    }

    protected function generatedasrowcolumnconstraintSql(Expression $expression): string
    {
        $start = $expression->flag('start') ? 'START' : 'END';
        $hidden = $expression->flag('hidden') ? ' HIDDEN' : '';

        return "GENERATED ALWAYS AS ROW {$start}{$hidden}";
    }

    protected function periodforsystemtimeconstraintSql(Expression $expression): string
    {
        return 'PERIOD FOR SYSTEM_TIME ('.$this->sql($expression, 'this').', '.$this->sql($expression, 'expression').')';
    }

    protected function notnullcolumnconstraintSql(Expression $expression): string
    {
        return ($expression->flag('allow_null') ? '' : 'NOT ').'NULL';
    }

    protected function primarykeycolumnconstraintSql(Expression $expression): string
    {
        $desc = $expression->args['desc'] ?? null;

        if ($desc !== null) {
            return 'PRIMARY KEY'.($this->truthy($desc) ? ' DESC' : ' ASC');
        }

        $options = $this->expressions($expression, key: 'options', flat: true, sep: ' ');
        $options = $options !== '' ? " {$options}" : '';

        return "PRIMARY KEY{$options}";
    }

    protected function uniquecolumnconstraintSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $indexType = $expression->args['index_type'] ?? null;
        $indexType = $this->truthy($indexType) ? " USING {$indexType}" : '';
        $onConflict = $this->sql($expression, 'on_conflict');
        $onConflict = $onConflict !== '' ? " {$onConflict}" : '';
        $nullsSql = $expression->flag('nulls') ? ' NULLS NOT DISTINCT' : '';
        $options = $this->expressions($expression, key: 'options', flat: true, sep: ' ');
        $options = $options !== '' ? " {$options}" : '';

        return "UNIQUE{$nullsSql}{$this_}{$indexType}{$onConflict}{$options}";
    }

    protected function inoutcolumnconstraintSql(Expression $expression): string
    {
        $input = $expression->flag('input_');
        $output = $expression->flag('output');

        // VARIADIC is mutually exclusive with IN/OUT/INOUT
        if ($expression->flag('variadic')) {
            return 'VARIADIC';
        }

        if ($input && $output) {
            return 'IN'.$this->t['INOUT_SEPARATOR'].'OUT';
        }

        if ($input) {
            return 'IN';
        }

        if ($output) {
            return 'OUT';
        }

        return '';
    }

    /**
     * @param  array<string, list<Expression>>  $locations
     */
    protected function createableSql(Expression $expression, array &$locations): string
    {
        return $this->sql($expression, 'this');
    }

    protected function createSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $kind = $this->dialect->settings['INVERSE_CREATABLE_KIND_MAPPING'][$kind] ?? $kind;

        $properties = $expression->args['properties'] ?? null;
        $propertyList = $properties instanceof Expression ? $properties->argList('expressions') : [];

        if (
            $kind === 'TRIGGER'
            && $propertyList !== []
            && $propertyList[0] instanceof TriggerProperties
            && $propertyList[0]->flag('constraint')
        ) {
            $kind = "CONSTRAINT {$kind}";
        }

        $propertiesLocs = $properties instanceof Properties ? $this->locateProperties($properties) : [];

        $this_ = $this->createableSql($expression, $propertiesLocs);

        $propertiesSql = '';
        $postSchema = $propertiesLocs['POST_SCHEMA'] ?? [];
        $postWith = $propertiesLocs['POST_WITH'] ?? [];

        if ($postSchema !== [] || $postWith !== []) {
            $propsAst = new Properties(['expressions' => [...$postSchema, ...$postWith]]);
            $propsAst->parent = $expression;
            $propertiesSql = $this->sql($propsAst);

            if ($postSchema !== []) {
                $propertiesSql = $this->sep().$propertiesSql;
            } elseif (! $this->pretty) {
                // Standalone POST_WITH properties need a leading whitespace in non-pretty mode
                $propertiesSql = " {$propertiesSql}";
            }
        }

        $begin = $expression->flag('begin') ? ' BEGIN' : '';

        $expressionSql = $this->sql($expression, 'expression');

        if ($expressionSql !== '') {
            $expressionSql = $begin.$this->sep().$expressionSql;
            $inner = $expression->args['expression'] ?? null;

            if (! $inner instanceof MacroOverloads && ($this->t['CREATE_FUNCTION_RETURN_AS'] === true || ! $inner instanceof ReturnExpr)) {
                $postaliasPropsSql = '';

                if (($propertiesLocs['POST_ALIAS'] ?? []) !== []) {
                    $postaliasPropsSql = $this->properties(new Properties(['expressions' => $propertiesLocs['POST_ALIAS']]), wrapped: false);
                }

                $postaliasPropsSql = $postaliasPropsSql !== '' ? " {$postaliasPropsSql}" : '';
                $expressionSql = " AS{$postaliasPropsSql}{$expressionSql}";
            }
        }

        $postindexPropsSql = '';

        if (($propertiesLocs['POST_INDEX'] ?? []) !== []) {
            $postindexPropsSql = $this->properties(new Properties(['expressions' => $propertiesLocs['POST_INDEX']]), wrapped: false, prefix: ' ');
        }

        $indexes = $this->expressions($expression, key: 'indexes', indent: false, sep: ' ');
        $indexes = $indexes !== '' ? " {$indexes}" : '';
        $indexSql = $indexes.$postindexPropsSql;

        $replace = $expression->flag('replace') ? ' OR REPLACE' : '';
        $refresh = $expression->flag('refresh') ? ' OR REFRESH' : '';
        $unique = $expression->flag('unique') ? ' UNIQUE' : '';

        $clustered = $expression->args['clustered'] ?? null;

        if ($clustered === null) {
            $clusteredSql = '';
        } elseif ($this->truthy($clustered)) {
            $clusteredSql = ' CLUSTERED COLUMNSTORE';
        } else {
            $clusteredSql = ' NONCLUSTERED COLUMNSTORE';
        }

        $postcreatePropsSql = '';

        if (($propertiesLocs['POST_CREATE'] ?? []) !== []) {
            $postcreatePropsSql = $this->properties(new Properties(['expressions' => $propertiesLocs['POST_CREATE']]), sep: ' ', prefix: ' ', wrapped: false);
        }

        $modifiers = $clusteredSql.$replace.$refresh.$unique.$postcreatePropsSql;

        $postexpressionPropsSql = '';

        if (($propertiesLocs['POST_EXPRESSION'] ?? []) !== []) {
            $postexpressionPropsSql = $this->properties(new Properties(['expressions' => $propertiesLocs['POST_EXPRESSION']]), sep: ' ', prefix: ' ', wrapped: false);
        }

        $concurrently = $expression->flag('concurrently') ? ' CONCURRENTLY' : '';
        $existsSql = $expression->flag('exists') ? ' IF NOT EXISTS' : '';
        $noSchemaBinding = $expression->flag('no_schema_binding') ? ' WITH NO SCHEMA BINDING' : '';

        $clone = $this->sql($expression, 'clone');
        $clone = $clone !== '' ? " {$clone}" : '';

        if (isset($this->t['EXPRESSION_PRECEDES_PROPERTIES_CREATABLES'][$kind])) {
            $propertiesExpression = $expressionSql.$propertiesSql;
        } else {
            $propertiesExpression = $propertiesSql.$expressionSql;
        }

        $expressionSql = "CREATE{$modifiers} {$kind}{$concurrently}{$existsSql} {$this_}{$propertiesExpression}{$postexpressionPropsSql}{$indexSql}{$noSchemaBinding}{$clone}";

        return $this->prependCtes($expression, $expressionSql);
    }

    protected function sequencepropertiesSql(Expression $expression): string
    {
        $start = $this->sql($expression, 'start');
        $start = $start !== '' ? "START WITH {$start}" : '';
        $increment = $this->sql($expression, 'increment');
        $increment = $increment !== '' ? " INCREMENT BY {$increment}" : '';
        $minvalue = $this->sql($expression, 'minvalue');
        $minvalue = $minvalue !== '' ? " MINVALUE {$minvalue}" : '';
        $maxvalue = $this->sql($expression, 'maxvalue');
        $maxvalue = $maxvalue !== '' ? " MAXVALUE {$maxvalue}" : '';
        $owned = $this->sql($expression, 'owned');
        $owned = $owned !== '' ? " OWNED BY {$owned}" : '';

        $cache = $expression->args['cache'] ?? null;

        if ($cache === null) {
            $cacheStr = '';
        } elseif ($cache === true) {
            $cacheStr = ' CACHE';
        } else {
            $cacheStr = ' CACHE '.($cache instanceof Expression ? (string) $cache : (string) $cache);
        }

        $options = $this->expressions($expression, key: 'options', flat: true, sep: ' ');
        $options = $options !== '' ? " {$options}" : '';

        return ltrim("{$start}{$increment}{$minvalue}{$maxvalue}{$cacheStr}{$options}{$owned}");
    }

    protected function triggerpropertiesSql(Expression $expression): string
    {
        $timing = (string) ($expression->args['timing'] ?? '');
        $events = implode(' OR ', array_map(fn (Expression $event): string => $this->sql($event), $expression->argList('events')));
        $timingEvents = $timing !== '' || $events !== '' ? trim("{$timing} {$events}") : '';

        $parts = [$timingEvents, 'ON', $this->sql($expression, 'table')];

        $referencedTable = $expression->args['referenced_table'] ?? null;

        if ($referencedTable instanceof Expression) {
            array_push($parts, 'FROM', $this->sql($referencedTable));
        }

        $deferrable = $expression->args['deferrable'] ?? null;

        if (is_string($deferrable) && $deferrable !== '') {
            $parts[] = $deferrable;
        }

        $initially = $expression->args['initially'] ?? null;

        if (is_string($initially) && $initially !== '') {
            $parts[] = "INITIALLY {$initially}";
        }

        $referencing = $expression->args['referencing'] ?? null;

        if ($referencing instanceof Expression) {
            $parts[] = $this->sql($referencing);
        }

        $forEach = $expression->args['for_each'] ?? null;

        if (is_string($forEach) && $forEach !== '') {
            $parts[] = "FOR EACH {$forEach}";
        }

        $when = $expression->args['when'] ?? null;

        if ($when instanceof Expression) {
            $parts[] = 'WHEN ('.$this->sql($when).')';
        }

        $parts[] = $this->sql($expression, 'execute');

        return implode($this->sep(), $parts);
    }

    protected function triggerreferencingSql(Expression $expression): string
    {
        $parts = [];

        $old = $expression->args['old'] ?? null;

        if ($old instanceof Expression) {
            $parts[] = 'OLD TABLE AS '.$this->sql($old);
        }

        $new = $expression->args['new'] ?? null;

        if ($new instanceof Expression) {
            $parts[] = 'NEW TABLE AS '.$this->sql($new);
        }

        return 'REFERENCING '.implode(' ', $parts);
    }

    protected function triggereventSql(Expression $expression): string
    {
        if ($expression->argList('columns') !== []) {
            return $expression->args['this'].' OF '.$this->expressions($expression, key: 'columns', flat: true);
        }

        return $this->sql($expression, 'this');
    }

    protected function cloneSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $shallow = $expression->flag('shallow') ? 'SHALLOW ' : '';
        $keyword = $expression->flag('copy') && $this->t['SUPPORTS_TABLE_COPY'] === true ? 'COPY' : 'CLONE';

        return "{$shallow}{$keyword} {$this_}";
    }

    protected function describeSql(Expression $expression): string
    {
        $style = $expression->args['style'] ?? null;
        $style = is_string($style) && $style !== '' ? " {$style}" : '';
        $partition = $this->sql($expression, 'partition');
        $partition = $partition !== '' ? " {$partition}" : '';
        $format = $this->sql($expression, 'format');
        $format = $format !== '' ? " {$format}" : '';
        $asJson = $expression->flag('as_json') ? ' AS JSON' : '';

        return "DESCRIBE{$style}{$format} ".$this->sql($expression, 'this').$partition.$asJson;
    }

    protected function heredocSql(Expression $expression): string
    {
        $tag = $this->sql($expression, 'tag');

        return '$'.$tag.'$'.$this->sql($expression, 'this').'$'.$tag.'$';
    }

    public function prependCtes(Expression $expression, string $sql): string
    {
        $with = $this->sql($expression, 'with_');

        if ($with !== '') {
            $sql = $with.$this->sep().$sql;
        }

        return $sql;
    }

    protected function withSql(Expression $expression): string
    {
        $udfs = $this->expressions($expression, key: 'udfs', flat: true);
        $udfs = $udfs !== '' ? "WITH {$udfs}" : '';

        $sql = $this->expressions($expression, flat: true);

        $recursive = $this->t['CTE_RECURSIVE_KEYWORD_REQUIRED'] === true && $expression->flag('recursive') ? 'RECURSIVE ' : '';
        $search = $this->sql($expression, 'search');
        $search = $search !== '' ? " {$search}" : '';

        $sql = $sql !== '' ? "WITH {$recursive}{$sql}{$search}" : '';

        return $udfs !== '' && $sql !== '' ? "{$udfs} {$sql}" : $udfs.$sql;
    }

    protected function cteSql(Expression $expression): string
    {
        $alias = $expression->args['alias'] ?? null;

        if ($alias instanceof Expression) {
            $alias->addComments($expression->popComments());
        }

        $aliasSql = $this->sql($expression, 'alias');

        $materialized = $expression->args['materialized'] ?? null;

        if ($materialized === false) {
            $materialized = 'NOT MATERIALIZED ';
        } elseif ($this->truthy($materialized)) {
            $materialized = 'MATERIALIZED ';
        } else {
            $materialized = '';
        }

        $keyExpressions = $this->expressions($expression, key: 'key_expressions', flat: true);
        $keyExpressions = $keyExpressions !== '' ? " USING KEY ({$keyExpressions})" : '';

        return "{$aliasSql}{$keyExpressions} AS {$materialized}".$this->wrap($expression);
    }

    protected function tablealiasSql(Expression $expression): string
    {
        $alias = $this->sql($expression, 'this');
        $columns = $this->expressions($expression, key: 'columns', flat: true);
        $columns = $columns !== '' ? "({$columns})" : '';

        if (
            $columns !== ''
            && $this->t['SUPPORTS_TABLE_ALIAS_COLUMNS'] !== true
            && ! ($this->t['SUPPORTS_NAMED_CTE_COLUMNS'] === true && $expression->parent instanceof CTE)
        ) {
            $columns = '';
            $this->unsupported('Named columns are not supported in table alias.');
        }

        if ($alias === '' && $this->dialect->settings['UNNEST_COLUMN_ONLY'] !== true) {
            $alias = $this->nextName();
        }

        return "{$alias}{$columns}";
    }

    protected function bitstringSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $bitStart = $this->dialect->settings['BIT_START'];

        if (is_string($bitStart) && $bitStart !== '') {
            return $bitStart.$this_.$this->dialect->settings['BIT_END'];
        }

        return self::baseToDecimal($this_, 2);
    }

    protected function hexstringSql(Expression $expression, ?string $binaryFunctionRepr = null): string
    {
        $this_ = $this->sql($expression, 'this');
        $isIntegerType = $expression->flag('is_integer');
        $hexStart = $this->dialect->settings['HEX_START'];

        if (($isIntegerType && $this->dialect->settings['HEX_STRING_IS_INTEGER_TYPE'] !== true) || (($hexStart === null || $hexStart === '') && $binaryFunctionRepr === null)) {
            // Integer representation will be returned if:
            // - The read dialect treats the hex value as integer literal but not the write
            // - The transpilation is not supported (write dialect hasn't set HEX_START or the param flag)
            return self::baseToDecimal($this_, 16);
        }

        if (! $isIntegerType) {
            // Read dialect treats the hex value as BINARY/BLOB
            if ($binaryFunctionRepr !== null) {
                // The write dialect supports the transpilation to its equivalent BINARY/BLOB
                return $this->func($binaryFunctionRepr, Literal::string($this_));
            }

            if ($this->dialect->settings['HEX_STRING_IS_INTEGER_TYPE'] === true) {
                // The write dialect does not support the transpilation, it'll treat the hex value as INTEGER
                $this->unsupported('Unsupported transpilation from BINARY/BLOB hex string');
            }
        }

        return $hexStart.$this_.$this->dialect->settings['HEX_END'];
    }

    /**
     * Python's int(text, base) as a decimal string (works for numbers beyond PHP_INT_MAX).
     */
    protected static function baseToDecimal(string $digits, int $base): string
    {
        $digits = strtolower(str_replace('_', '', trim($digits)));
        $negative = str_starts_with($digits, '-');
        $digits = ltrim($digits, '+-');
        $prefix = $base === 16 ? '0x' : '0b';

        if (str_starts_with($digits, $prefix)) {
            $digits = substr($digits, 2);
        }

        $result = '0';

        for ($i = 0, $n = strlen($digits); $i < $n; $i++) {
            $digit = (int) hexdec($digits[$i]);
            $carry = $digit;
            $next = '';

            for ($j = strlen($result) - 1; $j >= 0; $j--) {
                $value = ((int) $result[$j]) * $base + $carry;
                $next = ($value % 10).$next;
                $carry = intdiv($value, 10);
            }

            while ($carry > 0) {
                $next = ($carry % 10).$next;
                $carry = intdiv($carry, 10);
            }

            $result = ltrim($next, '0');
            $result = $result === '' ? '0' : $result;
        }

        return ($negative && $result !== '0' ? '-' : '').$result;
    }

    protected function bytestringSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $byteStart = $this->dialect->settings['BYTE_START'];

        if (is_string($byteStart) && $byteStart !== '') {
            $byteEnd = (string) $this->dialect->settings['BYTE_END'];
            $escapedByteString = $this->escapeStr($this_, escapeBackslash: false, delimiter: $byteEnd, escapedDelimiter: $this->escapedByteQuoteEnd, isByteString: true);
            $isBytes = $expression->flag('is_bytes');
            $delimitedByteString = $byteStart.$escapedByteString.$byteEnd;

            if ($isBytes && $this->dialect->settings['BYTE_STRING_IS_BYTES_TYPE'] !== true) {
                return $this->sql(Builder::cast(new Literal(['this' => $delimitedByteString, 'is_string' => false]), DType::BINARY));
            }

            if (! $isBytes && $this->dialect->settings['BYTE_STRING_IS_BYTES_TYPE'] === true) {
                return $this->sql(Builder::cast(new Literal(['this' => $delimitedByteString, 'is_string' => false]), DType::VARCHAR));
            }

            return $delimitedByteString;
        }

        if (in_array('\\', $this->dialect->tokenizerTable('STRING_ESCAPES_LIST'), true)) {
            return $this->sql(Literal::string($this_));
        }

        $this->unsupported('Byte strings are not supported for '.$this->dialectClassName());

        return '';
    }

    protected function dialectClassName(): string
    {
        return (new ReflectionClass($this->dialect))->getShortName();
    }

    protected function unicodestringSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $escape = $expression->args['escape'] ?? null;
        $unicodeStart = $this->dialect->settings['UNICODE_START'];

        if (is_string($unicodeStart) && $unicodeStart !== '') {
            $escapeSubstitute = '\\\\$1';
            $leftQuote = $unicodeStart;
            $rightQuote = (string) ($this->dialect->settings['UNICODE_END'] ?? '');
        } else {
            $escapeSubstitute = '\\\\u$1';
            $leftQuote = (string) $this->dialect->settings['QUOTE_START'];
            $rightQuote = (string) $this->dialect->settings['QUOTE_END'];
        }

        if ($escape instanceof Expression) {
            $escapePattern = '/'.preg_quote($escape->name(), '/').'(\d+)/';
            $escapeSql = $this->t['SUPPORTS_UESCAPE'] === true ? ' UESCAPE '.$this->sql($escape) : '';
        } else {
            $escapePattern = '/\\\\(\d+)/';
            $escapeSql = '';
        }

        if (! (is_string($unicodeStart) && $unicodeStart !== '') || ($escape !== null && $this->t['SUPPORTS_UESCAPE'] !== true)) {
            $substitute = $this->t['UNICODE_SUBSTITUTE'] ?? null;

            if (is_string($substitute) && $substitute !== '') {
                $this_ = (string) preg_replace($escapePattern, str_replace('\\1', '$1', $substitute), $this_);
            } else {
                $this_ = (string) preg_replace($escapePattern, $escapeSubstitute, $this_);
            }
        }

        if (is_string($unicodeStart) && $unicodeStart !== '') {
            // A Unicode literal only escapes its delimiter by doubling it
            $this_ = str_replace($rightQuote, $rightQuote.$rightQuote, $this->replaceLineBreaks($this_));
        } else {
            $this_ = $this->escapeStr($this_, escapeBackslash: false);
        }

        return "{$leftQuote}{$this_}{$rightQuote}{$escapeSql}";
    }

    protected function rawstringSql(Expression $expression): string
    {
        $string = (string) ($expression->args['this'] ?? '');

        if (in_array('\\', $this->dialect->tokenizerTable('STRING_ESCAPES_LIST'), true)) {
            $string = str_replace('\\', '\\\\', $string);
        }

        $string = $this->escapeStr($string, escapeBackslash: false);

        return $this->dialect->settings['QUOTE_START'].$string.$this->dialect->settings['QUOTE_END'];
    }

    protected function datatypeparamSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $specifier = $this->sql($expression, 'expression');
        $specifier = $specifier !== '' && $this->t['DATA_TYPE_SPECIFIERS_ALLOWED'] === true ? " {$specifier}" : '';

        return "{$this_}{$specifier}";
    }

    /**
     * @param  list<int>  $defaults
     * @param  list<int|null>  $bounds
     */
    protected function datatypeParamBoundLimiter(DataType $expression, string $typeValue, array $defaults, array $bounds): DataType
    {
        $params = $expression->argList('expressions');

        if ($params === []) {
            if ($defaults !== []) {
                $expression->set('expressions', array_map(static fn (int $d): DataTypeParam => new DataTypeParam(['this' => Literal::number($d)]), $defaults));
            }

            return $expression;
        }

        if ($bounds === []) {
            return $expression;
        }

        $changed = false;

        foreach ($params as $i => $param) {
            $bound = $bounds[$i] ?? null;

            if ($bound === null) {
                continue;
            }

            $paramValue = $param instanceof DataTypeParam ? $param->args['this'] : $param;
            $value = $paramValue instanceof Literal && $paramValue->isNumber() ? $paramValue->toPhp() : null;

            if ($value !== null && is_numeric($value) && $value > $bound) {
                $this->unsupported(DType::VALUES[$typeValue]." parameter {$paramValue->name()} exceeds ".$this->dialectClassName()."'s maximum of {$bound}; capping");
                $params[$i] = new DataTypeParam(['this' => Literal::number($bound)]);
                $changed = true;
            }
        }

        if ($changed) {
            $expression->set('expressions', $params);
        }

        return $expression;
    }

    protected function datatypeSql(DataType $expression): string
    {
        $nested = '';
        $values = '';

        $exprNested = $expression->flag('nested');
        $typeValue = $expression->args['this'] ?? null;

        if (! $exprNested && is_string($typeValue) && ($settings = $this->t['TYPE_PARAM_SETTINGS'][$typeValue] ?? null) !== null) {
            $expression = $this->datatypeParamBoundLimiter($expression, $typeValue, $settings[0] ?? [], $settings[1] ?? []);
        }

        $interior = $exprNested && $this->pretty
            ? $this->expressions($expression, dynamic: true, newLine: true, skipFirst: true, skipLast: true)
            : $this->expressions($expression, flat: true);

        if (is_string($typeValue) && isset($this->t['UNSUPPORTED_TYPES'][$typeValue])) {
            $this->unsupported('Data type '.DType::VALUES[$typeValue].' is not supported when targeting '.$this->dialectClassName());
        }

        if ($typeValue === DType::USERDEFINED && $expression->flag('kind')) {
            $typeSql = $this->sql($expression, 'kind');
        } elseif ($typeValue === DType::CHARACTER_SET) {
            return 'CHAR CHARACTER SET '.$this->sql($expression, 'kind');
        } else {
            $typeSql = is_string($typeValue)
                ? ($this->t['TYPE_MAPPING'][$typeValue] ?? DType::VALUES[$typeValue] ?? $typeValue)
                // sqlglot interpolates a non-DType value with str(), i.e. base-dialect SQL
                : ($typeValue instanceof Expression ? (string) $typeValue : $this->sql($typeValue));
        }

        if ($interior !== '') {
            if ($exprNested) {
                $nested = $this->t['STRUCT_DELIMITER'][0].$interior.$this->t['STRUCT_DELIMITER'][1];

                if (array_key_exists('values', $expression->args) && $expression->args['values'] !== null) {
                    $delimiters = $typeValue === DType::ARRAY ? ['[', ']'] : ['(', ')'];
                    $values = $this->expressions($expression, key: 'values', flat: true);
                    $values = $delimiters[0].$values.$delimiters[1];
                }
            } elseif ($typeValue === DType::INTERVAL) {
                $nested = " {$interior}";
            } else {
                $nested = "({$interior})";
            }
        }

        $typeSql = "{$typeSql}{$nested}{$values}";

        if ($this->t['TZ_TO_WITH_TIME_ZONE'] === true && ($typeValue === DType::TIMETZ || $typeValue === DType::TIMESTAMPTZ)) {
            $typeSql .= ' WITH TIME ZONE';
        }

        $collate = $this->sql($expression, 'collate');

        if ($collate !== '') {
            $typeSql .= " COLLATE {$collate}";
        }

        return $typeSql;
    }

    protected function directorySql(Expression $expression): string
    {
        $local = $expression->flag('local') ? 'LOCAL ' : '';
        $rowFormat = $this->sql($expression, 'row_format');
        $rowFormat = $rowFormat !== '' ? " {$rowFormat}" : '';

        return "{$local}DIRECTORY ".$this->sql($expression, 'this').$rowFormat;
    }

    protected function deleteSql(Expression $expression): string
    {
        $hint = $this->sql($expression, 'hint');
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " FROM {$this_}" : '';
        $using = $this->expressions($expression, key: 'using');
        $using = $using !== '' ? " USING {$using}" : '';
        $cluster = $this->sql($expression, 'cluster');
        $cluster = $cluster !== '' ? " {$cluster}" : '';
        $where = $this->sql($expression, 'where');
        $returning = $this->sql($expression, 'returning');
        $order = $this->sql($expression, 'order');
        $limit = $this->sql($expression, 'limit');
        $tables = $this->expressions($expression, key: 'tables');
        $tables = $tables !== '' ? " {$tables}" : '';

        if ($this->t['RETURNING_END'] === true) {
            $expressionSql = "{$this_}{$using}{$cluster}{$where}{$returning}{$order}{$limit}";
        } else {
            $expressionSql = "{$returning}{$this_}{$using}{$cluster}{$where}{$order}{$limit}";
        }

        return $this->prependCtes($expression, "DELETE{$hint}{$tables}{$expressionSql}");
    }

    protected function dropSql(Expression $expression): string
    {
        $tables = $this->expressions($expression, key: 'tables', flat: true);
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? " ({$expressions})" : '';
        $kind = (string) ($expression->args['kind'] ?? '');
        $kind = $this->dialect->settings['INVERSE_CREATABLE_KIND_MAPPING'][$kind] ?? $kind;
        $iceberg = $expression->flag('iceberg') && $this->t['SUPPORTS_DROP_ALTER_ICEBERG_PROPERTY'] === true ? ' ICEBERG' : '';
        $existsSql = $expression->flag('exists') ? ' IF EXISTS ' : ' ';
        $concurrentlySql = $expression->flag('concurrently') ? ' CONCURRENTLY' : '';
        $onCluster = $this->sql($expression, 'cluster');
        $onCluster = $onCluster !== '' ? " {$onCluster}" : '';
        $temporary = $expression->flag('temporary') ? ' TEMPORARY' : '';
        $materialized = $expression->flag('materialized') ? ' MATERIALIZED' : '';
        $cascade = $expression->flag('cascade') ? ' CASCADE' : '';
        $restrict = $expression->flag('restrict') ? ' RESTRICT' : '';
        $constraints = $expression->flag('constraints') ? ' CONSTRAINTS' : '';
        $purge = $expression->flag('purge') ? ' PURGE' : '';
        $sync = $expression->flag('sync') ? ' SYNC' : '';
        $force = $expression->flag('force') ? ' FORCE' : '';

        return "DROP{$temporary}{$materialized}{$iceberg} {$kind}{$concurrentlySql}{$existsSql}{$tables}{$onCluster}{$expressions}{$cascade}{$restrict}{$constraints}{$purge}{$sync}{$force}";
    }

    public function setOperation(SetOperation $expression): string
    {
        $opType = $expression::class;
        $opName = strtoupper($expression::KEY);

        $distinct = $expression->args['distinct'] ?? null;

        if (
            $distinct === false
            && ($expression instanceof Except || $expression instanceof Intersect)
            && $this->t['EXCEPT_INTERSECT_SUPPORT_ALL_CLAUSE'] !== true
        ) {
            $this->unsupported("{$opName} ALL is not supported");
        }

        $defaultDistinct = $this->dialect->settings['SET_OP_DISTINCT_BY_DEFAULT'][$opType] ?? null;

        if ($distinct === null) {
            $distinct = $defaultDistinct;

            if ($distinct === null) {
                $this->unsupported("{$opName} requires DISTINCT or ALL to be specified");
            }
        }

        if ($distinct === $defaultDistinct) {
            $distinctOrAll = '';
        } else {
            $distinctOrAll = $this->truthy($distinct) ? ' DISTINCT' : ' ALL';
        }

        $sideKind = self::csvWith(' ', (string) ($expression->args['side'] ?? ''), (string) ($expression->args['kind'] ?? ''));
        $sideKind = $sideKind !== '' ? "{$sideKind} " : '';

        $byName = $expression->flag('by_name') ? ' BY NAME' : '';
        $on = $this->expressions($expression, key: 'on', flat: true);
        $on = $on !== '' ? " ON ({$on})" : '';

        return "{$sideKind}{$opName}{$distinctOrAll}{$byName}{$on}";
    }

    public function setOperations(SetOperation $expression): string
    {
        if ($this->t['SET_OP_MODIFIERS'] !== true) {
            $limit = $expression->args['limit'] ?? null;
            $order = $expression->args['order'] ?? null;
            $offset = $expression->args['offset'] ?? null;

            if ($limit !== null || $order !== null || $offset !== null) {
                $select = Builder::selectStarFrom($expression->subquery('_l_0', copy: false));
                $select = $this->moveCtesToTopLevelIfNeeded($select);

                foreach (['limit', 'order', 'offset'] as $arg) {
                    $value = $expression->args[$arg] ?? null;

                    if ($value instanceof Expression) {
                        $select->set($arg, $value->pop());
                    }
                }

                return $this->sql($select);
            }
        }

        $sqls = [];
        $stack = [$expression];

        while ($stack !== []) {
            $node = array_pop($stack);

            if ($node instanceof SetOperation) {
                $stack[] = $node->args['expression'] ?? null;
                $stack[] = $this->maybeComment($this->setOperation($node), comments: $node->comments, separated: true);
                $stack[] = $node->args['this'] ?? null;
            } else {
                if ($this->t['SET_OP_LIMITS'] !== true && $node instanceof Select && $node->flag('limit')) {
                    $node = $node->subquery(copy: false);

                    if ($this->t['SET_OP_PARENTHESIZED_OPERANDS'] !== true) {
                        $node = Builder::selectStarFrom($node);
                    }
                }

                $sqls[] = $this->sql($node);
            }
        }

        $this_ = implode($this->sep(), $sqls);
        $this_ = $this->queryModifiers($expression, $this_);

        return $this->prependCtes($expression, $this_);
    }

    protected function fetchSql(Expression $expression): string
    {
        $direction = $expression->args['direction'] ?? null;
        $direction = is_string($direction) && $direction !== '' ? " {$direction}" : '';
        $count = $this->sql($expression, 'count');
        $count = $count !== '' ? " {$count}" : '';
        $limitOptions = $this->sql($expression, 'limit_options');
        $limitOptions = $limitOptions !== '' ? $limitOptions : ' ROWS ONLY';

        return $this->seg('FETCH').$direction.$count.$limitOptions;
    }

    protected function limitoptionsSql(Expression $expression): string
    {
        $percent = $expression->flag('percent') ? ' PERCENT' : '';
        $rows = $expression->flag('rows') ? ' ROWS' : '';
        $withTies = $expression->flag('with_ties') ? ' WITH TIES' : '';

        if ($withTies === '' && $rows !== '') {
            $withTies = ' ONLY';
        }

        return "{$percent}{$rows}{$withTies}";
    }

    protected function filterSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $where = trim($this->sql($expression, 'expression'));

        return "{$this_} FILTER({$where})";
    }

    protected function hintSql(Expression $expression): string
    {
        if ($this->t['QUERY_HINTS'] !== true) {
            $this->unsupported('Hints are not supported');

            return '';
        }

        return ' /*+ '.trim($this->expressions($expression, sep: $this->t['QUERY_HINT_SEP'])).' */';
    }

    protected function indexparametersSql(Expression $expression): string
    {
        $using = $this->sql($expression, 'using');
        $using = $using !== '' ? " USING {$using}" : '';
        $columns = $this->expressions($expression, key: 'columns', flat: true);
        $columns = $columns !== '' ? "({$columns})" : '';
        $partitionBy = $this->expressions($expression, key: 'partition_by', flat: true);
        $partitionBy = $partitionBy !== '' ? " PARTITION BY {$partitionBy}" : '';
        $where = $this->sql($expression, 'where');
        $include = $this->expressions($expression, key: 'include', flat: true);

        if ($include !== '') {
            $include = " INCLUDE ({$include})";
        }

        $withStorage = $this->expressions($expression, key: 'with_storage', flat: true);
        $withStorage = $withStorage !== '' ? " WITH ({$withStorage})" : '';
        $tablespace = $this->sql($expression, 'tablespace');
        $tablespace = $tablespace !== '' ? " USING INDEX TABLESPACE {$tablespace}" : '';
        $on = $this->sql($expression, 'on');
        $on = $on !== '' ? " ON {$on}" : '';

        return "{$using}{$columns}{$include}{$withStorage}{$tablespace}{$partitionBy}{$where}{$on}";
    }

    protected function indexSql(Expression $expression): string
    {
        $unique = $expression->flag('unique') ? 'UNIQUE ' : '';
        $primary = $expression->flag('primary') ? 'PRIMARY ' : '';
        $amp = $expression->flag('amp') ? 'AMP ' : '';
        $name = $this->sql($expression, 'this');
        $name = $name !== '' ? "{$name} " : '';
        $table = $this->sql($expression, 'table');
        $table = $table !== '' ? $this->t['INDEX_ON']." {$table}" : '';

        $index = $table === '' ? 'INDEX ' : '';

        $params = $this->sql($expression, 'params');

        return "{$unique}{$primary}{$amp}{$index}{$name}{$table}{$params}";
    }

    protected function dynamicidentifierSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Expression && $this_->isString()) {
            $resolved = Builder::maybeParse($this_->name())->sql($this->dialect);

            if (array_key_exists('expressions', $expression->args)) {
                // `IDENTIFIER(...)` invoked as a function, e.g. `IDENTIFIER('my_func')(1, 2)`
                $this->unsupported('Transpiling dynamically-invoked IDENTIFIER() functions is unsupported');
            }

            return $resolved;
        }

        $this->unsupported('IDENTIFIER() with non-literal arguments is not supported');

        return $this->func('IDENTIFIER', $this_);
    }

    protected function identifierSql(Identifier $expression): string
    {
        $text = $expression->name();
        $lower = strtolower($text);
        $quoted = $expression->quoted();
        $text = $this->normalize && ! $quoted ? $lower : $text;
        $text = str_replace($this->identifierEnd, $this->escapedIdentifierEnd, $text);

        if (
            $quoted
            || $this->dialect->canQuote($expression, $this->identify)
            || isset($this->t['RESERVED_KEYWORDS'][$lower])
            || ($this->dialect->settings['IDENTIFIERS_CAN_START_WITH_DIGIT'] !== true && $text !== '' && ctype_digit($text[0]))
        ) {
            $text = $this->identifierStart.$this->replaceLineBreaks($text).$this->identifierEnd;
        }

        return $text;
    }

    protected function hexSql(Expression $expression): string
    {
        $text = $this->func($this->t['HEX_FUNC'], $this->sql($expression, 'this'));

        if ($this->dialect->settings['HEX_LOWERCASE'] === true) {
            $text = $this->func('LOWER', $text);
        }

        return $text;
    }

    protected function lowerhexSql(Expression $expression): string
    {
        $text = $this->func($this->t['HEX_FUNC'], $this->sql($expression, 'this'));

        if ($this->dialect->settings['HEX_LOWERCASE'] !== true) {
            $text = $this->func('LOWER', $text);
        }

        return $text;
    }

    protected function inputoutputformatSql(Expression $expression): string
    {
        $inputFormat = $this->sql($expression, 'input_format');
        $inputFormat = $inputFormat !== '' ? "INPUTFORMAT {$inputFormat}" : '';
        $outputFormat = $this->sql($expression, 'output_format');
        $outputFormat = $outputFormat !== '' ? "OUTPUTFORMAT {$outputFormat}" : '';

        return implode($this->sep(), [$inputFormat, $outputFormat]);
    }

    protected function nationalSql(Expression $expression, string $prefix = 'N'): string
    {
        return $prefix.$this->sql(Literal::string($expression->name()));
    }

    protected function partitionSql(Expression $expression): string
    {
        $keyword = $expression->flag('subpartition') ? 'SUBPARTITION' : 'PARTITION';

        return $keyword.'('.$this->expressions($expression, flat: true).')';
    }

    protected function propertiesSql(Expression $expression): string
    {
        $rootProperties = [];
        $withProperties = [];

        foreach ($expression->argList('expressions') as $p) {
            $location = $this->t['PROPERTIES_LOCATION'][$p::class] ?? null;

            if ($location === 'POST_WITH') {
                $withProperties[] = $p;
            } elseif ($location === 'POST_SCHEMA') {
                $rootProperties[] = $p;
            }
        }

        $rootPropsAst = new Properties(['expressions' => $rootProperties]);
        $rootPropsAst->parent = $expression->parent;

        $withPropsAst = new Properties(['expressions' => $withProperties]);
        $withPropsAst->parent = $expression->parent;

        $rootProps = $this->rootProperties($rootPropsAst);
        $withProps = $this->withProperties($withPropsAst);

        if ($rootProps !== '' && $withProps !== '' && ! $this->pretty) {
            $withProps = " {$withProps}";
        }

        return $rootProps.$withProps;
    }

    protected function rootProperties(Properties $properties): string
    {
        if ($properties->argList('expressions') !== []) {
            return $this->expressions($properties, indent: false, sep: ' ');
        }

        return '';
    }

    public function properties(Properties $properties, string $prefix = '', string $sep = ', ', string $suffix = '', bool $wrapped = true): string
    {
        if ($properties->argList('expressions') !== []) {
            $expressions = $this->expressions($properties, sep: $sep, indent: false);

            if ($expressions !== '') {
                $expressions = $wrapped ? $this->wrap($expressions) : $expressions;

                return $prefix.(trim($prefix) !== '' ? ' ' : '').$expressions.$suffix;
            }
        }

        return '';
    }

    protected function withProperties(Properties $properties): string
    {
        return $this->properties($properties, prefix: $this->seg($this->t['WITH_PROPERTIES_PREFIX'], sep: ''));
    }

    /**
     * Group properties by where they go in a CREATE statement.
     *
     * @return array<string, list<Expression>>
     */
    protected function locateProperties(Properties $properties): array
    {
        $locations = [];

        foreach ($properties->argList('expressions') as $p) {
            $location = $this->t['PROPERTIES_LOCATION'][$p::class] ?? 'UNSUPPORTED';

            if ($location !== 'UNSUPPORTED') {
                $locations[$location][] = $p;
            } else {
                $this->unsupported('Unsupported property '.$p::KEY);
            }
        }

        return $locations;
    }

    protected function propertyName(Expression $expression, bool $stringKey = false): string
    {
        if (($expression->args['this'] ?? null) instanceof Dot) {
            return $this->sql($expression, 'this');
        }

        return $stringKey ? "'".$expression->name()."'" : $expression->name();
    }

    protected function propertySql(Expression $expression): string
    {
        if ($expression::class === Property::class) {
            return $this->propertyName($expression).'='.$this->sql($expression, 'value');
        }

        $propertyName = Properties::PROPERTY_TO_NAME[$expression::class] ?? null;

        if ($propertyName === null) {
            $this->unsupported('Unsupported property '.$expression::KEY);
        }

        return ($propertyName ?? 'None').'='.$this->sql($expression, 'this');
    }

    protected function uuidpropertySql(Expression $expression): string
    {
        return 'UUID '.$this->sql($expression, 'this');
    }

    protected function likepropertySql(Expression $expression): string
    {
        if ($this->t['SUPPORTS_CREATE_TABLE_LIKE'] === true) {
            $options = implode(' ', array_map(fn (Expression $e): string => $e->name().' '.$this->sql($e, 'value'), $expression->argList('expressions')));
            $options = $options !== '' ? " {$options}" : '';

            $like = 'LIKE '.$this->sql($expression, 'this').$options;

            if ($this->t['LIKE_PROPERTY_INSIDE_SCHEMA'] === true && ! $expression->parent instanceof Schema) {
                $like = "({$like})";
            }

            return $like;
        }

        if ($expression->argList('expressions') !== []) {
            $this->unsupported('Transpilation of LIKE property options is unsupported');
        }

        $select = Builder::selectStarFrom($expression->argExpr('this') ?? new Table);
        $select->set('limit', new Limit(['expression' => Literal::number(0)]));

        return 'AS '.$this->sql($select);
    }

    protected function fallbackpropertySql(Expression $expression): string
    {
        $no = $expression->flag('no') ? 'NO ' : '';
        $protection = $expression->flag('protection') ? ' PROTECTION' : '';

        return "{$no}FALLBACK{$protection}";
    }

    protected function journalpropertySql(Expression $expression): string
    {
        $no = $expression->flag('no') ? 'NO ' : '';
        $local = $expression->args['local'] ?? null;
        $local = is_string($local) && $local !== '' ? "{$local} " : '';
        $dual = $expression->flag('dual') ? 'DUAL ' : '';
        $before = $expression->flag('before') ? 'BEFORE ' : '';
        $after = $expression->flag('after') ? 'AFTER ' : '';

        return "{$no}{$local}{$dual}{$before}{$after}JOURNAL";
    }

    protected function freespacepropertySql(Expression $expression): string
    {
        $freespace = $this->sql($expression, 'this');
        $percent = $expression->flag('percent') ? ' PERCENT' : '';

        return "FREESPACE={$freespace}{$percent}";
    }

    protected function checksumpropertySql(Expression $expression): string
    {
        if ($expression->flag('default')) {
            $property = 'DEFAULT';
        } elseif ($expression->flag('on')) {
            $property = 'ON';
        } else {
            $property = 'OFF';
        }

        return "CHECKSUM={$property}";
    }

    protected function mergeblockratiopropertySql(Expression $expression): string
    {
        if ($expression->flag('no')) {
            return 'NO MERGEBLOCKRATIO';
        }

        if ($expression->flag('default')) {
            return 'DEFAULT MERGEBLOCKRATIO';
        }

        $percent = $expression->flag('percent') ? ' PERCENT' : '';

        return 'MERGEBLOCKRATIO='.$this->sql($expression, 'this').$percent;
    }

    protected function modulepropertySql(Expression $expression): string
    {
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? "({$expressions})" : '';

        return 'USING '.$this->sql($expression, 'this').$expressions;
    }

    protected function datablocksizepropertySql(Expression $expression): string
    {
        $default = $expression->flag('default');
        $minimum = $expression->flag('minimum');
        $maximum = $expression->flag('maximum');

        if ($default || $minimum || $maximum) {
            $prop = $default ? 'DEFAULT' : ($minimum ? 'MINIMUM' : 'MAXIMUM');

            return "{$prop} DATABLOCKSIZE";
        }

        $units = $expression->args['units'] ?? null;
        $units = is_string($units) && $units !== '' ? " {$units}" : '';

        return 'DATABLOCKSIZE='.$this->sql($expression, 'size').$units;
    }

    protected function blockcompressionpropertySql(Expression $expression): string
    {
        $autotemp = $expression->args['autotemp'] ?? null;
        $prop = '';

        if ($autotemp !== null) {
            $prop = 'AUTOTEMP('.$this->expressions($autotemp).')';
        } elseif ($expression->flag('always')) {
            $prop = 'ALWAYS';
        } elseif ($expression->flag('default')) {
            $prop = 'DEFAULT';
        } elseif ($expression->flag('manual')) {
            $prop = 'MANUAL';
        } elseif ($expression->flag('never')) {
            $prop = 'NEVER';
        }

        return "BLOCKCOMPRESSION={$prop}";
    }

    protected function isolatedloadingpropertySql(Expression $expression): string
    {
        $no = $expression->flag('no') ? ' NO' : '';
        $concurrent = $expression->flag('concurrent') ? ' CONCURRENT' : '';
        $target = $this->sql($expression, 'target');
        $target = $target !== '' ? " {$target}" : '';

        return "WITH{$no}{$concurrent} ISOLATED LOADING{$target}";
    }

    protected function partitionboundspecSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if (is_array($this_)) {
            return 'IN ('.$this->expressions($expression, key: 'this', flat: true).')';
        }

        if ($this_ !== null) {
            $modulus = $this->sql($expression, 'this');
            $remainder = $this->sql($expression, 'expression');

            return "WITH (MODULUS {$modulus}, REMAINDER {$remainder})";
        }

        $fromExpressions = $this->expressions($expression, key: 'from_expressions', flat: true);
        $toExpressions = $this->expressions($expression, key: 'to_expressions', flat: true);

        return "FROM ({$fromExpressions}) TO ({$toExpressions})";
    }

    protected function partitionedofpropertySql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $forValuesOrDefault = $expression->args['expression'] ?? null;

        if ($forValuesOrDefault instanceof PartitionBoundSpec) {
            $forValuesOrDefault = ' FOR VALUES '.$this->sql($forValuesOrDefault);
        } else {
            $forValuesOrDefault = ' DEFAULT';
        }

        return "PARTITION OF {$this_}{$forValuesOrDefault}";
    }

    protected function lockingpropertySql(Expression $expression): string
    {
        $kind = (string) ($expression->args['kind'] ?? 'None');
        $this_ = ($expression->args['this'] ?? null) !== null ? ' '.$this->sql($expression, 'this') : '';
        $forOrIn = $expression->args['for_or_in'] ?? null;
        $forOrIn = is_string($forOrIn) && $forOrIn !== '' ? " {$forOrIn}" : '';
        $lockType = (string) ($expression->args['lock_type'] ?? 'None');
        $override = $expression->flag('override') ? ' OVERRIDE' : '';

        return "LOCKING {$kind}{$this_}{$forOrIn} {$lockType}{$override}";
    }

    protected function withdatapropertySql(Expression $expression): string
    {
        $dataSql = 'WITH '.($expression->flag('no') ? 'NO ' : '').'DATA';
        $statistics = $expression->args['statistics'] ?? null;
        $statisticsSql = '';

        if ($statistics !== null) {
            $statisticsSql = ' AND '.(! $this->truthy($statistics) ? 'NO ' : '').'STATISTICS';
        }

        return "{$dataSql}{$statisticsSql}";
    }

    protected function withsystemversioningpropertySql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? "HISTORY_TABLE={$this_}" : '';
        $dataConsistency = $this->sql($expression, 'data_consistency');
        $dataConsistency = $dataConsistency !== '' ? "DATA_CONSISTENCY_CHECK={$dataConsistency}" : null;
        $retentionPeriod = $this->sql($expression, 'retention_period');
        $retentionPeriod = $retentionPeriod !== '' ? "HISTORY_RETENTION_PERIOD={$retentionPeriod}" : null;

        if ($this_ !== '') {
            $onSql = $this->func('ON', $this_, $dataConsistency, $retentionPeriod);
        } else {
            $onSql = $expression->flag('on') ? 'ON' : 'OFF';
        }

        $sql = "SYSTEM_VERSIONING={$onSql}";

        return $expression->flag('with_') ? "WITH({$sql})" : $sql;
    }

    protected function insertSql(Expression $expression): string
    {
        $hint = $this->sql($expression, 'hint');
        $overwrite = $expression->flag('overwrite');

        if (($expression->args['this'] ?? null) instanceof Directory) {
            $this_ = $overwrite ? ' OVERWRITE' : ' INTO';
        } else {
            $this_ = $overwrite ? $this->t['INSERT_OVERWRITE'] : ' INTO';
        }

        $stored = $this->sql($expression, 'stored');
        $stored = $stored !== '' ? " {$stored}" : '';
        $alternative = $expression->args['alternative'] ?? null;
        $alternative = is_string($alternative) && $alternative !== '' ? " OR {$alternative}" : '';
        $ignore = $expression->flag('ignore') ? ' IGNORE' : '';

        if ($expression->flag('is_function')) {
            $this_ = "{$this_} FUNCTION";
        }

        $this_ = "{$this_} ".$this->sql($expression, 'this');

        $exists = $expression->flag('exists') ? ' IF EXISTS' : '';
        $where = $this->sql($expression, 'where');
        $where = $where !== '' ? $this->sep()."REPLACE WHERE {$where}" : '';
        $using = $this->expressions($expression, key: 'using', flat: true);
        $using = $using !== '' ? $this->sep()."REPLACE USING ({$using})" : '';
        $expressionSql = $this->sep().$this->sql($expression, 'expression');
        $onConflict = $this->sql($expression, 'conflict');
        $onConflict = $onConflict !== '' ? " {$onConflict}" : '';
        $byName = $expression->flag('by_name') ? ' BY NAME' : '';
        $defaultValues = $expression->flag('default') ? 'DEFAULT VALUES' : '';
        $returning = $this->sql($expression, 'returning');

        if ($this->t['RETURNING_END'] === true) {
            $expressionSql = "{$expressionSql}{$onConflict}{$defaultValues}{$returning}";
        } else {
            $expressionSql = "{$returning}{$expressionSql}{$onConflict}";
        }

        $partitionBy = $this->sql($expression, 'partition');
        $partitionBy = $partitionBy !== '' ? " {$partitionBy}" : '';
        $settings = $this->sql($expression, 'settings');
        $settings = $settings !== '' ? " {$settings}" : '';

        $source = $this->sql($expression, 'source');
        $source = $source !== '' ? "TABLE {$source}" : '';

        $sql = "INSERT{$hint}{$alternative}{$ignore}{$this_}{$stored}{$byName}{$exists}{$partitionBy}{$settings}{$where}{$using}{$expressionSql}{$source}";

        return $this->prependCtes($expression, $sql);
    }

    protected function introducerSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' '.$this->sql($expression, 'expression');
    }

    protected function killSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $kind = $kind !== '' ? " {$kind}" : '';
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';

        return "KILL{$kind}{$this_}";
    }

    protected function pseudotypeSql(Expression $expression): string
    {
        return $expression->name();
    }

    protected function objectidentifierSql(Expression $expression): string
    {
        return $expression->name();
    }

    protected function onconflictSql(Expression $expression): string
    {
        $conflict = $expression->flag('duplicate') ? 'ON DUPLICATE KEY' : 'ON CONFLICT';

        $constraint = $this->sql($expression, 'constraint');
        $constraint = $constraint !== '' ? " ON CONSTRAINT {$constraint}" : '';

        $conflictKeys = $this->expressions($expression, key: 'conflict_keys', flat: true);

        if ($conflictKeys !== '') {
            $conflictKeys = "({$conflictKeys})";
        }

        $indexPredicate = $this->sql($expression, 'index_predicate');
        $conflictKeys = "{$conflictKeys}{$indexPredicate} ";

        $action = $this->sql($expression, 'action');

        $expressions = $this->expressions($expression, flat: true);

        if ($expressions !== '') {
            $setKeyword = $this->t['DUPLICATE_KEY_UPDATE_WITH_SET'] === true ? 'SET ' : '';
            $expressions = " {$setKeyword}{$expressions}";
        }

        $where = $this->sql($expression, 'where');

        return "{$conflict}{$constraint}{$conflictKeys}{$action}{$expressions}{$where}";
    }

    protected function returningSql(Expression $expression): string
    {
        return $this->seg('RETURNING').' '.$this->expressions($expression, flat: true);
    }

    protected function rowformatdelimitedpropertySql(Expression $expression): string
    {
        $parts = [
            'fields' => ' FIELDS TERMINATED BY ',
            'escaped' => ' ESCAPED BY ',
            'collection_items' => ' COLLECTION ITEMS TERMINATED BY ',
            'map_keys' => ' MAP KEYS TERMINATED BY ',
            'lines' => ' LINES TERMINATED BY ',
            'null' => ' NULL DEFINED AS ',
        ];

        $sql = 'ROW FORMAT DELIMITED';

        foreach ($parts as $key => $prefix) {
            $value = $this->sql($expression, $key);

            if ($value !== '') {
                $sql .= $prefix.$value;
            }
        }

        return $sql;
    }

    protected function withtablehintSql(Expression $expression): string
    {
        return 'WITH ('.$this->expressions($expression, flat: true).')';
    }

    protected function indextablehintSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this').' INDEX';
        $target = $this->sql($expression, 'target');
        $target = $target !== '' ? " FOR {$target}" : '';

        return "{$this_}{$target} (".$this->expressions($expression, flat: true).')';
    }

    protected function historicaldataSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $kind = $this->sql($expression, 'kind');
        $expr = $this->sql($expression, 'expression');

        return "{$this_} ({$kind} => {$expr})";
    }

    protected function tableParts(Expression $expression): string
    {
        $parts = [];

        foreach (['catalog', 'db', 'this'] as $key) {
            $part = $expression->args[$key] ?? null;

            if ($part !== null) {
                $parts[] = $this->sql($part);
            }
        }

        return implode('.', $parts);
    }

    protected function tableSql(Expression $expression, string $sep = ' AS '): string
    {
        $table = $this->tableParts($expression);
        $only = $expression->flag('only') ? 'ONLY ' : '';
        $partition = $this->sql($expression, 'partition');
        $partition = $partition !== '' ? " {$partition}" : '';
        $version = $this->sql($expression, 'version');
        $version = $version !== '' ? " {$version}" : '';
        $alias = $this->sql($expression, 'alias');
        $alias = $alias !== '' ? "{$sep}{$alias}" : '';

        $sample = $this->sql($expression, 'sample');
        $postAlias = '';
        $preAlias = '';

        if ($this->dialect->settings['ALIAS_POST_TABLESAMPLE'] === true) {
            $preAlias = $sample;
        } else {
            $postAlias = $sample;
        }

        if ($this->dialect->settings['ALIAS_POST_VERSION'] === true) {
            $preAlias .= $version;
        } else {
            $postAlias .= $version;
        }

        $hints = $this->expressions($expression, key: 'hints', sep: ' ');
        $hints = $hints !== '' && $this->t['TABLE_HINTS'] === true ? " {$hints}" : '';
        $pivots = $this->expressions($expression, key: 'pivots', sep: '', flat: true);
        $joins = $this->indent($this->expressions($expression, key: 'joins', sep: '', flat: true), skipFirst: true);
        $laterals = $this->expressions($expression, key: 'laterals', sep: '');

        $fileFormat = $this->sql($expression, 'format');
        $pattern = $this->sql($expression, 'pattern');

        if ($fileFormat !== '') {
            $pattern = $pattern !== '' ? ", PATTERN => {$pattern}" : '';
            $fileFormat = " (FILE_FORMAT => {$fileFormat}{$pattern})";
        } elseif ($pattern !== '') {
            $fileFormat = " (PATTERN => {$pattern})";
        }

        $ordinality = '';

        if ($expression->flag('ordinality')) {
            $ordinality = " WITH ORDINALITY{$alias}";
            $alias = '';
        }

        $when = $this->sql($expression, 'when');

        if ($when !== '') {
            if ($this->t['HISTORICAL_DATA_POST_ALIAS'] === true) {
                $alias = "{$alias} {$when}";
            } else {
                $table = "{$table} {$when}";
            }
        }

        $changes = $this->sql($expression, 'changes');
        $changes = $changes !== '' ? " {$changes}" : '';

        $rowsFrom = $this->expressions($expression, key: 'rows_from');

        if ($rowsFrom !== '') {
            $table = 'ROWS FROM '.$this->wrap($rowsFrom);
        }

        $indexed = $expression->args['indexed'] ?? null;

        if ($indexed !== null) {
            $indexed = $this->truthy($indexed) ? ' INDEXED BY '.$this->sql($indexed) : ' NOT INDEXED';
        } else {
            $indexed = '';
        }

        return "{$only}{$table}{$changes}{$partition}{$fileFormat}{$preAlias}{$alias}{$indexed}{$hints}{$pivots}{$postAlias}{$joins}{$laterals}{$ordinality}";
    }

    protected function tablefromrowsSql(Expression $expression): string
    {
        $table = $this->func('TABLE', $expression->args['this'] ?? null);
        $alias = $this->sql($expression, 'alias');
        $alias = $alias !== '' ? " AS {$alias}" : '';
        $sample = $this->sql($expression, 'sample');
        $pivots = $this->expressions($expression, key: 'pivots', sep: '', flat: true);
        $joins = $this->indent($this->expressions($expression, key: 'joins', sep: '', flat: true), skipFirst: true);

        return "{$table}{$alias}{$pivots}{$sample}{$joins}";
    }

    protected function tablesampleSql(Expression $expression, ?string $tablesampleKeyword = null): string
    {
        $method = $this->sql($expression, 'method');
        $method = $method !== '' && $this->t['TABLESAMPLE_WITH_METHOD'] === true ? "{$method} " : '';
        $numerator = $this->sql($expression, 'bucket_numerator');
        $denominator = $this->sql($expression, 'bucket_denominator');
        $field = $this->sql($expression, 'bucket_field');
        $field = $field !== '' ? " ON {$field}" : '';
        $bucket = $numerator !== '' ? "BUCKET {$numerator} OUT OF {$denominator}{$field}" : '';
        $seed = $this->sql($expression, 'seed');
        $seed = $seed !== '' ? ' '.$this->t['TABLESAMPLE_SEED_KEYWORD']." ({$seed})" : '';

        $size = $this->sql($expression, 'size');

        if ($size !== '' && $this->t['TABLESAMPLE_SIZE_IS_ROWS'] === true) {
            $size = "{$size} ROWS";
        }

        $percent = $this->sql($expression, 'percent');

        if ($percent !== '' && $this->dialect->settings['TABLESAMPLE_SIZE_IS_PERCENT'] !== true) {
            $percent = "{$percent} PERCENT";
        }

        $expr = "{$bucket}{$percent}{$size}";

        if ($this->t['TABLESAMPLE_REQUIRES_PARENS'] === true) {
            $expr = "({$expr})";
        }

        return ' '.($tablesampleKeyword ?? $this->t['TABLESAMPLE_KEYWORDS'])." {$method}{$expr}{$seed}";
    }

    /**
     * The rewritten IN-list of a PIVOT with explicit aliases where the target dialect would name
     * the output columns differently; null when no rewrite is needed.
     *
     * @return list<Expression>|null
     */
    protected function pivotInValueAliases(Expression $expression): ?array
    {
        $columns = $expression->argList('columns');
        $fields = $expression->argList('fields');

        if ($columns === [] || count($fields) !== 1) {
            return null;
        }

        $args = $expression->args;
        $parser = $this->dialect->parser();

        $tgtIdentifyPivotStrings = $parser->table('IDENTIFY_PIVOT_STRINGS');
        $tgtPrefixedPivotColumns = $parser->table('PREFIXED_PIVOT_COLUMNS');
        $tgtPivotColumnNaming = $parser->table('PIVOT_COLUMN_NAMING');

        $srcIdentifyPivotStrings = array_key_exists('identify_pivot_strings', $args) ? $args['identify_pivot_strings'] : $tgtIdentifyPivotStrings;
        $srcPrefixedPivotColumns = array_key_exists('prefixed_pivot_columns', $args) ? $args['prefixed_pivot_columns'] : $tgtPrefixedPivotColumns;
        $srcPivotColumnNaming = array_key_exists('pivot_column_naming', $args) ? $args['pivot_column_naming'] : $tgtPivotColumnNaming;

        if (
            $srcIdentifyPivotStrings === $tgtIdentifyPivotStrings
            && $srcPrefixedPivotColumns === $tgtPrefixedPivotColumns
            && $srcPivotColumnNaming === $tgtPivotColumnNaming
        ) {
            return null;
        }

        $inExprs = $fields[0]->argList('expressions');

        if ($inExprs === []) {
            return null;
        }

        $step = intdiv(count($columns), count($inExprs));

        // Derive the per-value suffix from the first stored column vs the first IN-list value.
        $firstBase = $srcIdentifyPivotStrings === true ? $inExprs[0]->sql() : $inExprs[0]->aliasOrName();
        $firstStored = $columns[0]->name();

        // exit if only suffix matches, not prefix. (e.g. BigQuery, which cannot be fixed)
        if (! str_starts_with($firstStored, $firstBase)) {
            return null;
        }

        $suffix = substr($firstStored, strlen($firstBase));

        $hasAlias = false;
        foreach ($expression->argList('expressions') as $aggregation) {
            if ($aggregation->alias() !== '') {
                $hasAlias = true;

                break;
            }
        }

        // Whether the target dialect would append an agg-name suffix for this pivot.
        $targetHasSuffix = (count($expression->argList('expressions')) > 1 || $tgtPivotColumnNaming !== 'agg_name_if_multiple') && $hasAlias;
        $sourceHasSuffix = $suffix !== '';

        $newExprs = [];
        $modified = false;

        foreach ($inExprs as $valIdx => $e) {
            if ($e instanceof PivotAlias) {
                $newExprs[] = $e;

                continue;
            }

            $storedFull = $columns[$valIdx * $step]->name();
            $storedValue = $suffix !== '' ? substr($storedFull, 0, -strlen($suffix)) : $storedFull;
            $targetValue = $tgtIdentifyPivotStrings === true ? $e->sql() : $e->aliasOrName();

            if ($sourceHasSuffix && ! $targetHasSuffix) {
                // Source had a suffix, but target won't apply one
                $newExprs[] = new PivotAlias(['this' => $e, 'alias' => Builder::toIdentifier($storedFull, quoted: true)]);
                $modified = true;
            } elseif ($storedValue !== $targetValue) {
                // Value-part mismatch (e.g. Snowflake's literal-style values vs others).
                $newExprs[] = new PivotAlias(['this' => $e, 'alias' => Builder::toIdentifier($storedValue, quoted: true)]);
                $modified = true;
            } else {
                $newExprs[] = $e;
            }
        }

        return $modified ? $newExprs : null;
    }

    protected function pivotSql(Expression $expression): string
    {
        $expressions = $this->expressions($expression, flat: true);
        $unpivot = $expression->flag('unpivot');
        $direction = $unpivot ? 'UNPIVOT' : 'PIVOT';

        $group = $this->sql($expression, 'group');

        if (($expression->args['this'] ?? null) !== null) {
            $this_ = $this->sql($expression, 'this');

            if ($expressions === '') {
                $sql = "UNPIVOT {$this_}";
            } else {
                $on = $this->seg('ON')." {$expressions}";
                $into = $this->sql($expression, 'into');
                $into = $into !== '' ? $this->seg('INTO')." {$into}" : '';
                $using = $this->expressions($expression, key: 'using', flat: true);
                $using = $using !== '' ? $this->seg('USING')." {$using}" : '';
                $sql = "{$direction} {$this_}{$on}{$into}{$using}{$group}";
            }

            return $this->prependCtes($expression, $sql);
        }

        if (! $unpivot) {
            // Wrap IN-list values with explicit aliases where the target dialect would differ
            $newFieldExprs = $this->pivotInValueAliases($expression);

            if ($newFieldExprs !== null) {
                $expression->argList('fields')[0]->set('expressions', $newFieldExprs);
            }
        }

        $alias = $this->sql($expression, 'alias');

        if ($alias !== '') {
            $alias = $this->t['PIVOT_ALIAS_WITH_AS'] === true ? " AS {$alias}" : " {$alias}";
        }

        $fields = $this->expressions($expression, key: 'fields', sep: ' ', dynamic: true, newLine: true, skipFirst: true, skipLast: true);

        $includeNulls = $expression->args['include_nulls'] ?? null;

        if ($includeNulls !== null) {
            $nulls = $this->truthy($includeNulls) ? ' INCLUDE NULLS ' : ' EXCLUDE NULLS ';
        } else {
            $nulls = '';
        }

        $defaultOnNull = $this->sql($expression, 'default_on_null');
        $defaultOnNull = $defaultOnNull !== '' ? " DEFAULT ON NULL ({$defaultOnNull})" : '';
        $sql = $this->seg($direction)."{$nulls}({$expressions} FOR {$fields}{$defaultOnNull}{$group}){$alias}";

        return $this->prependCtes($expression, $sql);
    }

    protected function versionSql(Expression $expression): string
    {
        $this_ = 'FOR '.$expression->name();
        $kind = $expression->text('kind');
        $expr = $this->sql($expression, 'expression');

        return "{$this_} {$kind} {$expr}";
    }

    protected function tupleSql(Expression $expression): string
    {
        return '('.$this->expressions($expression, dynamic: true, newLine: true, skipFirst: true, skipLast: true).')';
    }

    /**
     * (join_sql, from_sql) for UPDATE statements; dialects without UPDATE ... FROM get a JOIN.
     *
     * @return array{0: string, 1: string}
     */
    protected function updateFromJoinsSql(Expression $expression): array
    {
        $from = $expression->args['from_'] ?? null;

        if ($this->t['UPDATE_STATEMENT_SUPPORTS_FROM'] === true || ! $from instanceof Expression) {
            return ['', $this->sql($expression, 'from_')];
        }

        // Qualify unqualified columns in SET clause with the target table
        $targetTable = $expression->args['this'] ?? null;

        if ($targetTable instanceof Table) {
            $targetName = Builder::toIdentifier($targetTable->aliasOrName());

            foreach ($expression->argList('expressions') as $eq) {
                $col = $eq->args['this'] ?? null;

                if ($col instanceof Column && $col->text('table') === '') {
                    $col->set('table', $targetName?->copy());
                }
            }
        }

        $table = $from->args['this'] ?? null;
        $nestedJoins = $table instanceof Expression ? $table->argList('joins') : [];

        if ($nestedJoins !== []) {
            $table->set('joins', null);
        }

        $joinSql = $this->sql(new Join(['this' => $table, 'on' => Builder::true()]));

        foreach ($nestedJoins as $nested) {
            if (! $nested->flag('on') && ! $nested->flag('using')) {
                $nested->set('on', Builder::true());
            }

            $joinSql .= $this->sql($nested);
        }

        return [$joinSql, ''];
    }

    protected function updateSql(Expression $expression): string
    {
        $hint = $this->sql($expression, 'hint');
        $this_ = $this->sql($expression, 'this');
        [$joinSql, $fromSql] = $this->updateFromJoinsSql($expression);
        $setSql = $this->expressions($expression, flat: true);
        $whereSql = $this->sql($expression, 'where');
        $returning = $this->sql($expression, 'returning');
        $order = $this->sql($expression, 'order');
        $limit = $this->sql($expression, 'limit');

        if ($this->t['RETURNING_END'] === true) {
            $expressionSql = "{$fromSql}{$whereSql}{$returning}";
        } else {
            $expressionSql = "{$returning}{$fromSql}{$whereSql}";
        }

        $options = $this->expressions($expression, key: 'options');
        $options = $options !== '' ? " OPTION({$options})" : '';
        $sql = "UPDATE{$hint} {$this_}{$joinSql} SET {$setSql}{$expressionSql}{$order}{$limit}{$options}";

        return $this->prependCtes($expression, $sql);
    }

    protected function valuesSql(Expression $expression, bool $valuesAsTable = true): string
    {
        $valuesAsTable = $valuesAsTable && $this->t['VALUES_AS_TABLE'] === true;

        // The VALUES clause is still valid in an `INSERT INTO ..` statement, for example
        if ($valuesAsTable || $expression->findAncestor(From::class, Join::class) === null) {
            $args = $this->expressions($expression);
            $alias = $this->sql($expression, 'alias');
            $values = 'VALUES'.$this->seg('').$args;
            $values = $this->t['WRAP_DERIVED_VALUES'] === true && ($alias !== '' || $expression->parent instanceof From || $expression->parent instanceof Table)
                ? "({$values})"
                : $values;
            $values = $this->queryModifiers($expression, $values);

            return $alias !== '' ? "{$values} AS {$alias}" : $values;
        }

        // Converts `VALUES...` expression into a series of select unions.
        $aliasNode = $expression->args['alias'] ?? null;
        $columnNames = $aliasNode instanceof Expression ? $aliasNode->argList('columns') : [];

        $selects = [];

        foreach ($expression->argList('expressions') as $i => $tuple) {
            $row = $tuple->argList('expressions');

            if ($i === 0 && $columnNames !== []) {
                $aliased = [];
                foreach ($row as $j => $value) {
                    if (! isset($columnNames[$j])) {
                        break;
                    }

                    $aliased[] = Builder::alias($value, $columnNames[$j] instanceof Identifier ? $columnNames[$j] : $columnNames[$j]->name());
                }
                $row = $aliased;
            }

            $selects[] = new Select(['expressions' => $row]);
        }

        if ($this->pretty) {
            $query = array_shift($selects);

            foreach ($selects as $select) {
                $query = new Union(['this' => $query, 'expression' => $select, 'distinct' => false]);
            }

            $alias = $aliasNode instanceof Expression ? $aliasNode->argExpr('this') : null;

            return $this->subquerySql($query->subquery($alias, copy: false));
        }

        $alias = $aliasNode instanceof Expression ? ' AS '.$this->sql($aliasNode, 'this') : '';
        $unions = implode(' UNION ALL ', array_map(fn (Select $select): string => $this->sql($select), $selects));

        return "({$unions}){$alias}";
    }

    protected function varSql(Expression $expression): string
    {
        return $this->sql($expression, 'this');
    }

    protected function intoSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'expressions');

        $temporary = $expression->flag('temporary') ? ' TEMPORARY' : '';
        $unlogged = $expression->flag('unlogged') ? ' UNLOGGED' : '';

        return $this->seg('INTO').($temporary !== '' ? $temporary : $unlogged).' '.$this->sql($expression, 'this');
    }

    /**
     * sqlglot's @unsupported_args decorator: report arguments the target dialect can't express.
     *
     * @param  string|array{0: string, 1: string}  ...$args  an argument name, or [name, message]
     */
    protected function unsupportedArgs(Expression $expression, string|array ...$args): void
    {
        foreach ($args as $arg) {
            [$name, $diagnostic] = is_array($arg) ? $arg : [$arg, null];

            if ($expression->flag($name)) {
                $this->unsupported($diagnostic ?? "Argument '{$name}' is not supported for expression '".(new ReflectionClass($expression))->getShortName()."' when targeting ".$this->dialectClassName().'.');
            }
        }
    }

    protected function fromSql(Expression $expression): string
    {
        return $this->seg('FROM').' '.$this->sql($expression, 'this');
    }

    protected function groupingsetsSql(Expression $expression): string
    {
        return 'GROUPING SETS '.$this->wrap($this->expressions($expression, indent: false));
    }

    protected function rollupSql(Expression $expression): string
    {
        $expressions = $this->expressions($expression, indent: false);

        return $expressions !== '' ? 'ROLLUP '.$this->wrap($expressions) : 'WITH ROLLUP';
    }

    protected function rollupindexSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $columns = $this->expressions($expression, flat: true);
        $fromSql = $this->sql($expression, 'from_index');
        $fromSql = $fromSql !== '' ? " FROM {$fromSql}" : '';
        $properties = $expression->args['properties'] ?? null;
        $propertiesSql = $properties instanceof Properties ? ' '.$this->properties($properties, prefix: 'PROPERTIES') : '';

        return "{$this_}({$columns}){$fromSql}{$propertiesSql}";
    }

    protected function rolluppropertySql(Expression $expression): string
    {
        return 'ROLLUP ('.$this->expressions($expression, flat: true).')';
    }

    protected function cubeSql(Expression $expression): string
    {
        $expressions = $this->expressions($expression, indent: false);

        return $expressions !== '' ? 'CUBE '.$this->wrap($expressions) : 'WITH CUBE';
    }

    protected function groupSql(Expression $expression): string
    {
        $groupByAll = $expression->args['all'] ?? null;

        if ($groupByAll === true) {
            $modifier = ' ALL';
        } elseif ($groupByAll === false) {
            $modifier = ' DISTINCT';
        } else {
            $modifier = '';
        }

        $groupBy = $this->opExpressions("GROUP BY{$modifier}", $expression);

        $groupingSets = $this->expressions($expression, key: 'grouping_sets');
        $cube = $this->expressions($expression, key: 'cube');
        $rollup = $this->expressions($expression, key: 'rollup');

        $groupings = self::csvWith(
            $this->t['GROUPINGS_SEP'],
            $groupingSets !== '' ? $this->seg($groupingSets) : '',
            $cube !== '' ? $this->seg($cube) : '',
            $rollup !== '' ? $this->seg($rollup) : '',
            $expression->flag('totals') ? $this->seg('WITH TOTALS') : '',
        );

        if (
            $expression->argList('expressions') !== []
            && $groupings !== ''
            && ! in_array(trim($groupings), ['WITH CUBE', 'WITH ROLLUP'], true)
        ) {
            $addSeparator = true;

            if ($groupingSets !== '') {
                if ($this->t['SUPPORTS_GROUPING_SETS_AS_SUFFIX'] === true) {
                    $addSeparator = false;
                } else {
                    $this->unsupported('GROUPING SETS without a comma after GROUP BY expressions is not supported');
                }
            }

            if ($addSeparator) {
                $groupBy .= $this->t['GROUPINGS_SEP'];
            }
        }

        return "{$groupBy}{$groupings}";
    }

    protected function havingSql(Expression $expression): string
    {
        $this_ = $this->indent($this->sql($expression, 'this'));

        return $this->seg('HAVING').$this->sep().$this_;
    }

    protected function connectSql(Expression $expression): string
    {
        $start = $this->sql($expression, 'start');
        $start = $start !== '' ? $this->seg("START WITH {$start}") : '';
        $nocycle = $expression->flag('nocycle') ? ' NOCYCLE' : '';
        $connect = $this->sql($expression, 'connect');
        $connect = $this->seg("CONNECT BY{$nocycle} {$connect}");

        return $start.$connect;
    }

    protected function priorSql(Expression $expression): string
    {
        return 'PRIOR '.$this->sql($expression, 'this');
    }

    protected function joinSql(Join $expression): string
    {
        $kind = $expression->kind();
        $side = $this->t['SEMI_ANTI_JOIN_WITH_SIDE'] !== true && ($kind === 'SEMI' || $kind === 'ANTI') ? '' : $expression->side();

        $opSql = self::csvWith(
            ' ',
            $expression->method(),
            $expression->flag('global_') ? 'GLOBAL' : '',
            $side,
            $kind,
            $this->t['JOIN_HINTS'] === true ? $expression->hint() : '',
            $expression->flag('directed') && $this->t['DIRECTED_JOINS'] === true ? 'DIRECTED' : '',
        );

        $matchCond = $this->sql($expression, 'match_condition');
        $matchCond = $matchCond !== '' ? " MATCH_CONDITION ({$matchCond})" : '';
        $onSql = $this->sql($expression, 'on');
        $using = $expression->argList('using');

        if ($onSql === '' && $using !== []) {
            $onSql = self::csv(...array_map(fn (Expression $column): string => $this->sql($column), $using));
        }

        $this_ = $expression->args['this'] ?? null;
        $thisSql = $this->sql($this_);

        $exprs = $this->expressions($expression);

        if ($exprs !== '') {
            $thisSql = "{$thisSql},".$this->seg($exprs);
        }

        if ($onSql !== '') {
            $onSql = $this->indent($onSql, skipFirst: true);
            $space = $this->pretty ? $this->seg(str_repeat(' ', $this->pad)) : ' ';

            if ($using !== []) {
                $onSql = "{$space}USING ({$onSql})";
            } else {
                $onSql = "{$space}ON {$onSql}";
            }
        } elseif ($opSql === '') {
            if ($this_ instanceof Lateral && ($this_->args['cross_apply'] ?? null) !== null) {
                return " {$thisSql}";
            }

            return ", {$thisSql}";
        }

        if ($opSql !== 'STRAIGHT_JOIN') {
            $opSql = $opSql !== '' ? "{$opSql} JOIN" : 'JOIN';
        }

        $pivots = $this->expressions($expression, key: 'pivots', sep: '', flat: true);

        return $this->seg($opSql)." {$thisSql}{$matchCond}{$onSql}{$pivots}";
    }

    protected function lambdaSql(Expression $expression, string $arrowSep = '->', bool $wrap = true): string
    {
        $args = $this->expressions($expression, flat: true);
        $args = $wrap && count(explode(',', $args)) > 1 ? "({$args})" : $args;

        return "{$args} {$arrowSep} ".$this->sql($expression, 'this');
    }

    protected function lateralOp(Expression $expression): string
    {
        $crossApply = $expression->args['cross_apply'] ?? null;

        // https://www.mssqltips.com/sqlservertip/1958/sql-server-cross-apply-and-outer-apply/
        if ($crossApply === true) {
            $op = 'INNER JOIN ';
        } elseif ($crossApply === false) {
            $op = 'LEFT JOIN ';
        } else {
            $op = '';
        }

        return "{$op}LATERAL";
    }

    protected function lateralSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');

        if ($expression->flag('view')) {
            $alias = $expression->args['alias'];
            $columns = $this->expressions($alias, key: 'columns', flat: true);
            $table = $alias->name() !== '' ? ' '.$alias->name() : '';
            $columns = $columns !== '' ? " AS {$columns}" : '';
            $opSql = $this->seg('LATERAL VIEW'.($expression->flag('outer') ? ' OUTER' : ''));

            return $opSql.$this->sep().$this_.$table.$columns;
        }

        $tableAlias = $expression->args['alias'] ?? null;
        $inner = $expression->args['this'] ?? null;
        $offset = $inner instanceof Expression ? ($inner->args['offset'] ?? null) : null;

        if (
            $this->t['UNNEST_WITH_ORDINALITY'] === true
            && $tableAlias instanceof Expression
            && $inner instanceof Unnest
            && $offset instanceof Identifier
        ) {
            // UNNEST ... WITH ORDINALITY stores the ordinality column's name in Unnest.offset
            $tableAlias = $tableAlias->copy();
            $tableAlias->append('columns', $offset->copy());
        }

        $alias = $this->sql($tableAlias);
        $alias = $alias !== '' ? " AS {$alias}" : '';

        $ordinality = '';

        if ($expression->flag('ordinality')) {
            $ordinality = " WITH ORDINALITY{$alias}";
            $alias = '';
        }

        return $this->lateralOp($expression)." {$this_}{$alias}{$ordinality}";
    }

    protected function limitSql(Expression $expression, bool $top = false): string
    {
        $this_ = $this->sql($expression, 'this');

        $args = [];
        foreach (['offset', 'expression'] as $key) {
            $e = $expression->args[$key] ?? null;

            if ($e instanceof Expression) {
                $args[] = $this->t['LIMIT_ONLY_LITERALS'] === true ? $this->simplifyUnlessLiteral($e) : $e;
            }
        }

        $argsSql = implode(', ', array_map(fn (?Expression $e): string => $this->sql($e), $args));

        $anyNonNumber = false;
        foreach ($args as $e) {
            if (! $e->isNumber()) {
                $anyNonNumber = true;

                break;
            }
        }

        $argsSql = $top && $anyNonNumber ? "({$argsSql})" : $argsSql;
        $expressions = $this->expressions($expression, flat: true);
        $limitOptions = $this->sql($expression, 'limit_options');
        $expressions = $expressions !== '' ? " BY {$expressions}" : '';

        return $this_.$this->seg($top ? 'TOP' : 'LIMIT')." {$argsSql}{$limitOptions}{$expressions}";
    }

    protected function offsetSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $value = $expression->args['expression'] ?? null;
        $value = $this->t['LIMIT_ONLY_LITERALS'] === true && $value instanceof Expression ? $this->simplifyUnlessLiteral($value) : $value;
        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? " BY {$expressions}" : '';

        return $this_.$this->seg('OFFSET').' '.$this->sql($value).$expressions;
    }

    protected function setitemSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');

        if ($this->t['SET_ASSIGNMENT_REQUIRES_VARIABLE_KEYWORD'] !== true && $kind === 'VARIABLE') {
            $kind = '';
        } else {
            $kind = $kind !== '' ? "{$kind} " : '';
        }

        $this_ = $this->sql($expression, 'this');
        $expressions = $this->expressions($expression);
        $collate = $this->sql($expression, 'collate');
        $collate = $collate !== '' ? " COLLATE {$collate}" : '';
        $global = $expression->flag('global_') ? 'GLOBAL ' : '';

        return "{$global}{$kind}{$this_}{$expressions}{$collate}";
    }

    protected function setSql(Expression $expression): string
    {
        $expressions = ' '.$this->expressions($expression, flat: true);
        $tag = $expression->flag('tag') ? ' TAG' : '';

        return ($expression->flag('unset') ? 'UNSET' : 'SET').$tag.$expressions;
    }

    protected function querybandSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $update = $expression->flag('update') ? ' UPDATE' : '';
        $scope = $this->sql($expression, 'scope');
        $scope = $scope !== '' ? " FOR {$scope}" : '';

        return "QUERY_BAND = {$this_}{$update}{$scope}";
    }

    protected function pragmaSql(Expression $expression): string
    {
        return 'PRAGMA '.$this->sql($expression, 'this');
    }

    protected function lockSql(Expression $expression): string
    {
        if ($this->t['LOCKING_READS_SUPPORTED'] !== true) {
            $this->unsupported("Locking reads using 'FOR UPDATE/SHARE' are not supported");

            return '';
        }

        $update = $expression->flag('update');
        $key = $expression->flag('key');

        if ($update) {
            $lockType = $key ? 'FOR NO KEY UPDATE' : 'FOR UPDATE';
        } else {
            $lockType = $key ? 'FOR KEY SHARE' : 'FOR SHARE';
        }

        $expressions = $this->expressions($expression, flat: true);
        $expressions = $expressions !== '' ? " OF {$expressions}" : '';
        $wait = $expression->args['wait'] ?? null;
        $waitSql = '';

        if ($wait !== null) {
            if ($wait instanceof Literal) {
                $waitSql = ' WAIT '.$this->sql($wait);
            } else {
                $waitSql = $this->truthy($wait) ? ' NOWAIT' : ' SKIP LOCKED';
            }
        }

        return "{$lockType}{$expressions}{$waitSql}";
    }

    protected function literalSql(Expression $expression): string
    {
        $text = (string) ($expression->args['this'] ?? '');

        if ($expression->isString()) {
            $text = $this->dialect->settings['QUOTE_START'].$this->escapeStr($text).$this->dialect->settings['QUOTE_END'];
        }

        return $text;
    }

    public function escapeStr(string $text, bool $escapeBackslash = true, ?string $delimiter = null, ?string $escapedDelimiter = null, bool $isByteString = false): string
    {
        $supportsEscapeSequences = $isByteString
            ? $this->dialect->settings['BYTE_STRINGS_SUPPORT_ESCAPED_SEQUENCES']
            : $this->dialect->settings['STRINGS_SUPPORT_ESCAPED_SEQUENCES'];

        if ($supportsEscapeSequences === true) {
            $sequences = $this->dialect->settings['ESCAPED_SEQUENCES'];

            if (! $escapeBackslash) {
                unset($sequences['\\']);
            }

            $text = strtr($text, $sequences);
        }

        $delimiter = $delimiter !== null && $delimiter !== '' ? $delimiter : (string) $this->dialect->settings['QUOTE_END'];
        $escapedDelimiter = $escapedDelimiter !== null && $escapedDelimiter !== '' ? $escapedDelimiter : $this->escapedQuoteEnd;

        return str_replace($delimiter, $escapedDelimiter, $this->replaceLineBreaks($text));
    }

    protected function loaddataSql(Expression $expression): string
    {
        $isOverwrite = $expression->flag('overwrite');
        $overwrite = $isOverwrite ? ' OVERWRITE' : '';
        $this_ = $this->sql($expression, 'this');

        $files = $expression->args['files'] ?? null;

        if ($files instanceof Expression) {
            $filesSql = 'FILES'.$this->wrap($this->expressions($files, flat: true));

            if ($isOverwrite) {
                $this_ = " {$this_}";
            } elseif ($expression->flag('temp')) {
                $this_ = " INTO TEMP TABLE {$this_}";
            } else {
                $this_ = " INTO TABLE {$this_}";
            }

            return "LOAD DATA{$overwrite}{$this_} FROM {$filesSql}";
        }

        $local = $expression->flag('local') ? ' LOCAL' : '';
        $inpath = ' INPATH '.$this->sql($expression, 'inpath');
        $this_ = " INTO TABLE {$this_}";
        $partition = $this->sql($expression, 'partition');
        $partition = $partition !== '' ? " {$partition}" : '';
        $inputFormat = $this->sql($expression, 'input_format');
        $inputFormat = $inputFormat !== '' ? " INPUTFORMAT {$inputFormat}" : '';
        $serde = $this->sql($expression, 'serde');
        $serde = $serde !== '' ? " SERDE {$serde}" : '';

        return "LOAD DATA{$local}{$inpath}{$overwrite}{$this_}{$partition}{$inputFormat}{$serde}";
    }
}
