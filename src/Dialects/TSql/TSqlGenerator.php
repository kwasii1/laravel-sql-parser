<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\TSql;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Aliases;
use LaravelSqlParser\LaravelSqlParser\Expressions\AlterRename;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Create;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Fetch;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\Into;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONExtractScalar;
use LaravelSqlParser\LaravelSqlParser\Expressions\LikeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\NumberToStr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Offset;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\Parameter;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\QueryTransform;
use LaravelSqlParser\LaravelSqlParser\Expressions\ReturnExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TemporaryProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Values;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;
use LaravelSqlParser\LaravelSqlParser\Support\TimeFormat;

/**
 * T-SQL generator (sqlglot generators/tsql.py).
 */
class TSqlGenerator extends Generator
{
    public const string TABLES = TSqlGeneratorTables::class;

    private const array DATE_PART_UNMAPPING = [
        'WEEKISO' => 'ISO_WEEK',
        'DAYOFWEEK' => 'WEEKDAY',
        'TIMEZONE_MINUTE' => 'TZOFFSET',
    ];

    /** Parents under which booleans are generated as BIT values (1 / 0). */
    private const array BIT_TYPES = [
        EQ::class => true,
        NEQ::class => true,
        Is::class => true,
        In::class => true,
        Select::class => true,
        Alias::class => true,
    ];

    private const array SET_OP_MODIFIERS = ['limit', 'offset', 'order', 'for_', 'options'];

    private const array OPTIONS_THAT_REQUIRE_EQUAL = ['MAX_GRANT_PERCENT' => true, 'MIN_GRANT_PERCENT' => true, 'LABEL' => true];

    // ---------------------------------------------------------------------------------------
    // Transforms
    // ---------------------------------------------------------------------------------------

    /**
     * Ensure all (unnamed) output columns of CTEs and subqueries are aliased.
     */
    public function qualifyDerivedTableOutputs(Expression $expression): Expression
    {
        $alias = $expression->args['alias'] ?? null;
        $query = $expression->args['this'] ?? null;

        if (($expression instanceof CTE || $expression instanceof Subquery) && $alias instanceof TableAlias && $alias->argList('columns') === [] && $query instanceof Query) {
            $this->qualifyOutputs($query);
        }

        return $expression;
    }

    /**
     * Ensure all output columns of a query are aliased (sqlglot optimizer qualify_outputs).
     */
    private function qualifyOutputs(Query $query): void
    {
        $dialect = Dialect::get('tsql');
        $newSelections = [];

        foreach ($query->selects() as $i => $selection) {
            if ($selection instanceof QueryTransform) {
                break;
            }

            if ($selection instanceof Subquery) {
                if ($selection->outputName() === '') {
                    $identifier = Builder::toIdentifier("_col_{$i}") ?? new Identifier;
                    $dialect->normalizeIdentifier($identifier);
                    $selection->set('alias', new TableAlias(['this' => $identifier]));
                }
            } elseif (! $selection instanceof Alias && ! $selection instanceof Aliases && ! $selection->isStar()) {
                $unwrapped = $selection->unnest();
                $sourceIdentifier = match (true) {
                    $unwrapped instanceof Column => $unwrapped->args['this'] ?? null,
                    $unwrapped instanceof Dot => $unwrapped->args['expression'] ?? null,
                    default => null,
                };

                $outputName = $selection->outputName();
                $selection = Builder::alias($selection, $outputName !== '' ? $outputName : "_col_{$i}", copy: false);
                $aliasIdentifier = $selection->argExpr('alias');

                if ($sourceIdentifier instanceof Identifier) {
                    // The alias copies the exact spelling of an existing identifier, so it isn't
                    // normalized
                    if ($sourceIdentifier->quoted()) {
                        $aliasIdentifier?->set('quoted', true);
                    }
                } elseif ($aliasIdentifier !== null) {
                    $dialect->normalizeIdentifier($aliasIdentifier);
                }
            }

            $newSelections[] = $selection;
        }

        if ($newSelections !== [] && $query instanceof Select) {
            $query->set('expressions', $newSelections);
        }
    }

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function formatSqlHandler(Expression $expression): string
    {
        $fmt = $expression->args['format'] ?? null;

        if (! $expression instanceof NumberToStr && $fmt instanceof Expression) {
            if ($fmt->isString()) {
                $mappedFmt = TimeFormat::convertWith($fmt->name(), $this->dialect->settings['INVERSE_TIME_MAPPING']);
                $fmtSql = $this->sql(Literal::string((string) $mappedFmt));
            } else {
                $formatted = $this->formatTime($expression);
                $fmtSql = $formatted !== null && $formatted !== '' ? $formatted : $this->sql($fmt);
            }
        } else {
            $fmtSql = $this->sql($fmt instanceof Expression ? $fmt : null);
        }

        return $this->func('FORMAT', $expression->args['this'] ?? null, $fmtSql, $expression->args['culture'] ?? null);
    }

    protected function stringAggSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $distinct = Scope::findAllInScope($expression, Distinct::class)[0] ?? null;

        if ($distinct !== null) {
            // exp.Distinct can appear below an exp.Order or an exp.GroupConcat expression
            $this->unsupported("T-SQL STRING_AGG doesn't support DISTINCT.");
            $this_ = $distinct->pop()->argList('expressions')[0] ?? null;
        }

        $order = '';
        $orderNode = $expression->args['this'] ?? null;

        if ($orderNode instanceof Order) {
            if (($ordered = $orderNode->argExpr('this')) !== null) {
                $this_ = $ordered->pop();
            }

            // Order has a leading space
            $order = ' WITHIN GROUP ('.substr($this->sql($orderNode), 1).')';
        }

        $separator = $expression->args['separator'] ?? null;
        $separator = $separator instanceof Expression ? $separator : Literal::string(',');

        return 'STRING_AGG('.$this->formatArgs([$this_, $separator]).")$order";
    }

    /**
     * JSON_QUERY returns objects and arrays, JSON_VALUE returns scalars; a source that also returns
     * non-scalar values as text (e.g. SQLite's ->>) needs to try both.
     */
    protected function jsonExtractSqlHandler(Expression $expression): string
    {
        if ($expression instanceof JSONExtractScalar && $expression->flag('scalar_only')) {
            return $this->func('JSON_VALUE', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
        }

        $jsonQuery = $this->func('JSON_QUERY', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);

        if ($expression->flag('json_query')) {
            return $jsonQuery;
        }

        $jsonValue = $this->func('JSON_VALUE', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);

        return $this->func('ISNULL', $jsonQuery, $jsonValue);
    }

    protected function timestrtotimeSqlTsql(Expression $expression): string
    {
        $sql = $this->timestrtotimeSqlHandler($expression);

        if ($expression->flag('zone')) {
            // CAST('2020-01-01 12:13:14-08:00' AS DATETIMEOFFSET) AT TIME ZONE 'UTC': without the
            // AT TIME ZONE, casting back to DATETIME2 just drops the timezone information
            return $this->sql(new AtTimeZone(['this' => $sql, 'zone' => Literal::string('UTC')]));
        }

        return $sql;
    }

    protected function transformAutoIncrementColumnConstraint(Expression $e): string
    {
        return 'IDENTITY';
    }

    protected function transformLastDay(Expression $e): string
    {
        return $this->func('EOMONTH', $e->args['this'] ?? null);
    }

    protected function transformMd5(Expression $e): string
    {
        return $this->func('HASHBYTES', Literal::string('MD5'), $e->args['this'] ?? null);
    }

    protected function transformSha(Expression $e): string
    {
        return $this->func('HASHBYTES', Literal::string('SHA1'), $e->args['this'] ?? null);
    }

    protected function transformSHA1Digest(Expression $e): string
    {
        return $this->func('HASHBYTES', Literal::string('SHA1'), $e->args['this'] ?? null);
    }

    protected function transformSha2(Expression $e): string
    {
        $length = $e->args['length'] ?? null;
        $length = $length instanceof Expression ? (string) $length : '256';

        return $this->func('HASHBYTES', Literal::string("SHA2_{$length}"), $e->args['this'] ?? null);
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'CHARINDEX', supportsPosition: true);
    }

    protected function transformTemporaryProperty(Expression $e): string
    {
        return '';
    }

    protected function transformTimestampTrunc(Expression $e): string
    {
        return $this->func('DATETRUNC', $e->args['unit'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformTrunc(Expression $e): string
    {
        $decimals = $e->args['decimals'] ?? null;

        return $this->func('ROUND', $e->args['this'] ?? null, $this->truthy($decimals) ? $decimals : Literal::number(0), Literal::number(1));
    }

    protected function transformUuid(Expression $e): string
    {
        return 'NEWID()';
    }

    // ---------------------------------------------------------------------------------------
    // Generator overrides
    // ---------------------------------------------------------------------------------------

    public function scopeResolution(string $rhs, string $scopeName): string
    {
        return "{$scopeName}::{$rhs}";
    }

    protected function selectSql(Select $expression): string
    {
        $this->prepareLimitOffset($expression);

        // Handles transpiling e.g. SELECT 1 AS x ORDER BY x NULLS FIRST FETCH FIRST 1 ROWS ONLY UNION ALL SELECT 2 AS x
        if ($expression->parent instanceof SetOperation && ($expression->args['limit'] ?? null) instanceof Fetch) {
            return $this->sql(Builder::selectStarFrom($expression->subquery('_l_0', copy: false)));
        }

        return parent::selectSql($expression);
    }

    public function setOperations(SetOperation $expression): string
    {
        $limit = $expression->args['limit'] ?? null;
        $offset = $expression->args['offset'] ?? null;
        $order = $expression->args['order'] ?? null;

        // Set operations cannot order by the CASE used to emulate null ordering, or by expressions
        // that aren't in their select list.
        $wrapOrder = false;

        if ($order instanceof Order) {
            $selects = array_map(static fn (Expression $select): Expression => $select->unalias()->unnest(), $expression->selects());

            foreach ($order->argList('expressions') as $ordered) {
                $this_ = ($ordered->argExpr('this') ?? new NullExpr)->unnest();

                if ($this_->isInt()) {
                    continue;
                }

                $desc = $this->truthy($ordered->args['desc'] ?? null);
                $nullsFirst = $this->truthy($ordered->args['nulls_first'] ?? null);
                $emulateNullOrdering = ($desc && $nullsFirst) || (! $desc && ! $nullsFirst);

                $inSelects = false;
                foreach ($selects as $select) {
                    if ($select->equals($this_)) {
                        $inSelects = true;

                        break;
                    }
                }

                if ($emulateNullOrdering || (! $this_ instanceof Column && ! $inSelects)) {
                    $wrapOrder = true;

                    break;
                }
            }
        }

        if (
            $wrapOrder
            || ($limit instanceof Limit && $offset === null)
            || ($order === null && ($offset !== null || $limit instanceof Fetch))
        ) {
            $select = $this->moveCtesToTopLevelIfNeeded(Builder::selectStarFrom($expression->subquery('_l_0', copy: false)));

            foreach (self::SET_OP_MODIFIERS as $arg) {
                $value = $expression->args[$arg] ?? null;

                if ($this->truthy($value)) {
                    $expression->set($arg, null);
                    $select->set($arg, $value);
                }
            }

            return $this->sql($select);
        }

        $this->prepareLimitOffset($expression);

        return parent::setOperations($expression);
    }

    protected function prepareLimitOffset(Expression $expression): void
    {
        $limit = $expression->args['limit'] ?? null;
        $offset = $expression->args['offset'] ?? null;

        if ($limit instanceof Fetch && $offset === null) {
            // Dialects like Oracle can FETCH directly from a row set but T-SQL requires an
            // ORDER BY + OFFSET clause in order to FETCH
            $offset = new Offset(['expression' => Literal::number(0)]);
            $expression->set('offset', $offset);
        }

        if ($offset !== null) {
            if (($expression->args['order'] ?? null) === null) {
                // ORDER BY is required in order to use OFFSET in a query, so a noop order by is used
                $expression->set('order', new Order(['expressions' => [(new Select(['expressions' => [new NullExpr]]))->subquery()]]));
            }

            if ($limit instanceof Limit) {
                // TOP and OFFSET can't be combined, so FETCH is used instead of TOP
                $limit->replace(new Fetch(['direction' => 'FIRST', 'count' => $limit->args['expression'] ?? null]));
            }
        }
    }

    protected function convertSql(Expression $expression): string
    {
        $name = $expression->flag('safe') ? 'TRY_CONVERT' : 'CONVERT';

        return $this->func($name, $expression->args['this'] ?? null, $expression->args['expression'] ?? null, $expression->args['style'] ?? null);
    }

    protected function queryoptionSql(Expression $expression): string
    {
        $option = $this->sql($expression, 'this');
        $value = $this->sql($expression, 'expression');

        if ($value !== '') {
            $optionalEqualSign = isset(self::OPTIONS_THAT_REQUIRE_EQUAL[$option]) ? '= ' : '';

            return "{$option} {$optionalEqualSign}{$value}";
        }

        return $option;
    }

    protected function lateralOp(Expression $expression): string
    {
        $crossApply = $expression->args['cross_apply'] ?? null;

        if ($crossApply === true) {
            return 'CROSS APPLY';
        }

        if ($crossApply === false) {
            return 'OUTER APPLY';
        }

        $this->unsupported('LATERAL clause is not supported.');

        return 'LATERAL';
    }

    protected function splitpartSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $delimiter = $expression->args['delimiter'] ?? null;
        $partIndex = $expression->args['part_index'] ?? null;
        $splitCount = $this_ instanceof Expression ? count(explode('.', $this_->name())) : 0;

        if (
            ! ($this_ instanceof Literal && $delimiter instanceof Literal && $partIndex instanceof Literal)
            || $delimiter->name() !== '.'
            || $splitCount > 4
        ) {
            $this->unsupported("SPLIT_PART can be transpiled to PARSENAME only for '.' delimiter and literal values");

            return '';
        }

        return $this->func('PARSENAME', $this_, Literal::number($splitCount + 1 - (int) $partIndex->toPhp()));
    }

    protected function extractSql(Expression $expression): string
    {
        $part = $expression->argExpr('this') ?? new NullExpr;
        $name = self::DATE_PART_UNMAPPING[strtoupper($part->name())] ?? $part;

        return $this->func('DATEPART', $name, $expression->args['expression'] ?? null);
    }

    protected function timefrompartsSql(Expression $expression): string
    {
        $nano = $expression->args['nano'] ?? null;

        if ($nano instanceof Expression) {
            $nano->pop();
            $this->unsupported('Specifying nanoseconds is not supported in TIMEFROMPARTS.');
        }

        if (($expression->args['fractions'] ?? null) === null) {
            $expression->set('fractions', Literal::number(0));
        }

        if (($expression->args['precision'] ?? null) === null) {
            $expression->set('precision', Literal::number(0));
        }

        return $this->renameFunc($expression, 'TIMEFROMPARTS');
    }

    protected function timestampfrompartsSql(Expression $expression): string
    {
        $zone = $expression->args['zone'] ?? null;

        if ($zone instanceof Expression) {
            $zone->pop();
            $this->unsupported('Time zone is not supported in DATETIMEFROMPARTS.');
        }

        $nano = $expression->args['nano'] ?? null;

        if ($nano instanceof Expression) {
            $nano->pop();
            $this->unsupported('Specifying nanoseconds is not supported in DATETIMEFROMPARTS.');
        }

        if (($expression->args['milli'] ?? null) === null) {
            $expression->set('milli', Literal::number(0));
        }

        return $this->renameFunc($expression, 'DATETIMEFROMPARTS');
    }

    protected function setitemSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof EQ && ! ($this_->args['this'] ?? null) instanceof Parameter) {
            // T-SQL does not use '=' in SET command, except when the LHS is a variable.
            return $this->sql($this_, 'this').' '.$this->sql($this_, 'expression');
        }

        return parent::setitemSql($expression);
    }

    protected function booleanSql(Expression $expression): string
    {
        $value = $this->truthy($expression->args['this'] ?? null);
        $parent = $expression->parent;

        if (($parent !== null && isset(self::BIT_TYPES[$parent::class])) || $expression->findAncestor(Values::class, Select::class) instanceof Values) {
            return $value ? '1' : '0';
        }

        return $value ? '(1 = 1)' : '(1 = 0)';
    }

    protected function isSql(Expression $expression): string
    {
        $negate = $expression->flag('negate');

        if (($expression->args['expression'] ?? null) instanceof Boolean) {
            return $this->binary($expression, $negate ? '<>' : '=');
        }

        return $this->binary($expression, $negate ? 'IS NOT' : 'IS');
    }

    protected function createableSql(Expression $expression, array $locations): string
    {
        $sql = $this->sql($expression, 'this');
        $properties = $expression->args['properties'] ?? null;
        $start = $this->identifierStart;

        $temporary = false;
        foreach ($properties instanceof Expression ? $properties->argList('expressions') : [] as $property) {
            if ($property instanceof TemporaryProperty) {
                $temporary = true;

                break;
            }
        }

        if (! str_starts_with($sql, '#') && ! str_starts_with($sql, "{$start}#") && $temporary) {
            $sql = $start !== '' && str_starts_with($sql, $start) ? "{$start}#".substr($sql, strlen($start)) : "#{$sql}";
        }

        return $sql;
    }

    protected function createSql(Expression $expression): string
    {
        $kind = strtoupper($expression->text('kind'));
        $exists = $expression->flag('exists');
        $expression->set('exists', null);

        $likeProperty = $expression->find(LikeProperty::class);
        $ctasExpression = $likeProperty !== null ? $likeProperty->argExpr('this') : $expression->argExpr('expression');

        if ($kind === 'VIEW') {
            ($expression->args['this'] ?? null)?->set('catalog', null);
            $with = $expression->args['with_'] ?? null;

            // CREATE VIEW requires the WITH clause to come after it, so the CTEs bubbled up by
            // preprocessing are moved back into the view's query
            if ($ctasExpression !== null && $with instanceof Expression) {
                $ctasExpression->set('with_', $with->pop());
            }
        } elseif (
            $kind === 'FUNCTION'
            && $ctasExpression instanceof ReturnExpr
            && ($body = ($ctasExpression->argExpr('this') ?? new NullExpr)->unnest()) instanceof Query
            && ($with = $expression->args['with_'] ?? null) instanceof Expression
        ) {
            // Table-valued functions require the WITH clause to stay inside the RETURN body
            $body->set('with_', $with->pop());
        }

        $table = $expression->find(Table::class);

        // Convert CTAS statement to SELECT .. INTO ..
        if ($kind === 'TABLE' && $ctasExpression !== null) {
            if ($ctasExpression instanceof Select || $ctasExpression instanceof SetOperation) {
                $ctasExpression = $ctasExpression->subquery();
            }

            $properties = $expression->args['properties'] ?? null;
            $isTemp = false;

            foreach ($properties instanceof Properties ? $properties->argList('expressions') : [] as $property) {
                if ($property instanceof TemporaryProperty) {
                    $isTemp = true;

                    break;
                }
            }

            $selectInto = Builder::selectStarFrom(Builder::alias($ctasExpression, 'temp', table: true));
            $selectInto->set('into', new Into(['this' => $table, 'temporary' => $isTemp]));

            if ($likeProperty !== null) {
                $selectInto->set('limit', new Limit(['expression' => Literal::number(0)]));
            }

            $sql = $this->sql($selectInto);
        } else {
            $sql = parent::createSql($expression);
        }

        if ($exists) {
            $identifier = $this->sql(Literal::string($table !== null ? self::tableName($table) : ''));
            $sqlWithCtes = $this->prependCtes($expression, $sql);
            $sqlLiteral = $this->sql(Literal::string($sqlWithCtes));

            if ($kind === 'SCHEMA') {
                return "IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = {$identifier}) EXEC({$sqlLiteral})";
            }

            if ($kind === 'TABLE' && $table !== null) {
                $where = Builder::and([
                    new EQ(['this' => Builder::column('TABLE_NAME'), 'expression' => Literal::string($table->name())]),
                    $table->text('db') !== '' ? new EQ(['this' => Builder::column('TABLE_SCHEMA'), 'expression' => Literal::string($table->text('db'))]) : null,
                    $table->text('catalog') !== '' ? new EQ(['this' => Builder::column('TABLE_CATALOG'), 'expression' => Literal::string($table->text('catalog'))]) : null,
                ]);

                return "IF NOT EXISTS (SELECT * FROM INFORMATION_SCHEMA.TABLES WHERE {$where}) EXEC({$sqlLiteral})";
            }

            if ($kind === 'INDEX') {
                $index = $this->sql(Literal::string(($expression->argExpr('this') ?? new NullExpr)->text('this')));

                return "IF NOT EXISTS (SELECT * FROM sys.indexes WHERE object_id = object_id({$identifier}) AND name = {$index}) EXEC({$sqlLiteral})";
            }
        } elseif ($expression->flag('replace')) {
            $position = strpos($sql, 'CREATE OR REPLACE ');

            if ($position !== false) {
                $sql = substr_replace($sql, 'CREATE OR ALTER ', $position, strlen('CREATE OR REPLACE '));
            }
        }

        return $this->prependCtes($expression, $sql);
    }

    /**
     * sqlglot exp.table_name: the table's dotted name, with parts quoted only when necessary.
     */
    private static function tableName(Table $table): string
    {
        return implode('.', array_map(
            static fn (Expression $part): string => Builder::isSafeIdentifier($part->name()) ? $part->name() : $part->sql(),
            $table->parts(),
        ));
    }

    protected function intoSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'unlogged', 'expressions');

        if ($expression->flag('temporary')) {
            // Push a temporary Into down to the table's identifier
            $table = $expression->find(Table::class);
            $identifier = $table?->args['this'] ?? null;

            if ($identifier instanceof Identifier) {
                $identifier->set('temporary', true);
            }
        }

        return $this->seg('INTO').' '.$this->sql($expression, 'this');
    }

    protected function countSql(Expression $expression): string
    {
        return $this->renameFunc($expression, $expression->flag('big_int') ? 'COUNT_BIG' : 'COUNT');
    }

    protected function datediffSql(Expression $expression): string
    {
        return $this->dateDeltaSql($expression, $expression->flag('big_int') ? 'DATEDIFF_BIG' : 'DATEDIFF');
    }

    protected function offsetSql(Expression $expression): string
    {
        return parent::offsetSql($expression).' ROWS';
    }

    protected function versionSql(Expression $expression): string
    {
        $name = $expression->name() === 'TIMESTAMP' ? 'SYSTEM_TIME' : $expression->name();
        $expr = $expression->args['expression'] ?? null;
        $kind = $expression->text('kind');

        if (($kind === 'FROM' || $kind === 'BETWEEN') && $expr instanceof Expression) {
            $args = $expr->argList('expressions');
            $sep = $kind === 'FROM' ? 'TO' : 'AND';
            $exprSql = $this->sql($args[0] ?? null)." {$sep} ".$this->sql($args[1] ?? null);
        } else {
            $exprSql = $this->sql($expr instanceof Expression ? $expr : null);
        }

        $exprSql = $exprSql !== '' ? " {$exprSql}" : '';

        return "FOR {$name} {$kind}{$exprSql}";
    }

    protected function returnspropertySql(Expression $expression): string
    {
        $table = $expression->args['table'] ?? null;
        $table = $table instanceof Expression ? "{$table} " : '';

        return "RETURNS {$table}".$this->sql($expression, 'this');
    }

    protected function returningSql(Expression $expression): string
    {
        $into = $this->sql($expression, 'into');
        $into = $into !== '' ? $this->seg("INTO {$into}") : '';

        return $this->seg('OUTPUT').' '.$this->expressions($expression, flat: true).$into;
    }

    protected function transactionSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $mark = $this->sql($expression, 'mark');
        $mark = $mark !== '' ? " WITH MARK {$mark}" : '';

        return "BEGIN TRANSACTION{$this_}{$mark}";
    }

    protected function commitSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';
        $durability = $expression->args['durability'] ?? null;
        $durability = $durability !== null ? ' WITH (DELAYED_DURABILITY = '.($this->truthy($durability) ? 'ON' : 'OFF').')' : '';

        return "COMMIT TRANSACTION{$this_}{$durability}";
    }

    protected function rollbackSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';

        return "ROLLBACK TRANSACTION{$this_}";
    }

    protected function identifierSql(Identifier $expression): string
    {
        $identifier = parent::identifierSql($expression);

        if ($expression->flag('global_')) {
            $prefix = '##';
        } elseif ($expression->flag('temporary')) {
            $prefix = '#';
        } else {
            return $identifier;
        }

        $start = $this->identifierStart;

        if ($expression->quoted() && $start !== '' && str_starts_with($identifier, $start)) {
            return $start.$prefix.substr($identifier, strlen($start));
        }

        return "{$prefix}{$identifier}";
    }

    protected function constraintSql(Expression $expression): string
    {
        return 'CONSTRAINT '.$this->sql($expression, 'this').' '.$this->expressions($expression, flat: true, sep: ' ');
    }

    protected function lengthSql(Expression $expression): string
    {
        return $this->uncastText($expression, 'LEN');
    }

    protected function rightSql(Expression $expression): string
    {
        return $this->uncastText($expression, 'RIGHT');
    }

    protected function leftSql(Expression $expression): string
    {
        return $this->uncastText($expression, 'LEFT');
    }

    private function uncastText(Expression $expression, string $name): string
    {
        $this_ = $expression->args['this'] ?? null;
        $thisSql = $this_ instanceof Cast && $this_->isType(DType::TEXT)
            ? $this->sql($this_, 'this')
            : $this->sql($this_ instanceof Expression ? $this_ : null);
        $expressionSql = $this->sql($expression, 'expression');

        return $this->func($name, $thisSql, $expressionSql !== '' ? $expressionSql : null);
    }

    protected function partitionSql(Expression $expression): string
    {
        return 'WITH (PARTITIONS('.$this->expressions($expression, flat: true).'))';
    }

    protected function alterSql(Expression $expression): string
    {
        $action = $expression->argList('actions')[0] ?? null;

        if ($action instanceof AlterRename) {
            return "EXEC sp_rename '".$this->sql($expression, 'this')."', '".($action->argExpr('this') ?? new NullExpr)->name()."'";
        }

        return parent::alterSql($expression);
    }

    protected function dropSql(Expression $expression): string
    {
        if (($expression->args['kind'] ?? null) === 'VIEW') {
            foreach ($expression->argList('tables') as $table) {
                $table->set('catalog', null);
            }
        }

        return parent::dropSql($expression);
    }

    protected function optionsModifier(Expression $expression): string
    {
        $options = $this->expressions($expression, key: 'options');

        return $options !== '' ? ' OPTION'.$this->wrap($options) : '';
    }

    protected function dpipeSql(Expression $expression): string
    {
        $parts = $expression->flatten();
        $result = array_shift($parts);

        foreach ($parts as $part) {
            $result = new Add(['this' => $result, 'expression' => $part]);
        }

        return $this->sql($result);
    }

    protected function isasciiSql(Expression $expression): string
    {
        return '(PATINDEX(CONVERT(VARCHAR(MAX), 0x255b5e002d7f5d25) COLLATE Latin1_General_BIN, '.$this->sql($expression, 'this').') = 0)';
    }

    protected function columndefSql(Expression $expression, string $sep = ' '): string
    {
        $this_ = parent::columndefSql($expression, $sep);
        $default = $this->sql($expression, 'default');
        $default = $default !== '' ? " = {$default}" : '';
        $output = $this->sql($expression, 'output');
        $output = $output !== '' ? " {$output}" : '';

        return "{$this_}{$default}{$output}";
    }

    protected function coalesceSql(Expression $expression): string
    {
        return $this->renameFunc($expression, $expression->flag('is_null') ? 'ISNULL' : 'COALESCE');
    }

    protected function storedprocedureSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expressions = $this->expressions($expression);
        $expressions = $expression->flag('wrapped') ? $this->wrap($expressions) : " {$expressions}";

        return trim($expressions) !== '' ? "{$this_}{$expressions}" : $this_;
    }

    protected function ifblockSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $true = $this->sql($expression, 'true');
        $true = $true !== '' ? " {$true}" : ' ';
        $false = $this->sql($expression, 'false');
        $false = $false !== '' ? "; ELSE BEGIN {$false}" : '';

        return "IF {$this_} BEGIN{$true}{$false}";
    }

    protected function whileblockSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $body = $this->sql($expression, 'body');
        $body = $body !== '' ? " {$body}" : ' ';

        return "WHILE {$this_} BEGIN{$body}";
    }

    protected function executeSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expressions = $this->expressions($expression);
        $expressions = $expressions !== '' ? " {$expressions}" : '';
        $returnStatus = $this->sql($expression, 'return_status');
        $returnStatus = $returnStatus !== '' ? "{$returnStatus} = " : '';

        return "EXECUTE {$returnStatus}{$this_}{$expressions}";
    }

    protected function executesqlSql(Expression $expression): string
    {
        return $this->executeSql($expression);
    }
}
