<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Trino;

use LaravelSqlParser\LaravelSqlParser\Dialects\Presto\PrestoGenerator;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayConcat;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Boolean;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\IfBlock;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONPath;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;

/**
 * Trino generator (sqlglot generators/trino.py).
 */
class TrinoGenerator extends PrestoGenerator
{
    public const string TABLES = TrinoGeneratorTables::class;

    protected function transformArraySum(Expression $e): string
    {
        return 'REDUCE('.$this->sql($e, 'this').', 0, (acc, x) -> acc + x, acc -> acc)';
    }

    protected function transformArrayUniqueAgg(Expression $e): string
    {
        return 'ARRAY_AGG(DISTINCT '.$this->sql($e, 'this').')';
    }

    protected function transformGroupConcat(Expression $e): string
    {
        return $this->groupconcatSqlHandler($e, onOverflow: true);
    }

    protected function transformLocationProperty(Expression $e): string
    {
        return $this->propertySql($e);
    }

    protected function transformStabilityProperty(Expression $e): string
    {
        return $e->name() === 'IMMUTABLE' ? 'DETERMINISTIC' : 'NOT DETERMINISTIC';
    }

    protected function transformTimeStrToTime(Expression $e): string
    {
        return $this->timestrtotimeSqlHandler($e, includePrecision: true);
    }

    protected function concatwsSql(Expression $expression): string
    {
        if ($expression->flag('flatten')) {
            $arrays = [];
            $hasArray = false;
            $hasUnknown = false;

            foreach (array_slice($expression->argList('expressions'), 1) as $arg) {
                if ($arg instanceof ArrayExpr || $arg->isType(DType::ARRAY)) {
                    $hasArray = true;
                    $arg = Builder::func('COALESCE', [Builder::cast($arg, 'ARRAY<TEXT>'), new ArrayExpr(['expressions' => []])]);
                } else {
                    if (($arg->type() === null || $arg->isType(DType::UNKNOWN)) && ! ($arg instanceof Literal || $arg instanceof Boolean || $arg instanceof NullExpr)) {
                        $hasUnknown = true;
                    }

                    $arg = new ArrayExpr(['expressions' => [Builder::cast($arg, DType::TEXT)]]);
                }

                $arrays[] = $arg;
            }

            if ($hasUnknown) {
                $this->unsupported('Cannot transpile CONCAT_WS with unknown argument types to Trino.');
            }

            if ($hasArray) {
                $array = count($arrays) > 1
                    ? new ArrayConcat(['this' => $arrays[0], 'expressions' => array_slice($arrays, 1)])
                    : $arrays[0];

                return $this->func('CONCAT_WS', $expression->argList('expressions')[0] ?? null, $array);
            }
        }

        return parent::concatwsSql($expression);
    }

    protected function functionspecificationSql(Expression $expression): string
    {
        $characteristics = $expression->args['characteristics'] ?? null;
        $characteristicsSql = $characteristics instanceof Properties
            ? $this->properties($characteristics, prefix: ' ', sep: ' ', wrapped: false)
            : '';
        $properties = $expression->args['properties'] ?? null;
        $withSql = $properties instanceof Properties ? ' '.$this->withProperties($properties) : '';
        $body = $this->sql($expression, 'expression');

        return 'FUNCTION '.$this->sql($expression, 'this')."{$characteristicsSql}{$withSql} {$body}";
    }

    protected function ifblockSql(Expression $expression): string
    {
        // ELSEIF chains are nested into `false` at parse time, so this flattens them back out
        $branches = [];
        $node = $expression;

        while ($node instanceof IfBlock) {
            $keyword = $branches === [] ? 'IF' : 'ELSEIF';
            $branches[] = "{$keyword} ".$this->sql($node, 'this').' THEN '.$this->sql($node, 'true').';';
            $node = $node->args['false'] ?? null;
        }

        if ($node instanceof Expression) {
            $branches[] = 'ELSE '.$this->sql($node).';';
        }

        return implode(' ', $branches).' END IF';
    }

    protected function casestatementSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $branches = [$this_ !== '' ? "CASE {$this_}" : 'CASE'];

        foreach ($expression->argList('ifs') as $node) {
            $branches[] = 'WHEN '.$this->sql($node, 'this').' THEN '.$this->sql($node, 'true').';';
        }

        $default = $expression->args['default'] ?? null;

        if ($default instanceof Expression) {
            $branches[] = 'ELSE '.$this->sql($default).';';
        }

        $branches[] = 'END CASE';

        return implode(' ', $branches);
    }

    private function labelSql(Expression $expression): string
    {
        $label = $expression->args['label'] ?? null;

        return $label instanceof Expression ? $this->sql($label).': ' : '';
    }

    protected function whileblockSql(Expression $expression): string
    {
        return $this->labelSql($expression).'WHILE '.$this->sql($expression, 'this').' DO '.$this->sql($expression, 'body').'; END WHILE';
    }

    protected function loopblockSql(Expression $expression): string
    {
        return $this->labelSql($expression).'LOOP '.$this->sql($expression, 'body').'; END LOOP';
    }

    protected function repeatblockSql(Expression $expression): string
    {
        return $this->labelSql($expression).'REPEAT '.$this->sql($expression, 'body').'; UNTIL '.$this->sql($expression, 'until').' END REPEAT';
    }

    protected function leaveSql(Expression $expression): string
    {
        return 'LEAVE '.$this->sql($expression, 'this');
    }

    protected function iterateSql(Expression $expression): string
    {
        return 'ITERATE '.$this->sql($expression, 'this');
    }

    protected function jsonextractSql(Expression $expression): string
    {
        if (! $expression->flag('json_query')) {
            return parent::jsonextractSql($expression);
        }

        $jsonPath = $this->sql($expression, 'expression');

        // Trino's JSON_QUERY requires the path to start with a mode specifier. Paths parsed into
        // a JSONPath get the standard default mode; literal paths keep their own mode.
        if (($expression->args['expression'] ?? null) instanceof JSONPath) {
            $quote = $this->dialect->settings['QUOTE_START'];
            $jsonPath = "{$quote}lax ".(str_starts_with($jsonPath, $quote) ? substr($jsonPath, strlen($quote)) : $jsonPath);
        }

        $option = $this->sql($expression, 'option');
        $option = $option !== '' ? " {$option}" : '';

        $quote = $this->sql($expression, 'quote');
        $quote = $quote !== '' ? " {$quote}" : '';

        $onCondition = $this->sql($expression, 'on_condition');
        $onCondition = $onCondition !== '' ? " {$onCondition}" : '';

        return $this->func('JSON_QUERY', $expression->args['this'] ?? null, $jsonPath.$option.$quote.$onCondition);
    }
}
