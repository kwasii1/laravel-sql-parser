<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\Connector;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Explode;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lateral;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Map;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\Ordered;
use LaravelSqlParser\LaravelSqlParser\Expressions\PercentileDisc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Predicate;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Selectable;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Slice;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableFromRows;
use LaravelSqlParser\LaravelSqlParser\Expressions\UDTF;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarMap;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;
use WeakMap;

/**
 * Infers the types of an expression tree (sqlglot optimizer/annotate_types.py TypeAnnotator).
 *
 * The rules per expression type come from the dialect's generated TYPE_TABLES (sqlglot's
 * EXPRESSION_METADATA): a type the expression returns, or an annotator method of this class.
 * There's no schema, so table columns are UNKNOWN, as in sqlglot without one; columns of derived
 * tables and CTEs get the type of the projection they select.
 */
class TypeAnnotator
{
    /** EXTRACT / DATE_PART specifiers that return BIGINT instead of INT. */
    private const array BIGINT_EXTRACT_DATE_PARTS = [
        'EPOCH_SECOND' => true,
        'EPOCH_MILLISECOND' => true,
        'EPOCH_MICROSECOND' => true,
        'EPOCH_NANOSECOND' => true,
        'NANOSECOND' => true,
    ];

    private const array DATE_UNITS = ['day' => true, 'week' => true, 'month' => true, 'quarter' => true, 'year' => true, 'year_month' => true];

    /** @var array<string, array<string, mixed>> */
    protected array $metadata;

    /** @var array<string, array<string, true>> */
    protected array $coercesTo;

    /** @var WeakMap<Expression, true> annotated nodes, so each is only visited once */
    private WeakMap $visited;

    /** @var array<int, Expression> NULL-typed nodes, set to the dialect's default once inference is done */
    private array $nullExpressions = [];

    /** @var WeakMap<Expression, array<string, DataType|string>> */
    private WeakMap $setopColumnTypes;

    /** @var array<string, array<string, true>> type group name => set */
    private static array $groups = [];

    /** @var array<string, array<string, true>> */
    private static ?array $defaultCoercesTo = null;

    public function __construct(public readonly Dialect $dialect)
    {
        $tables = $dialect::TYPE_TABLES;
        $this->metadata = $tables::METADATA;
        $this->coercesTo = [];

        foreach ($tables::COERCES_TO as $type => $targets) {
            $this->coercesTo[(string) $type] = array_fill_keys(array_map('strval', array_keys($targets)), true);
        }

        if ($this->coercesTo === []) {
            $this->coercesTo = self::defaultCoercesTo();
        }

        /** @var WeakMap<Expression, true> $visited */
        $visited = new WeakMap;
        $this->visited = $visited;
        /** @var WeakMap<Expression, array<string, DataType|string>> $setopTypes */
        $setopTypes = new WeakMap;
        $this->setopColumnTypes = $setopTypes;
    }

    /**
     * Annotate an expression tree with inferred types (sqlglot annotate_types).
     */
    public function annotate(Expression $expression, bool $annotateScope = true): Expression
    {
        if ($annotateScope) {
            foreach (Scope::traverse($expression) as $scope) {
                $this->annotateScope($scope);
            }
        }

        // This takes care of non-traversable expressions
        $this->annotateExpression($expression);

        // NULL isn't an actual type, it only aids coercion: replace it with the dialect's default
        foreach ($this->nullExpressions as $node) {
            $this->setType($node, (string) $this->dialect->settings['DEFAULT_NULL_TYPE']);
        }

        return $expression;
    }

    // ---------------------------------------------------------------------------------------
    // Core
    // ---------------------------------------------------------------------------------------

    public function setType(Expression $expression, DataType|string|null $targetType): Expression
    {
        $prevType = $expression->type();
        $type = $targetType ?? DType::UNKNOWN;
        $expression->setType($type instanceof DataType ? $type : new DataType(['this' => $type]));
        $this->visited[$expression] = true;
        $id = spl_object_id($expression);

        if ($this->dialect->settings['SUPPORTS_NULL_TYPE'] !== true && self::typeName($expression->type()) === DType::NULL) {
            $this->nullExpressions[$id] = $expression;
        } elseif ($prevType !== null && self::typeName($prevType) === DType::NULL) {
            unset($this->nullExpressions[$id]);
        }

        return $expression;
    }

    protected function annotateScope(Scope $scope): void
    {
        // Iterate through all the expressions of the current scope in post-order, and annotate
        $this->annotateExpression($scope->expression, $scope);
        $this->fixupOrderByAliases($scope);

        if ($this->dialect->settings['QUERY_RESULTS_ARE_STRUCTS'] === true && $scope->expression instanceof Query) {
            $columns = [];

            foreach ($scope->expression->selects() as $select) {
                $type = $select->type();
                $columns[] = new ColumnDef(['this' => new Identifier(['this' => $select->outputName()]), 'kind' => $type?->copy()]);
            }

            foreach ($columns as $columnDef) {
                $kind = $columnDef->args['kind'] ?? null;

                if ($kind instanceof DataType && $kind->isType(DType::UNKNOWN)) {
                    return;
                }
            }

            // Not set as the query's type on purpose: using the query in other contexts, e.g.
            // ARRAY(<query>), mustn't read it as a STRUCT value
            $scope->expression->setMeta('query_type', new DataType(['this' => DType::STRUCT, 'expressions' => $columns, 'nested' => true]));
        }
    }

    protected function annotateExpression(Expression $expression, ?Scope $scope = null): void
    {
        $stack = [[$expression, false]];

        while ($stack !== []) {
            [$expr, $childrenAnnotated] = array_pop($stack);

            if (isset($this->visited[$expr])) {
                continue; // The expression's type has already been inferred
            }

            if (! $childrenAnnotated) {
                $stack[] = [$expr, true];

                foreach ($expr->children() as $child) {
                    $stack[] = [$child, false];
                }

                continue;
            }

            if ($scope !== null && $expr instanceof Column && $expr->text('table') !== '' && ! $expr->isStar()) {
                $this->annotateScopedColumn($expr, $scope);

                continue;
            }

            $spec = $this->metadata[$expr::class] ?? null;

            if (isset($spec['annotator'])) {
                $this->callAnnotator($spec['annotator'], $expr);
            } elseif (isset($spec['returns'])) {
                $this->setType($expr, (string) $spec['returns']);
            } else {
                $this->setType($expr, DType::UNKNOWN);
            }
        }
    }

    private function annotateScopedColumn(Column $column, Scope $scope): void
    {
        $tableName = $column->text('table');
        $source = null;
        $sourceScope = $scope;

        while ($sourceScope !== null && $source === null) {
            $source = $sourceScope->sources[$tableName] ?? null;

            if ($source === null) {
                $sourceScope = $sourceScope->parent;
            }
        }

        if ($source instanceof Table) {
            // No schema: table columns are UNKNOWN
            $this->setType($column, DType::UNKNOWN);
        } elseif ($source instanceof Scope) {
            $columnType = $this->sourceScopeSelects($source)[$column->name()] ?? null;

            if ($columnType !== null) {
                $this->setType($column, $columnType);
            } elseif ($source->expression instanceof Unnest) {
                $this->setType($column, $source->expression->type());
            } else {
                $this->setType($column, DType::UNKNOWN);
            }
        } else {
            $this->setType($column, DType::UNKNOWN);
        }

        $type = $column->type();

        if ($type !== null && ($type->args['nullable'] ?? null) === false) {
            $column->setMeta('nonnull', true);
        }
    }

    /**
     * @param  string|array<int|string, mixed>  $annotator
     */
    private function callAnnotator(string|array $annotator, Expression $expression): void
    {
        if (is_string($annotator)) {
            $this->{$annotator}($expression);

            return;
        }

        $method = (string) $annotator[0];
        unset($annotator[0]);

        $this->{$method}($expression, ...$annotator);
    }

    /**
     * The projection types of a derived table, CTE or table function scope.
     *
     * @return array<string, DataType|string>
     */
    private function sourceScopeSelects(Scope $source): array
    {
        $expression = $source->expression;

        if ($expression instanceof UDTF) {
            $values = [];

            if ($expression instanceof Lateral) {
                $inner = $expression->args['this'] ?? null;

                if ($inner instanceof Explode && ($inner->args['this'] ?? null) instanceof Expression) {
                    $values = [$inner->args['this']];
                }
            } elseif ($expression instanceof Unnest) {
                $values = [$expression];
            } elseif (! $expression instanceof TableFromRows) {
                $first = $expression->argList('expressions')[0] ?? null;
                $values = $first instanceof Expression ? $first->argList('expressions') : [];
            }

            if ($values === []) {
                return [];
            }

            $aliasColumnNames = $expression->aliasColumnNames();

            if ($expression instanceof Unnest) {
                $expType = $expression->type();
            } elseif ($expression instanceof Lateral && ($expression->args['this'] ?? null) instanceof Explode) {
                $expType = $expression->args['this']->type();
            } else {
                $expType = null;
            }

            if ($expType !== null && $expType->isType(DType::STRUCT)) {
                $types = [];

                foreach ($expType->argList('expressions') as $columnDef) {
                    $kind = $columnDef->args['kind'] ?? null;

                    if ($columnDef instanceof ColumnDef && $kind instanceof DataType) {
                        $types[$columnDef->name()] = $kind;
                    }
                }

                return $types;
            }

            $types = [];

            foreach ($aliasColumnNames as $i => $alias) {
                if (isset($values[$i])) {
                    $types[$alias] = $values[$i]->type() ?? DType::UNKNOWN;
                }
            }

            return $types;
        }

        if ($expression instanceof SetOperation) {
            $left = $expression->args['this'] ?? null;
            $right = $expression->args['expression'] ?? null;

            if ($expression->flag('by_name') || ($left instanceof Query && $right instanceof Query && count($left->selects()) === count($right->selects()))) {
                return $this->setopColumnTypes($expression);
            }
        }

        if ($expression instanceof Selectable && $expression instanceof Query) {
            $types = [];

            foreach ($expression->selects() as $select) {
                $type = $select->type();

                if ($type !== null) {
                    $types[$select->aliasOrName()] = $type;
                }
            }

            return $types;
        }

        return [];
    }

    private function fixupOrderByAliases(Scope $scope): void
    {
        $query = $scope->expression;

        if (! $query instanceof Query) {
            return;
        }

        $order = $query->args['order'] ?? null;

        if (! $order instanceof Expression) {
            return;
        }

        // alias => type of the fully annotated projections (the last match wins)
        $aliasTypes = [];

        foreach ($query->selects() as $select) {
            $inner = $select->args['this'] ?? null;

            if ($select instanceof Alias && $inner instanceof Expression && $inner->type() !== null && ! $inner->isType(DType::UNKNOWN)) {
                $aliasTypes[$select->alias()] = $inner->type();
            }
        }

        if ($aliasTypes === []) {
            return;
        }

        foreach ($order->argList('expressions') as $ordered) {
            $aliasColumns = [];

            foreach ($ordered->findAll(Column::class) as $column) {
                if ($column->text('table') === '' && isset($aliasTypes[$column->name()])) {
                    $aliasColumns[] = $column;
                    $this->setType($column, $aliasTypes[$column->name()]);
                }
            }

            if ($aliasColumns !== []) {
                foreach ($ordered->walk(prune: static fn (Expression $n): bool => $n instanceof Subquery) as $node) {
                    if (! $node instanceof Column && ! $node instanceof Literal) {
                        unset($this->visited[$node]);
                    }
                }

                $this->annotateExpression($ordered, $scope);
            }
        }
    }

    /**
     * type2 if type1 can be coerced into it, otherwise type1. A parameterized type (e.g.
     * DECIMAL(18, 2)) is assumed not to coerce.
     */
    public function maybeCoerce(DataType|string $type1, DataType|string $type2): DataType|string
    {
        if ($type1 instanceof DataType) {
            if ($type1->argList('expressions') !== [] || ! is_string($type1->args['this'] ?? null)) {
                return $type1;
            }

            $type1 = (string) $type1->args['this'];
        }

        if ($type2 instanceof DataType) {
            if ($type2->argList('expressions') !== [] || ! is_string($type2->args['this'] ?? null)) {
                return $type2;
            }

            $type2 = (string) $type2->args['this'];
        }

        // UNKNOWN propagates upwards
        if ($type1 === DType::UNKNOWN || $type2 === DType::UNKNOWN) {
            return DType::UNKNOWN;
        }

        if ($type1 === DType::NULL) {
            return $type2;
        }

        if ($type2 === DType::NULL) {
            return $type1;
        }

        return isset($this->coercesTo[$type1][$type2]) ? $type2 : $type1;
    }

    /**
     * The coerced projection types of a set operation (UNION, INTERSECT, EXCEPT) chain.
     *
     * @return array<string, DataType|string>
     */
    protected function setopColumnTypes(SetOperation $setop): array
    {
        if (isset($this->setopColumnTypes[$setop])) {
            return $this->setopColumnTypes[$setop];
        }

        $columnTypes = [];
        $left = $setop->args['this'] ?? null;
        $right = $setop->args['expression'] ?? null;

        if (
            ! $left instanceof Query || ! $right instanceof Query
            || $left->selects() === [] || $right->selects() === []
            || (! $setop->flag('by_name') && count($left->selects()) !== count($right->selects()))
        ) {
            return $columnTypes;
        }

        foreach ($setop->walk(prune: static fn (Expression $n): bool => ! $n instanceof SetOperation && ! $n instanceof Subquery) as $setOp) {
            if (! $setOp instanceof SetOperation) {
                continue;
            }

            $l = $setOp->args['this'] ?? null;
            $r = $setOp->args['expression'] ?? null;
            $leftSelects = $l instanceof Query ? $l->selects() : [];
            $rightSelects = $r instanceof Query ? $r->selects() : [];
            $setopColumns = [];

            if ($setOp->flag('by_name')) {
                // Columns missing from one side are filled with NULLs, so the other side's type
                // is preserved (NULL is the identity for maybeCoerce())
                $rightTypes = [];

                foreach ($rightSelects as $select) {
                    $rightTypes[$select->aliasOrName()] = $select->type() ?? DType::UNKNOWN;
                }

                foreach ($leftSelects as $select) {
                    $name = $select->aliasOrName();
                    $setopColumns[$name] = $this->maybeCoerce($select->type() ?? DType::UNKNOWN, $rightTypes[$name] ?? DType::NULL);
                    unset($rightTypes[$name]);
                }

                foreach ($rightTypes as $name => $type) {
                    $setopColumns[$name] = $type;
                }
            } else {
                foreach ($leftSelects as $i => $select) {
                    $other = $rightSelects[$i] ?? null;
                    $setopColumns[$select->aliasOrName()] = $this->maybeCoerce($select->type() ?? DType::UNKNOWN, $other?->type() ?? DType::UNKNOWN);
                }
            }

            // Coerce intermediate results with the previously registered types, if any
            foreach ($setopColumns as $name => $type) {
                $columnTypes[$name] = $this->maybeCoerce($type, $columnTypes[$name] ?? DType::NULL);
            }
        }

        $this->setopColumnTypes[$setop] = $columnTypes;

        return $columnTypes;
    }

    // ---------------------------------------------------------------------------------------
    // Annotators
    // ---------------------------------------------------------------------------------------

    protected function annotateBinary(Expression $expression): Expression
    {
        $left = $expression->args['this'] ?? null;
        $right = $expression->args['expression'] ?? null;

        if (! $left instanceof Expression || ! $right instanceof Expression) {
            $this->setType($expression, null);

            return $expression;
        }

        $leftType = self::typeName($left->type());
        $rightType = self::typeName($right->type());

        if ($expression instanceof Connector || $expression instanceof Predicate) {
            $this->setType($expression, DType::BOOLEAN);
        } elseif (($coerced = $this->binaryCoercion($leftType, $rightType, $left, $right)) !== false) {
            $this->setType($expression, $coerced);
        } else {
            $this->annotateByArgs($expression, args: [$left, $right]);
        }

        if ($expression instanceof Is || ($left->metaGet('nonnull') === true && $right->metaGet('nonnull') === true)) {
            $expression->setMeta('nonnull', true);
        }

        return $expression;
    }

    /**
     * sqlglot TypeAnnotator.BINARY_COERCIONS; false when no rule applies.
     */
    protected function binaryCoercion(string $leftType, string $rightType, Expression $left, Expression $right): DataType|string|null|false
    {
        $text = self::group('TEXT_TYPES');
        $numeric = self::group('NUMERIC_TYPES');

        if (isset($text[$leftType]) && $rightType === DType::INTERVAL) {
            return self::coerceDateLiteral($left, $right->args['unit'] ?? null);
        }

        if ($leftType === DType::INTERVAL && isset($text[$rightType])) {
            return self::coerceDateLiteral($right, $left->args['unit'] ?? null);
        }

        // text + numeric yields the numeric type, to match most dialects' semantics
        if ((isset($text[$leftType]) && isset($numeric[$rightType])) || (isset($numeric[$leftType]) && isset($text[$rightType]))) {
            return isset($numeric[self::typeName($left->type())]) ? $left->type() : $right->type();
        }

        if ($leftType === DType::DATE && $rightType === DType::INTERVAL) {
            return self::coerceDate($left, $right->args['unit'] ?? null);
        }

        if ($leftType === DType::INTERVAL && $rightType === DType::DATE) {
            return self::coerceDate($right, $left->args['unit'] ?? null);
        }

        return false;
    }

    protected function annotateUnary(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;

        if ($expression instanceof Not) {
            $this->setType($expression, DType::BOOLEAN);
        } else {
            $this->setType($expression, $this_ instanceof Expression ? $this_->type() : null);
        }

        if ($this_ instanceof Expression && $this_->metaGet('nonnull') === true) {
            $expression->setMeta('nonnull', true);
        }

        return $expression;
    }

    protected function annotateLiteral(Expression $expression): Expression
    {
        if ($expression->isString()) {
            $this->setType($expression, DType::VARCHAR);
        } elseif ($expression->isInt()) {
            $this->setType($expression, DType::INT);
        } else {
            $this->setType($expression, DType::DOUBLE);
        }

        $expression->setMeta('nonnull', true);

        return $expression;
    }

    /**
     * Type an expression by coercing the types of the given args (names or expressions).
     *
     * @param  list<string|Expression|null>  $args
     */
    public function annotateByArgs(Expression $expression, array $args = [], bool $promote = false, bool $array = false): Expression
    {
        $literalType = null;
        $nonLiteralType = null;
        $nestedType = null;

        foreach ($args as $arg) {
            $expressions = is_string($arg) ? ($expression->args[$arg] ?? null) : $arg;

            foreach (is_array($expressions) ? $expressions : ($expressions === null ? [] : [$expressions]) as $expr) {
                if (! $expr instanceof Expression) {
                    continue;
                }

                $exprType = $expr->type();

                if ($exprType === null || $exprType->isType(DType::UNKNOWN)) {
                    $this->setType($expression, DType::UNKNOWN);

                    return $expression;
                }

                if ($nestedType !== null) {
                    continue;
                }

                // Stop coercing at the first nested data type found
                if ($exprType->flag('nested')) {
                    $nestedType = $exprType;
                } elseif ($expr instanceof Literal) {
                    $literalType = $this->maybeCoerce($literalType ?? $exprType, $exprType);
                } else {
                    $nonLiteralType = $this->maybeCoerce($nonLiteralType ?? $exprType, $exprType);
                }
            }
        }

        $resultType = null;

        if ($nestedType !== null) {
            $resultType = $nestedType;
        } elseif ($literalType !== null && $nonLiteralType !== null) {
            if ($this->dialect->settings['PRIORITIZE_NON_LITERAL_TYPES'] === true) {
                $literalName = self::typeName($literalType);
                $nonLiteralName = self::typeName($nonLiteralType);
                $integers = self::group('INTEGER_TYPES');
                $reals = self::group('REAL_TYPES');

                if ((isset($integers[$literalName]) && isset($integers[$nonLiteralName])) || (isset($reals[$literalName]) && isset($reals[$nonLiteralName]))) {
                    $resultType = $nonLiteralType;
                }
            }

            $resultType ??= $this->maybeCoerce($nonLiteralType, $literalType);
        } else {
            $resultType = $literalType ?? $nonLiteralType ?? DType::UNKNOWN;
        }

        $this->setType($expression, $resultType);

        if ($promote) {
            $name = self::typeName($resultType);

            if (isset(self::group('INTEGER_TYPES')[$name])) {
                $this->setType($expression, DType::BIGINT);
            } elseif (isset(self::group('FLOAT_TYPES')[$name])) {
                $this->setType($expression, DType::DOUBLE);
            }
        }

        if ($array) {
            $this->setType($expression, new DataType(['this' => DType::ARRAY, 'expressions' => [$expression->type()], 'nested' => true]));
        }

        return $expression;
    }

    protected function annotateTimeunit(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;
        $name = $this_ instanceof Expression ? self::typeName($this_->type()) : DType::UNKNOWN;

        if ($this_ instanceof Expression && isset(self::group('TEXT_TYPES')[$name])) {
            $type = self::coerceDateLiteral($this_, $expression->args['unit'] ?? null);
        } elseif ($this_ instanceof Expression && isset(self::group('TEMPORAL_TYPES')[$name])) {
            $type = self::coerceDate($this_, $expression->args['unit'] ?? null);
        } else {
            $type = DType::UNKNOWN;
        }

        $this->setType($expression, $type);

        return $expression;
    }

    protected function annotateBracket(Expression $expression): Expression
    {
        $bracketArg = $expression->argList('expressions')[0] ?? null;
        $this_ = $expression->args['this'] ?? null;
        $thisType = $this_ instanceof Expression ? $this_->type() : null;

        if ($bracketArg instanceof Slice) {
            $this->setType($expression, $thisType);
        } elseif ($thisType !== null && $thisType->isType(DType::ARRAY)) {
            $this->setType($expression, self::elementType($thisType));
        } elseif (($this_ instanceof Map || $this_ instanceof VarMap) && $bracketArg !== null) {
            $keys = $this_->args['keys'] ?? null;
            $values = $this_->args['values'] ?? null;
            $type = DType::UNKNOWN;

            foreach ($keys instanceof Expression ? $keys->argList('expressions') : [] as $i => $key) {
                if ($key->equals($bracketArg)) {
                    $value = $values instanceof Expression ? ($values->argList('expressions')[$i] ?? null) : null;
                    $type = $value?->type();

                    break;
                }
            }

            $this->setType($expression, $type);
        } else {
            $this->setType($expression, DType::UNKNOWN);
        }

        return $expression;
    }

    protected function annotateDiv(Expression $expression): Expression
    {
        $left = $expression->args['this'] ?? null;
        $right = $expression->args['expression'] ?? null;
        $leftType = $left instanceof Expression ? self::typeName($left->type()) : DType::UNKNOWN;
        $rightType = $right instanceof Expression ? self::typeName($right->type()) : DType::UNKNOWN;
        $integers = self::group('INTEGER_TYPES');

        if ($expression->flag('typed') && isset($integers[$leftType]) && isset($integers[$rightType])) {
            $this->setType($expression, DType::BIGINT);
        } else {
            $this->setType($expression, $this->maybeCoerce($leftType, $rightType));

            if (! isset(self::group('REAL_TYPES')[self::typeName($expression->type())])) {
                $this->setType($expression, $this->maybeCoerce($expression->type() ?? DType::UNKNOWN, DType::DOUBLE));
            }
        }

        return $expression;
    }

    protected function annotateDot(Expression $expression): Expression
    {
        $this->setType($expression, null);
        $inner = $expression->args['expression'] ?? null;

        // Propagate the type of qualified UDF calls, e.g. db.my_udf(...)
        if ($inner instanceof Anonymous) {
            $this->setType($expression, $inner->type());

            return $expression;
        }

        $this_ = $expression->args['this'] ?? null;
        $thisType = $this_ instanceof Expression ? $this_->type() : null;

        if ($thisType !== null && $thisType->isType(DType::STRUCT) && $inner instanceof Expression) {
            foreach ($thisType->argList('expressions') as $field) {
                if ($field->name() === $inner->name()) {
                    $kind = $field->args['kind'] ?? null;
                    $this->setType($expression, $kind instanceof DataType ? $kind : null);

                    break;
                }
            }
        }

        return $expression;
    }

    protected function annotateExplode(Expression $expression): Expression
    {
        $this_ = $expression->args['this'] ?? null;
        $inputType = $this_ instanceof Expression ? $this_->type() : null;

        $this->setType($expression, $inputType !== null && $inputType->isType(DType::ARRAY) ? self::elementType($inputType) : null);

        return $expression;
    }

    protected function annotateUnnest(Expression $expression): Expression
    {
        $child = $expression->argList('expressions')[0] ?? null;
        $childType = $child?->type();

        $this->setType($expression, $childType !== null && $childType->isType(DType::ARRAY) ? self::elementType($childType) : null);

        return $expression;
    }

    protected function annotateSubquery(Expression $expression): Expression
    {
        // A scalar subquery (one projection) has its projection's type, e.g. SELECT (SELECT 1 AS c)
        $query = $expression->unnest();

        if ($query instanceof Query) {
            $selects = $query->selects();

            if (count($selects) === 1) {
                $this->setType($expression, $selects[0]->type());

                return $expression;
            }
        }

        $this->setType($expression, DType::UNKNOWN);

        return $expression;
    }

    private function annotateStructValue(Expression $expression): DataType|ColumnDef|null
    {
        $this_ = null;
        $kind = $expression->type();
        $alias = $expression->args['alias'] ?? null;
        $value = $expression->args['expression'] ?? null;

        if ($alias instanceof Expression) {
            // STRUCT(key AS value)
            $this_ = $alias->copy();
        } elseif ($value instanceof Expression) {
            // STRUCT(key = value) or STRUCT(key := value)
            $key = $expression->args['this'] ?? null;
            $this_ = $key instanceof Expression ? $key->copy() : null;
            $kind = $value->type();
        } elseif ($expression instanceof Column && ($expression->args['this'] ?? null) instanceof Expression) {
            // STRUCT(c)
            $this_ = $expression->args['this']->copy();
        }

        if ($kind !== null && $kind->isType(DType::UNKNOWN)) {
            return null;
        }

        if ($this_ !== null) {
            return new ColumnDef(['this' => $this_, 'kind' => $kind]);
        }

        return $kind;
    }

    protected function annotateStruct(Expression $expression): Expression
    {
        $fields = [];

        foreach ($expression->argList('expressions') as $expr) {
            $fieldType = $this->annotateStructValue($expr);

            if ($fieldType === null) {
                $this->setType($expression, null);

                return $expression;
            }

            $fields[] = $fieldType;
        }

        $this->setType($expression, new DataType(['this' => DType::STRUCT, 'expressions' => $fields, 'nested' => true]));

        return $expression;
    }

    protected function annotateMap(Expression $expression): Expression
    {
        $keys = $expression->args['keys'] ?? null;
        $values = $expression->args['values'] ?? null;
        $mapType = new DataType(['this' => DType::MAP]);

        if ($keys instanceof ArrayExpr && $values instanceof ArrayExpr) {
            $keyType = $keys->type()?->argList('expressions')[0] ?? null;
            $valueType = $values->type()?->argList('expressions')[0] ?? null;

            if ($keyType instanceof DataType && $valueType instanceof DataType && ! $keyType->isType(DType::UNKNOWN) && ! $valueType->isType(DType::UNKNOWN)) {
                $mapType->set('expressions', [$keyType, $valueType]);
                $mapType->set('nested', true);
            }
        }

        $this->setType($expression, $mapType);

        return $expression;
    }

    protected function annotateToMap(Expression $expression): Expression
    {
        $mapType = new DataType(['this' => DType::MAP]);
        $arg = $expression->args['this'] ?? null;
        $argType = $arg instanceof Expression ? $arg->type() : null;

        if ($argType !== null && $argType->isType(DType::STRUCT)) {
            foreach ($argType->argList('expressions') as $columnDef) {
                $kind = $columnDef->args['kind'] ?? null;

                if ($kind instanceof DataType && ! $kind->isType(DType::UNKNOWN)) {
                    $mapType->set('expressions', [new DataType(['this' => DType::VARCHAR]), $kind]);
                    $mapType->set('nested', true);

                    break;
                }
            }
        }

        $this->setType($expression, $mapType);

        return $expression;
    }

    protected function annotateExtract(Expression $expression): Expression
    {
        $part = $expression->name();

        $this->setType($expression, match (true) {
            $part === 'TIME' => DType::TIME,
            $part === 'DATE' => DType::DATE,
            isset(self::BIGINT_EXTRACT_DATE_PARTS[$part]) => DType::BIGINT,
            default => DType::INT,
        });

        return $expression;
    }

    protected function annotateWithinGroup(Expression $expression): Expression
    {
        if (($expression->args['this'] ?? null) instanceof PercentileDisc) {
            $order = $expression->args['expression'] ?? null;
            $first = $order instanceof Expression ? ($order->argList('expressions')[0] ?? null) : null;
            $sorted = $first instanceof Ordered ? ($first->args['this'] ?? null) : null;

            $this->setType($expression, $first !== null && $sorted instanceof Expression ? $sorted->type() : DType::UNKNOWN);

            return $expression;
        }

        return $this->annotateByArgs($expression, ['this']);
    }

    protected function annotateByArrayElement(Expression $expression): Expression
    {
        $array = $expression->args['this'] ?? null;
        $arrayType = $array instanceof Expression ? $array->type() : null;

        if ($arrayType !== null && $arrayType->isType(DType::ARRAY)) {
            $this->setType($expression, self::elementType($arrayType) ?? DType::UNKNOWN);
        } else {
            $this->setType($expression, DType::UNKNOWN);
        }

        return $expression;
    }

    // ---------------------------------------------------------------------------------------
    // Base EXPRESSION_METADATA lambdas
    // ---------------------------------------------------------------------------------------

    protected function setTypeFrom(Expression $expression, string $type, ?string $dialect = null): Expression
    {
        return $this->setType($expression, DataType::build($type, dialect: $dialect));
    }

    protected function setTypeFromArg(Expression $expression, string $arg): Expression
    {
        $value = $expression->args[$arg] ?? null;

        return $this->setType($expression, $value instanceof DataType ? $value : null);
    }

    protected function annotateNothing(Expression $expression): Expression
    {
        return $expression;
    }

    protected function annotateAnonymous(Expression $expression): Expression
    {
        // No schema, so user-defined functions are UNKNOWN
        return $this->setType($expression, DType::UNKNOWN);
    }

    protected function annotateCase(Expression $expression): Expression
    {
        $args = [];

        foreach ($expression->argList('ifs') as $if) {
            $args[] = $if->args['true'] ?? null;
        }

        $args[] = 'default';

        return $this->annotateByArgs($expression, $args);
    }

    protected function annotateCount(Expression $expression): Expression
    {
        return $this->setType($expression, $expression->flag('big_int') ? DType::BIGINT : DType::INT);
    }

    protected function annotateDataType(Expression $expression): Expression
    {
        return $expression;
    }

    protected function annotateDateDiff(Expression $expression): Expression
    {
        return $this->setType($expression, $expression->flag('big_int') ? DType::BIGINT : DType::INT);
    }

    protected function annotateHexString(Expression $expression): Expression
    {
        return $this->setType($expression, $expression->flag('is_integer') ? DType::BIGINT : DType::BINARY);
    }

    protected function annotateTimestamp(Expression $expression): Expression
    {
        return $this->setType($expression, $expression->flag('with_tz') ? DType::TIMESTAMPTZ : DType::TIMESTAMP);
    }

    // ---------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------

    /**
     * The element type of a nested type such as ARRAY<INT>.
     */
    public static function elementType(?DataType $type): ?DataType
    {
        $element = $type?->argList('expressions')[0] ?? null;

        return $element instanceof DataType ? $element : null;
    }

    /**
     * The type name (DType) of a type, UNKNOWN for none.
     */
    public static function typeName(DataType|string|null $type): string
    {
        if ($type instanceof DataType) {
            $name = $type->args['this'] ?? null;

            return is_string($name) ? $name : DType::USERDEFINED;
        }

        return $type ?? DType::UNKNOWN;
    }

    /**
     * A DataType type group (TEXT_TYPES, NUMERIC_TYPES, ...) as a set of type names.
     *
     * @return array<string, true>
     */
    public static function group(string $name): array
    {
        if (! isset(self::$groups[$name])) {
            $types = constant(DataType::class.'::'.$name);
            self::$groups[$name] = is_array($types) ? array_fill_keys(array_map('strval', array_is_list($types) ? $types : array_keys($types)), true) : [];
        }

        return self::$groups[$name];
    }

    private static function coerceDateLiteral(Expression $literal, mixed $unit): string
    {
        $text = $literal->name();
        $isIsoDate = self::isIsoDate($text);

        if ($isIsoDate && self::isDateUnit($unit)) {
            return DType::DATE;
        }

        // An ISO date is also an ISO datetime, but not vice versa
        if ($isIsoDate || self::isIsoDatetime($text)) {
            return DType::DATETIME;
        }

        return DType::UNKNOWN;
    }

    private static function coerceDate(Expression $left, mixed $unit): DataType|string
    {
        if ($left->isType(DType::DATE) && ! self::isDateUnit($unit)) {
            return DType::DATETIME;
        }

        return $left->type() ?? DType::UNKNOWN;
    }

    private static function isDateUnit(mixed $unit): bool
    {
        return $unit instanceof Expression && isset(self::DATE_UNITS[strtolower($unit->name())]);
    }

    /**
     * Python's date.fromisoformat: YYYY-MM-DD, YYYYMMDD or an ISO week date.
     */
    public static function isIsoDate(string $text): bool
    {
        if (preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/', $text, $m) === 1 && (strlen($text) === 10 || strlen($text) === 8)) {
            return ($text[4] === '-') === (strlen($text) === 10) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
        }

        return preg_match('/^\d{4}-?W(0[1-9]|[1-4]\d|5[0-3])(-?[1-7])?$/', $text) === 1;
    }

    /**
     * Python's datetime.fromisoformat: an ISO date, optionally followed by a time and an offset.
     */
    public static function isIsoDatetime(string $text): bool
    {
        if (self::isIsoDate($text)) {
            return true;
        }

        if (preg_match('/^(.{8,10})[T ](\d{2})(?::?(\d{2})(?::?(\d{2})(?:[.,]\d{1,9})?)?)?(Z|[+-]\d{2}(?::?\d{2}(?::?\d{2}(?:\.\d{1,6})?)?)?)?$/i', $text, $m) !== 1) {
            return false;
        }

        return self::isIsoDate($m[1]) && (int) $m[2] < 24 && (int) ($m[3] ?? 0) < 60 && (int) ($m[4] ?? 0) < 60;
    }

    /**
     * sqlglot _COERCES_TO: each type coerces into the types above it in its precedence chain.
     *
     * @return array<string, array<string, true>>
     */
    private static function defaultCoercesTo(): array
    {
        if (self::$defaultCoercesTo !== null) {
            return self::$defaultCoercesTo;
        }

        $result = [];

        foreach ([
            [DType::TEXT, DType::NVARCHAR, DType::VARCHAR, DType::NCHAR, DType::CHAR],
            [DType::DECFLOAT, DType::DOUBLE, DType::FLOAT, DType::BIGDECIMAL, DType::DECIMAL, DType::BIGINT, DType::INT, DType::SMALLINT, DType::TINYINT],
            [DType::TIMESTAMPLTZ, DType::TIMESTAMPTZ, DType::TIMESTAMP, DType::DATETIME, DType::DATE],
        ] as $precedence) {
            $coercesTo = [];

            foreach ($precedence as $type) {
                $result[$type] = $coercesTo;
                $coercesTo[$type] = true;
            }
        }

        return self::$defaultCoercesTo = $result;
    }
}
