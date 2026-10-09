<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Generator\Concerns;

use InvalidArgumentException;
use LaravelSqlParser\LaravelSqlParser\Errors\UnsupportedError;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Any;
use LaravelSqlParser\LaravelSqlParser\Expressions\ApproxQuantile;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\Connector;
use LaravelSqlParser\LaravelSqlParser\Expressions\Constraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\Create;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataTypeParam;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Exists;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\Func;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateDateArray;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateSeries;
use LaravelSqlParser\LaravelSqlParser\Expressions\Having;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\ILike;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lambda;
use LaravelSqlParser\LaravelSqlParser\Expressions\Like;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\LTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\Ordered;
use LaravelSqlParser\LaravelSqlParser\Expressions\PercentileCont;
use LaravelSqlParser\LaravelSqlParser\Expressions\PercentileDisc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pivot;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Qualify;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\RowNumber;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\SubqueryPredicate;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TemporaryProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Union;
use LaravelSqlParser\LaravelSqlParser\Expressions\UniqueColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;
use LaravelSqlParser\LaravelSqlParser\Expressions\With;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithinGroup;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;
use ReflectionClass;

/**
 * AST rewrites applied before generation (sqlglot transforms.py). Dialects list them in a
 * TRANSFORMS entry as [preprocessHandler, transforms: [...], generator: ?handler].
 */
trait Transforms
{
    /**
     * sqlglot transforms.preprocess: rewrite the node with the given transforms, then generate the
     * result with the generator handler, its `<key>Sql` method or its TRANSFORMS entry.
     *
     * @param  list<string|array<int|string, mixed>>  $transforms
     * @param  string|array<int|string, mixed>|null  $generator
     */
    protected function preprocessHandler(Expression $expression, array $transforms = [], string|array|null $generator = null): string
    {
        $expressionType = $expression::class;

        try {
            foreach ($transforms as $transform) {
                $expression = $this->applyTransform($transform, $expression);
            }
        } catch (UnsupportedError $error) {
            $this->unsupported($error->getMessage());
        }

        if ($generator !== null) {
            return $this->callTransform($generator, $expression);
        }

        $method = $expression::KEY.'Sql';

        if (method_exists($this, $method)) {
            return $this->{$method}($expression);
        }

        $transformsHandler = $this->t['TRANSFORMS'][$expression::class] ?? null;

        if ($transformsHandler !== null) {
            if ($expressionType === $expression::class) {
                if ($expression instanceof Func) {
                    return $this->functionFallbackSql($expression);
                }

                throw new InvalidArgumentException('Expr type '.(new ReflectionClass($expression))->getShortName().' requires a _sql method in order to be transformed.');
            }

            return $this->callTransform($transformsHandler, $expression);
        }

        throw new InvalidArgumentException('Unsupported expression type '.(new ReflectionClass($expression))->getShortName().'.');
    }

    /**
     * @param  string|array<int|string, mixed>  $transform  a method name or [method, ...named args]
     */
    protected function applyTransform(string|array $transform, Expression $expression): Expression
    {
        if (is_array($transform)) {
            $method = $transform[0];
            unset($transform[0]);

            return $this->{$method}($expression, ...$transform);
        }

        return $this->{$transform}($expression);
    }

    /**
     * sqlglot helper.find_new_name.
     *
     * @param  array<array-key, mixed>  $taken
     */
    protected static function findNewName(array $taken, string $base): string
    {
        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $i = 2;
        $new = "{$base}_{$i}";

        while (in_array($new, $taken, true)) {
            $i++;
            $new = "{$base}_{$i}";
        }

        return $new;
    }

    /**
     * AND a condition onto a query's WHERE (sqlglot Select.where(..., copy=False)).
     */
    protected static function addWhere(Expression $query, Expression $condition): void
    {
        $existing = $query->args['where'] ?? null;
        $conditions = $existing instanceof Where ? [$existing->argExpr('this'), $condition] : [$condition];

        $query->set('where', new Where(['this' => Builder::and($conditions, copy: false)]));
    }

    /**
     * @param  list<Expression>  $projections
     */
    protected static function selectFrom(array $projections, Expression $from, ?Expression $where = null): Select
    {
        $select = new Select(['expressions' => $projections, 'from_' => new From(['this' => $from])]);

        if ($where !== null) {
            $select->set('where', new Where(['this' => $where]));
        }

        return $select;
    }

    /**
     * Wrap numeric values used as predicates in `<> 0` (sqlglot transforms.ensure_bools).
     */
    public function ensureBools(Expression $expression): Expression
    {
        $ensureBool = static function (mixed $node): void {
            if (! $node instanceof Expression) {
                return;
            }

            if (
                $node->isNumber()
                || (! $node instanceof SubqueryPredicate && $node->isType(DType::UNKNOWN, ...DataType::NUMERIC_TYPES))
                || ($node instanceof Column && $node->type() === null)
            ) {
                $node->replace(new NEQ(['this' => $node->copy(), 'expression' => Literal::number(0)]));
            }
        };

        foreach (iterator_to_array($expression->walk(), false) as $node) {
            if ($node instanceof Connector) {
                $ensureBool($node->args['this'] ?? null);
                $ensureBool($node->args['expression'] ?? null);
            } elseif ($node instanceof Not) {
                $ensureBool($node->args['this'] ?? null);
            } elseif ($node instanceof IfExpr && ! ($node->parent instanceof CaseExpr && ($node->parent->args['this'] ?? null) !== null)) {
                // We can't replace num in CASE x WHEN num ..., because it's not the full predicate
                $ensureBool($node->args['this'] ?? null);
            } elseif ($node instanceof Where || $node instanceof Having) {
                $ensureBool($node->args['this'] ?? null);
            }
        }

        return $expression;
    }

    /**
     * Hoist nested WITH clauses to the top level (sqlglot transforms.move_ctes_to_top_level).
     */
    public function moveCtesToTopLevel(Expression $expression): Expression
    {
        $topLevelWith = $expression->args['with_'] ?? null;

        foreach ($expression->findAll(With::class) as $innerWith) {
            if ($innerWith->parent === $expression) {
                continue;
            }

            if (! $topLevelWith instanceof With) {
                $topLevelWith = $innerWith->pop();
                $expression->set('with_', $topLevelWith);

                continue;
            }

            if ($innerWith->flag('recursive')) {
                $topLevelWith->set('recursive', true);
            }

            $parentCte = $innerWith->findAncestor(CTE::class);
            $innerWith->pop();
            $expressions = $topLevelWith->argList('expressions');

            if ($parentCte !== null && ($i = array_search($parentCte, $expressions, true)) !== false) {
                array_splice($expressions, $i, 0, $innerWith->argList('expressions'));
            } else {
                $expressions = [...$expressions, ...$innerWith->argList('expressions')];
            }

            $topLevelWith->set('expressions', $expressions);
        }

        return $expression;
    }

    /**
     * Convert DISTINCT ON into a ROW_NUMBER() subquery filter.
     */
    public function eliminateDistinctOn(Expression $expression): Expression
    {
        $distinct = $expression->args['distinct'] ?? null;

        if (! $expression instanceof Select || ! $distinct instanceof Expression || ! ($distinct->args['on'] ?? null) instanceof Tuple) {
            return $expression;
        }

        $rowNumberWindowAlias = self::findNewName($expression->namedSelects(), '_row_number');
        $distinctCols = $distinct->pop()->args['on']->argList('expressions');
        $window = new Window(['this' => new RowNumber, 'partition_by' => $distinctCols]);
        $order = $expression->args['order'] ?? null;

        if ($order instanceof Order) {
            $window->set('order', $order->pop());
        } else {
            $window->set('order', new Order(['expressions' => array_map(static fn (Expression $c): Expression => $c->copy(), $distinctCols)]));
        }

        $expression->append('expressions', Builder::alias($window, $rowNumberWindowAlias));

        $newSelects = [];
        $takenNames = [$rowNumberWindowAlias];
        $selects = $expression->argList('expressions');

        foreach (array_slice($selects, 0, -1) as $select) {
            if ($select->isStar()) {
                $newSelects = [new Star];

                break;
            }

            if (! $select instanceof Alias) {
                $outputName = $select->outputName();
                $alias = self::findNewName($takenNames, $outputName !== '' ? $outputName : '_col');
                $quoted = $select instanceof Column ? (($select->args['this'] ?? null)?->args['quoted'] ?? null) : null;
                $select = $select->replace(Builder::alias($select, $alias, quoted: is_bool($quoted) ? $quoted : null));
            }

            $takenNames[] = $select->outputName();
            $newSelects[] = $select->args['alias'];
        }

        return self::selectFrom(
            $newSelects,
            $expression->subquery('_t', copy: false),
            new EQ(['this' => Builder::column($rowNumberWindowAlias), 'expression' => Literal::number(1)]),
        );
    }

    /**
     * Convert SELECT statements that contain QUALIFY into subqueries filtered by WHERE.
     */
    public function eliminateQualify(Expression $expression): Expression
    {
        $qualify = $expression->args['qualify'] ?? null;

        if (! $expression instanceof Select || ! $qualify instanceof Qualify) {
            return $expression;
        }

        $taken = $expression->namedSelects();

        foreach ($expression->argList('expressions') as $select) {
            if ($select->aliasOrName() === '') {
                $alias = self::findNewName($taken, '_c');
                $select->replace(Builder::alias($select, $alias));
                $taken[] = $alias;
            }
        }

        $outerSelects = [];

        foreach ($expression->argList('expressions') as $select) {
            $aliasOrName = $select->aliasOrName();
            $identifier = $select->args['alias'] ?? null;
            $identifier = $identifier instanceof Expression ? $identifier : ($select->args['this'] ?? null);

            $outerSelects[] = $identifier instanceof Identifier
                ? Builder::column($aliasOrName, quoted: $identifier->quoted())
                : Builder::maybeParse($aliasOrName);
        }

        $qualifyFilters = $qualify->pop()->argExpr('this') ?? Builder::true();

        $expressionByAlias = [];
        foreach ($expression->argList('expressions') as $select) {
            if ($select instanceof Alias) {
                $expressionByAlias[$select->alias()] = $select->args['this'] ?? null;
            }
        }

        $candidates = $expression->isStar() ? [Window::class] : [Window::class, Column::class];

        foreach ($qualifyFilters->findAll(...$candidates) as $selectCandidate) {
            if ($selectCandidate instanceof Window) {
                if ($expressionByAlias !== []) {
                    foreach ($selectCandidate->findAll(Column::class) as $column) {
                        $expr = $expressionByAlias[$column->name()] ?? null;

                        if ($expr instanceof Expression) {
                            $column->replace($expr);
                        }
                    }
                }

                $alias = self::findNewName($expression->namedSelects(), '_w');
                $expression->append('expressions', Builder::alias($selectCandidate, $alias));
                $column = Builder::column($alias);

                if ($selectCandidate->parent instanceof Qualify) {
                    $qualifyFilters = $column;
                } else {
                    $selectCandidate->replace($column);
                }
            } elseif (! in_array($selectCandidate->name(), $expression->namedSelects(), true) && $selectCandidate->findAncestor(Window::class) === null) {
                $expression->append('expressions', $selectCandidate->copy());
            }
        }

        return self::selectFrom($outerSelects, $expression->subquery('_t', copy: false), $qualifyFilters);
    }

    public function removePrecisionParameterizedTypes(Expression $expression): Expression
    {
        foreach ($expression->findAll(DataType::class) as $node) {
            $node->set('expressions', array_values(array_filter($node->argList('expressions'), static fn (Expression $e): bool => ! $e instanceof DataTypeParam)));
        }

        return $expression;
    }

    /**
     * Remove references to unnest table aliases, added by the optimizer's qualify_columns step.
     */
    public function unqualifyUnnest(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $unnestAliases = [];

        foreach (Scope::findAllInScope($expression, Unnest::class) as $unnest) {
            if ($unnest->parent instanceof From || $unnest->parent instanceof Join) {
                $unnestAliases[$unnest->alias()] = true;
            }
        }

        if ($unnestAliases !== []) {
            foreach ($expression->findAll(Column::class) as $column) {
                $leftmostPart = $column->parts()[0] ?? null;

                if ($leftmostPart !== null && $leftmostPart->argKey !== 'this' && isset($unnestAliases[$leftmostPart->name()])) {
                    $leftmostPart->pop();
                }
            }
        }

        return $expression;
    }

    /**
     * Convert UNNEST(GENERATE_DATE_ARRAY(...)) in FROM / JOIN into a recursive CTE.
     */
    public function unnestGenerateDateArrayUsingRecursiveCte(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $count = 0;
        $recursiveCtes = [];

        foreach ($expression->findAll(Unnest::class) as $unnest) {
            $args = $unnest->argList('expressions');

            if (! ($unnest->parent instanceof From || $unnest->parent instanceof Join) || count($args) !== 1 || ! $args[0] instanceof GenerateDateArray) {
                continue;
            }

            $generateDateArray = $args[0];
            $start = $generateDateArray->args['start'] ?? null;
            $end = $generateDateArray->args['end'] ?? null;
            $step = $generateDateArray->args['step'] ?? null;

            if (! $start instanceof Expression || ! $end instanceof Expression || ! $step instanceof Interval) {
                continue;
            }

            $alias = $unnest->args['alias'] ?? null;
            $columnName = $alias instanceof TableAlias ? ($alias->argList('columns')[0] ?? null)?->name() ?? 'date_value' : 'date_value';

            $start = Builder::cast($start, 'date');
            $dateAdd = Builder::func('date_add', [$columnName, Literal::number($step->name()), $step->argExpr('unit') ?? 'DAY']);
            $castDateAdd = Builder::cast($dateAdd, 'date');
            $cteName = '_generated_dates'.($count > 0 ? "_{$count}" : '');

            $baseQuery = new Select(['expressions' => [Builder::alias($start, $columnName)]]);
            $recursiveQuery = self::selectFrom(
                [$castDateAdd],
                Builder::table($cteName),
                new LTE(['this' => $castDateAdd->copy(), 'expression' => Builder::cast($end, 'date')]),
            );
            $cteQuery = new Union(['this' => $baseQuery, 'expression' => $recursiveQuery, 'distinct' => false]);
            $generateDatesQuery = self::selectFrom([Builder::column($columnName)], Builder::table($cteName));
            $unnest->replace($generateDatesQuery->subquery($cteName));
            $recursiveCtes[] = Builder::alias(new CTE(['this' => $cteQuery]), $cteName, table: [$columnName]);
            $count++;
        }

        if ($recursiveCtes !== []) {
            $withExpression = $expression->args['with_'] ?? null;
            $withExpression = $withExpression instanceof With ? $withExpression : new With;
            $withExpression->set('recursive', true);
            $withExpression->set('expressions', [...$recursiveCtes, ...$withExpression->argList('expressions')]);
            $expression->set('with_', $withExpression);
        }

        return $expression;
    }

    public function unnestGenerateSeries(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;

        if ($expression instanceof Table && $this_ instanceof GenerateSeries) {
            $unnest = new Unnest(['expressions' => [$this_]]);

            if ($expression->alias() !== '') {
                return Builder::alias($unnest, '_u', table: [$expression->alias()], copy: false);
            }

            return $unnest;
        }

        return $expression;
    }

    /**
     * Convert SEMI / ANTI joins into equivalent [NOT] EXISTS filters.
     */
    public function eliminateSemiAndAntiJoins(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        foreach ($expression->argList('joins') as $join) {
            if (! $join instanceof Join) {
                continue;
            }

            $on = $join->args['on'] ?? null;
            $kind = $join->kind();

            if ($on instanceof Expression && ($kind === 'SEMI' || $kind === 'ANTI')) {
                $subquery = self::selectFrom([Literal::number(1)], $join->argExpr('this') ?? new Table, $on);
                $exists = new Exists(['this' => $subquery]);

                if ($kind === 'ANTI') {
                    $exists = new Not(['this' => $exists]);
                }

                $join->pop();
                self::addWhere($expression, $exists);
            }
        }

        return $expression;
    }

    /**
     * Convert a single FULL OUTER JOIN into a UNION ALL of a LEFT and a RIGHT join.
     */
    public function eliminateFullOuterJoin(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $fullOuterJoins = [];

        foreach ($expression->argList('joins') as $index => $join) {
            if ($join instanceof Join && $join->side() === 'FULL') {
                $fullOuterJoins[] = [$index, $join];
            }
        }

        if (count($fullOuterJoins) !== 1) {
            return $expression;
        }

        $expressionCopy = $expression->copy();
        [$index, $fullOuterJoin] = $fullOuterJoins[0];
        $from = $expression->args['from_'];
        $tables = [$from->aliasOrName(), $fullOuterJoin->aliasOrName()];

        $joinConditions = $fullOuterJoin->args['on'] ?? null;

        if (! $joinConditions instanceof Expression) {
            $joinConditions = Builder::and(array_map(
                static fn (Expression $col): Expression => new EQ(['this' => Builder::column($col->name(), $tables[0]), 'expression' => Builder::column($col->name(), $tables[1])]),
                $fullOuterJoin->argList('using'),
            ));
        }

        $fullOuterJoin->set('side', 'left');
        $antiJoinClause = self::selectFrom([Literal::number(1)], $from->argExpr('this') ?? new Table, $joinConditions->copy());
        $expressionCopy->argList('joins')[$index]->set('side', 'right');
        self::addWhere($expressionCopy, new Not(['this' => new Exists(['this' => $antiJoinClause])]));

        $union = new Union(['this' => $expression, 'expression' => $expressionCopy, 'distinct' => false]);

        foreach (['with_', 'order', 'limit', 'offset'] as $arg) {
            $value = $expression->args[$arg] ?? null;

            if ($value instanceof Expression) {
                $expression->set($arg, null);
                $expressionCopy->set($arg, null);
                $union->set($arg, $value);
            }
        }

        return $union;
    }

    public function addWithinGroupForPercentiles(Expression $expression): Expression
    {
        if (
            ($expression instanceof PercentileCont || $expression instanceof PercentileDisc)
            && ! $expression->parent instanceof WithinGroup
            && ($expression->args['expression'] ?? null) instanceof Expression
        ) {
            $column = ($expression->argExpr('this') ?? new NullExpr)->pop();
            $expression->set('this', $expression->args['expression']->pop());
            $order = new Order(['expressions' => [new Ordered(['this' => $column])]]);

            return new WithinGroup(['this' => $expression, 'expression' => $order]);
        }

        return $expression;
    }

    public function removeWithinGroupForPercentiles(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;

        if (
            $expression instanceof WithinGroup
            && ($this_ instanceof PercentileCont || $this_ instanceof PercentileDisc)
            && ($expression->args['expression'] ?? null) instanceof Order
        ) {
            $quantile = $this_->args['this'] ?? null;
            $inputValue = $expression->find(Ordered::class)?->args['this'] ?? null;

            return $expression->replace(new ApproxQuantile(['this' => $inputValue, 'quantile' => $quantile]));
        }

        return $expression;
    }

    /**
     * Uses projection output names in recursive CTE definitions to define the CTEs' columns.
     */
    public function addRecursiveCteColumnNames(Expression $expression): Expression
    {
        if (! $expression instanceof With || ! $expression->flag('recursive')) {
            return $expression;
        }

        $counter = 0;

        foreach ($expression->argList('expressions') as $cte) {
            $alias = $cte->args['alias'] ?? null;

            if (! $alias instanceof Expression || $alias->argList('columns') !== []) {
                continue;
            }

            $query = $cte->args['this'] ?? null;

            if ($query instanceof SetOperation) {
                $query = $query->args['this'] ?? null;
            }

            $columns = [];
            foreach ($query instanceof Expression ? $query->argList('expressions') : [] as $s) {
                $name = $s->aliasOrName();
                $columns[] = Builder::toIdentifier($name !== '' ? $name : '_c_'.$counter++);
            }

            $alias->set('columns', $columns);
        }

        return $expression;
    }

    public function epochCastToTs(Expression $expression): Expression
    {
        if (
            $expression instanceof Cast
            && strtolower($expression->name()) === 'epoch'
            && in_array(($expression->args['to'] ?? null)?->args['this'] ?? null, DataType::TEMPORAL_TYPES, true)
        ) {
            ($expression->argExpr('this') ?? new NullExpr)->replace(Literal::string('1970-01-01 00:00:00'));
        }

        return $expression;
    }

    public function unqualifyColumns(Expression $expression): Expression
    {
        foreach ($expression->findAll(Column::class) as $column) {
            foreach (array_slice($column->parts(), 0, -1) as $part) {
                $part->pop();
            }
        }

        return $expression;
    }

    public function unqualifyPivotFields(Expression $expression): Expression
    {
        if ($expression instanceof Pivot) {
            $expression->set('fields', array_map(fn (Expression $field): Expression => $this->unqualifyColumns($field), $expression->argList('fields')));
        }

        return $expression;
    }

    public function removeUniqueConstraints(Expression $expression): Expression
    {
        foreach ($expression->findAll(UniqueColumnConstraint::class) as $constraint) {
            $parent = $constraint->parent;
            ($parent instanceof ColumnConstraint || $parent instanceof Constraint ? $parent : $constraint)->pop();
        }

        return $expression;
    }

    public function ctasWithTmpTablesToCreateTmpView(Expression $expression): Expression
    {
        if (! $expression instanceof Create) {
            return $expression;
        }

        $properties = $expression->args['properties'] ?? null;
        $temporary = false;

        foreach ($properties instanceof Expression ? $properties->argList('expressions') : [] as $property) {
            if ($property instanceof TemporaryProperty) {
                $temporary = true;

                break;
            }
        }

        if ($expression->text('kind') === 'TABLE' && $temporary && ($expression->args['expression'] ?? null) instanceof Expression) {
            return new Create(['kind' => 'TEMPORARY VIEW', 'this' => $expression->args['this'] ?? null, 'expression' => $expression->args['expression']]);
        }

        return $expression;
    }

    public function structKvToAlias(Expression $expression): Expression
    {
        if ($expression instanceof Struct) {
            $expression->set('expressions', array_map(
                static fn (Expression $e): Expression => $e instanceof PropertyEQ
                    ? Builder::alias($e->argExpr('expression') ?? new NullExpr, $e->args['this'] instanceof Identifier ? $e->args['this'] : $e->name())
                    : $e,
                $expression->argList('expressions'),
            ));
        }

        return $expression;
    }

    /**
     * Transform ANY operator to Spark's EXISTS.
     */
    public function anyToExists(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        foreach ($expression->findAll(Any::class) as $anyExpr) {
            $this_ = $anyExpr->args['this'] ?? null;

            if ($this_ instanceof Query || $anyExpr->parent instanceof Like || $anyExpr->parent instanceof ILike || ! $this_ instanceof Expression) {
                continue;
            }

            $binop = $anyExpr->parent;

            if ($binop instanceof Binary) {
                $lambdaArg = Builder::toIdentifier('x');
                $anyExpr->replace($lambdaArg);
                $lambdaExpr = new Lambda(['this' => $binop->copy(), 'expressions' => [$lambdaArg]]);
                $binop->replace(new Exists(['this' => $this_->unnest(), 'expression' => $lambdaExpr]));
            }
        }

        return $expression;
    }

    /**
     * Inline named windows (WINDOW w AS (...)) into the window functions that reference them.
     */
    public function eliminateWindowClause(Expression $expression): Expression
    {
        if (! $expression instanceof Select || ! array_key_exists('windows', $expression->args) || $expression->args['windows'] === null) {
            return $expression;
        }

        $windows = $expression->argList('windows');
        $expression->set('windows', null);
        $windowExpression = [];

        foreach ($windows as $window) {
            self::inlineInheritedWindow($window, $windowExpression);
            $windowExpression[strtolower($window->name())] = $window;
        }

        foreach (Scope::findAllInScope($expression, Window::class) as $window) {
            self::inlineInheritedWindow($window, $windowExpression);
        }

        return $expression;
    }

    /**
     * @param  array<string, Expression>  $windowExpression  named windows by lowercase name
     */
    private static function inlineInheritedWindow(Expression $window, array $windowExpression): void
    {
        $inherited = $windowExpression[strtolower($window->alias())] ?? null;

        if ($inherited === null) {
            return;
        }

        $window->set('alias', null);

        foreach (['partition_by', 'order', 'spec'] as $key) {
            $arg = $inherited->args[$key] ?? null;

            if ($arg instanceof Expression) {
                $window->set($key, $arg->copy());
            } elseif (is_array($arg)) {
                $window->set($key, array_map(static fn (Expression $e): Expression => $e->copy(), $arg));
            }
        }
    }

    public function inheritStructFieldNames(Expression $expression): Expression
    {
        $items = $expression->argList('expressions');
        $firstItem = $items[0] ?? null;

        if (! $expression instanceof ArrayExpr || ! $expression->flag('struct_name_inheritance') || ! $firstItem instanceof Struct) {
            return $expression;
        }

        $fieldNames = [];

        foreach ($firstItem->argList('expressions') as $field) {
            if (! $field instanceof PropertyEQ) {
                return $expression;
            }

            $fieldNames[] = $field->args['this'] ?? null;
        }

        foreach (array_slice($items, 1) as $struct) {
            if (! $struct instanceof Struct || count($struct->argList('expressions')) !== count($fieldNames)) {
                continue;
            }

            $newExpressions = [];

            foreach ($struct->argList('expressions') as $i => $expr) {
                if (! $expr instanceof PropertyEQ) {
                    $name = $fieldNames[$i];
                    $propertyEq = new PropertyEQ(['this' => $name instanceof Expression ? $name->copy() : $name, 'expression' => $expr]);
                    $propertyEq->setType($expr->type());
                    $newExpressions[] = $propertyEq;
                } else {
                    $newExpressions[] = $expr;
                }
            }

            $struct->set('expressions', $newExpressions);
        }

        return $expression;
    }
}
