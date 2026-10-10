<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\StarRocks;

use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySqlParser;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Create;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateDiff;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionByRangeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionByRangePropertyDynamic;
use LaravelSqlParser\LaravelSqlParser\Expressions\PartitionedByProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\PrimaryKey;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Property;
use LaravelSqlParser\LaravelSqlParser\Expressions\RefreshTriggerProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\RollupIndex;
use LaravelSqlParser\LaravelSqlParser\Expressions\RollupProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableFromRows;
use LaravelSqlParser\LaravelSqlParser\Expressions\UniqueKeyProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;

/**
 * StarRocks parser (sqlglot parsers/starrocks.py).
 */
class StarRocksParser extends MySqlParser
{
    public const string TABLES = StarRocksParserTables::class;

    /**
     * DATEDIFF(a, b) is a day difference; DATE_DIFF(unit, a, b) takes the unit first.
     *
     * @param  list<mixed>  $args
     */
    protected function buildDatediff(array $args): Expression
    {
        if ($this->functionBuilderName === 'DATE_DIFF') {
            return new DateDiff(['this' => $args[1] ?? null, 'expression' => $args[2] ?? null, 'unit' => $args[0] ?? null]);
        }

        return new DateDiff(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'unit' => Literal::string('DAY')]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTable(array $args): Expression
    {
        return new TableFromRows(['this' => $args[0] ?? null]);
    }

    protected function propertyUnique(): Expression
    {
        return $this->parseCompositeKeyProperty(UniqueKeyProperty::class);
    }

    protected function parseRollupProperty(): Expression
    {
        $parseRollupIndex = function (): Expression {
            $this_ = $this->parseIdVar();
            $expressions = $this->parseWrappedIdVars();
            $fromIndex = $this->matchTextSeq('FROM') ? $this->parseIdVar() : null;
            $properties = $this->matchTextSeq('PROPERTIES')
                ? $this->expression(new Properties(['expressions' => $this->parseWrappedProperties()]))
                : null;

            return $this->expression(new RollupIndex([
                'this' => $this_,
                'expressions' => $expressions,
                'from_index' => $fromIndex,
                'properties' => $properties,
            ]));
        };

        return $this->expression(new RollupProperty(['expressions' => $this->parseWrappedCsv($parseRollupIndex)]));
    }

    protected function parseCreate(): Expression
    {
        $create = parent::parseCreate();

        // StarRocks' primary key is defined outside of the schema, so we need to move it there
        if ($create instanceof Create && ($create->args['this'] ?? null) instanceof Schema) {
            $props = $create->args['properties'] ?? null;

            if ($props instanceof Expression) {
                $primaryKey = $props->find(PrimaryKey::class);

                if ($primaryKey !== null) {
                    $create->args['this']->append('expressions', $primaryKey->pop());
                }
            }
        }

        return $create;
    }

    protected function parseUnnest(bool $withAlias = true): ?Unnest
    {
        $unnest = parent::parseUnnest($withAlias);

        if ($unnest !== null) {
            $alias = $unnest->args['alias'] ?? null;

            if (! $alias instanceof Expression) {
                // StarRocks defaults to naming the table alias as "unnest"
                $unnest->set('alias', new TableAlias([
                    'this' => Builder::toIdentifier('unnest'),
                    'columns' => [Builder::toIdentifier('unnest')],
                ]));
            } elseif (! $alias->flag('columns')) {
                // StarRocks defaults to naming the UNNEST column as "unnest"
                $alias->set('columns', [Builder::toIdentifier('unnest')]);
            }
        }

        return $unnest;
    }

    protected function parsePartitionedBy(): PartitionedByProperty
    {
        return $this->expression(new PartitionedByProperty([
            'this' => new Schema(['expressions' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseAssignment(), optional: true)]),
        ]));
    }

    /**
     * @return Expression|list<Expression>|null
     */
    protected function parsePartitionProperty(): Expression|array|null
    {
        $expr = parent::parsePartitionProperty();

        if ($expr === null || $expr === []) {
            return $this->parsePartitionedBy();
        }

        if ($expr instanceof Property) {
            return $expr;
        }

        $this->matchLParen();

        $createExpressions = $this->peekTextSeq('START')
            ? $this->parseCsv(fn (): Expression => $this->parsePartitioningGranularityDynamic())
            : null;

        $this->matchRParen();

        return $this->expression(new PartitionByRangeProperty(['partition_expressions' => $expr, 'create_expressions' => $createExpressions]));
    }

    protected function parsePartitioningGranularityDynamic(): PartitionByRangePropertyDynamic
    {
        $this->matchTextSeq('START');
        $start = $this->parseWrapped(fn (): ?Expression => $this->parseString());
        $this->matchTextSeq('END');
        $end = $this->parseWrapped(fn (): ?Expression => $this->parseString());
        $this->matchTextSeq('EVERY');
        $every = $this->parseWrapped(fn (): ?Expression => $this->parseInterval() ?? $this->parseNumber());

        return $this->expression(new PartitionByRangePropertyDynamic(['start' => $start, 'end' => $end, 'every' => $every]));
    }

    protected function parseRefreshProperty(): Expression
    {
        $method = $this->matchTexts(['DEFERRED' => true, 'IMMEDIATE' => true]) ? strtoupper($this->prevText()) : null;
        $kind = $this->matchTexts(['ASYNC' => true, 'MANUAL' => true]) ? strtoupper($this->prevText()) : null;
        $start = $this->matchTextSeq('START') ? $this->parseWrapped(fn (): ?Expression => $this->parseString()) : null;

        if ($this->matchTextSeq('EVERY')) {
            $this->matchLParen();
            $this->matchTextSeq('INTERVAL');
            $every = $this->parseNumber();
            $unit = $this->parseVar(anyToken: true);
            $this->matchRParen();
        } else {
            $every = null;
            $unit = null;
        }

        return $this->expression(new RefreshTriggerProperty([
            'method' => $method,
            'kind' => $kind,
            'starts' => $start,
            'every' => $every,
            'unit' => $unit,
        ]));
    }
}
