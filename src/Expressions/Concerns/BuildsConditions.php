<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use InvalidArgumentException;
use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Add;
use LaravelSqlParser\LaravelSqlParser\Expressions\Between;
use LaravelSqlParser\LaravelSqlParser\Expressions\Binary;
use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Div;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\GT;
use LaravelSqlParser\LaravelSqlParser\Expressions\GTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\ILike;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\IntDiv;
use LaravelSqlParser\LaravelSqlParser\Expressions\Is;
use LaravelSqlParser\LaravelSqlParser\Expressions\Like;
use LaravelSqlParser\LaravelSqlParser\Expressions\LT;
use LaravelSqlParser\LaravelSqlParser\Expressions\LTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mod;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\Neg;
use LaravelSqlParser\LaravelSqlParser\Expressions\NEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Not;
use LaravelSqlParser\LaravelSqlParser\Expressions\Ordered;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pow;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpLike;
use LaravelSqlParser\LaravelSqlParser\Expressions\Sub;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;

/**
 * Condition builders available on every expression (sqlglot Expr.and_, or_, not_, as_, isin,
 * between, eq, ... and the Python operator overloads, as named methods).
 *
 * Variadic builders take their options as named arguments after the expressions, e.g.
 * `$condition->and('y = 1', 'z = 1', dialect: 'mysql')`.
 *
 * @phpstan-require-extends Expression
 */
trait BuildsConditions
{
    /**
     * AND this expression with others (sqlglot and_). Options: dialect, copy, wrap.
     */
    public function and(mixed ...$args): Expression
    {
        return $this->connect('and', $args);
    }

    /**
     * OR this expression with others (sqlglot or_). Options: dialect, copy, wrap.
     */
    public function or(mixed ...$args): Expression
    {
        return $this->connect('or', $args);
    }

    /**
     * NOT this expression (sqlglot not_).
     */
    public function not(bool $copy = true): Not
    {
        return Builder::not($this, copy: $copy);
    }

    /**
     * Alias this expression (sqlglot as_).
     *
     * @param  bool|list<Identifier|string>  $table
     */
    public function as(Identifier|string $alias, ?bool $quoted = null, string|Dialect|null $dialect = null, bool $copy = true, bool|array $table = false): Expression
    {
        return Builder::alias($this, $alias, $table, $quoted, $dialect, $copy);
    }

    /**
     * <this> IN (<values>) | IN (<query>) | IN UNNEST(<unnest>) (sqlglot isin).
     * Options: query, unnest, dialect, copy. Values are converted with Builder::convert().
     */
    public function isin(mixed ...$args): In
    {
        [$values, $options] = Builder::splitOptions($args, ['query' => null, 'unnest' => null, 'dialect' => null, 'copy' => true]);
        $dialect = Builder::dialectOption($options);
        $copy = (bool) $options['copy'];

        $subquery = null;

        if ($options['query'] !== null && $options['query'] !== '') {
            $subquery = Builder::parseSql($options['query'], dialect: $dialect, copy: $copy);

            if ($subquery instanceof Query) {
                $subquery = $subquery->subquery(copy: false);
            }
        }

        return new In([
            'this' => $copy ? $this->copy() : $this,
            'expressions' => array_map(static fn (mixed $e): Expression => Builder::convert($e, $copy), $values),
            'query' => $subquery,
            'unnest' => self::unnestOption($options['unnest'], $dialect, $copy),
        ]);
    }

    /**
     * <this> BETWEEN <low> AND <high> (sqlglot between).
     */
    public function between(mixed $low, mixed $high, bool $copy = true, ?bool $symmetric = null): Between
    {
        $between = new Between([
            'this' => $copy ? $this->copy() : $this,
            'low' => Builder::convert($low, $copy),
            'high' => Builder::convert($high, $copy),
        ]);

        if ($symmetric !== null) {
            $between->set('symmetric', $symmetric);
        }

        return $between;
    }

    public function is(mixed $other): Is
    {
        /** @var Is */
        return Builder::binop(Is::class, $this, $other);
    }

    public function like(mixed $other): Like
    {
        /** @var Like */
        return Builder::binop(Like::class, $this, $other);
    }

    public function ilike(mixed $other): ILike
    {
        /** @var ILike */
        return Builder::binop(ILike::class, $this, $other);
    }

    public function rlike(mixed $other): RegexpLike
    {
        /** @var RegexpLike */
        return Builder::binop(RegexpLike::class, $this, $other);
    }

    public function eq(mixed $other): EQ
    {
        /** @var EQ */
        return Builder::binop(EQ::class, $this, $other);
    }

    public function neq(mixed $other): NEQ
    {
        /** @var NEQ */
        return Builder::binop(NEQ::class, $this, $other);
    }

    public function lt(mixed $other): LT
    {
        /** @var LT */
        return Builder::binop(LT::class, $this, $other);
    }

    public function lte(mixed $other): LTE
    {
        /** @var LTE */
        return Builder::binop(LTE::class, $this, $other);
    }

    public function gt(mixed $other): GT
    {
        /** @var GT */
        return Builder::binop(GT::class, $this, $other);
    }

    public function gte(mixed $other): GTE
    {
        /** @var GTE */
        return Builder::binop(GTE::class, $this, $other);
    }

    public function add(mixed $other): Add
    {
        /** @var Add */
        return Builder::binop(Add::class, $this, $other);
    }

    public function sub(mixed $other): Sub
    {
        /** @var Sub */
        return Builder::binop(Sub::class, $this, $other);
    }

    public function mul(mixed $other): Mul
    {
        /** @var Mul */
        return Builder::binop(Mul::class, $this, $other);
    }

    /**
     * <this> / <other> (sqlglot div; typed and safe set the Div flags).
     */
    public function div(mixed $other, bool $typed = false, bool $safe = false): Div
    {
        /** @var Div $div */
        $div = Builder::binop(Div::class, $this, $other);
        $div->set('typed', $typed);
        $div->set('safe', $safe);

        return $div;
    }

    /**
     * Integer division (Python's `//`).
     */
    public function intDiv(mixed $other): IntDiv
    {
        /** @var IntDiv */
        return Builder::binop(IntDiv::class, $this, $other);
    }

    public function mod(mixed $other): Mod
    {
        /** @var Mod */
        return Builder::binop(Mod::class, $this, $other);
    }

    public function pow(mixed $other): Pow
    {
        /** @var Pow */
        return Builder::binop(Pow::class, $this, $other);
    }

    /**
     * Negation (Python's unary `-`).
     */
    public function neg(): Neg
    {
        return new Neg(['this' => Builder::wrap($this->copy(), Binary::class)]);
    }

    /**
     * Subscript (Python's `x[1]`, `x[1, 2]`).
     */
    public function bracket(mixed ...$indexes): Bracket
    {
        return new Bracket([
            'this' => $this->copy(),
            'expressions' => array_map(static fn (mixed $e): Expression => Builder::convert($e, true), array_values($indexes)),
        ]);
    }

    public function asc(bool $nullsFirst = true): Ordered
    {
        return new Ordered(['this' => $this->copy(), 'nulls_first' => $nullsFirst]);
    }

    public function desc(bool $nullsFirst = false): Ordered
    {
        return new Ordered(['this' => $this->copy(), 'desc' => true, 'nulls_first' => $nullsFirst]);
    }

    /**
     * Narrow this expression's type, or throw (sqlglot assert_is).
     *
     * @template T of Expression
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function assertIs(string $class): Expression
    {
        if (! $this instanceof $class) {
            throw new InvalidArgumentException(static::class." is not {$class}.");
        }

        return $this;
    }

    /**
     * @param  array<int|string, mixed>  $args
     */
    private function connect(string $operator, array $args): Expression
    {
        [$expressions, $options] = Builder::splitOptions($args, ['dialect' => null, 'copy' => true, 'wrap' => true]);
        $conditions = [$this];

        foreach ($expressions as $expression) {
            if ($expression !== null) {
                $conditions[] = Builder::sqlArg($expression);
            }
        }

        $dialect = Builder::dialectOption($options);

        return $operator === 'and'
            ? Builder::and($conditions, $dialect, (bool) $options['copy'], (bool) $options['wrap'])
            : Builder::or($conditions, $dialect, (bool) $options['copy'], (bool) $options['wrap']);
    }

    private static function unnestOption(mixed $unnest, string|Dialect|null $dialect, bool $copy): ?Unnest
    {
        if ($unnest === null || $unnest === '' || $unnest === []) {
            return null;
        }

        $items = is_array($unnest) ? array_values($unnest) : [$unnest];

        return new Unnest(['expressions' => array_map(static fn (mixed $e): Expression => Builder::parseSql($e, dialect: $dialect, copy: $copy), $items)]);
    }
}
