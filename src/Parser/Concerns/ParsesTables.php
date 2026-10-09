<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Parser\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Alias;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtIndex;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Changes;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Connect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cube;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\Except;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Fetch;
use LaravelSqlParser\LaravelSqlParser\Expressions\Group;
use LaravelSqlParser\LaravelSqlParser\Expressions\GroupingSets;
use LaravelSqlParser\LaravelSqlParser\Expressions\Having;
use LaravelSqlParser\LaravelSqlParser\Expressions\HistoricalData;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\In;
use LaravelSqlParser\LaravelSqlParser\Expressions\Intersect;
use LaravelSqlParser\LaravelSqlParser\Expressions\Join;
use LaravelSqlParser\LaravelSqlParser\Expressions\Limit;
use LaravelSqlParser\LaravelSqlParser\Expressions\LimitOptions;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lock;
use LaravelSqlParser\LaravelSqlParser\Expressions\Offset;
use LaravelSqlParser\LaravelSqlParser\Expressions\Order;
use LaravelSqlParser\LaravelSqlParser\Expressions\Ordered;
use LaravelSqlParser\LaravelSqlParser\Expressions\Pivot;
use LaravelSqlParser\LaravelSqlParser\Expressions\PivotAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\PivotAny;
use LaravelSqlParser\LaravelSqlParser\Expressions\PreWhere;
use LaravelSqlParser\LaravelSqlParser\Expressions\Prior;
use LaravelSqlParser\LaravelSqlParser\Expressions\Qualify;
use LaravelSqlParser\LaravelSqlParser\Expressions\Rollup;
use LaravelSqlParser\LaravelSqlParser\Expressions\SetOperation;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableAlias;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableFromRows;
use LaravelSqlParser\LaravelSqlParser\Expressions\TableSample;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Union;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\UnpivotColumns;
use LaravelSqlParser\LaravelSqlParser\Expressions\Values;
use LaravelSqlParser\LaravelSqlParser\Expressions\Version;
use LaravelSqlParser\LaravelSqlParser\Expressions\Where;
use LaravelSqlParser\LaravelSqlParser\Expressions\WithFill;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;
use ReflectionClass;

/**
 * Table references and query clauses: table parts, pivots, WHERE, GROUP BY, ORDER BY, LIMIT,
 * locks and set operations (sqlglot parser.py, from _parse_table_parts_fast to
 * _parse_set_operations).
 */
trait ParsesTables
{
    /** sqlglot Parser.VERSION_PHRASES: phrase words => the Version node's `this`. */
    protected const array VERSION_PHRASES = [
        [['FOR', 'SYSTEM_TIME'], 'TIMESTAMP'],
        [['FOR', 'SYSTEM', 'TIME'], 'TIMESTAMP'],
        [['FOR', 'TIMESTAMP'], 'TIMESTAMP'],
        [['FOR', 'VERSION'], 'VERSION'],
        [['TIMESTAMP', 'AS', 'OF'], 'TIMESTAMP'],
        [['VERSION', 'AS', 'OF'], 'VERSION'],
    ];

    protected function parseTablePartsFast(): ?Table
    {
        $index = $this->index;
        $parts = null;
        $allComments = null;
        $identifierTokens = $this->t['IDENTIFIER_TOKENS'];

        while ($this->matchSet($identifierTokens)) {
            $token = $this->index - 1;
            $comments = $this->prevComments;

            $hasDot = $this->match(TokenType::DOT);
            $currType = $this->curr;

            if (! $hasDot) {
                if (isset($this->t['TABLE_POSTFIX_TOKENS'][$currType])) {
                    $this->retreat($index);

                    return null;
                }
            } elseif (! isset($identifierTokens[$currType])) {
                $this->retreat($index);

                return null;
            }

            $parts ??= [];

            if ($comments !== []) {
                $allComments ??= [];
                array_push($allComments, ...$comments);
                $this->prevComments = [];
            }

            $parts[] = $this->expression(new Identifier([
                'this' => $this->texts[$token],
                'quoted' => $this->types[$token] === TokenType::IDENTIFIER,
            ]), $token);

            if (! $hasDot) {
                break;
            }
        }

        if ($parts === null) {
            return null;
        }

        $n = count($parts);

        if ($n === 1) {
            $table = new Table(['this' => $parts[0]]);
        } elseif ($n === 2) {
            $table = new Table(['this' => $parts[1], 'db' => $parts[0]]);
        } else {
            $this_ = $parts[2];
            for ($i = 3; $i < $n; $i++) {
                $this_ = new Dot(['this' => $this_, 'expression' => $parts[$i]]);
            }

            $table = new Table(['this' => $this_, 'db' => $parts[1], 'catalog' => $parts[0]]);
        }

        if ($allComments !== null) {
            $table->addComments($allComments);
        }

        return $table;
    }

    protected function parseTableParts(bool $schema = false, bool $isDbReference = false, bool $wildcard = false, bool $fast = false): ?Expression
    {
        if ($fast) {
            return $this->parseTablePartsFast();
        }

        $catalog = null;
        $db = null;
        $table = $this->parseTablePart(schema: $schema);

        while ($this->match(TokenType::DOT)) {
            if ($catalog !== null && $catalog !== '') {
                // This allows nesting the table in arbitrarily many dot expressions if needed
                $table = $this->expression(new Dot(['this' => $table, 'expression' => $this->parseTablePart(schema: $schema)]));
            } else {
                $catalog = $db;
                $db = $table;
                // "" used for tsql FROM a..b case
                $table = $this->parseTablePart(schema: $schema) ?? '';
            }
        }

        if (
            $wildcard
            && $this->isConnected()
            && ($table instanceof Identifier || $table === null || $table === '')
            && $this->match(TokenType::STAR)
        ) {
            if ($table instanceof Identifier) {
                $table->args['this'] .= '*';
            } else {
                $table = new Identifier(['this' => '*']);
            }
        }

        if ($isDbReference) {
            $catalog = $db;
            $db = $table;
            $table = null;
        }

        if (($table === null || $table === '') && ! $isDbReference) {
            $this->raiseError('Expected table name but got '.$this->describeCurr());
        }

        if (($db === null || $db === '') && $isDbReference) {
            $this->raiseError('Expected database name but got '.$this->describeCurr());
        }

        $table = $this->expression(new Table(['this' => $table, 'db' => $db, 'catalog' => $catalog]));

        // Bubble up comments from identifier parts to the Table
        $comments = [];
        foreach ($table->parts() as $part) {
            $partComments = $part->popComments();

            if ($partComments !== []) {
                array_push($comments, ...$partComments);
            }
        }

        if ($comments !== []) {
            $table->addComments($comments);
        }

        $changes = $this->parseChanges();

        if ($changes !== null) {
            $table->set('changes', $changes);
        }

        $atBefore = $this->parseHistoricalData();

        if ($atBefore !== null) {
            $table->set('when', $atBefore);
        }

        $pivots = $this->parsePivots();

        if ($pivots !== null) {
            $table->set('pivots', $pivots);
        }

        return $table;
    }

    /**
     * sqlglot formats `self._curr` (a Token) in some error messages.
     */
    protected function describeCurr(): string
    {
        if ($this->curr === 0) {
            return 'None';
        }

        return '<Token token_type: TokenType.'.TokenType::name($this->curr).", text: {$this->currText()}>";
    }

    /**
     * @param  array<int, true>|null  $aliasTokens
     */
    protected function parseTable(
        bool $schema = false,
        bool $joins = false,
        ?array $aliasTokens = null,
        bool $parseBracket = false,
        bool $isDbReference = false,
        bool $parsePartition = false,
        bool $consumePipe = false,
    ): ?Expression {
        $tableAliasTokens = $aliasTokens !== null && $aliasTokens !== [] ? $aliasTokens : $this->t['TABLE_ALIAS_TOKENS'];

        if (! $schema && ! $isDbReference && ! $consumePipe && ! $joins) {
            $index = $this->index;
            // Through parseTableParts(), so dialect overrides (e.g. BigQuery's) still apply
            $table = $this->parseTableParts(fast: true);

            if ($table instanceof Table) {
                $currType = $this->curr;
                $nextType = $this->next;
                $fastTerminators = $this->t['TABLE_TERMINATORS'];

                // only return the table if we're sure there are no other operators
                // MATCH_CONDITION is a special case because it accepts any alias before it like LIMIT
                if (isset($fastTerminators[$currType]) && $nextType !== TokenType::MATCH_CONDITION) {
                    return $table;
                }

                $postfixTokens = $this->t['TABLE_POSTFIX_TOKENS'];

                if (! isset($postfixTokens[$currType]) && ! isset($postfixTokens[$nextType])) {
                    $alias = $this->parseTableAlias(aliasTokens: $tableAliasTokens);

                    if ($alias !== null) {
                        $table->set('alias', $alias);
                    }

                    if (isset($fastTerminators[$this->curr])) {
                        return $table;
                    }
                }

                $this->retreat($index);
            }
        }

        $stream = $this->parseStream();

        if ($stream !== null) {
            return $stream;
        }

        $lateral = $this->parseLateral();

        if ($lateral !== null) {
            return $lateral;
        }

        $unnest = $this->parseUnnest();

        if ($unnest !== null) {
            return $unnest;
        }

        $values = $this->parseDerivedTableValues();

        if ($values !== null) {
            return $values;
        }

        $subquery = $this->parseSelect(table: true, consumePipe: $consumePipe);

        if ($subquery !== null) {
            if (! $subquery->flag('pivots')) {
                $subquery->set('pivots', $this->parsePivots());
            }

            if ($joins) {
                foreach ($this->parseJoins() as $join) {
                    $subquery->append('joins', $join);
                }
            }

            return $subquery;
        }

        $bracket = $parseBracket ? $this->parseBracket(null) : null;
        $bracket = $bracket !== null ? $this->expression(new Table(['this' => $bracket])) : null;

        $rowsFromTables = $this->matchTextSeq('ROWS', 'FROM')
            ? $this->parseWrappedCsv(fn (): ?Expression => $this->parseTable())
            : null;

        $rowsFrom = $rowsFromTables !== null && $rowsFromTables !== []
            ? $this->expression(new Table(['rows_from' => $rowsFromTables]))
            : null;

        $only = $this->match(TokenType::ONLY);

        $this_ = $bracket
            ?? $rowsFrom
            ?? $this->parseBracket($this->parseTableParts(schema: $schema, isDbReference: $isDbReference));

        if ($this_ === null) {
            return null;
        }

        if ($only) {
            $this_->set('only', $only);
        }

        // Postgres supports a wildcard (table) suffix operator, which is a no-op in this context
        $this->match(TokenType::STAR);

        $parsePartition = $parsePartition || $this->t['SUPPORTS_PARTITION_SELECTION'] === true;

        if ($parsePartition && $this->match(TokenType::PARTITION, false)) {
            $this_->set('partition', $this->parsePartition());
        }

        if ($schema) {
            return $this->parseSchema($this_);
        }

        if ($this->dialect->settings['ALIAS_POST_VERSION'] === true) {
            $this_->set('version', $this->parseVersion());
        }

        if ($this->dialect->settings['ALIAS_POST_TABLESAMPLE'] === true) {
            $this_->set('sample', $this->parseTableSample());
        }

        $alias = $this->parseTableAlias(aliasTokens: $tableAliasTokens);

        if ($alias !== null) {
            $this_->set('alias', $alias);

            // DuckDB requires the time-travel clause to come after the alias, e.g.
            // SELECT * FROM t AS a AT (VERSION => 1)
            if ($this_ instanceof Table && ! $this_->flag('when')) {
                $this_->set('when', $this->parseHistoricalData());
            }
        }

        if ($this->match(TokenType::INDEXED_BY)) {
            $this_->set('indexed', $this->parseTableParts());
        } elseif ($this->matchTextSeq('NOT', 'INDEXED')) {
            $this_->set('indexed', false);
        }

        if ($this_ instanceof Table && $this->matchTextSeq('AT')) {
            return $this->expression(new AtIndex(['this' => $this_->toColumn(copy: false), 'expression' => $this->parseIdVar()]));
        }

        $this_->set('hints', $this->parseTableHints());

        if (! $this_->flag('pivots')) {
            $this_->set('pivots', $this->parsePivots());
        }

        if ($this->dialect->settings['ALIAS_POST_TABLESAMPLE'] !== true) {
            $this_->set('sample', $this->parseTableSample());
        }

        if ($this->dialect->settings['ALIAS_POST_VERSION'] !== true) {
            $this_->set('version', $this->parseVersion());
        }

        if ($joins) {
            foreach ($this->parseJoins(aliasTokens: $aliasTokens) as $join) {
                $this_->append('joins', $join);
            }
        }

        if ($this->matchPair(TokenType::WITH, TokenType::ORDINALITY)) {
            $this_->set('ordinality', true);
            $this_->set('alias', $this->parseTableAlias());
        }

        // TABLE(<tvf>) is parsed into a Table wrapping exp.TableFromRows, so we
        // hoist the table args onto the latter and return it instead
        if ($this_ instanceof Table && ($tableFromRows = $this_->args['this'] ?? null) instanceof TableFromRows) {
            foreach (array_keys(TableFromRows::ARG_TYPES) as $arg) {
                if ($arg !== 'this') {
                    $tableFromRows->set($arg, $this_->args[$arg] ?? null);
                }
            }

            $this_ = $tableFromRows;
        }

        return $this_;
    }

    protected function parseVersion(): ?Version
    {
        $this_ = null;

        foreach (static::VERSION_PHRASES as [$phrase, $value]) {
            if ($this->matchTextSeq(...$phrase)) {
                $this_ = $value;

                break;
            }
        }

        if ($this_ === null) {
            return null;
        }

        if ($this->matchSet([TokenType::FROM => true, TokenType::BETWEEN => true])) {
            $kind = strtoupper($this->prevText());
            $start = $this->parseBitwise();
            $this->matchTexts(['TO' => true, 'AND' => true]);
            $end = $this->parseBitwise();
            $expression = $this->expression(new Tuple(['expressions' => [$start, $end]]));
        } elseif ($this->matchTextSeq('CONTAINED', 'IN')) {
            $kind = 'CONTAINED IN';
            $expression = $this->expression(new Tuple(['expressions' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseBitwise())]));
        } elseif ($this->match(TokenType::ALL)) {
            $kind = 'ALL';
            $expression = null;
        } else {
            $this->matchTextSeq('AS', 'OF');
            $kind = 'AS OF';
            $expression = $this->parseType();
        }

        return $this->expression(new Version(['this' => $this_, 'expression' => $expression, 'kind' => $kind]));
    }

    protected function parseHistoricalData(): ?HistoricalData
    {
        // https://docs.snowflake.com/en/sql-reference/constructs/at-before
        $index = $this->index;
        $historicalData = null;

        if ($this->matchTexts($this->t['HISTORICAL_DATA_PREFIX'])) {
            $this_ = strtoupper($this->prevText());
            $kind = $this->match(TokenType::L_PAREN) && $this->matchTexts($this->t['HISTORICAL_DATA_KIND'])
                ? strtoupper($this->prevText())
                : null;
            $expression = $this->match(TokenType::FARROW) ? $this->parseBitwise() : null;

            if ($expression !== null) {
                $this->matchRParen();
                $historicalData = $this->expression(new HistoricalData(['this' => $this_, 'kind' => $kind, 'expression' => $expression]));
            } else {
                $this->retreat($index);
            }
        }

        return $historicalData;
    }

    protected function parseChanges(): ?Changes
    {
        if (! $this->matchTextSeq('CHANGES', '(', 'INFORMATION', '=>')) {
            return null;
        }

        $information = $this->parseVar(anyToken: true);
        $this->matchRParen();

        return $this->expression(new Changes([
            'information' => $information,
            'at_before' => $this->parseHistoricalData(),
            'end' => $this->parseHistoricalData(),
        ]));
    }

    protected function parseUnnest(bool $withAlias = true): ?Unnest
    {
        if (! $this->matchPair(TokenType::UNNEST, TokenType::L_PAREN, false)) {
            return null;
        }

        $this->advance();

        $expressions = $this->parseWrappedCsv(fn (): ?Expression => $this->parseEquality());
        $offset = $this->matchPair(TokenType::WITH, TokenType::ORDINALITY);

        $alias = $withAlias ? $this->parseTableAlias() : null;

        if ($alias !== null) {
            if ($this->dialect->settings['UNNEST_COLUMN_ONLY'] === true) {
                if ($alias->flag('columns')) {
                    $this->raiseError('Unexpected extra column alias in unnest.');
                }

                $alias->set('columns', [$alias->args['this'] ?? null]);
                $alias->set('this', null);
            }

            $columns = $alias->argList('columns');

            if ($offset === true && count($expressions) < count($columns)) {
                $offset = array_pop($columns);
                $alias->set('columns', $columns);
            }
        }

        if ($offset === false && $this->matchPair(TokenType::WITH, TokenType::OFFSET)) {
            $this->match(TokenType::ALIAS);
            $offset = $this->parseIdVar(anyToken: false, tokens: $this->t['UNNEST_OFFSET_ALIAS_TOKENS']) ?? Builder::toIdentifier('offset');
        }

        return $this->expression(new Unnest(['expressions' => $expressions, 'alias' => $alias, 'offset' => $offset]));
    }

    protected function parseDerivedTableValues(bool $allowValueSynonym = false): ?Values
    {
        $isDerived = $this->matchPair(TokenType::L_PAREN, TokenType::VALUES);

        if (! $isDerived && ! (
            // ClickHouse's `FORMAT Values` is equivalent to `VALUES`
            $this->matchTextSeq('VALUES')
            || $this->matchTextSeq('FORMAT', 'VALUES')
            // MySQL accepts VALUE as a synonym for VALUES in INSERT statements.
            || ($allowValueSynonym && $this->matchTextSeq('VALUE'))
        )) {
            return null;
        }

        $expressions = $this->parseCsv(fn (): ?Expression => $this->parseValue());
        $alias = $this->parseTableAlias();

        if ($isDerived) {
            $this->matchRParen();
        }

        return $this->expression(new Values(['expressions' => $expressions, 'alias' => $alias ?? $this->parseTableAlias()]));
    }

    protected function parseTableSample(bool $asModifier = false): ?TableSample
    {
        if (! $this->match(TokenType::TABLE_SAMPLE) && ! ($asModifier && $this->matchTextSeq('USING', 'SAMPLE'))) {
            return null;
        }

        $bucketNumerator = null;
        $bucketDenominator = null;
        $bucketField = null;
        $percent = null;
        $size = null;
        $seed = null;

        $method = $this->parseVar(tokens: [TokenType::ROW => true], upper: true);
        $matchedLParen = $this->match(TokenType::L_PAREN);

        if ($this->t['TABLESAMPLE_CSV'] === true) {
            $num = null;
            $expressions = $this->parseCsv(fn (): ?Expression => $this->parsePrimary());
        } else {
            $expressions = null;
            $num = $this->match(TokenType::NUMBER, false)
                ? $this->parseFactor(parseMod: false)
                : ($this->parsePrimary() ?? $this->parsePlaceholder());
        }

        if ($this->matchTextSeq('BUCKET')) {
            $bucketNumerator = $this->parseNumber();
            $this->matchTextSeq('OUT', 'OF');
            $bucketDenominator = $this->parseNumber();
            $this->match(TokenType::ON);
            $bucketField = $this->parseField();
        } elseif ($this->matchSet([TokenType::PERCENT => true, TokenType::MOD => true])) {
            $percent = $num;
        } elseif ($this->match(TokenType::ROWS) || $this->dialect->settings['TABLESAMPLE_SIZE_IS_PERCENT'] !== true) {
            $size = $num;
        } else {
            $percent = $num;
        }

        if ($matchedLParen) {
            $this->matchRParen();
        }

        if ($this->match(TokenType::L_PAREN)) {
            $method = $this->parseVar(upper: true);
            $seed = $this->match(TokenType::COMMA) ? $this->parseNumber() : null;
            $this->matchRParen();
        } elseif ($this->matchTexts(['SEED' => true, 'REPEATABLE' => true])) {
            $seed = $this->parseWrapped(fn (): ?Expression => $this->parseNumber());
        }

        $defaultMethod = $this->t['DEFAULT_SAMPLING_METHOD'] ?? null;

        if ($method === null && is_string($defaultMethod) && $defaultMethod !== '') {
            $method = Builder::var($defaultMethod);
        }

        return $this->expression(new TableSample([
            'expressions' => $expressions,
            'method' => $method,
            'bucket_numerator' => $bucketNumerator,
            'bucket_denominator' => $bucketDenominator,
            'bucket_field' => $bucketField,
            'percent' => $percent,
            'size' => $size,
            'seed' => $seed,
        ]));
    }

    /**
     * @return list<Pivot>|null
     */
    protected function parsePivots(): ?array
    {
        if ($this->curr !== TokenType::PIVOT && $this->curr !== TokenType::UNPIVOT) {
            return null;
        }

        $pivots = [];
        while (($pivot = $this->parsePivot()) !== null) {
            $pivots[] = $pivot;
        }

        return $pivots !== [] ? $pivots : null;
    }

    /**
     * @param  array<int, true>|null  $aliasTokens
     * @return list<Join>
     */
    protected function parseJoins(?array $aliasTokens = null): array
    {
        $joins = [];
        while (($join = $this->parseJoin(aliasTokens: $aliasTokens)) !== null) {
            $joins[] = $join;
        }

        return $joins;
    }

    protected function parseUnpivotColumns(): ?UnpivotColumns
    {
        if (! $this->match(TokenType::INTO)) {
            return null;
        }

        return $this->expression(new UnpivotColumns([
            'this' => $this->matchTextSeq('NAME') ? $this->parseColumn() : null,
            'expressions' => $this->matchTextSeq('VALUE') ? $this->parseCsv(fn (): ?Expression => $this->parseColumn()) : null,
        ]));
    }

    // https://duckdb.org/docs/sql/statements/pivot
    protected function parseSimplifiedPivot(?bool $isUnpivot = null): Pivot
    {
        $parseOn = function (): ?Expression {
            $this_ = $this->parseBitwise();

            if ($this->match(TokenType::IN)) {
                // PIVOT ... ON col IN (row_val1, row_val2)
                return $this->parseIn($this_);
            }

            if ($this->match(TokenType::ALIAS, false)) {
                // UNPIVOT ... ON (col1, col2, col3) AS row_val
                return $this->parseAlias($this_);
            }

            return $this_;
        };

        $this_ = $this->parseTable();
        $expressions = $this->match(TokenType::ON) ? $this->parseCsv($parseOn) : null;
        $into = $this->parseUnpivotColumns();
        $using = $this->match(TokenType::USING) ? $this->parseCsv(fn (): ?Expression => $this->parseAlias($this->parseColumn())) : null;
        $group = $this->parseGroup();

        return $this->expression(new Pivot([
            'this' => $this_,
            'expressions' => $expressions,
            'using' => $using,
            'group' => $group,
            'unpivot' => $isUnpivot,
            'into' => $into,
        ]));
    }

    protected function parsePivotIn(): In
    {
        $parseAliasedExpression = function (): ?Expression {
            $this_ = $this->parseSelectOrExpression();

            $this->match(TokenType::ALIAS);
            $alias = $this->parseBitwise();

            if ($alias !== null) {
                if ($alias instanceof Column && $alias->text('db') === '') {
                    $alias = $alias->args['this'];
                }

                return $this->expression(new PivotAlias(['this' => $this_, 'alias' => $alias]));
            }

            return $this_;
        };

        $value = $this->parseColumn();

        if (! $this->match(TokenType::IN)) {
            $this->raiseError('Expecting IN');
        }

        if ($this->match(TokenType::L_PAREN)) {
            if ($this->match(TokenType::ANY)) {
                $exprs = [new PivotAny(['this' => $this->parseOrder()])];
            } else {
                $exprs = $this->parseCsv($parseAliasedExpression);
            }

            $this->matchRParen();

            return $this->expression(new In(['this' => $value, 'expressions' => $exprs]));
        }

        return $this->expression(new In(['this' => $value, 'field' => $this->parseIdVar()]));
    }

    protected function parsePivotAggregation(): ?Expression
    {
        $func = $this->parseFunction();

        if ($func === null) {
            if ($this->prev === TokenType::COMMA) {
                return null;
            }

            $this->raiseError('Expecting an aggregation function in PIVOT');
        }

        return $this->parseAlias($func);
    }

    protected function parsePivot(): ?Pivot
    {
        $index = $this->index;
        $includeNulls = null;

        if ($this->match(TokenType::PIVOT)) {
            $unpivot = false;
        } elseif ($this->match(TokenType::UNPIVOT)) {
            $unpivot = true;

            // https://docs.databricks.com/en/sql/language-manual/sql-ref-syntax-qry-select-unpivot.html#syntax
            if ($this->matchTextSeq('INCLUDE', 'NULLS')) {
                $includeNulls = true;
            } elseif ($this->matchTextSeq('EXCLUDE', 'NULLS')) {
                $includeNulls = false;
            }
        } else {
            return null;
        }

        if (! $this->match(TokenType::L_PAREN)) {
            $this->retreat($index);

            return null;
        }

        if ($unpivot) {
            $expressions = $this->parseCsv(fn (): ?Expression => $this->parseColumn());
        } else {
            $expressions = $this->parseCsv(fn (): ?Expression => $this->parsePivotAggregation());
        }

        if ($expressions === []) {
            $this->raiseError("Failed to parse PIVOT's aggregation list");
        }

        if (! $this->match(TokenType::FOR)) {
            $this->raiseError('Expecting FOR');
        }

        $fields = [];
        while (true) {
            $field = $this->tryParse(fn (): In => $this->parsePivotIn());

            if ($field === null) {
                break;
            }

            $fields[] = $field;
        }

        $defaultOnNull = $this->matchTextSeq('DEFAULT', 'ON', 'NULL')
            ? $this->parseWrapped(fn (): ?Expression => $this->parseBitwise())
            : null;

        $group = $this->parseGroup();

        $this->matchRParen();

        $pivot = $this->expression(new Pivot([
            'expressions' => $expressions,
            'fields' => $fields,
            'unpivot' => $unpivot,
            'include_nulls' => $includeNulls,
            'default_on_null' => $defaultOnNull,
            'group' => $group,
        ]));

        if ($unpivot) {
            $pivot->set('expressions', array_map(fn (Expression $e): Expression => $this->unpivotTarget($e), $pivot->argList('expressions')));

            foreach ($pivot->argList('fields') as $pivotField) {
                if ($pivotField instanceof In && ($target = $pivotField->argExpr('this')) !== null) {
                    $pivotField->set('this', $this->unpivotTarget($target));
                }
            }

            $pivot->set('value_columns_first', $this->t['UNPIVOT_VALUE_COLUMNS_FIRST']);
        }

        if (! $this->matchSet([TokenType::PIVOT => true, TokenType::UNPIVOT => true], false)) {
            $pivot->set('alias', $this->parseTableAlias());
        }

        if (! $unpivot) {
            $names = $this->pivotColumnNames($expressions);

            $columns = [];
            $allFields = [];

            foreach ($pivot->argList('fields') as $pivotField) {
                $pivotFieldExpressions = $pivotField->argList('expressions');

                // The `PivotAny` expression corresponds to `ANY ORDER BY <column>`; we can't infer in this case.
                if (($pivotFieldExpressions[0] ?? null) instanceof PivotAny) {
                    continue;
                }

                $allFields[] = array_map(
                    // An explicit `<field> AS <alias>` names the output column directly,
                    // so it wins over the dialect's string-identifying convention
                    fn (Expression $field): string => $this->t['IDENTIFY_PIVOT_STRINGS'] === true && ! $field instanceof PivotAlias
                        ? $field->sql()
                        : $field->aliasOrName(),
                    $pivotFieldExpressions,
                );
            }

            if ($allFields !== []) {
                if ($names !== []) {
                    $allFields[] = $names;
                }

                // Generate all possible combinations of the pivot columns
                // e.g PIVOT(sum(...) as total FOR year IN (2000, 2010) FOR country IN ('NL', 'US'))
                // generates the product between [[2000, 2010], ['NL', 'US'], ['total']]
                foreach (self::cartesianProduct($allFields) as $fieldParts) {
                    if ($names !== [] && $this->t['PREFIXED_PIVOT_COLUMNS'] === true) {
                        // Move the "name" to the front of the list
                        array_unshift($fieldParts, array_pop($fieldParts));
                    }

                    $columns[] = Builder::toIdentifier(implode('_', $fieldParts));
                }
            }

            $pivot->set('columns', $columns);
            $pivot->set('identify_pivot_strings', $this->t['IDENTIFY_PIVOT_STRINGS']);
            $pivot->set('prefixed_pivot_columns', $this->t['PREFIXED_PIVOT_COLUMNS']);
            $pivot->set('pivot_column_naming', $this->t['PIVOT_COLUMN_NAMING']);
        }

        return $pivot;
    }

    /**
     * UNPIVOT's pre-FOR values and FOR field are new output names, not column references.
     */
    protected function unpivotTarget(Expression $expression): Expression
    {
        if ($expression instanceof Column && $expression->text('table') === '') {
            return $expression->args['this'];
        }

        if ($expression instanceof Tuple) {
            $expression->set('expressions', array_map(fn (Expression $e): Expression => $this->unpivotTarget($e), $expression->argList('expressions')));
        }

        return $expression;
    }

    /**
     * @param  list<list<string>>  $lists
     * @return list<list<string>>
     */
    private static function cartesianProduct(array $lists): array
    {
        $result = [[]];

        foreach ($lists as $list) {
            $next = [];
            foreach ($result as $prefix) {
                foreach ($list as $item) {
                    $next[] = [...$prefix, $item];
                }
            }
            $result = $next;
        }

        return $result;
    }

    /**
     * @param  list<Expression>  $aggregations
     * @return list<string>
     */
    protected function pivotColumnNames(array $aggregations): array
    {
        $names = [];
        foreach ($aggregations as $aggregation) {
            if ($aggregation->alias() !== '') {
                $names[] = $aggregation->alias();
            }
        }

        return $names;
    }

    protected function parsePrewhere(bool $skipWhereToken = false): ?PreWhere
    {
        if (! $skipWhereToken && ! $this->match(TokenType::PREWHERE)) {
            return null;
        }

        $comments = $this->prevComments;

        return $this->expression(new PreWhere(['this' => $this->parseDisjunction()]), null, $comments);
    }

    protected function parseWhere(bool $skipWhereToken = false): ?Where
    {
        if (! $skipWhereToken && ! $this->match(TokenType::WHERE)) {
            return null;
        }

        $comments = $this->prevComments;

        return $this->expression(new Where(['this' => $this->parseDisjunction()]), null, $comments);
    }

    protected function parseGroup(bool $skipGroupByToken = false): ?Group
    {
        if (! $skipGroupByToken && ! $this->match(TokenType::GROUP_BY)) {
            return null;
        }

        $comments = $this->prevComments;
        $elements = [];

        if ($this->match(TokenType::ALL)) {
            $elements['all'] = true;
        } elseif ($this->match(TokenType::DISTINCT)) {
            $elements['all'] = false;
        }

        while (true) {
            // Stop before consuming modifier tokens like LIMIT, OFFSET and WINDOW,
            // which are also valid identifiers
            if ($this->matchSet($this->t['QUERY_MODIFIER_TOKENS'], false)) {
                break;
            }

            $elements['expressions'] ??= [];
            array_push($elements['expressions'], ...$this->parseCsv(
                fn (): ?Expression => $this->parseGroupingSets() ?? $this->parseCubeOrRollup() ?? $this->parseDisjunction(),
            ));

            $beforeWithIndex = $this->index;

            if ($this->match(TokenType::WITH) && ($cubeOrRollup = $this->parseCubeOrRollup(withPrefix: true)) !== null) {
                $key = $cubeOrRollup instanceof Rollup ? 'rollup' : 'cube';
                $elements[$key][] = $cubeOrRollup;
            } elseif (($groupingSets = $this->parseGroupingSets()) !== null) {
                // Hive-style suffix syntax: GROUP BY a, b GROUPING SETS (...)
                $elements['grouping_sets'][] = $groupingSets;

                break;
            } elseif ($this->matchTextSeq('TOTALS')) {
                $elements['totals'] = true;
            }

            if ($beforeWithIndex <= $this->index && $this->index <= $beforeWithIndex + 1) {
                $this->retreat($beforeWithIndex);

                break;
            }
        }

        return $this->expression(new Group($elements), null, $comments);
    }

    protected function parseCubeOrRollup(bool $withPrefix = false): Cube|Rollup|null
    {
        if ($this->match(TokenType::CUBE)) {
            $kind = Cube::class;
        } elseif ($this->match(TokenType::ROLLUP)) {
            $kind = Rollup::class;
        } else {
            return null;
        }

        return $this->expression(new $kind([
            'expressions' => $withPrefix ? [] : $this->parseWrappedCsv(fn (): ?Expression => $this->parseBitwise()),
        ]));
    }

    protected function parseGroupingSets(): ?GroupingSets
    {
        if ($this->match(TokenType::GROUPING_SETS)) {
            return $this->expression(new GroupingSets([
                'expressions' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseGroupingSet()),
            ]));
        }

        return null;
    }

    protected function parseGroupingSet(): ?Expression
    {
        return $this->parseGroupingSets() ?? $this->parseCubeOrRollup() ?? $this->parseBitwise();
    }

    protected function parseHaving(bool $skipHavingToken = false): ?Having
    {
        if (! $skipHavingToken && ! $this->match(TokenType::HAVING)) {
            return null;
        }

        $comments = $this->prevComments;

        return $this->expression(new Having(['this' => $this->parseDisjunction()]), null, $comments);
    }

    protected function parseQualify(): ?Qualify
    {
        if (! $this->match(TokenType::QUALIFY)) {
            return null;
        }

        return $this->expression(new Qualify(['this' => $this->parseDisjunction()]));
    }

    protected function parseConnectWithPrior(): ?Expression
    {
        $previous = $this->t['NO_PAREN_FUNCTION_PARSERS']['PRIOR'] ?? null;
        $this->t['NO_PAREN_FUNCTION_PARSERS']['PRIOR'] = 'noParenFunctionPrior';

        try {
            return $this->parseDisjunction();
        } finally {
            if ($previous === null) {
                unset($this->t['NO_PAREN_FUNCTION_PARSERS']['PRIOR']);
            } else {
                $this->t['NO_PAREN_FUNCTION_PARSERS']['PRIOR'] = $previous;
            }
        }
    }

    protected function noParenFunctionPrior(): Prior
    {
        return $this->expression(new Prior(['this' => $this->parseBitwise()]));
    }

    protected function parseConnect(bool $skipStartToken = false): ?Connect
    {
        if ($skipStartToken) {
            $start = null;
        } elseif ($this->matchTextSeq('START', 'WITH')) {
            $start = $this->parseDisjunction();
        } else {
            return null;
        }

        $this->match(TokenType::CONNECT_BY);
        $nocycle = $this->matchTextSeq('NOCYCLE');
        $connect = $this->parseConnectWithPrior();

        if ($start === null && $this->matchTextSeq('START', 'WITH')) {
            $start = $this->parseDisjunction();
        }

        return $this->expression(new Connect(['start' => $start, 'connect' => $connect, 'nocycle' => $nocycle]));
    }

    protected function parseNameAsExpression(): ?Expression
    {
        $this_ = $this->parseIdVar(anyToken: true);

        if ($this->match(TokenType::ALIAS)) {
            $this_ = $this->expression(new Alias(['alias' => $this_, 'this' => $this->parseDisjunction()]));
        }

        return $this_;
    }

    /**
     * @return list<Expression>|null
     */
    protected function parseInterpolate(): ?array
    {
        if ($this->matchTextSeq('INTERPOLATE')) {
            return $this->parseWrappedCsv(fn (): ?Expression => $this->parseNameAsExpression());
        }

        return null;
    }

    protected function parseOrder(?Expression $this_ = null, bool $skipOrderToken = false): ?Expression
    {
        $siblings = null;

        if (! $skipOrderToken && ! $this->match(TokenType::ORDER_BY)) {
            if (! $this->match(TokenType::ORDER_SIBLINGS_BY)) {
                return $this_;
            }

            $siblings = true;
        }

        $comments = $this->prevComments;

        return $this->expression(new Order([
            'this' => $this_,
            'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseOrdered()),
            'siblings' => $siblings,
        ]), null, $comments);
    }

    /**
     * @template T of Expression
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    protected function parseSort(string $class, int $token): ?Expression
    {
        if (! $this->match($token)) {
            return null;
        }

        return $this->expression(new $class(['expressions' => $this->parseCsv(fn (): ?Expression => $this->parseOrdered())]));
    }

    /**
     * @param  (callable(): ?Expression)|null  $parseMethod
     */
    protected function parseOrdered(?callable $parseMethod = null): ?Ordered
    {
        $this_ = $parseMethod !== null ? $parseMethod() : $this->parseDisjunction();

        if ($this_ === null) {
            return null;
        }

        if (strtoupper($this_->name()) === 'ALL' && $this->dialect->settings['SUPPORTS_ORDER_BY_ALL'] === true) {
            $this_ = Builder::var('ALL');
        }

        $asc = $this->match(TokenType::ASC);
        $desc = $this->match(TokenType::DESC) ? true : ($asc ? false : null);

        $isNullsFirst = $this->matchTextSeq('NULLS', 'FIRST');
        $isNullsLast = $this->matchTextSeq('NULLS', 'LAST');

        $nullsFirst = $isNullsFirst;
        $explicitlyNullOrdered = $isNullsFirst || $isNullsLast;
        $nullOrdering = $this->dialect->settings['NULL_ORDERING'];

        if (
            ! $explicitlyNullOrdered
            && (
                ($desc !== true && $nullOrdering === 'nulls_are_small')
                || ($desc === true && $nullOrdering !== 'nulls_are_small')
            )
            && $nullOrdering !== 'nulls_are_last'
        ) {
            $nullsFirst = true;
        }

        if ($this->matchTextSeq('WITH', 'FILL')) {
            $withFill = $this->expression(new WithFill([
                'from_' => $this->match(TokenType::FROM) ? $this->parseBitwise() : null,
                'to' => $this->matchTextSeq('TO') ? $this->parseBitwise() : null,
                'step' => $this->matchTextSeq('STEP') ? $this->parseBitwise() : null,
                'interpolate' => $this->parseInterpolate(),
            ]));
        } else {
            $withFill = null;
        }

        return $this->expression(new Ordered([
            'this' => $this_,
            'desc' => $desc,
            'nulls_first' => $nullsFirst,
            'with_fill' => $withFill,
        ]));
    }

    protected function parseLimitOptions(): ?LimitOptions
    {
        $percent = $this->matchSet([TokenType::PERCENT => true, TokenType::MOD => true]);
        $rows = $this->matchTexts(['ROW' => true, 'ROWS' => true]);
        $this->matchTextSeq('ONLY');
        $withTies = $this->matchTextSeq('WITH', 'TIES');

        if (! ($percent || $rows || $withTies)) {
            return null;
        }

        return $this->expression(new LimitOptions(['percent' => $percent, 'rows' => $rows, 'with_ties' => $withTies]));
    }

    protected function parseLimit(?Expression $this_ = null, bool $top = false, bool $skipLimitToken = false): ?Expression
    {
        if ($skipLimitToken || $this->match($top ? TokenType::TOP : TokenType::LIMIT)) {
            $comments = $this->prevComments;

            if ($top) {
                $limitParen = $this->match(TokenType::L_PAREN);
                $expression = $limitParen ? ($this->parseTerm() ?? $this->parseSelect()) : $this->parseNumber();

                if ($limitParen) {
                    $this->matchRParen();
                }
            } else {
                if ($this->dialect->settings['SUPPORTS_LIMIT_ALL'] === true && $this->match(TokenType::ALL)) {
                    return $this_;
                }

                $expression = $this->parseTerm(parseMod: false);
            }

            $limitOptions = $this->parseLimitOptions();

            if ($this->match(TokenType::COMMA)) {
                $offset = $expression;
                $expression = $this->parseTerm();
            } else {
                $offset = null;
            }

            $limit = $this->expression(new Limit([
                'this' => $this_,
                'expression' => $expression,
                'offset' => $offset,
                'limit_options' => $limitOptions,
                'expressions' => $this->parseLimitBy(),
            ]), null, $comments);

            if ($top) {
                $limit->setMeta('top', true);
            }

            return $limit;
        }

        if ($this->match(TokenType::FETCH)) {
            $direction = $this->matchSet([TokenType::FIRST => true, TokenType::NEXT => true])
                ? strtoupper($this->prevText())
                : 'FIRST';

            $count = $this->matchTexts(['ROW' => true, 'ROWS' => true], false)
                ? null
                : $this->parseField(tokens: $this->t['FETCH_TOKENS']);

            return $this->expression(new Fetch([
                'direction' => $direction,
                'count' => $count,
                'limit_options' => $this->parseLimitOptions(),
            ]));
        }

        return $this_;
    }

    protected function parseOffset(?Expression $this_ = null): ?Expression
    {
        if (! $this->match(TokenType::OFFSET)) {
            return $this_;
        }

        $count = $this->parseTerm();
        $this->matchTexts(['ROW' => true, 'ROWS' => true]);

        return $this->expression(new Offset(['this' => $this_, 'expression' => $count, 'expressions' => $this->parseLimitBy()]));
    }

    protected function canParseLimitOrOffset(): bool
    {
        if (! $this->matchSet($this->t['AMBIGUOUS_ALIAS_TOKENS'], false)) {
            return false;
        }

        $index = $this->index;
        $result = $this->tryParse(fn (): ?Expression => $this->parseLimit(), retreat: true) !== null
            || $this->tryParse(fn (): ?Expression => $this->parseOffset(), retreat: true) !== null;
        $this->retreat($index);

        // MATCH_CONDITION (...) is a special construct that should not be consumed by limit/offset
        if ($this->next === TokenType::MATCH_CONDITION) {
            $result = false;
        }

        return $result;
    }

    protected function canParseNamedWindow(): bool
    {
        // `WINDOW` is in ID_VAR_TOKENS so it could be mistakenly consumed as an implicit alias.
        // Refuse only when the following tokens look like a named-window clause: `WINDOW <id> AS (`.
        if (! $this->match(TokenType::WINDOW, false)) {
            return false;
        }

        $name = $this->index + 1 < $this->chunkEnd ? $this->types[$this->index + 1] : null;

        if ($name === null || ! isset($this->idVarTokens[$name])) {
            return false;
        }

        $aliasToken = $this->index + 2 < $this->chunkEnd ? $this->types[$this->index + 2] : null;

        if ($aliasToken !== TokenType::ALIAS) {
            return false;
        }

        $body = $this->index + 3 < $this->chunkEnd ? $this->types[$this->index + 3] : null;

        return $body === TokenType::L_PAREN;
    }

    /**
     * @return list<Expression>|null
     */
    protected function parseLimitBy(): ?array
    {
        return $this->matchTextSeq('BY') ? $this->parseCsv(fn (): ?Expression => $this->parseBitwise()) : null;
    }

    /**
     * @return list<Lock>
     */
    protected function parseLocks(): array
    {
        $locks = [];

        while (true) {
            $update = null;
            $key = null;

            if ($this->matchTextSeq('FOR', 'UPDATE')) {
                $update = true;
            } elseif ($this->matchTextSeq('FOR', 'SHARE') || $this->matchTextSeq('LOCK', 'IN', 'SHARE', 'MODE')) {
                $update = false;
            } elseif ($this->matchTextSeq('FOR', 'KEY', 'SHARE')) {
                $update = false;
                $key = true;
            } elseif ($this->matchTextSeq('FOR', 'NO', 'KEY', 'UPDATE')) {
                $update = true;
                $key = true;
            } else {
                break;
            }

            $expressions = null;

            if ($this->matchTextSeq('OF')) {
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseTable(schema: true));
            }

            $wait = null;

            if ($this->matchTextSeq('NOWAIT')) {
                $wait = true;
            } elseif ($this->matchTextSeq('WAIT')) {
                $wait = $this->parsePrimary();
            } elseif ($this->matchTextSeq('SKIP', 'LOCKED')) {
                $wait = false;
            }

            $locks[] = $this->expression(new Lock(['update' => $update, 'expressions' => $expressions, 'wait' => $wait, 'key' => $key]));
        }

        return $locks;
    }

    public function parseSetOperation(?Expression $this_, bool $consumePipe = false): ?Expression
    {
        $start = $this->index;
        [, $sideToken, $kindToken] = $this->parseJoinParts();

        $side = $sideToken !== null ? $this->texts[$sideToken] : null;
        $kind = $kindToken !== null ? $this->texts[$kindToken] : null;

        if (! $this->matchSet($this->t['SET_OPERATIONS'])) {
            $this->retreat($start);

            return null;
        }

        $tokenType = $this->prev;

        if ($tokenType === TokenType::UNION) {
            $operation = Union::class;
        } elseif ($tokenType === TokenType::EXCEPT) {
            $operation = Except::class;
        } else {
            $operation = Intersect::class;
        }

        $comments = $this->tokenComments[$this->index - 1] ?? [];

        if ($this->match(TokenType::DISTINCT)) {
            $distinct = true;
        } elseif ($this->match(TokenType::ALL)) {
            $distinct = false;
        } else {
            $distinct = $this->dialect->settings['SET_OP_DISTINCT_BY_DEFAULT'][$operation] ?? null;

            if ($distinct === null) {
                $this->raiseError('Expected DISTINCT or ALL for '.(new ReflectionClass($operation))->getShortName());
            }
        }

        $byName = ($this->matchTextSeq('BY', 'NAME') || $this->matchTextSeq('STRICT', 'CORRESPONDING')) ? true : null;

        if ($this->matchTextSeq('CORRESPONDING')) {
            $byName = true;

            if ($side === null && $kind === null) {
                $kind = 'INNER';
            }
        }

        $onColumnList = null;

        if ($byName === true && $this->matchTexts(['ON' => true, 'BY' => true])) {
            $onColumnList = $this->parseWrappedCsv(fn (): ?Expression => $this->parseColumn());
        }

        $expression = $this->parseSelect(nested: true, parseSetOperation: false, consumePipe: $consumePipe);

        // Wrap VALUES operands in selects, both for consistency with the CTE canonicalization
        // in _parse_cte and so that alias pushdown can reach into set operation branches
        if ($this_ instanceof Values) {
            $this_ = $this->valuesToSelect($this_);
        }

        if ($expression instanceof Values) {
            $expression = $this->valuesToSelect($expression);
        }

        if ($this_ instanceof Alias && ($subquery = $this_->args['this'] ?? null) instanceof Subquery) {
            $subquery->set('alias', new TableAlias(['this' => $this_->args['alias'] ?? null]));
            $subquery->addComments($this_->popComments());
            $this_ = $subquery;
        }

        return $this->expression(new $operation([
            'this' => $this_,
            'distinct' => $distinct,
            'by_name' => $byName,
            'expression' => $expression,
            'side' => $side,
            'kind' => $kind,
            'on' => $onColumnList,
        ]), null, $comments);
    }

    protected function parseSetOperations(?Expression $this_): ?Expression
    {
        while ($this_ !== null) {
            $setop = $this->parseSetOperation($this_);

            if ($setop === null) {
                break;
            }

            $this_ = $setop;
        }

        if ($this_ instanceof SetOperation && $this->t['MODIFIERS_ATTACHED_TO_SET_OP'] === true) {
            $expression = $this_->argExpr('expression');

            if ($expression !== null) {
                foreach ($this->t['SET_OP_MODIFIERS'] as $arg => $_) {
                    $expr = $expression->args[$arg] ?? null;

                    if ($expr !== null && $expr !== false && $expr !== [] && ! ($arg === 'limit' && $expr instanceof Expression && $expr->metaGet('top') === true)) {
                        $expression->set($arg, null);
                        $this_->set($arg, $expr);
                    }
                }
            }

            // A trailing LIMIT/FETCH can coexist with TOP on the final operand.
            if ($this->curr === TokenType::LIMIT || $this->curr === TokenType::FETCH) {
                $this_ = $this->parseQueryModifiers($this_);
            }
        }

        return $this_;
    }
}
