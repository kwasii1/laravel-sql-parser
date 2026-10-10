<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Teradata;

use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Extract;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Neg;
use Kwasii\LaravelSqlParser\Expressions\Properties;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * Teradata generator (sqlglot generators/teradata.py).
 */
class TeradataGenerator extends Generator
{
    public const string TABLES = TeradataGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS lambdas and module helpers
    // ---------------------------------------------------------------------------------------

    /**
     * sqlglot _date_add_sql(kind).
     */
    protected function dateAddSqlHandler(Expression $expression, string $kind): string
    {
        $this_ = $this->sql($expression, 'this');
        $unit = $expression->args['unit'] ?? null;
        $value = $this->simplifyUnlessLiteral($expression->argExpr('expression'));

        if (! $value instanceof Literal) {
            $this->unsupported('Cannot add non literal');
        }

        if ($value instanceof Neg) {
            $kindToOp = ['+' => '-', '-' => '+'];
            $inner = $value->args['this'] ?? null;
            $value = Literal::string($inner instanceof Expression ? $inner->name() : '');
        } else {
            $kindToOp = ['+' => '+', '-' => '-'];
            $value?->set('is_string', true);
        }

        return "{$this_} {$kindToOp[$kind]} ".$this->sql(new Interval(['this' => $value, 'unit' => $unit]));
    }

    protected function transformPow(Expression $e): string
    {
        return $this->binary($e, '**');
    }

    protected function transformRand(Expression $e): string
    {
        return $this->func('RANDOM', $e->args['lower'] ?? null, $e->args['upper'] ?? null);
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'INSTR', supportsPosition: true, supportsOccurrence: true);
    }

    protected function transformStrToDate(Expression $e): string
    {
        return 'CAST('.$this->sql($e, 'this').' AS DATE FORMAT '.$this->formatTime($e).')';
    }

    protected function transformToChar(Expression $e): string
    {
        return $this->functionFallbackSql($e);
    }

    protected function transformUse(Expression $e): string
    {
        return 'DATABASE '.$this->sql($e, 'this');
    }

    protected function transformQuarter(Expression $e): string
    {
        return $this->sql(new Extract(['this' => 'QUARTER', 'expression' => $e->args['this'] ?? null]));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function currenttimestampSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        return $this_ instanceof Expression
            ? $this->funcWith('CURRENT_TIMESTAMP', [$this_])
            : $this->funcWith('CURRENT_TIMESTAMP', [], prefix: '', suffix: '');
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        $to = $expression->args['to'] ?? null;

        if ($to instanceof DataType && $to->isType(DType::UNKNOWN) && ($expression->args['format'] ?? null) instanceof Expression) {
            // We don't actually want to print the unknown type in CAST(<value> AS FORMAT <format>)
            $to->pop();
        }

        return parent::castSql($expression, $safePrefix);
    }

    protected function trycastSql(Expression $expression): string
    {
        return $this->castSql($expression, 'TRY');
    }

    protected function tablesampleSql(Expression $expression, ?string $tablesampleKeyword = null): string
    {
        return $this->sql($expression, 'this').' SAMPLE '.$this->expressions($expression);
    }

    protected function partitionedbypropertySql(Expression $expression): string
    {
        return 'PARTITION BY '.$this->sql($expression, 'this');
    }

    // FROM before SET in Teradata UPDATE syntax
    protected function updateSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $fromSql = $this->sql($expression, 'from_');
        $setSql = $this->expressions($expression, flat: true);
        $whereSql = $this->sql($expression, 'where');

        return $this->prependCtes($expression, "UPDATE {$this_}{$fromSql} SET {$setSql}{$whereSql}");
    }

    protected function rangenSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expressionsSql = $this->expressions($expression);
        $eachSql = $this->sql($expression, 'each');
        $eachSql = $eachSql !== '' ? " EACH {$eachSql}" : '';

        return "RANGE_N({$this_} BETWEEN {$expressionsSql}{$eachSql})";
    }

    protected function lockingstatementSql(Expression $expression): string
    {
        return $this->sql($expression, 'this').' '.$this->sql($expression, 'expression');
    }

    /**
     * @param  array<string, list<Expression>>  $locations
     */
    protected function createableSql(Expression $expression, array &$locations): string
    {
        $kind = strtoupper($this->sql($expression, 'kind'));
        $postName = $locations['POST_NAME'] ?? [];
        $table = $expression->argExpr('this');

        if ($kind === 'TABLE' && $postName !== [] && $table instanceof Expression) {
            $thisName = $this->sql($table, 'this');
            $thisProperties = $this->properties(new Properties(['expressions' => $postName]), prefix: ',', wrapped: false);
            $thisSchema = $this->schemaColumnsSql($table);

            return $thisName.$thisProperties.$this->sep().$thisSchema;
        }

        return parent::createableSql($expression, $locations);
    }

    protected function extractSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');

        if (strtoupper($this_) !== 'QUARTER') {
            return parent::extractSql($expression);
        }

        $toChar = Builder::func('to_char', [$expression->args['expression'] ?? null, Literal::string('Q')]);

        return $this->sql(Builder::cast($toChar, DType::INT));
    }

    protected function intervalSql(Expression $expression): string
    {
        $multiplier = 0;
        $unit = $expression->text('unit');

        if (str_starts_with($unit, 'WEEK')) {
            $multiplier = 7;
        } elseif (str_starts_with($unit, 'QUARTER')) {
            $multiplier = 90;
        }

        if ($multiplier !== 0) {
            return "({$multiplier} * ".parent::intervalSql(new Interval(['this' => $expression->args['this'] ?? null, 'unit' => Builder::var('DAY')])).')';
        }

        return parent::intervalSql($expression);
    }
}
