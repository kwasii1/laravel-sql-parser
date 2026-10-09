<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Parser\Concerns;

use LaravelSqlParser\LaravelSqlParser\Errors\ParseError;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\CTE;
use LaravelSqlParser\LaravelSqlParser\Expressions\Distinct;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\From;
use LaravelSqlParser\LaravelSqlParser\Expressions\FunctionSpecification;
use LaravelSqlParser\LaravelSqlParser\Expressions\Hint;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Index;
use LaravelSqlParser\LaravelSqlParser\Expressions\IndexParameters;
use LaravelSqlParser\LaravelSqlParser\Expressions\IndexTableHint;
use LaravelSqlParser\LaravelSqlParser\Expressions\Into;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lateral;
use LaravelSqlParser\LaravelSqlParser\Expressions\MatchRecognize;
use LaravelSqlParser\LaravelSqlParser\Expressions\MatchRecognizeMeasure;
use LaravelSqlParser\LaravelSqlParser\Expressions\Offset;
use LaravelSqlParser\LaravelSqlParser\Expressions\Opclass;
use LaravelSqlParser\LaravelSqlParser\Expressions\Query;
use LaravelSqlParser\LaravelSqlParser\Expressions\RecursiveWithSearch;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Expressions\Stream;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Summarize;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Values;
use LaravelSqlParser\LaravelSqlParser\Expressions\With;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithTableHint;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * SELECT, WITH, query modifiers, FROM, joins and indexes
 * (sqlglot parser.py, from _parse_projections to _parse_table_part).
 */
trait ParsesQueries
{
    /**
     * @return array{0: list<Expression>, 1: list<Expression>|null}
     */
    protected function parseProjections(): array
    {
        return [$this->parseExpressions(), null];
    }

    protected function parseWrappedSelect(bool $table = false): ?Expression
    {
        if ($this->matchSet([TokenType::PIVOT => true, TokenType::UNPIVOT => true])) {
            $this_ = $this->parseSimplifiedPivot(isUnpivot: $this->prev === TokenType::UNPIVOT);
        } elseif ($this->match(TokenType::FROM)) {
            $from = $this->parseFrom(joins: true, skipFromToken: true, consumePipe: true);
            // Support parentheses for duckdb FROM-first syntax
            $select = $this->parseSelect(from: $from);

            if ($select !== null) {
                if (! isset($select->args['from_'])) {
                    $select->set('from_', $from);
                }
                $this_ = $select;
            } else {
                $this_ = Builder::selectStarFrom($from ?? new From);
                $this_ = $this->parseQueryModifiers($this->parseSetOperations($this_));
            }
        } else {
            $this_ = $table
                ? $this->parseTable(consumePipe: true)
                : $this->parseSelect(nested: true, parseSetOperation: false);

            // Transform exp.Values into a exp.Table to pass through parse_query_modifiers
            // in case a modifier (e.g. join) is following
            if ($table && $this_ instanceof Values && $this_->alias() !== '') {
                $alias = $this_->argExpr('alias')?->pop();
                $this_ = new Table(['this' => $this_, 'alias' => $alias]);
            }

            $this_ = $this->parseQueryModifiers($this->parseSetOperations($this_));
        }

        return $this_;
    }

    protected function parseSelect(
        bool $nested = false,
        bool $table = false,
        bool $parseSubqueryAlias = true,
        bool $parseSetOperation = true,
        bool $consumePipe = true,
        ?From $from = null,
    ): ?Expression {
        $query = $this->parseSelectQuery(
            nested: $nested,
            table: $table,
            parseSubqueryAlias: $parseSubqueryAlias,
            parseSetOperation: $parseSetOperation,
        );

        if ($consumePipe && $this->match(TokenType::PIPE_GT, false)) {
            if ($query === null && $from !== null) {
                $query = Builder::selectStarFrom($from);
            }

            if ($query instanceof Query) {
                $query = $this->parsePipeSyntaxQuery($query);

                if ($query instanceof Query && $table) {
                    $query = $query->subquery(copy: false);
                }
            }
        }

        return $query;
    }

    protected function parseSelectQuery(
        bool $nested = false,
        bool $table = false,
        bool $parseSubqueryAlias = true,
        bool $parseSetOperation = true,
    ): ?Expression {
        $cte = $this->parseWith();

        if ($cte !== null) {
            $this_ = $this->parseStatement();

            if ($this_ === null) {
                $this->raiseError('Failed to parse any statement following CTE');

                return $cte;
            }

            while ($this_ instanceof Subquery && $this_->isWrapper()) {
                $this_ = $this_->args['this'];
            }

            if (array_key_exists('with_', $this_::ARG_TYPES)) {
                $innerCte = $this_->argExpr('with_');

                if ($innerCte !== null) {
                    $cte->set('expressions', [...$cte->argList('expressions'), ...$innerCte->argList('expressions')]);

                    if ($innerCte->flag('recursive')) {
                        $cte->set('recursive', true);
                    }
                }

                $this_->set('with_', $cte);
            } else {
                $this->raiseError($this_::KEY.' does not support CTE');
                $this_ = $cte;
            }

            return $this_;
        }

        // duckdb supports leading with FROM x
        $from = $this->match(TokenType::FROM, false) ? $this->parseFrom(joins: true, consumePipe: true) : null;

        if ($this->match(TokenType::SELECT)) {
            $comments = $this->prevComments;

            $hint = $this->parseHint();

            if ($this->next !== 0 && $this->next !== TokenType::DOT) {
                $all = $this->match(TokenType::ALL);
                $matchedDistinct = $this->matchSet($this->t['DISTINCT_TOKENS']);
            } else {
                $all = null;
                $matchedDistinct = false;
            }

            $kind = $this->match(TokenType::ALIAS) && $this->matchTexts(['STRUCT' => true, 'VALUE' => true])
                ? strtoupper($this->prevText())
                : null;

            $distinct = $matchedDistinct
                ? $this->expression(new Distinct(['on' => $this->match(TokenType::ON) ? $this->parseValue(values: false) : null]))
                : null;

            $operationModifiers = [];
            while ($this->curr !== 0 && $this->matchTexts($this->t['OPERATION_MODIFIERS'])) {
                $operationModifiers[] = Builder::var(strtoupper($this->prevText()));
            }

            $limit = $this->parseLimit(top: true);

            // Some dialects (e.g. Redshift, T-SQL) allow SELECT TOP N DISTINCT ...
            if ($limit !== null && ! $matchedDistinct && $all !== true) {
                $matchedDistinct = $this->matchSet($this->t['DISTINCT_TOKENS']);

                if ($matchedDistinct) {
                    $distinct = $this->expression(new Distinct(['on' => $this->match(TokenType::ON) ? $this->parseValue(values: false) : null]));
                } else {
                    $all = $this->match(TokenType::ALL);
                }
            }

            if ($all === true && $distinct !== null) {
                $this->raiseError('Cannot specify both ALL and DISTINCT after SELECT');
            }

            [$projections, $exclude] = $this->parseProjections();

            $this_ = $this->expression(new Select([
                'kind' => $kind,
                'hint' => $hint,
                'distinct' => $distinct,
                'expressions' => $projections,
                'limit' => $limit,
                'exclude' => $exclude,
                'operation_modifiers' => $operationModifiers !== [] ? $operationModifiers : null,
            ]));
            $this_->comments = $comments !== [] ? $comments : null;

            $into = $this->parseIntoClause();

            if ($into !== null) {
                $this_->set('into', $into);
            }

            if ($from === null) {
                $from = $this->parseFrom();
            }

            if ($from !== null) {
                $this_->set('from_', $from);
            }

            $this_ = $this->parseQueryModifiers($this_);
        } elseif (($table || $nested) && $this->match(TokenType::L_PAREN)) {
            $comments = $this->prevComments;
            $this_ = $this->parseWrappedSelect(table: $table);

            $this_?->addComments($comments, prepend: true);

            // We return early here so that the UNION isn't attached to the subquery by the
            // following call to _parse_set_operations, but instead becomes the parent node
            $this->matchRParen();

            return $this->parseSubquery($this_, parseAlias: $parseSubqueryAlias);
        } elseif ($this->match(TokenType::VALUES, false)) {
            $this_ = $this->parseDerivedTableValues();
        } elseif ($from !== null) {
            $this_ = Builder::selectStarFrom($from->args['this']);
            $this_ = $this->parseQueryModifiers($this_);
        } elseif ($this->match(TokenType::SUMMARIZE)) {
            $table = $this->match(TokenType::TABLE);
            $this_ = $this->parseSelect() ?? $this->parseString() ?? $this->parseTable();

            return $this->expression(new Summarize(['this' => $this_, 'table' => $table]));
        } elseif ($this->match(TokenType::DESCRIBE)) {
            $this_ = $this->parseDescribe();
        } else {
            $this_ = null;
        }

        return $parseSetOperation ? $this->parseSetOperations($this_) : $this_;
    }

    protected function parseRecursiveWithSearch(): ?RecursiveWithSearch
    {
        $this->matchTextSeq('SEARCH');

        $kind = $this->matchTexts($this->t['RECURSIVE_CTE_SEARCH_KIND']) ? strtoupper($this->prevText()) : null;

        if ($kind === null) {
            return null;
        }

        $this->matchTextSeq('FIRST', 'BY');

        return $this->expression(new RecursiveWithSearch([
            'kind' => $kind,
            'this' => $this->parseIdVar(),
            'expression' => $this->matchTextSeq('SET') ? $this->parseIdVar() : null,
            'using' => $this->matchTextSeq('USING') ? $this->parseIdVar() : null,
        ]));
    }

    protected function parseWith(bool $skipWithToken = false): ?With
    {
        if (! $skipWithToken && ! $this->match(TokenType::WITH)) {
            return null;
        }

        $comments = $this->prevComments;
        $recursive = $this->match(TokenType::RECURSIVE);

        $lastComments = null;
        $expressions = [];
        $udfs = [];

        while (true) {
            $cte = $this->parseCte();

            if ($cte !== null) {
                if ($cte instanceof FunctionSpecification) {
                    $udfs[] = $cte;
                } else {
                    $expressions[] = $cte;
                }

                if ($lastComments !== null && $lastComments !== []) {
                    $cte->addComments($lastComments);
                }
            }

            if (! $this->match(TokenType::COMMA) && ! $this->match(TokenType::WITH)) {
                break;
            }

            $this->match(TokenType::WITH);
            $recursive = $this->match(TokenType::RECURSIVE) || $recursive;

            $lastComments = $this->prevComments;
        }

        return $this->expression(new With([
            'expressions' => $expressions,
            'recursive' => $recursive ? true : null,
            'search' => $this->parseRecursiveWithSearch(),
            'udfs' => $udfs !== [] ? $udfs : null,
        ]), null, $comments);
    }

    protected function parseCte(): ?Expression
    {
        $index = $this->index;

        $alias = $this->parseTableAlias($this->idVarTokens);

        if ($alias === null || $alias->argExpr('this') === null) {
            $this->raiseError('Expected CTE to have alias');
        }

        $keyExpressions = $this->matchTextSeq('USING', 'KEY') ? $this->parseWrappedIdVars() : null;

        if (! $this->match(TokenType::ALIAS) && $this->t['OPTIONAL_ALIAS_TOKEN_CTE'] !== true) {
            $this->retreat($index);

            return null;
        }

        $comments = $this->prevComments;

        if ($this->matchTextSeq('NOT', 'MATERIALIZED')) {
            $materialized = false;
        } elseif ($this->matchTextSeq('MATERIALIZED')) {
            $materialized = true;
        } else {
            $materialized = null;
        }

        $cte = $this->expression(new CTE([
            'this' => $this->parseWrapped(fn (): ?Expression => $this->parseStatement()),
            'alias' => $alias,
            'materialized' => $materialized,
            'key_expressions' => $keyExpressions,
        ]), null, $comments);

        $values = $cte->args['this'] ?? null;

        if ($values instanceof Values) {
            $cte->set('this', $this->valuesToSelect($values));
        }

        return $cte;
    }

    protected function valuesToSelect(Values $values): Select
    {
        if ($values->alias() !== '') {
            return Builder::selectStarFrom($values);
        }

        return Builder::selectStarFrom(Builder::alias($values, '_values', table: true));
    }

    /**
     * @param  array<int, true>|null  $aliasTokens
     */
    protected function parseTableAlias(?array $aliasTokens = null): ?TableAlias
    {
        // In some dialects, LIMIT and OFFSET can act as both identifiers and keywords (clauses)
        // so this section tries to parse the clause version and if it fails, it treats the token
        // as an identifier (alias)
        if ($this->canParseLimitOrOffset()) {
            return null;
        }

        // START is never treated as an implicit alias when followed by WITH, since that
        // would swallow the beginning of a START WITH ... CONNECT BY clause
        if (strtoupper($this->currText()) === 'START' && strtoupper($this->nextText()) === 'WITH') {
            return null;
        }

        $anyToken = $this->match(TokenType::ALIAS);
        $alias = $this->parseIdVar(anyToken: $anyToken, tokens: $aliasTokens !== null && $aliasTokens !== [] ? $aliasTokens : $this->t['TABLE_ALIAS_TOKENS'])
            ?? $this->parseStringAsIdentifier();

        $index = $this->index;

        if ($this->match(TokenType::L_PAREN)) {
            $columns = $this->parseCsv(fn (): ?Expression => $this->parseFunctionParameter());

            if ($columns !== []) {
                $this->matchRParen();
            } else {
                $this->retreat($index);
            }
        } else {
            $columns = null;
        }

        if ($alias === null && ($columns === null || $columns === [])) {
            return null;
        }

        $tableAlias = $this->expression(new TableAlias(['this' => $alias, 'columns' => $columns]));

        // We bubble up comments from the Identifier to the TableAlias
        if ($alias instanceof Identifier) {
            $tableAlias->addComments($alias->popComments());
        }

        return $tableAlias;
    }

    protected function parseSubquery(?Expression $this_, bool $parseAlias = true): ?Subquery
    {
        if ($this_ === null) {
            return null;
        }

        return $this->expression(new Subquery([
            'this' => $this_,
            'pivots' => $this->parsePivots(),
            'alias' => $parseAlias ? $this->parseTableAlias() : null,
            'sample' => $this->parseTableSample(),
        ]));
    }

    protected function parseQueryModifiers(?Expression $this_): ?Expression
    {
        if ($this_ === null || ! $this->isModifiable($this_)) {
            return $this_;
        }

        foreach ($this->parseJoins() as $join) {
            $this_->append('joins', $join);
        }

        while (($lateral = $this->parseLateral()) !== null) {
            $this_->append('laterals', $lateral);
        }

        while (true) {
            if ($this->matchSet($this->t['QUERY_MODIFIER_PARSERS'], false)) {
                $modifierToken = $this->index;
                $modifierType = $this->curr;

                // Defer LIMIT/FETCH after TOP until a set op is built so it applies to the whole result
                // e.g., SELECT 1 AS x UNION ALL SELECT TOP 2 2 AS x LIMIT 1 -> limit applies to union
                $limit = $this_->argExpr('limit');

                if (($modifierType === TokenType::LIMIT || $modifierType === TokenType::FETCH) && $limit !== null && $limit->metaGet('top') === true) {
                    break;
                }

                [$key, $expression] = $this->callHandler($this->t['QUERY_MODIFIER_PARSERS'][$modifierType]);

                if ($expression !== null && $expression !== false && $expression !== []) {
                    if ($this_->flag($key)) {
                        $this->raiseError("Found multiple '".strtoupper($this->texts[$modifierToken])."' clauses", $modifierToken);
                    }

                    $this_->set($key, $expression);

                    if ($key === 'limit' && $expression instanceof Expression) {
                        $offset = $expression->args['offset'] ?? null;
                        $expression->set('offset', null);

                        if ($offset !== null && $offset !== false) {
                            if ($this_->flag('offset')) {
                                $this->raiseError("Found multiple 'OFFSET' clauses", $modifierToken);
                            }

                            $offset = new Offset(['expression' => $offset]);
                            $this_->set('offset', $offset);

                            $limitByExpressions = $expression->args['expressions'] ?? null;
                            $expression->set('expressions', null);
                            $offset->set('expressions', $limitByExpressions);
                        }
                    }

                    continue;
                }
            }

            if (strtoupper($this->currText()) === 'START') {
                $modifierToken = $this->index;
                $connect = $this->parseConnect();

                if ($connect !== null) {
                    if ($this_->flag('connect')) {
                        $this->raiseError("Found multiple 'START WITH' clauses", $modifierToken);
                    }

                    $this_->set('connect', $connect);

                    continue;
                }
            }

            break;
        }

        if ($this->t['SUPPORTS_IMPLICIT_UNNEST'] === true && $this_->flag('from_')) {
            $this_ = $this->implicitUnnestsToExplicit($this_);
        }

        return $this_;
    }

    /**
     * MODIFIABLES holds class names; subclasses count too (sqlglot uses isinstance).
     */
    protected function isModifiable(Expression $expression): bool
    {
        foreach ($this->t['MODIFIABLES'] as $class) {
            if ($expression instanceof $class) {
                return true;
            }
        }

        return false;
    }

    protected function implicitUnnestsToExplicit(Expression $this_): Expression
    {
        return $this_;
    }

    protected function parseHintFallbackToString(): ?Hint
    {
        $start = $this->index;

        while ($this->curr !== 0) {
            $this->advance();
        }

        $end = $this->index - 1;

        return new Hint(['expressions' => [$this->findSql($start, $end)]]);
    }

    protected function parseHintFunctionCall(): ?Expression
    {
        return $this->parseFunctionCall();
    }

    protected function parseHintBody(): ?Hint
    {
        $startIndex = $this->index;
        $shouldFallbackToString = false;

        $hints = [];

        try {
            while (true) {
                $hint = $this->parseCsv(fn (): ?Expression => $this->parseHintFunctionCall() ?? $this->parseVar(upper: true));

                if ($hint === []) {
                    break;
                }

                array_push($hints, ...$hint);
            }
        } catch (ParseError) {
            $shouldFallbackToString = true;
        }

        if ($shouldFallbackToString || $this->curr !== 0) {
            $this->retreat($startIndex);

            return $this->parseHintFallbackToString();
        }

        return $this->expression(new Hint(['expressions' => $hints]));
    }

    protected function parseHint(): ?Expression
    {
        if ($this->match(TokenType::HINT) && $this->prevComments !== []) {
            return $this->dialect->parseInto(Hint::class, $this->prevComments[0]);
        }

        return null;
    }

    protected function parseIntoClause(): ?Into
    {
        if (! $this->match(TokenType::INTO)) {
            return null;
        }

        $temp = $this->match(TokenType::TEMPORARY);
        $unlogged = $this->matchTextSeq('UNLOGGED');
        $this->match(TokenType::TABLE);

        return $this->expression(new Into([
            'this' => $this->parseTable(schema: true),
            'temporary' => $temp,
            'unlogged' => $unlogged,
        ]));
    }

    protected function parseFrom(bool $joins = false, bool $skipFromToken = false, bool $consumePipe = false): ?From
    {
        if (! $skipFromToken && ! $this->match(TokenType::FROM)) {
            return null;
        }

        $comments = $this->prevComments;

        return $this->expression(new From(['this' => $this->parseTable(joins: $joins, consumePipe: $consumePipe)]), null, $comments);
    }

    protected function parseMatchRecognizeMeasure(): MatchRecognizeMeasure
    {
        return $this->expression(new MatchRecognizeMeasure([
            'window_frame' => $this->matchTexts(['FINAL' => true, 'RUNNING' => true]) ? strtoupper($this->prevText()) : null,
            'this' => $this->parseExpression(),
        ]));
    }

    protected function parseMatchRecognize(): ?MatchRecognize
    {
        if (! $this->match(TokenType::MATCH_RECOGNIZE)) {
            return null;
        }

        $this->matchLParen();

        $partition = $this->parsePartitionBy();
        $order = $this->parseOrder();

        $measures = $this->matchTextSeq('MEASURES')
            ? $this->parseCsv(fn (): Expression => $this->parseMatchRecognizeMeasure())
            : null;

        if ($this->matchTextSeq('ONE', 'ROW', 'PER', 'MATCH')) {
            $rows = Builder::var('ONE ROW PER MATCH');
        } elseif ($this->matchTextSeq('ALL', 'ROWS', 'PER', 'MATCH')) {
            $text = 'ALL ROWS PER MATCH';

            if ($this->matchTextSeq('SHOW', 'EMPTY', 'MATCHES')) {
                $text .= ' SHOW EMPTY MATCHES';
            } elseif ($this->matchTextSeq('OMIT', 'EMPTY', 'MATCHES')) {
                $text .= ' OMIT EMPTY MATCHES';
            } elseif ($this->matchTextSeq('WITH', 'UNMATCHED', 'ROWS')) {
                $text .= ' WITH UNMATCHED ROWS';
            }

            $rows = Builder::var($text);
        } else {
            $rows = null;
        }

        if ($this->matchTextSeq('AFTER', 'MATCH', 'SKIP')) {
            $text = 'AFTER MATCH SKIP';

            if ($this->matchTextSeq('PAST', 'LAST', 'ROW')) {
                $text .= ' PAST LAST ROW';
            } elseif ($this->matchTextSeq('TO', 'NEXT', 'ROW')) {
                $text .= ' TO NEXT ROW';
            } elseif ($this->matchTextSeq('TO', 'FIRST') || $this->matchTextSeq('TO', 'LAST')) {
                $direction = strtoupper($this->prevText());
                $patternVar = $this->advanceAny();

                if ($patternVar === null) {
                    $this->raiseError("Expecting pattern variable after AFTER MATCH SKIP TO {$direction}");
                }

                $text .= " TO {$direction} ".($patternVar !== null ? $this->texts[$patternVar] : '');
            }

            $after = Builder::var($text);
        } else {
            $after = null;
        }

        if ($this->matchTextSeq('PATTERN')) {
            $this->matchLParen();

            if ($this->curr === 0) {
                $this->raiseError('Expecting )');
            }

            $paren = 1;
            $start = $this->index;
            $end = $start;

            while ($this->curr !== 0 && $paren > 0) {
                if ($this->curr === TokenType::L_PAREN) {
                    $paren++;
                }

                if ($this->curr === TokenType::R_PAREN) {
                    $paren--;
                }

                $end = $this->index - 1;
                $this->advance();
            }

            if ($paren > 0) {
                $this->raiseError('Expecting )');
            }

            $pattern = Builder::var($this->findSql($start, $end));
        } else {
            $pattern = null;
        }

        $define = $this->matchTextSeq('DEFINE')
            ? $this->parseCsv(fn (): ?Expression => $this->parseNameAsExpression())
            : null;

        $this->matchRParen();

        return $this->expression(new MatchRecognize([
            'partition_by' => $partition,
            'order' => $order,
            'measures' => $measures,
            'rows' => $rows,
            'after' => $after,
            'pattern' => $pattern,
            'define' => $define,
            'alias' => $this->parseTableAlias(),
        ]));
    }

    protected function parseLateral(): ?Lateral
    {
        $crossApply = null;

        if ($this->matchPair(TokenType::CROSS, TokenType::APPLY)) {
            $crossApply = true;
        } elseif ($this->matchPair(TokenType::OUTER, TokenType::APPLY)) {
            $crossApply = false;
        }

        if ($crossApply !== null) {
            $this_ = $this->parseSelect(table: true);
            $view = null;
            $outer = null;
        } elseif ($this->match(TokenType::LATERAL)) {
            $this_ = $this->parseSelect(table: true);
            $view = $this->match(TokenType::VIEW);
            $outer = $this->match(TokenType::OUTER);
        } else {
            return null;
        }

        if ($this_ === null) {
            $this_ = $this->parseUnnest() ?? $this->parseFunction() ?? $this->parseIdVar(anyToken: false);

            while ($this->match(TokenType::DOT)) {
                $this_ = new Dot([
                    'this' => $this_,
                    'expression' => $this->parseFunction() ?? $this->parseIdVar(anyToken: false),
                ]);
            }
        }

        $ordinality = null;

        if ($view === true) {
            $table = $this->parseIdVar(anyToken: false);
            $columns = $this->match(TokenType::ALIAS) ? $this->parseCsv(fn (): ?Expression => $this->parseIdVar()) : [];
            $tableAlias = $this->expression(new TableAlias(['this' => $table, 'columns' => $columns]));
        } elseif (($this_ instanceof Subquery || $this_ instanceof Unnest) && $this_->alias() !== '') {
            // We move the alias from the lateral's child node to the lateral itself
            $tableAlias = $this_->argExpr('alias')?->pop();
        } else {
            $ordinality = $this->matchPair(TokenType::WITH, TokenType::ORDINALITY);
            $tableAlias = $this->parseTableAlias();
        }

        return $this->expression(new Lateral([
            'this' => $this_,
            'view' => $view,
            'outer' => $outer,
            'alias' => $tableAlias,
            'cross_apply' => $crossApply,
            'ordinality' => $ordinality,
        ]));
    }

    protected function parseStream(): ?Stream
    {
        $index = $this->index;

        if ($this->match(TokenType::STREAM)) {
            $this_ = $this->tryParse(fn (): ?Expression => $this->parseTable());

            if ($this_ !== null) {
                return $this->expression(new Stream(['this' => $this_]));
            }

            $this->retreat($index);
        }

        return null;
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: int|null} token indexes of method, side and kind
     */
    protected function parseJoinParts(): array
    {
        return [
            $this->matchSet($this->t['JOIN_METHODS']) ? $this->index - 1 : null,
            $this->matchSet($this->t['JOIN_SIDES']) ? $this->index - 1 : null,
            $this->matchSet($this->t['JOIN_KINDS']) ? $this->index - 1 : null,
        ];
    }

    /**
     * @return list<Expression>
     */
    protected function parseUsingIdentifiers(): array
    {
        $parseColumnAsIdentifier = function (): ?Expression {
            $this_ = $this->parseColumn();

            if ($this_ instanceof Column) {
                return $this_->argExpr('this');
            }

            return $this_;
        };

        return $this->parseWrappedCsv($parseColumnAsIdentifier, optional: true);
    }

    /**
     * @param  array<int, true>|null  $aliasTokens
     */
    protected function parseJoin(bool $skipJoinToken = false, bool $parseBracket = false, ?array $aliasTokens = null): ?Join
    {
        if ($this->match(TokenType::COMMA)) {
            $table = $this->tryParse(fn (): ?Expression => $this->parseTable(aliasTokens: $aliasTokens));
            $crossJoin = $table !== null ? $this->expression(new Join(['this' => $table])) : null;

            if ($crossJoin !== null && $this->t['JOINS_HAVE_EQUAL_PRECEDENCE'] === true) {
                $crossJoin->set('kind', 'CROSS');
            }

            return $crossJoin;
        }

        $index = $this->index;
        [$method, $side, $kind] = $this->parseJoinParts();
        $directed = $this->matchTextSeq('DIRECTED');
        $hint = $this->matchTexts($this->t['JOIN_HINTS']) ? $this->prevText() : null;
        $join = $this->match(TokenType::JOIN) || ($kind !== null && $this->types[$kind] === TokenType::STRAIGHT_JOIN);
        $joinComments = $this->prevComments;

        if (! $skipJoinToken && ! $join) {
            $this->retreat($index);
            $kind = null;
            $method = null;
            $side = null;
        }

        $outerApply = $this->matchPair(TokenType::OUTER, TokenType::APPLY, false);
        $crossApply = $this->matchPair(TokenType::CROSS, TokenType::APPLY, false);

        if (! $skipJoinToken && ! $join && ! $outerApply && ! $crossApply) {
            return null;
        }

        $kwargs = ['this' => $this->parseTable(aliasTokens: $aliasTokens, parseBracket: $parseBracket)];

        if ($kind !== null && $this->types[$kind] === TokenType::ARRAY && $this->match(TokenType::COMMA)) {
            $kwargs['expressions'] = $this->parseCsv(fn (): ?Expression => $this->parseTable(aliasTokens: $aliasTokens, parseBracket: $parseBracket));
        }

        if ($method !== null) {
            $kwargs['method'] = strtoupper($this->texts[$method]);
        }

        if ($side !== null) {
            $kwargs['side'] = strtoupper($this->texts[$side]);
        }

        if ($kind !== null) {
            $kwargs['kind'] = strtoupper($this->texts[$kind]);
        }

        if ($hint !== null) {
            $kwargs['hint'] = $hint;
        }

        if ($this->match(TokenType::MATCH_CONDITION)) {
            $kwargs['match_condition'] = $this->parseWrapped(fn (): ?Expression => $this->parseComparison());
        }

        if ($this->match(TokenType::ON)) {
            $kwargs['on'] = $this->parseDisjunction();
        } elseif ($this->match(TokenType::USING)) {
            $kwargs['using'] = $this->parseUsingIdentifiers();
        } elseif (
            $method === null
            && ! ($outerApply || $crossApply)
            && ! $kwargs['this'] instanceof Unnest
            && ! ($kind !== null && in_array($this->types[$kind], [TokenType::CROSS, TokenType::ARRAY], true))
        ) {
            $index = $this->index;
            $joins = $this->parseJoins(aliasTokens: $aliasTokens);

            if ($joins !== [] && $this->match(TokenType::ON)) {
                $kwargs['on'] = $this->parseDisjunction();
            } elseif ($joins !== [] && $this->match(TokenType::USING)) {
                $kwargs['using'] = $this->parseUsingIdentifiers();
            } else {
                $joins = null;
                $this->retreat($index);
            }

            $kwargs['this']?->set('joins', $joins);
        }

        $kwargs['pivots'] = $this->parsePivots();

        $comments = $joinComments;
        foreach ([$method, $side, $kind] as $token) {
            if ($token !== null) {
                array_push($comments, ...($this->tokenComments[$token] ?? []));
            }
        }

        if (
            $this->t['ADD_JOIN_ON_TRUE'] === true
            && ! isset($kwargs['on'])
            && ! isset($kwargs['using'])
            && ! isset($kwargs['method'])
            && in_array($kwargs['kind'] ?? null, [null, 'INNER', 'OUTER'], true)
        ) {
            $kwargs['on'] = Builder::true();
        }

        if ($directed) {
            $kwargs['directed'] = $directed;
        }

        return $this->expression(new Join($kwargs), null, $comments);
    }

    protected function parseOpclass(): ?Expression
    {
        $this_ = $this->parseDisjunction();

        if ($this->matchTexts($this->t['OPCLASS_FOLLOW_KEYWORDS'], false)) {
            return $this_;
        }

        if (! $this->matchSet($this->t['OPTYPE_FOLLOW_TOKENS'], false)) {
            return $this->expression(new Opclass(['this' => $this_, 'expression' => $this->parseTableParts()]));
        }

        return $this_;
    }

    protected function parseIndexParams(): IndexParameters
    {
        $using = $this->match(TokenType::USING) ? $this->parseVar(anyToken: true) : null;

        $columns = $this->match(TokenType::L_PAREN, false)
            ? $this->parseWrappedCsv(fn (): ?Expression => $this->parseWithOperator())
            : null;

        $include = $this->matchTextSeq('INCLUDE') ? $this->parseWrappedIdVars() : null;
        $partitionBy = $this->parsePartitionBy();
        $withStorage = $this->match(TokenType::WITH) ? $this->parseWrappedProperties() : null;
        $tablespace = $this->matchTextSeq('USING', 'INDEX', 'TABLESPACE') ? $this->parseVar(anyToken: true) : null;
        $where = $this->parseWhere();

        $on = $this->match(TokenType::ON) ? $this->parseField() : null;

        return $this->expression(new IndexParameters([
            'using' => $using,
            'columns' => $columns,
            'include' => $include,
            'partition_by' => $partitionBy,
            'where' => $where,
            'with_storage' => $withStorage,
            'tablespace' => $tablespace,
            'on' => $on,
        ]));
    }

    protected function parseIndex(?Expression $index = null, bool $anonymous = false): ?Index
    {
        if ($index !== null || $anonymous) {
            $unique = null;
            $primary = null;
            $amp = null;

            $this->match(TokenType::ON);
            $this->match(TokenType::TABLE); // hive
            $table = $this->parseTableParts(schema: true);
        } else {
            $unique = $this->match(TokenType::UNIQUE);
            $primary = $this->matchTextSeq('PRIMARY');
            $amp = $this->matchTextSeq('AMP');

            if (! $this->match(TokenType::INDEX)) {
                return null;
            }

            $index = $this->parseIdVar();
            $table = null;
        }

        $params = $this->parseIndexParams();

        return $this->expression(new Index([
            'this' => $index,
            'table' => $table,
            'unique' => $unique,
            'primary' => $primary,
            'amp' => $amp,
            'params' => $params,
        ]));
    }

    /**
     * @return list<Expression>|null
     */
    protected function parseTableHints(): ?array
    {
        $hints = [];

        if ($this->matchPair(TokenType::WITH, TokenType::L_PAREN)) {
            // https://learn.microsoft.com/en-us/sql/t-sql/queries/hints-transact-sql-table?view=sql-server-ver16
            $hints[] = $this->expression(new WithTableHint([
                'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseFunction() ?? $this->parseVar(anyToken: true)),
            ]));
            $this->matchRParen();
        } else {
            // https://dev.mysql.com/doc/refman/8.0/en/index-hints.html
            while ($this->matchSet($this->t['TABLE_INDEX_HINT_TOKENS'])) {
                $hint = new IndexTableHint(['this' => strtoupper($this->prevText())]);

                $this->matchSet([TokenType::INDEX => true, TokenType::KEY => true]);

                if ($this->match(TokenType::FOR)) {
                    $hint->set('target', $this->advanceAny() !== null ? strtoupper($this->prevText()) : null);
                }

                $hint->set('expressions', $this->parseWrappedIdVars());
                $hints[] = $hint;
            }
        }

        return $hints !== [] ? $hints : null;
    }

    protected function parseTablePart(bool $schema = false): ?Expression
    {
        return (! $schema ? $this->parseFunction(optionalParens: false) : null)
            ?? $this->parseIdVar(anyToken: false)
            ?? $this->parseStringAsIdentifier()
            ?? $this->parsePlaceholder();
    }
}
