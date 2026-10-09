<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Oracle;

use LaravelSqlParser\LaravelSqlParser\Expressions\Anonymous;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\InOutColumnConstraint;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;

/**
 * Oracle generator (sqlglot generators/oracle.py).
 */
class OracleGenerator extends Generator
{
    public const string TABLES = OracleGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS lambdas
    // ---------------------------------------------------------------------------------------

    protected function transformGroupConcat(Expression $e): string
    {
        return $this->groupconcatSqlHandler($e, onOverflow: true);
    }

    protected function transformDateStrToDate(Expression $e): string
    {
        return $this->func('TO_DATE', $e->args['this'] ?? null, Literal::string('YYYY-MM-DD'));
    }

    protected function transformDateTrunc(Expression $e): string
    {
        return $this->func('TRUNC', $e->args['this'] ?? null, $e->args['unit'] ?? null);
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'INSTR', supportsPosition: true, supportsOccurrence: true);
    }

    protected function transformStrToTime(Expression $e): string
    {
        return $this->func('TO_TIMESTAMP', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformStrToDate(Expression $e): string
    {
        return $this->func('TO_DATE', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformSubquery(Expression $e): string
    {
        return $this->subquerySql($e, sep: ' ');
    }

    protected function transformTable(Expression $e): string
    {
        return $this->tableSql($e, sep: ' ');
    }

    protected function transformTableSample(Expression $e): string
    {
        return $this->tablesampleSql($e);
    }

    protected function transformTemporaryProperty(Expression $e): string
    {
        $name = $e->name();

        return ($name !== '' ? $name : 'GLOBAL').' TEMPORARY';
    }

    protected function transformTimeToStr(Expression $e): string
    {
        return $this->func('TO_CHAR', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformToChar(Expression $e): string
    {
        return $this->functionFallbackSql($e);
    }

    protected function transformUnicode(Expression $e): string
    {
        return 'ASCII(UNISTR('.$this->sql($e->args['this'] ?? null).'))';
    }

    protected function transformUnixToTime(Expression $e): string
    {
        return "TO_DATE('1970-01-01', 'YYYY-MM-DD') + (".$this->sql($e, 'this').' / 86400)';
    }

    protected function transformSystimestamp(Expression $e): string
    {
        return 'SYSTIMESTAMP';
    }

    protected function trimSqlOracle(Expression $expression): string
    {
        $position = $expression->args['position'] ?? null;

        if (is_string($position) && in_array(strtoupper($position), ['LEADING', 'TRAILING'], true)) {
            return $this->trimSql($expression);
        }

        return $this->trimSqlHandler($expression);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function currenttimestampSql(Expression $expression): string
    {
        if ($expression->flag('sysdate')) {
            return 'SYSDATE';
        }

        $this_ = $expression->args['this'] ?? null;

        return $this_ instanceof Expression ? $this->func('CURRENT_TIMESTAMP', $this_) : 'CURRENT_TIMESTAMP';
    }

    protected function offsetSql(Expression $expression): string
    {
        return parent::offsetSql($expression).' ROWS';
    }

    public function addColumnSql(Expression $expression): string
    {
        return 'ADD '.$this->sql($expression);
    }

    protected function queryoptionSql(Expression $expression): string
    {
        $option = $this->sql($expression, 'this');
        $value = $this->sql($expression, 'expression');
        $value = $value !== '' ? " CONSTRAINT {$value}" : '';

        return "{$option}{$value}";
    }

    protected function coalesceSql(Expression $expression): string
    {
        return $this->renameFunc($expression, $expression->flag('is_nvl') ? 'NVL' : 'COALESCE');
    }

    protected function intoSql(Expression $expression): string
    {
        $into = $expression->flag('bulk_collect') ? 'BULK COLLECT INTO' : 'INTO';

        if (($expression->args['this'] ?? null) instanceof Expression) {
            return $this->seg($into).' '.$this->sql($expression, 'this');
        }

        return $this->seg($into).' '.$this->expressions($expression);
    }

    protected function hintSql(Expression $expression): string
    {
        $expressions = [];

        foreach ($expression->args['expressions'] ?? [] as $hint) {
            if (is_string($hint)) {
                $expressions[] = $hint;
            } elseif ($hint instanceof Anonymous) {
                $formattedArgs = $this->formatArgs($hint->argList('expressions'), ' ');
                $expressions[] = $this->sql($hint, 'this')."({$formattedArgs})";
            } elseif ($hint instanceof Expression) {
                $expressions[] = $this->sql($hint);
            }
        }

        return ' /*+ '.trim($this->expressions(sqls: $expressions, sep: $this->t['QUERY_HINT_SEP'])).' */';
    }

    protected function isasciiSql(Expression $expression): string
    {
        return 'NVL(REGEXP_LIKE('.$this->sql($expression->args['this'] ?? null).", '^[' || CHR(1) || '-' || CHR(127) || ']*$'), TRUE)";
    }

    protected function intervalSql(Expression $expression): string
    {
        $prefix = ($expression->args['this'] ?? null) instanceof Literal ? 'INTERVAL ' : '';

        return $prefix.$this->sql($expression, 'this').' '.$this->sql($expression, 'unit');
    }

    protected function tonumberSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $default = $this->sql($expression, 'default');

        if ($default !== '') {
            $this_ = "{$this_} DEFAULT {$default} ON CONVERSION ERROR";
        }

        return $this->func('TO_NUMBER', $this_, $expression->args['format'] ?? null, $expression->args['nlsparam'] ?? null);
    }

    protected function columndefSql(Expression $expression, string $sep = ' '): string
    {
        $paramConstraint = $expression->find(InOutColumnConstraint::class);

        if ($paramConstraint !== null) {
            $sep = ' '.$this->sql($paramConstraint).' ';
            $paramConstraint->pop();
        }

        return parent::columndefSql($expression, $sep);
    }
}
