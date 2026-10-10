<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Optimizer;

use Closure;
use Error;
use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Schema\Schema;
use ReflectionFunction;

/**
 * Rewrite an AST into an optimized form (sqlglot optimizer/optimizer.py).
 *
 *     Optimizer::optimize('SELECT a FROM (SELECT x.a FROM x)', schema: ['x' => ['a' => 'INT']]);
 */
final class Optimizer
{
    /**
     * The default rules, in sqlglot's order (sqlglot RULES).
     *
     * @return list<Closure>
     */
    public static function rules(): array
    {
        return [
            Qualify::qualify(...),
            PushdownProjections::pushdownProjections(...),
            Normalize::normalize(...),
            UnnestSubqueries::unnestSubqueries(...),
            PushdownPredicates::pushdownPredicates(...),
            OptimizeJoins::optimizeJoins(...),
            EliminateSubqueries::eliminateSubqueries(...),
            MergeSubqueries::mergeSubqueries(...),
            EliminateJoins::eliminateJoins(...),
            EliminateCtes::eliminateCtes(...),
            QualifyColumns::quoteIdentifiers(...),
            AnnotateTypes::annotateTypes(...),
            Canonicalize::canonicalize(...),
            Simplify::simplify(...),
        ];
    }

    /**
     * Optimize an expression or SQL string. Each rule receives only the options its signature
     * accepts (the keyword arguments are matched by parameter name, like sqlglot).
     *
     * @param  array<array-key, mixed>|Schema|null  $schema  {table: {col: type}}, {db: {table: ...}} or {catalog: {db: ...}}
     * @param  list<callable>|null  $rules  rules `fn (Expression $expression, ...): Expression`, defaulting to self::rules()
     * @param  string|null  $sql  original SQL, for error highlighting
     * @param  mixed  ...$kwargs  extra options passed to the rules that accept them (camelCase names)
     */
    public static function optimize(
        Expression|string $expression,
        array|Schema|null $schema = null,
        ?string $db = null,
        ?string $catalog = null,
        string|Dialect|null $dialect = null,
        ?array $rules = null,
        ?string $sql = null,
        mixed ...$kwargs,
    ): Expression {
        $schema = Schema::ensure($schema, $dialect);
        $possible = [
            'db' => $db,
            'catalog' => $catalog,
            'schema' => $schema,
            'dialect' => $dialect,
            'sql' => $sql,
            'isolateTables' => true,
            'quoteIdentifiers' => false,
        ];

        foreach ($kwargs as $name => $value) {
            if (is_string($name)) {
                $possible[$name] = $value;
            }
        }

        $optimized = Builder::maybeParse($expression, dialect: $dialect, copy: true);

        foreach ($rules ?? self::rules() as $rule) {
            $result = self::apply(Closure::fromCallable($rule), $optimized, $possible);
            $optimized = $result instanceof Expression ? $result : $optimized;
        }

        return $optimized;
    }

    /**
     * Call a rule with the options it accepts. A variadic rule (e.g. a wrapper closure)
     * gets every option, minus the ones the wrapped function rejects.
     *
     * @param  array<string, mixed>  $possible
     */
    private static function apply(Closure $rule, Expression $expression, array $possible): mixed
    {
        $reflection = new ReflectionFunction($rule);
        $params = $reflection->getParameters();
        $variadic = false;
        $kwargs = [];

        foreach (array_slice($params, 1) as $param) {
            if ($param->isVariadic()) {
                $variadic = true;

                continue;
            }

            if (array_key_exists($param->getName(), $possible)) {
                $kwargs[$param->getName()] = $possible[$param->getName()];
            }
        }

        if (! $variadic) {
            return $rule($expression, ...$kwargs);
        }

        $kwargs = $possible;

        while (true) {
            try {
                return $rule($expression, ...$kwargs);
            } catch (Error $e) {
                if (preg_match('/^Unknown named parameter \$(\w+)$/', $e->getMessage(), $m) !== 1 || ! array_key_exists($m[1], $kwargs)) {
                    throw $e;
                }

                unset($kwargs[$m[1]]);
            }
        }
    }
}
