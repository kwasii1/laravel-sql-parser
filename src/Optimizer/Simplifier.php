<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use Closure;
use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\All;
use LaravelSqlParser\LaravelSqlParser\Expressions\AndExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Any;
use LaravelSqlParser\LaravelSqlParser\Expressions\Between;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\CaseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\Coalesce;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Concat;
use LaravelSqlParser\LaravelSqlParser\Expressions\ConcatWs;
use LaravelSqlParser\LaravelSqlParser\Expressions\Condition;
use LaravelSqlParser\LaravelSqlParser\Expressions\Connector;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateSub;
use LaravelSqlParser\LaravelSqlParser\Expressions\DatetimeAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DatetimeSub;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\DPipe;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Filter;
use LaravelSqlParser\LaravelSqlParser\Expressions\Func;
use LaravelSqlParser\LaravelSqlParser\Expressions\GT;
use LaravelSqlParser\LaravelSqlParser\Expressions\GTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Hint;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\Interval;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lambda;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\LT;
use LaravelSqlParser\LaravelSqlParser\Expressions\LTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\Neg;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullSafeEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullSafeNEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\OrExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Paren;
use LaravelSqlParser\LaravelSqlParser\Expressions\Predicate;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\Rand;
use LaravelSqlParser\LaravelSqlParser\Expressions\Randn;
use LaravelSqlParser\LaravelSqlParser\Expressions\StartsWith;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sub;
use LaravelSqlParser\LaravelSqlParser\Expressions\SubqueryPredicate;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Expressions\XorExpr;
use LaravelSqlParser\LaravelSqlParser\Optimizer\Simplify as S;
use LaravelSqlParser\LaravelSqlParser\Optimizer\Simplify\DateValue;
use LaravelSqlParser\LaravelSqlParser\Optimizer\Simplify\UnsupportedUnit;

/**
 * The rewrite rules of sqlglot's optimizer/simplify.py (sqlglot simplify.Simplifier).
 */
class Simplifier
{
    // Value ranges for byte-sized signed/unsigned integers
    public const int TINYINT_MIN = -128;

    public const int TINYINT_MAX = 127;

    public const int UTINYINT_MIN = 0;

    public const int UTINYINT_MAX = 255;

    /** @var array<class-string<Expression>, class-string<Expression>> */
    public const array COMPLEMENT_COMPARISONS = [
        LT::class => GTE::class,
        GT::class => LTE::class,
        LTE::class => GT::class,
        GTE::class => LT::class,
        EQ::class => NEQ::class,
        NEQ::class => EQ::class,
    ];

    /** @var array<class-string<Expression>, class-string<Expression>> */
    public const array COMPLEMENT_SUBQUERY_PREDICATES = [
        All::class => Any::class,
        Any::class => All::class,
    ];

    /** @var list<class-string> */
    public const array COMPARISONS = [LT::class, LTE::class, GT::class, GTE::class, EQ::class, NEQ::class, Is::class];

    /**
     * Operand types that allow a connector pair to combine in flatSimplify: constants and comparisons.
     *
     * @var list<class-string>
     */
    public const array CONNECTOR_COMBINABLE = [Boolean::class, Literal::class, NullExpr::class, LT::class, LTE::class, GT::class, GTE::class, EQ::class, NEQ::class, Is::class];

    /** @var array<class-string<Expression>, class-string<Expression>> */
    public const array INVERSE_COMPARISONS = [
        LT::class => GT::class,
        GT::class => LT::class,
        LTE::class => GTE::class,
        GTE::class => LTE::class,
    ];

    /** @var array<class-string<Expression>, class-string<Expression>> */
    public const array INVERSE_DATE_OPS = [
        DateAdd::class => Sub::class,
        DateSub::class => Add::class,
        DatetimeAdd::class => Sub::class,
        DatetimeSub::class => Add::class,
    ];

    /** @var array<class-string<Expression>, class-string<Expression>> */
    public const array INVERSE_OPS = [
        ...self::INVERSE_DATE_OPS,
        Add::class => Sub::class,
        Sub::class => Add::class,
    ];

    /** @var list<class-string> */
    public const array NULL_OK = [NullSafeEQ::class, NullSafeNEQ::class, PropertyEQ::class];

    /** @var list<class-string> */
    public const array DATETRUNC_COMPARISONS = [In::class, LT::class, GT::class, LTE::class, GTE::class, EQ::class, NEQ::class];

    public readonly Dialect $dialect;

    private TypeAnnotator $annotator;

    public function __construct(string|Dialect|null $dialect = null, public readonly bool $annotateNewExpressions = true)
    {
        $this->dialect = Dialect::get($dialect);
        $class = $this->dialect::TYPE_ANNOTATOR;
        /** @var TypeAnnotator $annotator */
        $annotator = new $class($this->dialect);
        $annotator->overwriteTypes = false;
        $this->annotator = $annotator;
    }

    public function simplify(Expression $expression, bool $constantPropagation = false, bool $coalesceSimplification = false): Expression
    {
        /** @var list<Where> $wheres */
        $wheres = [];
        /** @var list<Join> $joins */
        $joins = [];

        $prune = static fn (Expression $n): bool => $n instanceof Condition || $n->metaGet(S::FINAL) === true;

        foreach ($expression->walk(true, $prune) as $node) {
            if ($node->metaGet(S::FINAL) === true) {
                continue;
            }

            // group by expressions cannot be simplified, for example
            // select x + 1 + 1 FROM y GROUP BY x + 1 + 1
            // the projection must exactly match the group by key
            $group = $node->args['group'] ?? null;

            if ($group instanceof Expression && method_exists($node, 'selects')) {
                $groups = [];
                foreach ($group->expressions() as $g) {
                    if ($g instanceof Expression) {
                        $groups[S::key($g)] = true;
                    }
                }
                $group->setMeta(S::FINAL, true);

                /** @var list<Expression> $selects */
                $selects = $node->selects();

                foreach ($selects as $s) {
                    foreach ($s->walk() as $n) {
                        if (isset($groups[S::key($n)])) {
                            $s->setMeta(S::FINAL, true);

                            break;
                        }
                    }
                }

                $having = $node->args['having'] ?? null;

                if ($having instanceof Expression) {
                    foreach ($having->walk() as $n) {
                        if (isset($groups[S::key($n)])) {
                            $having->setMeta(S::FINAL, true);

                            break;
                        }
                    }
                }
            }

            if ($node instanceof Condition) {
                $simplified = self::whileChanging($node, fn (Expression $e): Expression => $this->simplifyOnce($e, $constantPropagation, $coalesceSimplification));

                if ($node === $expression) {
                    $expression = $simplified;
                }
            } elseif ($node instanceof Where) {
                $wheres[] = $node;
            } elseif ($node instanceof Join) {
                // snowflake match_conditions have very strict ordering rules
                $match = $node->args['match_condition'] ?? null;

                if ($match instanceof Expression) {
                    $match->setMeta(S::FINAL, true);
                }

                $joins[] = $node;
            }
        }

        foreach ($wheres as $where) {
            if (S::alwaysTrue($where->args['this'] ?? null) && ! $where->parent instanceof Filter) {
                $where->pop();
            }
        }

        foreach ($joins as $join) {
            // Only an inner join can become a CROSS JOIN: an outer join keeps the rows of its outer
            // side and pads them with NULLs
            $on = $join->args['on'] ?? null;

            if (
                $on instanceof Expression
                && S::alwaysTrue($on)
                && ($join->args['using'] ?? []) === []
                && $join->method() === ''
                && $join->side() === ''
                && in_array($join->kind(), ['', 'INNER'], true)
            ) {
                $on->pop();
                $join->set('side', null);
                $join->set('kind', 'CROSS');
            }
        }

        return $expression;
    }

    /**
     * Apply a transformation until a fix point is reached (sqlglot helper.while_changing).
     *
     * @param  Closure(Expression): Expression  $func
     */
    public static function whileChanging(Expression $expression, Closure $func): Expression
    {
        while (true) {
            $startHash = $expression->hashKey();
            $expression = $func($expression);

            if ($startHash === $expression->hashKey()) {
                return $expression;
            }
        }
    }

    private static function isSimplifiable(Expression $node): bool
    {
        return $node instanceof Binary || $node instanceof Func || $node instanceof Lambda || $node instanceof Predicate || $node instanceof Unary;
    }

    private function simplifyOnce(Expression $expression, bool $constantPropagation, bool $coalesceSimplification): Expression
    {
        $pre = [$expression];
        /** @var list<array{0: Expression, 1: ?Expression}> $post */
        $post = [];
        $node = $expression;

        while ($pre !== []) {
            $original = array_pop($pre);
            $node = $original;

            if (! self::isSimplifiable($node)) {
                if ($node instanceof Query) {
                    $this->simplify($node, $constantPropagation, $coalesceSimplification);
                }

                continue;
            }

            $parent = $node->parent;
            $root = $node === $expression;

            $node = $this->rewriteBetween($node);
            $node = $this->uniqSort($node, $root);
            $node = $this->absorbAndEliminate($node, $root);
            $node = $this->simplifyConcat($node);
            $node = $this->simplifyConditionals($node);

            if ($constantPropagation) {
                $node = S::propagateConstants($node, $root);
            }

            if ($node !== $original) {
                $original->replace($node);
            }

            foreach (array_reverse($node->children()) as $n) {
                if ($n->metaGet(S::FINAL) !== true) {
                    $pre[] = $n;
                }
            }

            $post[] = [$node, $parent];
        }

        while ($post !== []) {
            [$original, $parent] = array_pop($post);
            $root = $original === $expression;

            // Resets parent, arg_key, index pointers: some of the previous transformations mutate
            // the AST, leading to an inconsistent state. Values are unchanged, so hashes stay valid.
            foreach ($original->args as $k => $v) {
                if ($v === null) {
                    unset($original->args[$k]);
                } elseif ($v instanceof Expression) {
                    $v->parent = $original;
                    $v->argKey = $k;
                    $v->index = null;
                } elseif (is_array($v)) {
                    foreach ($v as $i => $item) {
                        if ($item instanceof Expression) {
                            $item->parent = $original;
                            $item->argKey = $k;
                            $item->index = $i;
                        }
                    }
                }
            }

            // Post-order transformations
            $node = $this->simplifyNot($original);
            $node = S::flatten($node);
            $node = $this->simplifyConnectors($node, $root);
            $node = $this->removeComplements($node, $root);

            if ($coalesceSimplification) {
                $node = $this->simplifyCoalesce($node);
            }

            $node->parent = $parent;

            $node = $this->simplifyLiterals($node, $root);
            $node = $this->simplifyEquality($node);
            $node = S::simplifyParens($node, $this->dialect);
            $node = $this->simplifyDatetrunc($node);
            $node = $this->sortComparison($node);
            $node = $this->simplifyStartswith($node);

            if ($node !== $original) {
                $original->replace($node);
            }
        }

        return $node;
    }

    /**
     * Annotate the types of a rewritten expression (sqlglot annotate_types_on_change): new
     * children get types, and the result keeps the original's type so the schema can't change.
     *
     * @return ($newExpression is null ? null : Expression)
     */
    private function annotateOnChange(Expression $expression, ?Expression $newExpression): ?Expression
    {
        if ($newExpression === null) {
            return $newExpression;
        }

        if ($this->annotateNewExpressions && ! $expression->equals($newExpression)) {
            $this->annotator->clear();

            // We annotate this to ensure new children nodes are also annotated
            $newExpression = $this->annotator->annotate($newExpression, annotateScope: false);

            // Whatever the original expression is transformed into needs to preserve its type,
            // otherwise the simplification could result in a different schema
            $newExpression->setType($expression->type());
        }

        return $newExpression;
    }

    /**
     * Rewrite x BETWEEN y AND z to x >= y AND x <= z, since comparison simplification is only
     * done on lt/lte/gt/gte.
     */
    public function rewriteBetween(Expression $expression): Expression
    {
        $new = $expression;

        if ($expression instanceof Between) {
            $negate = $expression->parent instanceof Not;
            $this_ = $expression->argExpr('this');

            $new = Builder::and([
                new GTE(['this' => $this_?->copy(), 'expression' => $expression->args['low'] ?? null]),
                new LTE(['this' => $this_?->copy(), 'expression' => $expression->args['high'] ?? null]),
            ], copy: false);

            if ($negate) {
                $new = Builder::paren($new, copy: false);
            }
        }

        return $this->annotateOnChange($expression, $new);
    }

    /**
     * De Morgan's law:
     * NOT (x OR y) -> NOT x AND NOT y
     * NOT (x AND y) -> NOT x OR NOT y
     */
    public function simplifyNot(Expression $expression): Expression
    {
        return $this->annotateOnChange($expression, $this->simplifyNotRule($expression));
    }

    private function simplifyNotRule(Expression $expression): Expression
    {
        if (! $expression instanceof Not) {
            return $expression;
        }

        $this_ = $expression->argExpr('this');

        if ($this_ === null) {
            return $expression;
        }

        if (S::isNull($this_)) {
            return S::parenthesizeNestedConnector(Builder::and([Builder::null(), Builder::true()], copy: false), $expression->parent);
        }

        if (isset(self::COMPLEMENT_COMPARISONS[$this_::class])) {
            $right = $this_->argExpr('expression');
            $complementSubqueryPredicate = $right !== null ? (self::COMPLEMENT_SUBQUERY_PREDICATES[$right::class] ?? null) : null;

            if ($right !== null && $complementSubqueryPredicate !== null) {
                $right = new $complementSubqueryPredicate(self::args(['this' => $right->args['this'] ?? null]));
            }

            $class = self::COMPLEMENT_COMPARISONS[$this_::class];

            return Builder::paren(new $class(self::args(['this' => $this_->args['this'] ?? null, 'expression' => $right])), copy: false);
        }

        if ($this_ instanceof Paren) {
            $condition = $this_->unnest();

            if ($condition instanceof AndExpr || $condition instanceof OrExpr) {
                $left = Builder::not($condition->argExpr('this') ?? Builder::null(), copy: false);
                $right = Builder::not($condition->argExpr('expression') ?? Builder::null(), copy: false);
                $combined = $condition instanceof AndExpr
                    ? Builder::or([$left, $right], copy: false)
                    : Builder::and([$left, $right], copy: false);

                return Builder::paren($combined, copy: false);
            }

            if (S::isNull($condition)) {
                return S::parenthesizeNestedConnector(Builder::and([Builder::null(), Builder::true()], copy: false), $expression->parent);
            }
        }

        if (S::alwaysTrue($this_)) {
            return Builder::false();
        }

        if (S::isFalse($this_)) {
            return Builder::true();
        }

        if ($this_ instanceof Not && $this->dialect->settings['SAFE_TO_ELIMINATE_DOUBLE_NEGATION'] === true) {
            $inner = $this_->argExpr('this');

            // double negation: NOT NOT x -> x, if x is BOOLEAN type
            if ($inner !== null && $inner->isType(DType::BOOLEAN)) {
                return $inner;
            }
        }

        return $expression;
    }

    public function simplifyConnectors(Expression $expression, bool $root = true): Expression
    {
        $original = $expression;

        if ($expression instanceof Connector) {
            $originalParent = $expression->parent;
            $expression = $this->flatSimplify($expression, fn (Expression $e, Expression $l, Expression $r): ?Expression => $this->simplifyConnectorPair($e, $l, $r), $root);

            // If we reduced a connector to, e.g., a column (t1 AND ... AND tn -> Tk), then we need
            // to ensure that the resulting type is boolean. We know this is true only for connectors,
            // boolean values and columns that are essentially operands to a connector
            if (! ($expression instanceof Connector || $expression instanceof Boolean) && ! $expression->isType(DType::BOOLEAN)) {
                while (true) {
                    if ($originalParent instanceof Connector) {
                        break;
                    }

                    if (! $originalParent instanceof Paren) {
                        $expression = Builder::and([$expression, Builder::true()], copy: false);

                        break;
                    }

                    $originalParent = $originalParent->parent;
                }
            }
        }

        return $this->annotateOnChange($original, $expression);
    }

    private function simplifyConnectorPair(Expression $expression, Expression $left, Expression $right): ?Expression
    {
        if ($expression instanceof AndExpr) {
            if (S::isFalse($left) || S::isFalse($right)) {
                return Builder::false();
            }

            if (S::isZero($left) || S::isZero($right)) {
                return Builder::false();
            }

            if ((S::isNull($left) && S::isNull($right)) || (S::isNull($left) && S::alwaysTrue($right)) || (S::alwaysTrue($left) && S::isNull($right))) {
                return Builder::null();
            }

            if (S::alwaysTrue($left) && S::alwaysTrue($right)) {
                return Builder::true();
            }

            if (S::alwaysTrue($left)) {
                return $right;
            }

            if (S::alwaysTrue($right)) {
                return $left;
            }

            return $this->simplifyComparison($expression, $left, $right);
        }

        if ($expression instanceof OrExpr) {
            if (S::alwaysTrue($left) || S::alwaysTrue($right)) {
                return Builder::true();
            }

            if ((S::isNull($left) && S::isNull($right)) || (S::isNull($left) && S::alwaysFalse($right)) || (S::alwaysFalse($left) && S::isNull($right))) {
                return Builder::null();
            }

            if (S::isFalse($left)) {
                return $right;
            }

            if (S::isFalse($right)) {
                return $left;
            }

            return $this->simplifyComparison($expression, $left, $right, true);
        }

        return null;
    }

    private static function isComparison(?Expression $expression): bool
    {
        foreach (self::COMPARISONS as $class) {
            if ($expression instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function simplifyComparison(Expression $expression, Expression $left, Expression $right, bool $or = false): ?Expression
    {
        return $this->annotateOnChange($expression, $this->simplifyComparisonRule($expression, $left, $right, $or));
    }

    private function simplifyComparisonRule(Expression $expression, Expression $left, Expression $right, bool $or): ?Expression
    {
        if (! self::isComparison($left) || ! self::isComparison($right)) {
            return null;
        }

        if (($left instanceof Is && $left->flag('negate')) || ($right instanceof Is && $right->flag('negate'))) {
            return null;
        }

        $largs = self::set([$left->args['this'] ?? null, $left->args['expression'] ?? null]);
        $rargs = self::set([$right->args['this'] ?? null, $right->args['expression'] ?? null]);

        $matching = array_intersect_key($largs, $rargs);
        $columns = array_filter($matching, static fn (Expression $m): bool => ! S::isConstant($m) && $m->find(Rand::class, Randn::class) === null);

        if ($matching === [] || $columns === []) {
            return null;
        }

        $l = array_values(array_diff_key($largs, $columns))[0] ?? null;
        $r = array_values(array_diff_key($rargs, $columns))[0] ?? null;

        if ($l === null || $r === null) {
            return $expression;
        }

        if ($l->isNumber() && $r->isNumber()) {
            $lv = S::number($l);
            $rv = S::number($r);
        } elseif ($l->isString() && $r->isString()) {
            $lv = $l->name();
            $rv = $r->name();
        } else {
            $ld = S::extractDate($l);

            if ($ld === null) {
                return null;
            }
            $rd = S::extractDate($r);

            if ($rd === null) {
                return null;
            }
            // python won't compare date and datetime, but many engines will upcast
            $lv = $ld->toDatetime();
            $rv = $rd->toDatetime();
        }

        if ($lv === null || $rv === null) {
            return null;
        }

        $false = $left->metaGet('nonnull') === true && $right->metaGet('nonnull') === true ? Builder::false() : null;

        foreach ([[[$left, $lv], [$right, $rv]], [[$right, $rv], [$left, $lv]]] as [[$a, $av], [$b, $bv]]) {
            $cmp = S::compareValues($av, $bv);

            if ($cmp === null) {
                return null;
            }

            $aLt = $a instanceof LT || $a instanceof LTE;
            $bLt = $b instanceof LT || $b instanceof LTE;
            $aGt = $a instanceof GT || $a instanceof GTE;
            $bGt = $b instanceof GT || $b instanceof GTE;

            if ($aLt && $bLt) {
                return ($or ? $cmp > 0 : $cmp <= 0) ? $left : $right;
            }

            if ($aGt && $bGt) {
                return ($or ? $cmp < 0 : $cmp >= 0) ? $left : $right;
            }

            if (! $or) {
                if ($a instanceof LT && $bGt) {
                    if ($cmp <= 0) {
                        return $false;
                    }
                } elseif ($a instanceof GT && $bLt) {
                    if ($cmp >= 0) {
                        return $false;
                    }
                } elseif ($a instanceof EQ) {
                    if ($b instanceof LT) {
                        return $cmp >= 0 ? $false : $a;
                    }

                    if ($b instanceof LTE) {
                        return $cmp > 0 ? $false : $a;
                    }

                    if ($b instanceof GT) {
                        return $cmp <= 0 ? $false : $a;
                    }

                    if ($b instanceof GTE) {
                        return $cmp < 0 ? $false : $a;
                    }

                    if ($b instanceof NEQ) {
                        return $cmp === 0 ? $false : $a;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Removing complements.
     *
     * A AND NOT A -> FALSE (only for non-NULL A)
     * A OR NOT A -> TRUE (only for non-NULL A)
     */
    public function removeComplements(Expression $expression, bool $root = true): Expression
    {
        $new = $expression;

        if (($expression instanceof AndExpr || $expression instanceof OrExpr) && ($root || ! $expression->sameParent())) {
            $ops = self::set($expression->flatten());

            foreach ($ops as $op) {
                $opThis = $op->argExpr('this');

                if ($op instanceof Not && $opThis !== null && isset($ops[S::key($opThis)]) && $expression->metaGet('nonnull') === true) {
                    $new = $expression instanceof AndExpr ? Builder::false() : Builder::true();

                    break;
                }
            }
        }

        return $this->annotateOnChange($expression, $new);
    }

    /**
     * Uniq and sort a connector.
     *
     * C AND A AND B AND B -> A AND B AND C
     */
    public function uniqSort(Expression $expression, bool $root = true): Expression
    {
        $original = $expression;

        if ($expression instanceof Connector && ($root || ! $expression->sameParent())) {
            $flattened = $expression->flatten();
            $deduped = null;
            /** @var list<array{0: string, 1: Expression}> $arr */
            $arr = [];

            if ($expression instanceof XorExpr) {
                $resultFunc = Builder::xor(...);
                // Do not deduplicate XOR as A XOR A != A if A == True
                foreach ($flattened as $e) {
                    $arr[] = [S::gen($e), $e];
                }
            } else {
                $resultFunc = $expression instanceof AndExpr ? Builder::and(...) : Builder::or(...);
                $deduped = [];
                foreach ($flattened as $e) {
                    $deduped["\0".S::gen($e)] = $e;
                }
                foreach ($deduped as $sql => $e) {
                    $arr[] = [substr($sql, 1), $e];
                }
            }

            // check if the operands are already sorted, if not sort them
            // A AND C AND B -> A AND B AND C
            $sorted = true;

            for ($i = 1, $n = count($arr); $i < $n; $i++) {
                if (strcmp($arr[$i][0], $arr[$i - 1][0]) < 0) {
                    $sorted = false;

                    break;
                }
            }

            if (! $sorted) {
                usort($arr, static fn (array $x, array $y): int => strcmp($x[0], $y[0]));
                $expression = $resultFunc(array_map(static fn (array $pair): Expression => $pair[1], $arr), copy: false);
            } elseif ($deduped !== null && count($deduped) < count($flattened)) {
                // we didn't have to sort but maybe we need to dedup
                $uniqueOperand = $flattened[0];

                $expression = count($deduped) === 1
                    ? Builder::and([$uniqueOperand, Builder::true()], copy: false)
                    : $resultFunc(array_values($deduped), copy: false);
            }
        }

        return $this->annotateOnChange($original, $expression);
    }

    /**
     * absorption:
     *     A AND (A OR B) -> A
     *     A OR (A AND B) -> A
     *     A AND (NOT A OR B) -> A AND B (only for non-NULL A)
     *     A OR (NOT A AND B) -> A OR B (only for non-NULL A)
     * elimination:
     *     (A AND B) OR (A AND NOT B) -> A (only for non-NULL B)
     *     (A OR B) AND (A OR NOT B) -> A (only for non-NULL B)
     */
    public function absorbAndEliminate(Expression $expression, bool $root = true): Expression
    {
        if (($expression instanceof AndExpr || $expression instanceof OrExpr) && ($root || ! $expression->sameParent())) {
            $kind = $expression instanceof AndExpr ? OrExpr::class : AndExpr::class;
            $ops = $expression->flatten();

            // Set of all operands, used to find complements for absorption
            $opSet = [];
            // Sub-operands, used to find subsets for absorption
            /** @var array<string, list<array<string, true>>> $subops */
            $subops = [];
            // Pairs of complements, used for elimination
            /** @var array<string, list<array{0: Expression, 1: Expression}>> $pairs */
            $pairs = [];

            foreach ($ops as $op) {
                $opKey = S::key($op);
                $opSet[$opKey] = true;

                if (! $op instanceof $kind) {
                    // In cases like: A OR (A AND B), the subop is A
                    $subops[$opKey][] = [$opKey => true];

                    continue;
                }

                // In cases like: (A AND B) OR (A AND B AND C), the subops are A and B
                $subset = array_map(static fn (): bool => true, self::set($op->flatten()));
                foreach ($subset as $i => $_) {
                    $subops[$i][] = $subset;
                }

                [$a, $b] = $op->unnestOperands() + [null, null];

                if ($a === null || $b === null) {
                    continue;
                }

                $aThis = $a instanceof Not ? $a->argExpr('this') : null;
                $bThis = $b instanceof Not ? $b->argExpr('this') : null;

                if ($aThis !== null && $aThis->metaGet('nonnull') === true) {
                    $pairs[self::pairKey($aThis, $b)][] = [$op, $b];
                }

                if ($bThis !== null && $bThis->metaGet('nonnull') === true) {
                    $pairs[self::pairKey($a, $bThis)][] = [$op, $a];
                }
            }

            foreach ($ops as $op) {
                if (! $op instanceof $kind) {
                    continue;
                }

                [$a, $b] = $op->unnestOperands() + [null, null];

                if ($a === null || $b === null) {
                    continue;
                }

                // Absorb
                $aThis = $a instanceof Not ? $a->argExpr('this') : null;

                if ($aThis !== null && isset($opSet[S::key($aThis)]) && $aThis->metaGet('nonnull') === true) {
                    $a->replace($kind === AndExpr::class ? Builder::true() : Builder::false());

                    continue;
                }

                $bThis = $b instanceof Not ? $b->argExpr('this') : null;

                if ($bThis !== null && isset($opSet[S::key($bThis)]) && $bThis->metaGet('nonnull') === true) {
                    $b->replace($kind === AndExpr::class ? Builder::true() : Builder::false());

                    continue;
                }

                $superset = array_map(static fn (): bool => true, self::set($op->flatten()));
                $absorbed = false;

                foreach ($superset as $i => $_) {
                    foreach ($subops[$i] ?? [] as $subset) {
                        if (count($subset) < count($superset) && array_diff_key($subset, $superset) === []) {
                            $absorbed = true;

                            break 2;
                        }
                    }
                }

                if ($absorbed) {
                    $op->replace($kind === AndExpr::class ? Builder::false() : Builder::true());

                    continue;
                }

                // Eliminate
                foreach ($pairs[self::pairKey($a, $b)] ?? [] as [$other, $complement]) {
                    $op->replace($complement);
                    $other->replace($complement);
                }
            }
        }

        return $this->annotateOnChange($expression, $expression);
    }

    /**
     * Use the subtraction and addition properties of equality to simplify expressions:
     * x + 1 = 3 becomes x = 2, 5 - x = 2 becomes x = 3, 5 - x < 2 becomes x > 3.
     */
    public function simplifyEquality(Expression $expression): Expression
    {
        try {
            $new = $this->simplifyEqualityRule($expression);
        } catch (UnsupportedUnit) {
            $new = $expression;
        }

        return $this->annotateOnChange($expression, $new);
    }

    private function simplifyEqualityRule(Expression $expression): Expression
    {
        if (! self::isComparison($expression)) {
            return $expression;
        }

        $l = $expression->argExpr('this');
        $r = $expression->argExpr('expression');

        if ($l === null || $r === null || ! isset(self::INVERSE_OPS[$l::class])) {
            return $expression;
        }

        if ($r->isNumber()) {
            $aPredicate = S::isNumber(...);
            $bPredicate = S::isNumber(...);
        } elseif (S::isDateLiteral($r)) {
            $aPredicate = S::isDateLiteral(...);
            $bPredicate = S::isInterval(...);
        } else {
            return $expression;
        }

        if (isset(self::INVERSE_DATE_OPS[$l::class])) {
            $a = $l->argExpr('this');
            $b = self::intervalOf($l);
        } else {
            $a = $l->argExpr('this');
            $b = $l->argExpr('expression');
        }

        if ($a === null || $b === null) {
            return $expression;
        }

        if (! $aPredicate($a) && $bPredicate($b)) {
            // the variable is on the left
        } elseif (! $aPredicate($b) && $bPredicate($a)) {
            if ($l instanceof Sub) {
                $class = self::INVERSE_COMPARISONS[$expression::class] ?? $expression::class;

                return new $class(['this' => $b, 'expression' => new Sub(['this' => $a, 'expression' => $r])]);
            }

            [$a, $b] = [$b, $a];
        } else {
            return $expression;
        }

        if ($b instanceof Interval && ! S::isExactIntervalMove($l, $r, $b)) {
            return $expression;
        }

        $class = $expression::class;
        $inverse = self::INVERSE_OPS[$l::class];

        return new $class(['this' => $a, 'expression' => new $inverse(['this' => $r, 'expression' => $b])]);
    }

    /**
     * The interval of a date operation (sqlglot IntervalOp.interval()).
     */
    private static function intervalOf(Expression $expression): Interval
    {
        return new Interval(self::args([
            'this' => $expression->argExpr('expression')?->copy(),
            'unit' => $expression->argExpr('unit')?->copy(),
        ]));
    }

    public function simplifyLiterals(Expression $expression, bool $root = true): Expression
    {
        $new = $expression;

        if ($expression instanceof Binary && ! $expression instanceof Connector) {
            $new = $this->flatSimplify($expression, fn (Expression $e, Expression $a, Expression $b): ?Expression => $this->simplifyBinary($e, $a, $b), $root);
        } elseif ($expression instanceof Neg && ($inner = $expression->argExpr('this')) instanceof Neg && $inner->argExpr('this') !== null) {
            $new = $inner->argExpr('this');
        } elseif (isset(self::INVERSE_DATE_OPS[$expression::class]) && $expression->argExpr('this') !== null) {
            $new = $this->simplifyBinary($expression, $expression->argExpr('this'), self::intervalOf($expression)) ?? $expression;
        }

        return $this->annotateOnChange($expression, $new);
    }

    private function simplifyIntegerCast(Expression $expr): Expression
    {
        if ($expr instanceof Cast && $expr->args['this'] instanceof Cast) {
            $this_ = $this->simplifyIntegerCast($expr->args['this']);
        } else {
            $this_ = $expr->args['this'] ?? null;
        }

        if ($expr instanceof Cast && $this_ instanceof Expression && $this_->isInt()) {
            $num = S::number($this_);
            $to = $expr->argExpr('to');
            $toType = $to?->args['this'] ?? null;

            // Remove the (up)cast from small (byte-sized) integers in predicates which is side-effect
            // free. Downcasts on any integer type might cause overflow, so they're kept
            if ($num !== null && is_string($toType)) {
                $value = $num->toInt();

                if (
                    $value !== null
                    && (
                        ($value >= self::TINYINT_MIN && $value <= self::TINYINT_MAX && in_array($toType, DataType::SIGNED_INTEGER_TYPES, true))
                        || ($value >= self::UTINYINT_MIN && $value <= self::UTINYINT_MAX && in_array($toType, DataType::UNSIGNED_INTEGER_TYPES, true))
                    )
                ) {
                    return $this_;
                }
            }
        }

        return $expr;
    }

    private function simplifyBinary(Expression $expression, Expression $a, Expression $b): ?Expression
    {
        if (self::isComparison($expression)) {
            $a = $this->simplifyIntegerCast($a);
            $b = $this->simplifyIntegerCast($b);
        }

        if ($expression instanceof Is) {
            if ($b instanceof Not) {
                $c = $b->argExpr('this');
                $not = true;
            } else {
                $c = $b;
                $not = false;
            }

            if ($expression->flag('negate')) {
                $not = ! $not;
            }

            if (S::isNull($c)) {
                if ($a instanceof Literal) {
                    return $not ? Builder::true() : Builder::false();
                }

                if (S::isNull($a)) {
                    return $not ? Builder::false() : Builder::true();
                }
            }
        } elseif ($expression instanceof NullSafeEQ || $expression instanceof NullSafeNEQ || $expression instanceof PropertyEQ) {
            return null;
        } elseif ((S::isNull($a) || S::isNull($b)) && $expression->parent instanceof IfExpr) {
            return Builder::null();
        }

        if ($a->isNumber() && $b->isNumber()) {
            $numA = S::number($a);
            $numB = S::number($b);

            if ($numA === null || $numB === null) {
                return null;
            }

            if ($expression instanceof Add) {
                return Literal::number($numA->add($numB)->toString());
            }

            if ($expression instanceof Mul) {
                return Literal::number($numA->mul($numB)->toString());
            }

            // We only simplify Sub, Div if a and b have the same parent because they're not associative
            if ($expression instanceof Sub) {
                return $a->parent === $b->parent ? Literal::number($numA->sub($numB)->toString()) : null;
            }

            if ($expression instanceof Div) {
                // engines have differing int div behavior so intdiv is not safe
                if (($numA->isInt && $numB->isInt) || $a->parent !== $b->parent) {
                    return null;
                }

                $quotient = $numA->div($numB);

                return $quotient === null ? null : Literal::number($quotient->toString());
            }

            return S::evalBoolean($expression, $numA, $numB);
        }

        if ($a->isString() && $b->isString()) {
            return S::evalBoolean($expression, (string) ($a->args['this'] ?? ''), (string) ($b->args['this'] ?? ''));
        }

        if (S::isDateLiteral($a) && $b instanceof Interval) {
            $date = S::extractDate($a);
            $delta = S::extractInterval($b);

            if ($date !== null && $delta !== null && ! $delta->isZero()) {
                if ($expression instanceof Add || $expression instanceof DateAdd || $expression instanceof DatetimeAdd) {
                    return S::dateLiteral($date->add($delta), S::extractType($a));
                }

                if ($expression instanceof Sub || $expression instanceof DateSub || $expression instanceof DatetimeSub) {
                    return S::dateLiteral($date->sub($delta), S::extractType($a));
                }
            }
        } elseif ($a instanceof Interval && S::isDateLiteral($b)) {
            $delta = S::extractInterval($a);
            $date = S::extractDate($b);

            // you cannot subtract a date from an interval
            if ($delta !== null && ! $delta->isZero() && $date !== null && $expression instanceof Add) {
                return S::dateLiteral($date->add($delta), S::extractType($b));
            }
        } elseif (S::isDateLiteral($a) && S::isDateLiteral($b)) {
            if ($expression instanceof Predicate) {
                $da = S::extractDate($a);
                $db = S::extractDate($b);

                if ($da !== null && $db !== null) {
                    return S::evalBoolean($expression, $da, $db);
                }
            }
        }

        return null;
    }

    public function simplifyCoalesce(Expression $expression): Expression
    {
        return $this->annotateOnChange($expression, $this->simplifyCoalesceRule($expression));
    }

    private function simplifyCoalesceRule(Expression $expression): Expression
    {
        // COALESCE(x) -> x
        if (
            $expression instanceof Coalesce
            && ($expression->expressions() === [] || (($first = $expression->argExpr('this')) !== null && S::isNonnullConstant($first)))
            // COALESCE is also used as a Spark partitioning hint
            && ! $expression->parent instanceof Hint
            && $expression->argExpr('this') !== null
        ) {
            return $expression->argExpr('this');
        }

        if ($this->dialect->settings['COALESCE_COMPARISON_NON_STANDARD'] === true) {
            return $expression;
        }

        if (! self::isComparison($expression)) {
            return $expression;
        }

        $left = $expression->argExpr('this');
        $right = $expression->argExpr('expression');

        if ($left instanceof Coalesce) {
            $coalesce = $left;
            $other = $right;
        } elseif ($right instanceof Coalesce) {
            $coalesce = $right;
            $other = $left;
        } else {
            return $expression;
        }

        // This transformation is valid for non-constants,
        // but it really only does anything if they are both constants.
        if ($other === null || ! S::isConstant($other)) {
            return $expression;
        }

        // Find the first non-NULL constant arg
        $arg = null;
        $argIndex = 0;

        foreach ($coalesce->expressions() as $argIndex => $candidate) {
            if ($candidate instanceof Expression && S::isNonnullConstant($candidate)) {
                $arg = $candidate;

                break;
            }
        }

        if ($arg === null) {
            return $expression;
        }

        $coalesce->set('expressions', array_slice($coalesce->expressions(), 0, $argIndex));

        // Remove the COALESCE function. This is an optimization, skipping a simplify iteration,
        // since we already remove COALESCE at the top of this function.
        $this_ = $coalesce->expressions() !== [] ? $coalesce : ($coalesce->argExpr('this') ?? $coalesce);

        // The constant takes the COALESCE's side of the comparison
        $substituted = $expression->copy();
        $substituted->set($coalesce === $left ? 'this' : 'expression', $arg->copy());

        // This expression is more complex than when we started, but it will get simplified further
        return Builder::paren(
            Builder::or([
                Builder::and([
                    Builder::not(Builder::binop(Is::class, $this_, Builder::null()), copy: false),
                    $expression->copy(),
                ], copy: false),
                Builder::and([Builder::binop(Is::class, $this_, Builder::null()), $substituted], copy: false),
            ], copy: false),
            copy: false,
        );
    }

    /**
     * Reduces all groups that contain string literals by concatenating them.
     */
    public function simplifyConcat(Expression $expression): Expression
    {
        return $this->annotateOnChange($expression, $this->simplifyConcatRule($expression));
    }

    private function simplifyConcatRule(Expression $expression): Expression
    {
        if (! ($expression instanceof Concat || $expression instanceof DPipe)) {
            return $expression;
        }

        $exprs = $expression->expressions();

        // We can't reduce a CONCAT_WS call if we don't statically know the separator
        if ($expression instanceof ConcatWs && ! (($exprs[0] ?? null) instanceof Expression && $exprs[0]->isString())) {
            return $expression;
        }

        $sepExpr = null;

        if ($expression instanceof ConcatWs) {
            $sepExpr = array_shift($exprs);
            $sep = $sepExpr instanceof Expression ? $sepExpr->name() : '';
            $concatType = ConcatWs::class;
        } else {
            $sep = '';
            $concatType = Concat::class;
        }

        $safe = $expression->args['safe'] ?? null;
        $coalesce = $expression->args['coalesce'] ?? null;

        /** @var list<Expression> $operands */
        $operands = array_values(array_filter($exprs !== [] ? $exprs : $expression->flatten(false), static fn (mixed $e): bool => $e instanceof Expression));
        // itertools.groupby by is_string: runs of string literals are joined with the separator
        /** @var list<Expression> $newArgs */
        $newArgs = [];
        $count = count($operands);

        for ($i = 0; $i < $count; $i++) {
            if (! $operands[$i]->isString()) {
                $newArgs[] = $operands[$i];

                continue;
            }

            $strings = [];

            while ($i < $count && $operands[$i]->isString()) {
                $strings[] = $operands[$i]->name();
                $i++;
            }

            $i--;
            $newArgs[] = Literal::string(implode($sep, $strings));
        }

        if (count($newArgs) === 1 && $newArgs[0]->isString()) {
            return $newArgs[0];
        }

        if ($concatType === ConcatWs::class && $sepExpr instanceof Expression) {
            $newArgs = [$sepExpr, ...$newArgs];
        } elseif ($expression instanceof DPipe) {
            $result = array_shift($newArgs);

            if ($result === null) {
                return $expression;
            }

            foreach ($newArgs as $arg) {
                $result = new DPipe(self::args(['this' => $result, 'expression' => $arg, 'safe' => $safe]));
            }

            return $result;
        }

        return new $concatType(self::args(['expressions' => $newArgs, 'safe' => $safe, 'coalesce' => $coalesce]));
    }

    /**
     * Simplifies expressions like IF, CASE if their condition is statically known.
     */
    public function simplifyConditionals(Expression $expression): Expression
    {
        return $this->annotateOnChange($expression, $this->simplifyConditionalsRule($expression));
    }

    private function simplifyConditionalsRule(Expression $expression): Expression
    {
        if ($expression instanceof CaseExpr) {
            $this_ = $expression->argExpr('this');
            $i = 0;

            // Like Python, iterate over the live list: popping a case skips the next one
            while (($case = ($expression->args['ifs'] ?? [])[$i] ?? null) instanceof Expression) {
                $i++;
                $cond = $case->argExpr('this');

                if ($cond === null) {
                    continue;
                }

                if ($this_ !== null) {
                    // Convert CASE x WHEN matching_value ... to CASE WHEN x = matching_value ...
                    $cond = $cond->replace(Builder::binop(EQ::class, $this_->pop(), $cond));
                }

                if (S::alwaysTrue($cond)) {
                    return Builder::paren($case->argExpr('true') ?? Builder::null(), copy: false);
                }

                if (S::alwaysFalse($cond)) {
                    // the live list shifts left while Python's iterator still advances
                    $case->pop();

                    if (($expression->args['ifs'] ?? []) === []) {
                        return Builder::paren($expression->argExpr('default') ?? Builder::null(), copy: false);
                    }
                }
            }
        } elseif ($expression instanceof IfExpr && ! $expression->parent instanceof CaseExpr) {
            if (S::alwaysTrue($expression->args['this'] ?? null)) {
                return Builder::paren($expression->argExpr('true') ?? Builder::null(), copy: false);
            }

            if (S::alwaysFalse($expression->args['this'] ?? null)) {
                return Builder::paren($expression->argExpr('false') ?? Builder::null(), copy: false);
            }
        }

        return $expression;
    }

    /**
     * Reduces a prefix check to either TRUE or FALSE if both the string and the prefix are
     * statically known.
     */
    public function simplifyStartswith(Expression $expression): Expression
    {
        $new = $expression;

        if (
            $expression instanceof StartsWith
            && ($this_ = $expression->argExpr('this')) !== null && $this_->isString()
            && ($prefix = $expression->argExpr('expression')) !== null && $prefix->isString()
        ) {
            $new = Builder::convert(str_starts_with($this_->name(), $prefix->name()));
        }

        return $this->annotateOnChange($expression, $new);
    }

    private static function isDatetrunc(?Expression $expression): bool
    {
        return $expression instanceof DateTrunc || $expression instanceof TimestampTrunc;
    }

    private function isDatetruncPredicate(?Expression $left, ?Expression $right): bool
    {
        return self::isDatetrunc($left) && $right !== null && S::isDateLiteral($right);
    }

    /**
     * Simplify expressions like `DATE_TRUNC('year', x) >= CAST('2021-01-01' AS DATE)`.
     */
    public function simplifyDatetrunc(Expression $expression): Expression
    {
        try {
            $new = $this->simplifyDatetruncRule($expression);
        } catch (UnsupportedUnit) {
            $new = $expression;
        }

        return $this->annotateOnChange($expression, $new);
    }

    private function simplifyDatetruncRule(Expression $expression): Expression
    {
        $comparison = $expression::class;

        if (self::isDatetrunc($expression)) {
            $this_ = $expression->argExpr('this');
            $truncType = $expression->isType(...DataType::TEMPORAL_TYPES) ? $expression->type() : ($this_ !== null ? S::extractType($this_) : null);
            $date = S::extractDate($this_);
            $unit = $expression->argExpr('unit');

            if ($date !== null && $unit !== null) {
                return S::dateLiteral(S::datetimeFloor($date, S::truncUnit($unit, $this->dialect), $this->dialect), $truncType);
            }
        } elseif (! in_array($comparison, self::DATETRUNC_COMPARISONS, true)) {
            return $expression;
        }

        if ($expression instanceof Binary) {
            $l = $expression->argExpr('this');
            $r = $expression->argExpr('expression');

            if ($l === null || $r === null || ! $this->isDatetruncPredicate($l, $r)) {
                return $expression;
            }

            $truncArg = $l->argExpr('this');
            $unitExpr = $l->argExpr('unit');
            $date = S::extractDate($r);

            if ($truncArg === null || $unitExpr === null || $date === null) {
                return $expression;
            }

            $unit = S::truncUnit($unitExpr, $this->dialect);
            $simplified = $this->datetruncBinaryComparison($comparison, $truncArg, $date, $unit, S::extractType($r));

            if ($simplified === null) {
                return $expression;
            }

            return S::parenthesizeNestedConnector($simplified, $expression->parent);
        }

        if ($expression instanceof In) {
            $l = $expression->argExpr('this');
            $rs = array_values(array_filter($expression->expressions(), static fn (mixed $e): bool => $e instanceof Expression));

            if ($rs === [] || $l === null || ! self::isDatetrunc($l)) {
                return $expression;
            }

            foreach ($rs as $r) {
                if (! $this->isDatetruncPredicate($l, $r)) {
                    return $expression;
                }
            }

            $unitExpr = $l->argExpr('unit');

            if ($unitExpr === null) {
                return $expression;
            }

            $unit = S::truncUnit($unitExpr, $this->dialect);
            $ranges = [];

            foreach ($rs as $r) {
                $date = S::extractDate($r);

                if ($date === null) {
                    return $expression;
                }

                $range = S::datetruncRange($date, $unit, $this->dialect);

                if ($range !== null) {
                    $ranges[] = $range;
                }
            }

            if ($ranges === []) {
                return $expression;
            }

            $targetType = S::extractType(...$rs);

            $simplified = Builder::or(
                array_map(static fn (array $range): Expression => S::datetruncEqExpression($l, $range, $targetType), self::mergeRanges($ranges)),
                copy: false,
            );

            return S::parenthesizeNestedConnector($simplified, $expression->parent);
        }

        return $expression;
    }

    private function datetruncBinaryComparison(string $comparison, Expression $l, DateValue $dt, string $u, ?DataType $t): ?Expression
    {
        $d = $this->dialect;

        return match ($comparison) {
            LT::class => Builder::binop(LT::class, $l, S::dateLiteral(
                $dt->equals(S::datetimeFloor($dt, $u, $d)) ? $dt : S::datetimeFloor($dt, $u, $d)->add(S::interval($u)),
                $t,
            )),
            GT::class => Builder::binop(GTE::class, $l, S::dateLiteral(S::datetimeFloor($dt, $u, $d)->add(S::interval($u)), $t)),
            LTE::class => Builder::binop(LT::class, $l, S::dateLiteral(S::datetimeFloor($dt, $u, $d)->add(S::interval($u)), $t)),
            GTE::class => Builder::binop(GTE::class, $l, S::dateLiteral(S::dateCeil($dt, $u, $d), $t)),
            EQ::class => S::datetruncEq($l, $dt, $u, $d, $t),
            NEQ::class => S::datetruncNeq($l, $dt, $u, $d, $t),
            default => null,
        };
    }

    /**
     * Merges ranges (low, high) of a totally-ordered set (sqlglot helper.merge_ranges).
     *
     * @param  list<array{0: DateValue, 1: DateValue}>  $ranges
     * @return list<array{0: DateValue, 1: DateValue}>
     */
    private static function mergeRanges(array $ranges): array
    {
        usort($ranges, static fn (array $x, array $y): int => ($x[0]->compare($y[0]) ?? 0) ?: ($x[1]->compare($y[1]) ?? 0));

        $merged = [array_shift($ranges)];

        foreach ($ranges as [$start, $end]) {
            [$lastStart, $lastEnd] = $merged[count($merged) - 1];

            if (($start->compare($lastEnd) ?? 1) <= 0) {
                $merged[count($merged) - 1] = [$lastStart, ($end->compare($lastEnd) ?? 0) > 0 ? $end : $lastEnd];
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    public function sortComparison(Expression $expression): Expression
    {
        $new = $expression;

        if (isset(self::COMPLEMENT_COMPARISONS[$expression::class])) {
            $l = $expression->argExpr('this');
            $r = $expression->argExpr('expression');

            if ($l !== null && $r !== null) {
                $lColumn = $l instanceof Column;
                $rColumn = $r instanceof Column;
                $lConst = S::isConstant($l);
                $rConst = S::isConstant($r);

                if (($lColumn && ! $rColumn) || ($rConst && ! $lConst) || $r instanceof SubqueryPredicate) {
                    $new = $expression;
                } elseif (($rColumn && ! $lColumn) || ($lConst && ! $rConst) || strcmp(S::gen($l), S::gen($r)) > 0) {
                    $class = self::INVERSE_COMPARISONS[$expression::class] ?? $expression::class;
                    $new = new $class(['this' => $r, 'expression' => $l]);
                }
            }
        }

        return $this->annotateOnChange($expression, $new);
    }

    /**
     * Combine the operands of a chain of the same binary operator pairwise.
     *
     * @param  Closure(Expression, Expression, Expression): ?Expression  $simplifier
     */
    private function flatSimplify(Expression $expression, Closure $simplifier, bool $root = true): Expression
    {
        if (! $root && $expression->sameParent()) {
            return $expression;
        }

        $operands = [];
        $queue = $expression->flatten(false);
        $size = count($queue);

        // The pairwise scan below is O(n^2); a connector pair only combines if one side is a
        // constant or both are comparisons, so skip the scan when no operand is combinable
        if ($expression instanceof Connector) {
            $combinable = false;

            foreach ($queue as $op) {
                foreach (self::CONNECTOR_COMBINABLE as $class) {
                    if ($op instanceof $class) {
                        $combinable = true;

                        break 2;
                    }
                }
            }

            if (! $combinable) {
                return $expression;
            }
        }

        while ($queue !== []) {
            $a = array_shift($queue);
            $combined = false;

            foreach ($queue as $b) {
                $result = $simplifier($expression, $a, $b);

                if ($result !== null && $result !== $expression) {
                    // deque.remove(b) removes the first element equal to b
                    foreach ($queue as $i => $candidate) {
                        if ($candidate->equals($b)) {
                            array_splice($queue, $i, 1);

                            break;
                        }
                    }

                    array_unshift($queue, $result);
                    $combined = true;

                    break;
                }
            }

            if (! $combined) {
                $operands[] = $a;
            }
        }

        if (count($operands) < $size) {
            $class = $expression::class;
            $result = array_shift($operands);

            foreach ($operands as $operand) {
                $result = new $class(['this' => $result, 'expression' => $operand]);
            }

            return $result ?? $expression;
        }

        return $expression;
    }

    /**
     * Expressions as a structural set (a Python set of expressions), keyed by S::key().
     *
     * @param  list<mixed>  $expressions
     * @return array<string, Expression>
     */
    private static function set(array $expressions): array
    {
        $set = [];

        foreach ($expressions as $expression) {
            if ($expression instanceof Expression) {
                $set[S::key($expression)] ??= $expression;
            }
        }

        return $set;
    }

    /**
     * A frozenset((a, b)) key.
     */
    private static function pairKey(Expression $a, Expression $b): string
    {
        $keys = array_unique([S::key($a), S::key($b)]);
        sort($keys);

        return implode('|', $keys);
    }

    /**
     * Constructor args without nulls (Python stores None args, which nothing distinguishes).
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private static function args(array $args): array
    {
        return array_filter($args, static fn (mixed $value): bool => $value !== null);
    }
}
