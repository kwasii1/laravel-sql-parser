<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Sqlite;

use Kwasii\LaravelSqlParser\Expressions\AutoIncrementColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Coalesce;
use Kwasii\LaravelSqlParser\Expressions\ColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\ColumnDef;
use Kwasii\LaravelSqlParser\Expressions\Create;
use Kwasii\LaravelSqlParser\Expressions\DPipe;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Filter;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\GeneratedAsIdentityColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Greatest;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\Limit;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Neg;
use Kwasii\LaravelSqlParser\Expressions\NotNullColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\NullExpr;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\Paren;
use Kwasii\LaravelSqlParser\Expressions\PrimaryKey;
use Kwasii\LaravelSqlParser\Expressions\PrimaryKeyColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Schema;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\TableAlias;
use Kwasii\LaravelSqlParser\Expressions\Window;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * SQLite generator (sqlglot generators/sqlite.py).
 */
class SqliteGenerator extends Generator
{
    public const string TABLES = SqliteGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // Transforms
    // ---------------------------------------------------------------------------------------

    /**
     * Move the primary key to a column and place AUTOINCREMENT after it.
     */
    public function transformCreate(Expression $expression): Expression
    {
        $schema = $expression->args['this'] ?? null;

        if (! $expression instanceof Create || ! $schema instanceof Schema) {
            return $expression;
        }

        $defs = [];
        $primaryKey = null;

        foreach ($schema->argList('expressions') as $e) {
            if ($e instanceof ColumnDef) {
                $defs[$e->name()] = $e;
            } elseif ($e instanceof PrimaryKey) {
                $primaryKey = $e;
            }
        }

        if ($primaryKey !== null && count($primaryKey->argList('expressions')) === 1) {
            $column = $defs[$primaryKey->argList('expressions')[0]->name()] ?? null;
            $column?->append('constraints', new ColumnConstraint(['kind' => new PrimaryKeyColumnConstraint]));
            $schema->set('expressions', array_values(array_filter($schema->argList('expressions'), static fn (Expression $e): bool => $e !== $primaryKey)));
        }

        foreach ($defs as $column) {
            $primaryKeyIndex = -1;
            $autoIncrement = null;
            $autoIncrementIndex = -1;
            $constraints = $column->argList('constraints');

            foreach ($constraints as $i => $constraint) {
                $kind = $constraint->args['kind'] ?? null;

                if ($kind instanceof PrimaryKeyColumnConstraint) {
                    $primaryKeyIndex = $i;
                } elseif ($kind instanceof AutoIncrementColumnConstraint) {
                    $autoIncrement = $constraint;
                    $autoIncrementIndex = $i;
                }
            }

            if ($autoIncrement !== null && $autoIncrementIndex >= 0 && $autoIncrementIndex < $primaryKeyIndex) {
                array_splice($constraints, $autoIncrementIndex, 1);
                array_splice($constraints, $primaryKeyIndex, 0, [$autoIncrement]);
                $column->set('constraints', $constraints);
            }
        }

        return $expression;
    }

    public function generatedToAutoIncrement(Expression $expression): Expression
    {
        if (! $expression instanceof ColumnDef) {
            return $expression;
        }

        $generated = $expression->find(GeneratedAsIdentityColumnConstraint::class);

        // Only rewrite true identity columns. Expression-bearing forms are computed columns
        // (GENERATED ALWAYS AS (expr)) and must keep their expression.
        if ($generated !== null && ($generated->args['expression'] ?? null) === null) {
            $generated->parent?->pop();
            $expression->find(NotNullColumnConstraint::class)?->parent?->pop();
            $expression->append('constraints', new ColumnConstraint(['kind' => new AutoIncrementColumnConstraint]));
        }

        return $expression;
    }

    public function offsetToLimit(Expression $expression): Expression
    {
        if ($expression instanceof Select && ($expression->args['offset'] ?? null) !== null && ($expression->args['limit'] ?? null) === null) {
            $expression->set('limit', new Limit(['expression' => new Neg(['this' => Literal::number(1)])]));
        }

        return $expression;
    }

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function transformCurrentDate(Expression $e): string
    {
        return 'CURRENT_DATE';
    }

    protected function transformCurrentTime(Expression $e): string
    {
        return 'CURRENT_TIME';
    }

    protected function transformCurrentTimestamp(Expression $e): string
    {
        return 'CURRENT_TIMESTAMP';
    }

    protected function transformCurrentVersion(Expression $e): string
    {
        return 'SQLITE_VERSION()';
    }

    protected function transformDateStrToDate(Expression $e): string
    {
        return $this->sql($e, 'this');
    }

    protected function transformJSONObjectAgg(Expression $e): string
    {
        return $this->jsonobjectSqlInternal($e, name: 'JSON_GROUP_OBJECT');
    }

    protected function transformRegexpLike(Expression $e): string
    {
        return $this->binary($e, 'REGEXP');
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'INSTR');
    }

    protected function transformTimeStrToTime(Expression $e): string
    {
        return $this->sql($e, 'this');
    }

    protected function transformTimeToStr(Expression $e): string
    {
        return $this->func('STRFTIME', $e->args['format'] ?? null, $e->args['this'] ?? null);
    }

    protected function transformTsOrDsToTimestamp(Expression $e): string
    {
        return $this->sql($e, 'this');
    }

    // ---------------------------------------------------------------------------------------
    // Generator overrides
    // ---------------------------------------------------------------------------------------

    protected function autoincrementcolumnconstraintSql(Expression $expression): string
    {
        $column = $expression->findAncestor(ColumnDef::class);

        if ($column !== null && $column->find(PrimaryKeyColumnConstraint::class) !== null) {
            return parent::autoincrementcolumnconstraintSql($expression);
        }

        $this->unsupported('SQLite AUTOINCREMENT requires an INTEGER PRIMARY KEY');

        return '';
    }

    protected function insertSql(Expression $expression): string
    {
        if ($expression->flag('ignore')) {
            $expression->set('ignore', false);
            $expression->set('alternative', 'IGNORE');
        }

        return parent::insertSql($expression);
    }

    protected function bitwiseandaggSql(Expression $expression): string
    {
        $this->unsupported('BITWISE_AND aggregation is not supported in SQLite');

        return $this->functionFallbackSql($expression);
    }

    protected function bitwiseoraggSql(Expression $expression): string
    {
        $this->unsupported('BITWISE_OR aggregation is not supported in SQLite');

        return $this->functionFallbackSql($expression);
    }

    protected function bitwisexoraggSql(Expression $expression): string
    {
        $this->unsupported('BITWISE_XOR aggregation is not supported in SQLite');

        return $this->functionFallbackSql($expression);
    }

    protected function jsonextractSql(Expression $expression): string
    {
        if ($expression->argList('expressions') !== []) {
            return $this->functionFallbackSql($expression);
        }

        return $this->arrowJsonExtractSql($expression);
    }

    protected function jsonextractscalarSql(Expression $expression): string
    {
        if ($expression->flag('json_subtype')) {
            // json_extract() keeps the JSON subtype on object/array results; ->> strips it,
            // observable when the result feeds another JSON function
            return $this->func('JSON_EXTRACT', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
        }

        return $this->arrowJsonExtractSql($expression);
    }

    protected function dateaddSql(Expression $expression): string
    {
        $modifier = $expression->argExpr('expression') ?? new NullExpr;
        $unit = $expression->args['unit'] ?? null;

        // An INTERVAL amount carries its own unit, e.g. DATE_ADD(d, INTERVAL 1 DAY); unwrap it so
        // the unit is not left inside the quoted modifier string.
        if ($modifier instanceof Interval) {
            $unit = $this->truthy($unit) ? $unit : ($modifier->args['unit'] ?? null);
            $modifier = $modifier->argExpr('this') ?? new NullExpr;
        }

        $modifier = $modifier->unnest();

        if (
            $unit instanceof Expression
            && ! $modifier instanceof Literal
            && ! ($modifier instanceof Neg && ($modifier->argExpr('this')?->isNumber() ?? false))
        ) {
            return $this->func('DATE', $expression->args['this'] ?? null, new DPipe([
                'this' => Builder::paren($modifier),
                'expression' => Literal::string(' '.$unit->name()),
            ]));
        }

        $modifierSql = $modifier->isString() ? $modifier->name() : $this->sql($modifier);
        $modifierSql = $unit instanceof Expression ? "'{$modifierSql} ".$unit->name()."'" : "'{$modifierSql}'";

        return $this->func('DATE', $expression->args['this'] ?? null, $modifierSql);
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        if ($expression->isType('date')) {
            return $this->func('DATE', $expression->args['this'] ?? null);
        }

        return parent::castSql($expression);
    }

    /**
     * https://www.sqlite.org/gencol.html
     */
    protected function computedcolumnconstraintSql(Expression $expression): string
    {
        if ($expression->flag('data_type')) {
            $this->unsupported('SQLite generated columns do not support a data type');
        }

        $this_ = $expression->args['this'] ?? null;
        $thisSql = $this_ instanceof Paren ? $this->sql($this_) : '('.$this->sql($this_ instanceof Expression ? $this_ : null).')';
        $storage = $expression->flag('persisted') ? ' STORED' : '';
        $notNull = $expression->flag('not_null') ? ' NOT NULL' : '';

        return "AS {$thisSql}{$storage}{$notNull}";
    }

    /**
     * SQLite's TRUNC always returns REAL and has no decimals parameter.
     */
    protected function truncSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'decimals');

        return $this->func('TRUNC', $expression->args['this'] ?? null);
    }

    protected function generateseriesSql(Expression $expression): string
    {
        $alias = $expression->parent?->args['alias'] ?? null;

        if ($alias instanceof TableAlias && $alias->argList('columns') !== []) {
            $columnAlias = $alias->argList('columns')[0];
            $alias->set('columns', null);

            return $this->sql((new Select([
                'expressions' => [Builder::alias('value', $columnAlias instanceof Identifier ? $columnAlias : $columnAlias->name())],
                'from_' => new From(['this' => $expression]),
            ]))->subquery());
        }

        return $this->functionFallbackSql($expression);
    }

    protected function datediffSql(Expression $expression): string
    {
        $unit = $expression->args['unit'] ?? null;
        $unit = $unit instanceof Expression ? strtoupper($unit->name()) : 'DAY';

        $sql = '(JULIANDAY('.$this->sql($expression, 'this').') - JULIANDAY('.$this->sql($expression, 'expression').'))';

        $factor = match ($unit) {
            'MONTH' => ' / 30.0',
            'YEAR' => ' / 365.0',
            'HOUR' => ' * 24.0',
            'MINUTE' => ' * 1440.0',
            'SECOND' => ' * 86400.0',
            'MILLISECOND' => ' * 86400000.0',
            'MICROSECOND' => ' * 86400000000.0',
            'NANOSECOND' => ' * 86400000000000.0',
            default => null,
        };

        if ($factor !== null) {
            $sql .= $factor;
        } elseif ($unit !== 'DAY') {
            $this->unsupported("DATEDIFF unsupported for '{$unit}'.");
        }

        return "CAST({$sql} AS INTEGER)";
    }

    protected function groupconcatSql(Expression $expression): string
    {
        $node = $expression->parent instanceof Filter ? $expression->parent : $expression;
        $window = $node->parent;
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof Order && $window instanceof Window && ($window->args['this'] ?? null) === $node) {
            $this->unsupported('SQLite GROUP_CONCAT window functions do not support argument ORDER BY');
            $expression->set('this', $this_->args['this'] ?? null);
        }

        return $this->groupconcatSqlHandler($expression, funcName: 'GROUP_CONCAT', sep: null, withinGroup: false);
    }

    protected function greatestLeastSqlInternal(Expression $expression): string
    {
        if ($expression->argList('expressions') === []) {
            return $this->sql($expression, 'this');
        }

        $name = $expression instanceof Greatest ? 'MAX' : 'MIN';

        if (! $expression->flag('ignore_nulls')) {
            return $this->renameFunc($expression, $name);
        }

        // SQLite's multi-argument MAX/MIN return NULL if any argument is NULL:
        // GREATEST(a, b, c) -> MAX(COALESCE(a, b, c), COALESCE(b, c, a), COALESCE(c, a, b)).
        $args = [$expression->args['this'] ?? null, ...$expression->argList('expressions')];
        $coalesces = [];

        for ($i = 0, $n = count($args); $i < $n; $i++) {
            $rotated = [...array_slice($args, $i), ...array_slice($args, 0, $i)];
            $coalesces[] = new Coalesce([
                'this' => $rotated[0] instanceof Expression ? $rotated[0]->copy() : $rotated[0],
                'expressions' => array_map(static fn (mixed $e): mixed => $e instanceof Expression ? $e->copy() : $e, array_slice($rotated, 1)),
            ]);
        }

        return $this->funcWith($name, $coalesces);
    }

    protected function greatestSql(Expression $expression): string
    {
        return $this->greatestLeastSqlInternal($expression);
    }

    protected function leastSql(Expression $expression): string
    {
        return $this->greatestLeastSqlInternal($expression);
    }

    protected function transactionSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? " {$this_}" : '';

        return "BEGIN{$this_} TRANSACTION";
    }

    protected function isasciiSql(Expression $expression): string
    {
        return '(NOT '.$this->sql($expression, 'this')." GLOB CAST(x'2a5b5e012d7f5d2a' AS TEXT))";
    }

    protected function currentschemaSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'this');

        return "'main'";
    }

    protected function ignorenullsSql(Expression $expression): string
    {
        $this->unsupported('SQLite does not support IGNORE NULLS.');

        return $this->sql($expression, 'this');
    }

    protected function respectnullsSql(Expression $expression): string
    {
        return $this->sql($expression, 'this');
    }

    protected function windowspecSql(Expression $expression): string
    {
        if (
            strtoupper($expression->text('kind')) === 'RANGE'
            && strtoupper($expression->text('start')) === 'CURRENT ROW'
            && ($expression->args['end'] ?? null) === null
        ) {
            return 'RANGE CURRENT ROW';
        }

        return parent::windowspecSql($expression);
    }
}
