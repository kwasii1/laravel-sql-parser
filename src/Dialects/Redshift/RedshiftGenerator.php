<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Redshift;

use Kwasii\LaravelSqlParser\Dialects\Postgres\PostgresGenerator;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\Join;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Order;
use Kwasii\LaravelSqlParser\Expressions\Ordered;
use Kwasii\LaravelSqlParser\Expressions\PercentileDisc;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\WithinGroup;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * Amazon Redshift generator (sqlglot generators/redshift.py).
 */
class RedshiftGenerator extends PostgresGenerator
{
    public const string TABLES = RedshiftGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS lambdas
    // ---------------------------------------------------------------------------------------

    protected function transformApproxDistinct(Expression $e): string
    {
        return 'APPROXIMATE COUNT(DISTINCT '.$this->sql($e, 'this').')';
    }

    protected function transformCurrentTimestamp(Expression $e): string
    {
        return $e->flag('sysdate') ? 'SYSDATE' : 'GETDATE()';
    }

    protected function transformCurrentUserId(Expression $e): string
    {
        return 'CURRENT_USER_ID';
    }

    protected function transformDistKeyProperty(Expression $e): string
    {
        return $this->func('DISTKEY', $e->args['this'] ?? null);
    }

    protected function transformDistStyleProperty(Expression $e): string
    {
        return $this->nakedProperty($e);
    }

    protected function transformExplode(Expression $e): string
    {
        return $this->explodeSql($e);
    }

    protected function transformHex(Expression $e): string
    {
        return $this->func('UPPER', $this->func('TO_HEX', $this->sql($e, 'this')));
    }

    protected function transformSHA2Digest(Expression $e): string
    {
        $length = $e->args['length'] ?? null;

        return $this->func('SHA2', $e->args['this'] ?? null, $this->truthy($length) ? $length : Literal::number(256));
    }

    protected function transformSortKeyProperty(Expression $e): string
    {
        $compound = $e->flag('compound') ? 'COMPOUND ' : '';

        return "{$compound}SORTKEY(".$this->formatArgs($e->argList('this')).')';
    }

    protected function transformStartsWith(Expression $e): string
    {
        return $this->sql($e->args['this'] ?? null).' LIKE '.$this->sql($e->args['expression'] ?? null)." || '%'";
    }

    protected function transformUnixToTime(Expression $e): string
    {
        return $this->unixToTimeSqlRedshift($e);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function stpointSql(Expression $expression): string
    {
        // ST_POINT only accepts 2 args in Redshift; use ST_MAKEPOINT for 3 or 4 args
        if ($expression->flag('z') || $expression->flag('m')) {
            return $this->func(
                'ST_MAKEPOINT',
                $expression->args['this'] ?? null,
                $expression->args['expression'] ?? null,
                $expression->args['z'] ?? null,
                $expression->args['m'] ?? null,
            );
        }

        return $this->func('ST_POINT', $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
    }

    protected function arraycontainsSql(Expression $expression): string
    {
        return $this->func(
            'ARRAY_CONTAINS',
            $expression->args['this'] ?? null,
            $expression->args['expression'] ?? null,
            $expression->args['check_null'] ?? null,
        );
    }

    protected function objecttransformSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $keep = $this->expressions($expression, key: 'keep', flat: true);
        $set = $this->expressions($expression, key: 'set_', flat: true);
        $keepSql = $keep !== '' ? " KEEP {$keep}" : '';
        $setSql = $set !== '' ? " SET {$set}" : '';

        return "OBJECT_TRANSFORM({$this_}{$keepSql}{$setSql})";
    }

    protected function approxquantileSql(Expression $expression): string
    {
        return 'APPROXIMATE '.$this->sql(new WithinGroup([
            'this' => new PercentileDisc(['this' => $expression->args['quantile'] ?? null]),
            'expression' => new Order(['expressions' => [new Ordered(['this' => $expression->args['this'] ?? null])]]),
        ]));
    }

    protected function unnestSql(Expression $expression): string
    {
        $args = $expression->argList('expressions');
        $numArgs = count($args);

        if ($numArgs !== 1) {
            $this->unsupported("Unsupported number of arguments in UNNEST: {$numArgs}");

            return '';
        }

        if ($expression->findAncestor(From::class, Join::class, Select::class) instanceof Select) {
            $this->unsupported('Unsupported UNNEST when not used in FROM/JOIN clauses');

            return '';
        }

        $arg = $this->sql($args[0]);
        $aliasNode = $expression->args['alias'] ?? null;
        $alias = $aliasNode instanceof Expression ? $this->expressions($aliasNode, key: 'columns', flat: true) : '';

        return $alias !== '' ? "{$arg} AS {$alias}" : $arg;
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        if ($expression instanceof Cast && $expression->isType(DType::JSON)) {
            // Redshift doesn't support a JSON type, so casting to it is treated as a noop
            return $this->sql($expression, 'this');
        }

        return parent::castSql($expression, safePrefix: $safePrefix);
    }

    /**
     * Redshift converts TEXT to VARCHAR(255) by default, when people more generally mean VARCHAR of
     * max length, which is VARCHAR(max) in Redshift. So TEXT without precision becomes
     * VARCHAR(max), and TEXT with precision becomes VARCHAR.
     */
    protected function datatypeSql(DataType $expression): string
    {
        if ($expression->isType(DType::TEXT)) {
            $expression->set('this', DType::VARCHAR);

            if ($expression->argList('expressions') === []) {
                $expression->append('expressions', Builder::var('MAX'));
            }
        }

        return parent::datatypeSql($expression);
    }

    protected function altersetSql(Expression $expression): string
    {
        $exprs = $this->expressions($expression, flat: true);
        $exprs = $exprs !== '' ? " TABLE PROPERTIES ({$exprs})" : '';
        $location = $this->sql($expression, 'location');
        $location = $location !== '' ? " LOCATION {$location}" : '';
        $fileFormat = $this->expressions($expression, key: 'file_format', flat: true, sep: ' ');
        $fileFormat = $fileFormat !== '' ? " FILE FORMAT {$fileFormat}" : '';

        return "SET{$exprs}{$location}{$fileFormat}";
    }

    protected function arraySql(Expression $expression): string
    {
        if ($expression->flag('bracket_notation')) {
            return parent::arraySql($expression);
        }

        return $this->funcWith('ARRAY', $expression->argList('expressions'));
    }

    protected function ignorenullsSql(Expression $expression): string
    {
        return Generator::ignorenullsSql($expression);
    }

    protected function respectnullsSql(Expression $expression): string
    {
        return Generator::respectnullsSql($expression);
    }

    public function explodeSql(Expression $expression): string
    {
        $this->unsupported('Unsupported EXPLODE() function');

        return '';
    }

    private function unixToTimeSqlRedshift(Expression $expression): string
    {
        $scale = $expression->args['scale'] ?? null;
        $this_ = $this->sql($expression->args['this'] ?? null);

        if ($scale instanceof Expression && ! $scale->equals(Literal::number(0)) && $scale->isInt()) {
            $this_ = "({$this_} / POWER(10, ".$scale->toPhp().'))';
        }

        return "(TIMESTAMP 'epoch' + {$this_} * INTERVAL '1 SECOND')";
    }
}
