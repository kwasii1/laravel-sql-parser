<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Generator\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\AggFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\IgnoreNulls;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\Ordered;
use LaravelSqlParser\LaravelSqlParser\Expressions\PropertyEQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Rand;
use LaravelSqlParser\LaravelSqlParser\Expressions\RespectNulls;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Star;
use LaravelSqlParser\LaravelSqlParser\Expressions\Struct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Window;

/**
 * SELECT and its clauses: ordering, windows, subqueries, UNNEST, stars and placeholders
 * (sqlglot generator.py, from null_sql to windowspec_sql).
 */
trait GeneratesQueries
{
    protected function nullSql(Expression $expression): string
    {
        return 'NULL';
    }

    protected function booleanSql(Expression $expression): string
    {
        return $this->truthy($expression->args['this'] ?? null) ? 'TRUE' : 'FALSE';
    }

    protected function boolandSql(Expression $expression): string
    {
        return '(('.$this->sql($expression, 'this').') AND ('.$this->sql($expression, 'expression').'))';
    }

    protected function boolorSql(Expression $expression): string
    {
        return '(('.$this->sql($expression, 'this').') OR ('.$this->sql($expression, 'expression').'))';
    }

    protected function orderSql(Expression $expression, bool $flat = false): string
    {
        $this_ = $this->sql($expression, 'this');
        $this_ = $this_ !== '' ? "{$this_} " : '';
        $siblings = $expression->flag('siblings') ? 'SIBLINGS ' : '';

        return $this->opExpressions("{$this_}ORDER {$siblings}BY", $expression, flat: $this_ !== '' || $flat);
    }

    protected function withfillSql(Expression $expression): string
    {
        $fromSql = $this->sql($expression, 'from_');
        $fromSql = $fromSql !== '' ? " FROM {$fromSql}" : '';
        $toSql = $this->sql($expression, 'to');
        $toSql = $toSql !== '' ? " TO {$toSql}" : '';
        $stepSql = $this->sql($expression, 'step');
        $stepSql = $stepSql !== '' ? " STEP {$stepSql}" : '';

        $interpolatedValues = [];
        foreach ($expression->argList('interpolate') as $e) {
            $interpolatedValues[] = $e instanceof Alias
                ? $this->sql($e, 'alias').' AS '.$this->sql($e, 'this')
                : $this->sql($e, 'this');
        }

        $interpolate = $interpolatedValues !== [] ? ' INTERPOLATE ('.implode(', ', $interpolatedValues).')' : '';

        return "WITH FILL{$fromSql}{$toSql}{$stepSql}{$interpolate}";
    }

    protected function clusterSql(Expression $expression): string
    {
        return $this->opExpressions('CLUSTER BY', $expression);
    }

    protected function clusterpropertySql(Expression $expression): string
    {
        if (($expression->args['this'] ?? null) !== null) {
            $this->unsupported('Unsupported CLUSTER BY '.$this->sql($expression, 'this'));

            return '';
        }

        return 'CLUSTER BY ('.$this->expressions($expression, flat: true).')';
    }

    protected function distributeSql(Expression $expression): string
    {
        return $this->opExpressions('DISTRIBUTE BY', $expression);
    }

    protected function sortSql(Expression $expression): string
    {
        return $this->opExpressions('SORT BY', $expression);
    }

    /**
     * Resolve a bare ORDER BY name against the enclosing SELECT projection, for the NULLS FIRST/LAST
     * CASE simulation: the CASE is evaluated in FROM-clause scope rather than alias scope.
     */
    protected function resolveOrderedForNullOrderingSimulation(Expression $expression): ?Expression
    {
        $this_ = $expression->args['this'] ?? null;

        if (! ($this_ instanceof Column && $this_->text('table') === '')) {
            return null;
        }

        $ancestor = $expression->findAncestor(Select::class, Window::class);

        if (! $ancestor instanceof Select) {
            return null;
        }

        $columnName = $this_->name();
        $matched = [];

        foreach ($ancestor->argList('expressions') as $projection) {
            if ($projection->outputName() === $columnName) {
                $matched[] = $projection instanceof Alias ? $projection->args['this'] : $projection;
            }
        }

        $match = count($matched) === 1 ? $matched[0] : null;

        // Skip the substitution when it would be identical to the existing reference
        if ($match instanceof Column && $match->text('table') === '' && $match->name() === $columnName) {
            return null;
        }

        return $match instanceof Expression ? $match : null;
    }

    protected function orderedSql(Ordered $expression): string
    {
        $desc = $expression->args['desc'] ?? null;
        $isDesc = $this->truthy($desc);
        $asc = ! $isDesc;

        $nullsFirst = $expression->flag('nulls_first');
        $nullsLast = ! $nullsFirst;
        $nullOrdering = $this->dialect->settings['NULL_ORDERING'];
        $nullsAreLarge = $nullOrdering === 'nulls_are_large';
        $nullsAreSmall = $nullOrdering === 'nulls_are_small';
        $nullsAreLast = $nullOrdering === 'nulls_are_last';

        $this_ = $this->sql($expression, 'this');

        $sortOrder = $isDesc ? ' DESC' : ($desc === false ? ' ASC' : '');
        $nullsSortChange = '';

        if ($nullsFirst && (($asc && $nullsAreLarge) || ($isDesc && $nullsAreSmall) || $nullsAreLast)) {
            $nullsSortChange = ' NULLS FIRST';
        } elseif ($nullsLast && (($asc && $nullsAreSmall) || ($isDesc && $nullsAreLarge))) {
            $nullsSortChange = ' NULLS LAST';
        }

        $nullOrderingSupported = $this->t['NULL_ORDERING_SUPPORTED'];

        // If the NULLS FIRST/LAST clause is unsupported, we add another sort key to simulate it
        if ($nullsSortChange !== '' && $nullOrderingSupported !== true) {
            $window = $expression->findAncestor(Window::class, Select::class);

            if ($window instanceof Window) {
                $windowThis = $window->args['this'] ?? null;

                if ($windowThis instanceof IgnoreNulls || $windowThis instanceof RespectNulls) {
                    $windowThis = $windowThis->args['this'] ?? null;
                }

                $spec = $window->args['spec'] ?? null;
            } else {
                $windowThis = null;
                $spec = null;
            }

            // Some window functions (e.g. LAST_VALUE, RANK) support NULLS FIRST/LAST
            // without a spec or with a ROWS spec, but not with RANGE
            if (! (
                $windowThis instanceof Expression
                && $this->isInstanceOfAny($windowThis, $this->t['WINDOW_FUNCS_WITH_NULL_ORDERING'])
                && (! $spec instanceof Expression || strtoupper($spec->text('kind')) === 'ROWS')
            )) {
                if ($windowThis instanceof Expression && $spec instanceof Expression) {
                    $this->unsupported("'".trim($nullsSortChange)."' translation not supported in window function ".$windowThis::sqlName());
                    $nullsSortChange = '';
                } elseif (
                    $nullOrderingSupported === false
                    && (($asc && $nullsSortChange === ' NULLS LAST') || ($isDesc && $nullsSortChange === ' NULLS FIRST'))
                ) {
                    // BigQuery does not allow these ordering/nulls combinations when used under
                    // an aggregation func or under a window containing one
                    $ancestor = $expression->findAncestor(AggFunc::class, Window::class, Select::class);

                    if ($ancestor instanceof Window) {
                        $ancestor = $ancestor->args['this'] ?? null;
                    }

                    if ($ancestor instanceof AggFunc && $ancestor instanceof Expression) {
                        $this->unsupported("'".trim($nullsSortChange)."' translation not supported for aggregate function ".$ancestor::sqlName()." with {$sortOrder} sort order");
                        $nullsSortChange = '';
                    }
                } elseif ($nullOrderingSupported === null) {
                    $ordered = $expression->args['this'] ?? null;

                    if ($ordered instanceof Expression && $ordered->isInt()) {
                        $this->unsupported("'".trim($nullsSortChange)."' translation not supported with positional ordering");
                    } elseif (! $ordered instanceof Rand) {
                        $resolved = $this->resolveOrderedForNullOrderingSimulation($expression);
                        $target = $resolved !== null ? $this->sql($resolved) : $this_;
                        $nullSortOrder = $nullsSortChange === ' NULLS FIRST' ? ' DESC' : '';
                        $this_ = "CASE WHEN {$target} IS NULL THEN 1 ELSE 0 END{$nullSortOrder}, {$target}";
                    }

                    $nullsSortChange = '';
                }
            }
        }

        $withFill = $this->sql($expression, 'with_fill');
        $withFill = $withFill !== '' ? " {$withFill}" : '';

        return "{$this_}{$sortOrder}{$nullsSortChange}{$withFill}";
    }

    protected function matchrecognizemeasureSql(Expression $expression): string
    {
        $windowFrame = $this->sql($expression, 'window_frame');
        $windowFrame = $windowFrame !== '' ? "{$windowFrame} " : '';

        return $windowFrame.$this->sql($expression, 'this');
    }

    protected function matchrecognizeSql(Expression $expression): string
    {
        $partition = $this->partitionBySql($expression);
        $order = $this->sql($expression, 'order');
        $measures = $this->expressions($expression, key: 'measures');
        $measures = $measures !== '' ? $this->seg('MEASURES'.$this->seg($measures)) : '';
        $rows = $this->sql($expression, 'rows');
        $rows = $rows !== '' ? $this->seg($rows) : '';
        $after = $this->sql($expression, 'after');
        $after = $after !== '' ? $this->seg($after) : '';
        $pattern = $this->sql($expression, 'pattern');
        $pattern = $pattern !== '' ? $this->seg("PATTERN ({$pattern})") : '';

        $definitionSqls = [];
        foreach ($expression->argList('define') as $definition) {
            $definitionSqls[] = $this->sql($definition, 'alias').' AS '.$this->sql($definition, 'this');
        }

        $definitions = $this->expressions(sqls: $definitionSqls);
        $define = $definitions !== '' ? $this->seg('DEFINE'.$this->seg($definitions)) : '';
        $body = $partition.$order.$measures.$rows.$after.$pattern.$define;
        $alias = $this->sql($expression, 'alias');
        $alias = $alias !== '' ? " {$alias}" : '';

        return $this->seg('MATCH_RECOGNIZE').' '.$this->wrap($body).$alias;
    }

    protected function optionsModifier(Expression $expression): string
    {
        $options = $this->expressions($expression, key: 'options');

        return $options !== '' ? " {$options}" : '';
    }

    protected function forclauseSql(Expression $expression): string
    {
        $kind = $expression->text('kind');

        if ($kind === 'BROWSE') {
            return $this->sep().'FOR BROWSE';
        }

        // FOR XML/JSON always carry at least AUTO/PATH. An empty rendering means
        // the target dialect doesn't support QueryOption, so we drop the clause.
        $options = $this->expressions($expression, key: 'expressions');

        if ($options === '') {
            return '';
        }

        return $this->sep()."FOR {$kind}".$this->seg($options);
    }

    protected function queryoptionSql(Expression $expression): string
    {
        $this->unsupported('Unsupported query option.');

        return '';
    }

    protected function selectSql(Select $expression): string
    {
        $into = $expression->args['into'] ?? null;

        if ($this->t['SUPPORTS_SELECT_INTO'] !== true && $into instanceof Expression) {
            $into->pop();
        }

        $hint = $this->sql($expression, 'hint');
        $distinct = $this->sql($expression, 'distinct');
        $distinct = $distinct !== '' ? " {$distinct}" : '';
        $kind = $this->sql($expression, 'kind');

        $limit = $expression->args['limit'] ?? null;

        if ($limit instanceof Limit && $this->t['LIMIT_IS_TOP'] === true) {
            $top = $this->limitSql($limit, top: true);
            $limit->pop();
        } else {
            $top = '';
        }

        $expressions = $this->expressions($expression);

        if ($kind !== '') {
            if (isset($this->t['SELECT_KINDS'][$kind]) || in_array($kind, $this->t['SELECT_KINDS'], true)) {
                $kind = " AS {$kind}";
            } else {
                if ($kind === 'STRUCT') {
                    $fields = [];
                    foreach ($expression->argList('expressions') as $e) {
                        $fields[] = $e instanceof Alias
                            ? new PropertyEQ(['this' => $e->args['alias'] ?? null, 'expression' => $e->args['this'] ?? null])
                            : $e;
                    }

                    $expressions = $this->expressions(sqls: [$this->sql(new Struct(['expressions' => $fields]))]);
                }

                $kind = '';
            }
        }

        $operationModifiers = $this->expressions($expression, key: 'operation_modifiers', sep: ' ');
        $operationModifiers = $operationModifiers !== '' ? $this->sep().$operationModifiers : '';

        $exclude = $expression->argList('exclude');

        if ($this->t['STAR_EXCLUDE_REQUIRES_DERIVED_TABLE'] !== true && $exclude !== []) {
            $excludeSql = $this->expressions(sqls: $exclude, flat: true);
            $expressions = "{$expressions}".$this->seg('EXCLUDE')." ({$excludeSql})";
        }

        // We use LIMIT_IS_TOP as a proxy for whether DISTINCT should go first because tsql and Teradata
        // are the only dialects that use LIMIT_IS_TOP and both place DISTINCT first.
        $topDistinct = $this->t['LIMIT_IS_TOP'] === true ? "{$distinct}{$hint}{$top}" : "{$top}{$hint}{$distinct}";
        $expressions = $expressions !== '' ? $this->sep().$expressions : $expressions;
        $sql = $this->queryModifiers(
            $expression,
            "SELECT{$topDistinct}{$operationModifiers}{$kind}{$expressions}",
            $this->sql($expression->args['into'] ?? null, comment: false),
            $this->sql($expression->args['from_'] ?? null, comment: false),
        );

        // If both the CTE and SELECT clauses have comments, generate the latter earlier
        if (($expression->args['with_'] ?? null) !== null) {
            $sql = $this->maybeComment($sql, $expression);
            $expression->popComments();
        }

        $sql = $this->prependCtes($expression, $sql);

        if ($this->t['STAR_EXCLUDE_REQUIRES_DERIVED_TABLE'] === true && $exclude !== []) {
            $expression->set('exclude', null);
            $subquery = $expression->subquery(copy: false);
            $star = new Star(['except_' => $exclude]);
            $sql = $this->sql(new Select(['expressions' => [$star], 'from_' => new From(['this' => $subquery])]));
        }

        if ($this->t['SUPPORTS_SELECT_INTO'] !== true && $into instanceof Expression) {
            if ($into->flag('temporary')) {
                $tableKind = ' TEMPORARY';
            } elseif ($this->t['SUPPORTS_UNLOGGED_TABLES'] === true && $into->flag('unlogged')) {
                $tableKind = ' UNLOGGED';
            } else {
                $tableKind = '';
            }

            $sql = "CREATE{$tableKind} TABLE ".$this->sql($into->args['this'] ?? null)." AS {$sql}";
        }

        return $sql;
    }

    protected function schemaSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $sql = $this->schemaColumnsSql($expression);

        return $this_ !== '' && $sql !== '' ? "{$this_} {$sql}" : ($this_ !== '' ? $this_ : $sql);
    }

    protected function schemaColumnsSql(Expression $expression): string
    {
        if ($expression->argList('expressions') !== []) {
            return '('.$this->sep('').$this->expressions($expression).$this->seg(')', sep: '');
        }

        return '';
    }

    protected function starSql(Expression $expression): string
    {
        $except = $this->expressions($expression, key: 'except_', flat: true);
        $except = $except !== '' ? $this->seg($this->t['STAR_EXCEPT'])." ({$except})" : '';
        $replace = $this->expressions($expression, key: 'replace', flat: true);
        $replace = $replace !== '' ? $this->seg('REPLACE')." ({$replace})" : '';
        $rename = $this->expressions($expression, key: 'rename', flat: true);
        $rename = $rename !== '' ? $this->seg('RENAME')." ({$rename})" : '';
        $ilike = $this->sql($expression, 'ilike');
        $ilike = $ilike !== '' ? $this->seg('ILIKE')." {$ilike}" : '';

        return "*{$ilike}{$except}{$replace}{$rename}";
    }

    protected function parameterSql(Expression $expression): string
    {
        return $this->t['PARAMETER_TOKEN'].$this->sql($expression, 'this');
    }

    protected function sessionparameterSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $kind = $expression->text('kind');

        if ($kind !== '') {
            $kind = "{$kind}.";
        }

        return "@@{$kind}{$this_}";
    }

    protected function placeholderSql(Expression $expression): string
    {
        return $this->truthy($expression->args['this'] ?? null) ? $this->t['NAMED_PLACEHOLDER_TOKEN'].$expression->name() : '?';
    }

    protected function subquerySql(Expression $expression, string $sep = ' AS '): string
    {
        $alias = $this->sql($expression, 'alias');
        $alias = $alias !== '' ? "{$sep}{$alias}" : '';
        $sample = $this->sql($expression, 'sample');

        if ($this->dialect->settings['ALIAS_POST_TABLESAMPLE'] === true && $sample !== '') {
            $alias = "{$sample}{$alias}";

            // Set to None so it's not generated again by queryModifiers()
            $expression->set('sample', null);
        }

        $pivots = $this->expressions($expression, key: 'pivots', sep: '', flat: true);
        $sql = $this->queryModifiers($expression, $this->wrap($expression), $alias, $pivots);

        return $this->prependCtes($expression, $sql);
    }

    protected function qualifySql(Expression $expression): string
    {
        $this_ = $this->indent($this->sql($expression, 'this'));

        return $this->seg('QUALIFY').$this->sep().$this_;
    }

    protected function unnestSql(Expression $expression): string
    {
        $args = $this->expressions($expression, flat: true);

        $alias = $expression->args['alias'] ?? null;
        $offset = $expression->args['offset'] ?? null;

        if ($this->t['UNNEST_WITH_ORDINALITY'] === true && $alias instanceof Expression && $offset instanceof Expression) {
            $alias->append('columns', $offset);
            $expression->set('offset', null);
        }

        if ($alias instanceof Expression && $this->dialect->settings['UNNEST_COLUMN_ONLY'] === true) {
            $columns = $alias->argList('columns');
            $aliasSql = $columns !== [] ? $this->sql($columns[0]) : '';
        } else {
            $aliasSql = $this->sql($alias);
        }

        $aliasSql = $aliasSql !== '' ? " AS {$aliasSql}" : $aliasSql;

        if ($this->t['UNNEST_WITH_ORDINALITY'] === true) {
            $suffix = $this->truthy($offset) ? " WITH ORDINALITY{$aliasSql}" : $aliasSql;
        } elseif ($offset instanceof Expression) {
            $suffix = "{$aliasSql} WITH OFFSET AS ".$this->sql($offset);
        } elseif ($this->truthy($offset)) {
            $suffix = "{$aliasSql} WITH OFFSET";
        } else {
            $suffix = $aliasSql;
        }

        return "UNNEST({$args}){$suffix}";
    }

    protected function prewhereSql(Expression $expression): string
    {
        return '';
    }

    protected function whereSql(Expression $expression): string
    {
        $this_ = $this->indent($this->sql($expression, 'this'));

        return $this->seg('WHERE').$this->sep().$this_;
    }

    protected function windowSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $partition = $this->partitionBySql($expression);
        $order = $expression->args['order'] ?? null;
        $order = $order instanceof Expression ? $this->orderSql($order, flat: true) : '';
        $spec = $this->sql($expression, 'spec');
        $alias = $this->sql($expression, 'alias');
        $over = $this->sql($expression, 'over');
        $over = $over !== '' ? $over : 'OVER';

        $this_ = "{$this_} ".($expression->argKey === 'windows' ? 'AS' : $over);

        $first = $expression->args['first'] ?? null;
        $first = $first === null ? '' : ($this->truthy($first) ? 'FIRST' : 'LAST');

        if ($partition === '' && $order === '' && $spec === '' && $alias !== '') {
            return "{$this_} {$alias}";
        }

        $args = $this->formatArgs(array_values(array_filter([$alias, $first, $partition, $order, $spec], static fn (string $arg): bool => $arg !== '')), sep: ' ');

        return "{$this_} ({$args})";
    }

    public function partitionBySql(Expression $expression): string
    {
        $partition = $this->expressions($expression, key: 'partition_by', flat: true);

        return $partition !== '' ? "PARTITION BY {$partition}" : '';
    }

    protected function windowspecSql(Expression $expression): string
    {
        $kind = $this->sql($expression, 'kind');
        $start = self::csvWith(' ', $this->sql($expression, 'start'), $this->sql($expression, 'start_side'));
        $end = self::csvWith(' ', $this->sql($expression, 'end'), $this->sql($expression, 'end_side'));
        $end = $end !== '' ? $end : 'CURRENT ROW';

        $windowSpec = "{$kind} BETWEEN {$start} AND {$end}";

        $exclude = $this->sql($expression, 'exclude');

        if ($exclude !== '') {
            if ($this->t['SUPPORTS_WINDOW_EXCLUDE'] === true) {
                $windowSpec .= " EXCLUDE {$exclude}";
            } else {
                $this->unsupported('EXCLUDE clause is not supported in the WINDOW clause');
            }
        }

        return $windowSpec;
    }
}
