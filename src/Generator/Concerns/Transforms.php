<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Generator\Concerns;

use InvalidArgumentException;
use Kwasii\LaravelSqlParser\Errors\UnsupportedError;
use Kwasii\LaravelSqlParser\Expressions\Alias;
use Kwasii\LaravelSqlParser\Expressions\Aliases;
use Kwasii\LaravelSqlParser\Expressions\Anonymous;
use Kwasii\LaravelSqlParser\Expressions\Any;
use Kwasii\LaravelSqlParser\Expressions\ApproxQuantile;
use Kwasii\LaravelSqlParser\Expressions\ArrayExpr;
use Kwasii\LaravelSqlParser\Expressions\ArraySize;
use Kwasii\LaravelSqlParser\Expressions\Binary;
use Kwasii\LaravelSqlParser\Expressions\Bracket;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\CaseExpr;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\ColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\ColumnDef;
use Kwasii\LaravelSqlParser\Expressions\Connector;
use Kwasii\LaravelSqlParser\Expressions\Constraint;
use Kwasii\LaravelSqlParser\Expressions\Create;
use Kwasii\LaravelSqlParser\Expressions\CTE;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DataTypeParam;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Exists;
use Kwasii\LaravelSqlParser\Expressions\Explode;
use Kwasii\LaravelSqlParser\Expressions\ExplodeOuter;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\Func;
use Kwasii\LaravelSqlParser\Expressions\GenerateDateArray;
use Kwasii\LaravelSqlParser\Expressions\GenerateSeries;
use Kwasii\LaravelSqlParser\Expressions\Greatest;
use Kwasii\LaravelSqlParser\Expressions\GT;
use Kwasii\LaravelSqlParser\Expressions\Having;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\IfExpr;
use Kwasii\LaravelSqlParser\Expressions\ILike;
use Kwasii\LaravelSqlParser\Expressions\Inline;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\Join;
use Kwasii\LaravelSqlParser\Expressions\Lambda;
use Kwasii\LaravelSqlParser\Expressions\Lateral;
use Kwasii\LaravelSqlParser\Expressions\Like;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\LTE;
use Kwasii\LaravelSqlParser\Expressions\NEQ;
use Kwasii\LaravelSqlParser\Expressions\Not;
use Kwasii\LaravelSqlParser\Expressions\NullExpr;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\Ordered;
use Kwasii\LaravelSqlParser\Expressions\PartitionedByProperty;
use Kwasii\LaravelSqlParser\Expressions\PercentileCont;
use Kwasii\LaravelSqlParser\Expressions\PercentileDisc;
use Kwasii\LaravelSqlParser\Expressions\Pivot;
use Kwasii\LaravelSqlParser\Expressions\Posexplode;
use Kwasii\LaravelSqlParser\Expressions\PropertyEQ;
use Kwasii\LaravelSqlParser\Expressions\Qualify;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\RowNumber;
use Kwasii\LaravelSqlParser\Expressions\Schema;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\SetOperation;
use Kwasii\LaravelSqlParser\Expressions\Star;
use Kwasii\LaravelSqlParser\Expressions\Struct;
use Kwasii\LaravelSqlParser\Expressions\Sub;
use Kwasii\LaravelSqlParser\Expressions\SubqueryPredicate;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\TableAlias;
use Kwasii\LaravelSqlParser\Expressions\TemporaryProperty;
use Kwasii\LaravelSqlParser\Expressions\Tuple;
use Kwasii\LaravelSqlParser\Expressions\Union;
use Kwasii\LaravelSqlParser\Expressions\UniqueColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Unnest;
use Kwasii\LaravelSqlParser\Expressions\Where;
use Kwasii\LaravelSqlParser\Expressions\Window;
use Kwasii\LaravelSqlParser\Expressions\With;
use Kwasii\LaravelSqlParser\Expressions\WithinGroup;
use Kwasii\LaravelSqlParser\Support\Scope;
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
     * Convert EXPLODE / POSEXPLODE projections into unnests (sqlglot transforms
     * explode_projection_to_unnest): the exploded arrays are cross joined with a position
     * series, and each projection picks the element whose position matches.
     */
    public function explodeProjectionToUnnest(Expression $expression, int $indexOffset = 0, bool $unnestMap = false): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $takenSelectNames = array_fill_keys($expression->namedSelects(), true);
        $takenSourceNames = [];

        foreach ((new Scope($expression))->references() as [$name]) {
            $takenSourceNames[$name] = true;
        }

        $newName = static function (array &$names, string $name): string {
            $name = self::findNewName(array_map('strval', array_keys($names)), $name);
            $names[$name] = true;

            return $name;
        };

        $arrays = [];
        $seriesAlias = $newName($takenSelectNames, 'pos');
        $series = Builder::alias(
            new Unnest(['expressions' => [new GenerateSeries(['start' => Literal::number($indexOffset)])]]),
            $newName($takenSourceNames, '_u'),
            table: [$seriesAlias],
        );
        $seriesTableAlias = $series->args['alias']->args['this'];
        $seriesColumn = static fn (): Expression => Builder::column($seriesAlias, $seriesTableAlias);

        // A copy of the list, since the projections are replaced inside the loop
        foreach ($expression->selects() as $select) {
            $explode = $select->find(Explode::class);

            if ($explode === null) {
                continue;
            }

            if (
                $unnestMap
                && $explode::class === Explode::class
                && ($explode->args['this'] ?? null) instanceof Expression
                && $explode->args['this']->isType(DType::MAP)
                && ($select === $explode || $select instanceof Aliases)
            ) {
                if ($select instanceof Aliases) {
                    [$mapKeyAlias, $mapValueAlias] = array_map(static fn (Expression $a): string => $a->name(), $select->argList('expressions'));
                } else {
                    $mapKeyAlias = $newName($takenSelectNames, 'key');
                    $mapValueAlias = $newName($takenSelectNames, 'value');
                }

                $mapUnnestSource = $newName($takenSourceNames, '_u');
                $mapKeySelect = $select->replace(Builder::alias(Builder::column($mapKeyAlias, $mapUnnestSource), $mapKeyAlias));
                $expressions = $expression->argList('expressions');
                $position = array_search($mapKeySelect, $expressions, true);
                array_splice($expressions, $position === false ? count($expressions) : $position + 1, 0, [Builder::alias(Builder::column($mapValueAlias, $mapUnnestSource), $mapValueAlias)]);
                $expression->set('expressions', $expressions);

                $unnest = Builder::alias(new Unnest(['expressions' => [$explode->args['this']->copy()]]), $mapUnnestSource, table: [$mapKeyAlias, $mapValueAlias]);
                self::joinOrFrom($expression, $unnest);

                continue;
            }

            $posAlias = '';
            $explodeAlias = '';

            if ($select instanceof Alias) {
                $explodeAlias = $select->args['alias'] ?? '';
                $alias = $select;
            } elseif ($select instanceof Aliases) {
                [$posAlias, $explodeAlias] = $select->argList('expressions');
                $alias = $select->replace(Builder::alias($select->args['this'], '', copy: false));
            } else {
                $alias = $select->replace(Builder::alias($select, ''));
                $explode = $alias->find(Explode::class);
            }

            if ($explode === null) {
                continue;
            }

            $isPosexplode = $explode instanceof Posexplode;
            $explodeArg = $explode->args['this'] ?? null;

            if (! $explodeArg instanceof Expression) {
                continue;
            }

            if ($explode instanceof ExplodeOuter) {
                $bracket = new Bracket(['this' => $explodeArg->copy(), 'expressions' => [Literal::number(0)], 'safe' => true, 'offset' => true]);
                $explodeArg = Builder::func('IF', [
                    new EQ(['this' => Builder::func('ARRAY_SIZE', [Builder::func('COALESCE', [$explodeArg, new ArrayExpr(['expressions' => []])])]), 'expression' => Literal::number(0)]),
                    new ArrayExpr(['expressions' => [$bracket]]),
                    $explodeArg,
                ]);
            }

            // Don't use [POS]EXPLODE's argument as a new projection name
            if ($explodeArg instanceof Column) {
                $takenSelectNames[$explodeArg->outputName()] = true;
            }

            $unnestSourceAlias = $newName($takenSourceNames, '_u');

            if (self::aliasName($explodeAlias) === '') {
                $explodeAlias = $newName($takenSelectNames, 'col');

                if ($isPosexplode) {
                    $posAlias = $newName($takenSelectNames, 'pos');
                }
            }

            if (self::aliasName($posAlias) === '') {
                $posAlias = $newName($takenSelectNames, 'pos');
            }

            $alias->set('alias', Builder::toIdentifier($explodeAlias instanceof Identifier ? $explodeAlias : self::aliasRef($explodeAlias)));

            $posMatches = static fn (): Expression => Builder::binop(EQ::class, $seriesColumn(), Builder::column(self::aliasRef($posAlias), $unnestSourceAlias));
            $explode->replace(new IfExpr(['this' => $posMatches(), 'true' => Builder::column(self::aliasRef($explodeAlias), $unnestSourceAlias)]));

            if ($isPosexplode) {
                $expressions = $expression->argList('expressions');
                $position = array_search($alias, $expressions, true);
                array_splice($expressions, $position === false ? count($expressions) : $position + 1, 0, [Builder::alias(
                    new IfExpr(['this' => $posMatches(), 'true' => Builder::column(self::aliasRef($posAlias), $unnestSourceAlias)]),
                    self::aliasRef($posAlias),
                )]);
                $expression->set('expressions', $expressions);
            }

            if ($arrays === []) {
                self::joinOrFrom($expression, $series);
            }

            $size = new ArraySize(['this' => $explodeArg->copy()]);
            $arrays[] = $size;

            // Trino doesn't support LEFT JOIN UNNEST with ON conditions; if it did this would be
            // much simpler
            self::appendJoin($expression, new Join([
                'this' => Builder::alias(
                    new Unnest(['expressions' => [$explodeArg->copy()], 'offset' => Builder::toIdentifier(self::aliasRef($posAlias))]),
                    $unnestSourceAlias,
                    table: [self::aliasRef($explodeAlias)],
                ),
                'kind' => 'CROSS',
            ]));

            $sizeExpr = $indexOffset !== 1 ? Builder::binop(Sub::class, $size, Literal::number(1)) : $size;

            self::addWhere($expression, Builder::or([
                Builder::binop(EQ::class, $seriesColumn(), Builder::column(self::aliasRef($posAlias), $unnestSourceAlias)),
                Builder::and([
                    Builder::binop(GT::class, $seriesColumn(), $sizeExpr),
                    Builder::binop(EQ::class, Builder::column(self::aliasRef($posAlias), $unnestSourceAlias), $sizeExpr),
                ]),
            ]));
        }

        if ($arrays !== []) {
            $end = new Greatest(['this' => $arrays[0], 'expressions' => array_slice($arrays, 1)]);

            if ($indexOffset !== 1) {
                $end = Builder::binop(Sub::class, $end, Literal::number(1 - $indexOffset));
            }

            $series->argList('expressions')[0]->set('end', $end);
        }

        return $expression;
    }

    /**
     * An alias as sqlglot passes it on: the identifier itself (keeping its quoting) or its name.
     */
    private static function aliasRef(mixed $alias): Identifier|string
    {
        return $alias instanceof Identifier ? $alias->copy() : self::aliasName($alias);
    }

    private static function aliasName(mixed $alias): string
    {
        return $alias instanceof Expression ? $alias->name() : (string) $alias;
    }

    /**
     * Cross join a source onto a query, or make it the FROM if there's none (sqlglot
     * Select.join(join_type="CROSS") / Select.from_).
     */
    private static function joinOrFrom(Select $query, Expression $source): void
    {
        if (($query->args['from_'] ?? null) instanceof From) {
            self::appendJoin($query, new Join(['this' => $source, 'kind' => 'CROSS']));
        } else {
            $query->set('from_', new From(['this' => $source]));
        }
    }

    private static function appendJoin(Select $query, Join $join): void
    {
        $query->append('joins', $join);
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

    /**
     * sqlglot unnest_to_explode: convert (cross join) UNNEST into (LATERAL VIEW) EXPLODE.
     */
    public function unnestToExplode(Expression $expression, bool $unnestUsingArraysZip = true): Expression
    {
        $zipExprs = static function (Expression $unnest, array $exprs, bool $hasMultiExpr) use ($unnestUsingArraysZip): array {
            if ($hasMultiExpr) {
                if (! $unnestUsingArraysZip) {
                    throw new UnsupportedError('Cannot transpile UNNEST with multiple input arrays');
                }

                $zipped = [new Anonymous(['this' => 'ARRAYS_ZIP', 'expressions' => $exprs])];
                $unnest->set('expressions', $zipped);

                return $zipped;
            }

            return $exprs;
        };

        $udtfType = static fn (Expression $unnest, bool $hasMultiExpr): string => $unnest->flag('offset')
            ? Posexplode::class
            : ($hasMultiExpr ? Inline::class : Explode::class);

        $offsetIdentifier = static function (Expression $unnest): ?Expression {
            $offset = $unnest->args['offset'] ?? null;

            if ($offset === null || $offset === false) {
                return null;
            }

            return $offset instanceof Identifier ? $offset : Builder::toIdentifier('pos');
        };

        if (! $expression instanceof Select) {
            return $expression;
        }

        $from = $expression->argExpr('from_');

        if ($from !== null && ($from->args['this'] ?? null) instanceof Unnest) {
            $unnest = $from->args['this'];
            $alias = $unnest->argExpr('alias');
            $exprs = $unnest->argList('expressions');
            $hasMultiExpr = count($exprs) > 1;
            $this_ = $zipExprs($unnest, $exprs, $hasMultiExpr)[0] ?? null;

            $columns = $alias !== null ? $alias->argList('columns') : [];

            if (($offset = $offsetIdentifier($unnest)) !== null) {
                array_unshift($columns, $offset);
            }

            $class = $udtfType($unnest, $hasMultiExpr);
            $unnest->replace(new Table([
                'this' => new $class(['this' => $this_]),
                'alias' => $alias !== null ? new TableAlias(['this' => $alias->args['this'] ?? null, 'columns' => $columns]) : null,
            ]));
        }

        foreach ($expression->argList('joins') as $join) {
            $joinExpr = $join->args['this'] ?? null;
            $isLateral = $joinExpr instanceof Lateral;
            $unnest = $isLateral ? ($joinExpr->args['this'] ?? null) : $joinExpr;

            if (! $unnest instanceof Unnest) {
                continue;
            }

            $alias = $isLateral ? $joinExpr->argExpr('alias') : $unnest->argExpr('alias');

            if ($alias === null) {
                throw new UnsupportedError('CROSS JOIN UNNEST to LATERAL VIEW EXPLODE transformation requires an alias');
            }

            $exprs = $unnest->argList('expressions');
            $hasMultiExpr = count($exprs) > 1;
            $exprs = $zipExprs($unnest, $exprs, $hasMultiExpr);

            $join->pop();

            $aliasCols = $alias->argList('columns');

            if (! $hasMultiExpr && ! in_array(count($aliasCols), [1, 2], true)) {
                throw new UnsupportedError('CROSS JOIN UNNEST to LATERAL VIEW EXPLODE transformation requires explicit column aliases');
            }

            if (($offset = $offsetIdentifier($unnest)) !== null) {
                array_unshift($aliasCols, $offset);
            }

            $class = $udtfType($unnest, $hasMultiExpr);

            foreach (array_values($exprs) as $i => $e) {
                if (! isset($aliasCols[$i])) {
                    break;
                }

                $expression->append('laterals', new Lateral([
                    'this' => new $class(['this' => $e]),
                    'view' => true,
                    'alias' => new TableAlias([
                        'this' => $i === 0 ? ($alias->args['this'] ?? null) : $alias->argExpr('this')?->copy(),
                        'columns' => $i === 0 ? $aliasCols : array_map(static fn (Expression $c): Expression => $c->copy(), $aliasCols),
                    ]),
                ]));
            }
        }

        return $expression;
    }

    /**
     * sqlglot move_schema_columns_to_partitioned_by.
     */
    public function moveSchemaColumnsToPartitionedBy(Expression $expression): Expression
    {
        $schema = $expression->args['this'] ?? null;
        $isPartitionable = in_array($expression->text('kind'), ['TABLE', 'VIEW'], true);

        if ($schema instanceof Schema && $isPartitionable) {
            $prop = $expression->find(PartitionedByProperty::class);
            $propThis = $prop?->argExpr('this');

            if ($prop !== null && $propThis !== null && ! $propThis instanceof Schema) {
                $columns = [];

                foreach ($propThis->argList('expressions') as $v) {
                    $columns[strtoupper($v->name())] = true;
                }

                $partitions = [];
                $rest = [];

                foreach ($schema->argList('expressions') as $col) {
                    if (isset($columns[strtoupper($col->name())])) {
                        $partitions[] = $col;
                    } else {
                        $rest[] = $col;
                    }
                }

                $schema->set('expressions', $rest);
                $prop->replace(new PartitionedByProperty(['this' => new Schema(['expressions' => $partitions])]));
                $expression->set('this', $schema);
            }
        }

        return $expression;
    }

    /**
     * sqlglot move_partitioned_by_to_schema_columns.
     */
    public function movePartitionedByToSchemaColumns(Expression $expression): Expression
    {
        $prop = $expression->find(PartitionedByProperty::class);
        $propThis = $prop?->argExpr('this');

        if ($prop === null || ! $propThis instanceof Schema) {
            return $expression;
        }

        foreach ($propThis->argList('expressions') as $e) {
            if (! $e instanceof ColumnDef || ! $e->flag('kind')) {
                return $expression;
            }
        }

        $defs = $propThis->argList('expressions');
        $identifiers = [];

        foreach ($defs as $e) {
            $id = $e->args['this'] ?? null;
            $identifiers[] = $id instanceof Identifier ? $id->copy() : Builder::toIdentifier(is_string($id) ? $id : null);
        }

        $schema = $expression->args['this'] ?? null;

        if ($schema instanceof Expression) {
            foreach ($defs as $e) {
                $schema->append('expressions', $e);
            }
        }

        $prop->set('this', new Tuple(['expressions' => $identifiers]));

        return $expression;
    }
}
