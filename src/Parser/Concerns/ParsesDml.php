<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Parser\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cache;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\ConditionalInsert;
use LaravelSqlParser\LaravelSqlParser\Expressions\Delete;
use LaravelSqlParser\LaravelSqlParser\Expressions\Describe;
use LaravelSqlParser\LaravelSqlParser\Expressions\Directory;
use LaravelSqlParser\LaravelSqlParser\Expressions\EQ;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Insert;
use LaravelSqlParser\LaravelSqlParser\Expressions\Kill;
use LaravelSqlParser\LaravelSqlParser\Expressions\LoadData;
use LaravelSqlParser\LaravelSqlParser\Expressions\MultitableInserts;
use LaravelSqlParser\LaravelSqlParser\Expressions\OnConflict;
use LaravelSqlParser\LaravelSqlParser\Expressions\Partition;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Returning;
use LaravelSqlParser\LaravelSqlParser\Expressions\RowFormatDelimitedProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\RowFormatSerdeProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\SerdeProperties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Tuple;
use LaravelSqlParser\LaravelSqlParser\Expressions\Uncache;
use LaravelSqlParser\LaravelSqlParser\Expressions\Update;
use LaravelSqlParser\LaravelSqlParser\Expressions\UseExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Values;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * DESCRIBE, INSERT, UPDATE, DELETE, LOAD, USE, CACHE and VALUES
 * (sqlglot parser.py, from _parse_describe to _parse_value).
 */
trait ParsesDml
{
    protected function parseDescribe(): Describe
    {
        $kind = $this->matchSet($this->t['CREATABLES']) ? $this->prevText() : null;
        $style = $this->matchTexts($this->t['DESCRIBE_STYLES']) ? strtoupper($this->prevText()) : null;

        if ($this->match(TokenType::DOT)) {
            $style = null;
            $this->retreat($this->index - 2);
        }

        $format = $this->match(TokenType::FORMAT, false) ? $this->parseProperty() : null;

        if ($this->matchSet($this->t['STATEMENT_PARSERS'], false)) {
            $this_ = $this->parseStatement();
        } else {
            $this_ = $this->parseTable(schema: true);
        }

        $properties = $this->parseProperties();
        $expressions = $properties?->args['expressions'] ?? null;
        $partition = $this->parsePartition();

        return $this->expression(new Describe([
            'this' => $this_,
            'style' => $style,
            'kind' => $kind,
            'expressions' => $expressions,
            'partition' => $partition,
            'format' => $format,
            'as_json' => $this->matchTextSeq('AS', 'JSON'),
        ]));
    }

    /**
     * @param  list<string>|null  $comments
     */
    protected function parseMultitableInserts(?array $comments): MultitableInserts
    {
        $kind = strtoupper($this->prevText());
        $expressions = [];

        $parseConditionalInsert = function (): ?ConditionalInsert {
            if ($this->match(TokenType::WHEN)) {
                $expression = $this->parseDisjunction();
                $this->match(TokenType::THEN);
            } else {
                $expression = null;
            }

            $else = $this->match(TokenType::ELSE);

            if (! $this->match(TokenType::INTO)) {
                return null;
            }

            return $this->expression(new ConditionalInsert([
                'this' => $this->expression(new Insert([
                    'this' => $this->parseTable(schema: true),
                    'expression' => $this->parseDerivedTableValues(),
                ])),
                'expression' => $expression,
                'else_' => $else,
            ]));
        };

        $expression = $parseConditionalInsert();
        while ($expression !== null) {
            $expressions[] = $expression;
            $expression = $parseConditionalInsert();
        }

        return $this->expression(new MultitableInserts([
            'kind' => $kind,
            'expressions' => $expressions,
            'source' => $this->parseTable(),
        ]), null, $comments);
    }

    protected function parseInsert(): Expression
    {
        $comments = [];
        $hint = $this->parseHint();
        $overwrite = $this->match(TokenType::OVERWRITE);
        $ignore = $this->match(TokenType::IGNORE);
        $local = $this->matchTextSeq('LOCAL');
        $alternative = null;
        $isFunction = null;

        if ($this->matchTextSeq('DIRECTORY')) {
            $this_ = $this->expression(new Directory([
                'this' => $this->parseVarOrString(),
                'local' => $local,
                'row_format' => $this->parseRowFormat(matchRow: true),
            ]));
        } else {
            if ($this->matchSet([TokenType::FIRST => true, TokenType::ALL => true])) {
                array_push($comments, ...$this->prevComments);

                return $this->parseMultitableInserts($comments);
            }

            if ($this->match(TokenType::OR)) {
                $alternative = $this->matchTexts($this->t['INSERT_ALTERNATIVES']) ? $this->prevText() : null;
            }

            $this->match(TokenType::INTO);
            array_push($comments, ...$this->prevComments);
            $this->match(TokenType::TABLE);
            $isFunction = $this->match(TokenType::FUNCTION);

            $this_ = $isFunction ? $this->parseFunction() : $this->parseInsertTable();
        }

        // MySQL's INSERT ... SET is normalized into the INSERT ... (cols) VALUES (vals) variant
        $setValues = null;

        if ($this->match(TokenType::SET)) {
            $columns = [];
            $values = [];

            $parseSetAssignment = function () use (&$columns, &$values): ?Expression {
                $target = $this->parseColumn();

                if ($target instanceof Column && $this->match(TokenType::EQ)) {
                    if (($this->dialect->settings['SUPPORTS_VALUES_DEFAULT'] ?? false) === true && $this->match(TokenType::DEFAULT)) {
                        $value = Builder::var(strtoupper($this->prevText()));
                    } else {
                        $value = $this->parseDisjunction();
                    }

                    if ($value !== null) {
                        $columns[] = $target->args['this'];
                        $values[] = $value;

                        return $value;
                    }
                }

                $this->raiseError('Expected column assignment in INSERT ... SET');

                return null;
            };

            $this->parseCsv($parseSetAssignment);

            $this_ = $this->expression(new Schema(['this' => $this_, 'expressions' => $columns]));
            $setValues = $this->expression(new Values([
                'expressions' => [new Tuple(['expressions' => $values])],
                'alias' => $this->parseTableAlias(),
            ]));
        }

        $returning = $this->parseReturning(); // TSQL allows RETURNING before source

        $stored = $this->matchTextSeq('STORED') ? $this->parseStored() : null;
        $byName = $this->matchTextSeq('BY', 'NAME');
        $exists = $this->parseExists();
        $replaceWhere = null;
        $replaceUsing = null;

        if ($this->match(TokenType::REPLACE)) {
            if ($this->match(TokenType::WHERE)) {
                $replaceWhere = $this->parseDisjunction();
            } elseif ($this->match(TokenType::USING)) {
                $replaceUsing = $this->parseUsingIdentifiers();
            }
        }

        return $this->expression(new Insert([
            'hint' => $hint,
            'is_function' => $isFunction,
            'this' => $this_,
            'stored' => $stored,
            'by_name' => $byName,
            'exists' => $exists,
            'where' => $replaceWhere,
            'using' => $replaceUsing,
            'partition' => $this->match(TokenType::PARTITION_BY) ? $this->parsePartitionedBy() : null,
            'settings' => $this->matchTextSeq('SETTINGS') ? $this->parseSettingsProperty() : null,
            'default' => $this->matchTextSeq('DEFAULT', 'VALUES'),
            'expression' => $setValues
                ?? $this->parseDerivedTableValues(allowValueSynonym: true)
                ?? $this->parseDdlSelect(),
            'conflict' => $this->parseOnConflict(),
            'returning' => $returning ?? $this->parseReturning(),
            'overwrite' => $overwrite,
            'alternative' => $alternative,
            'ignore' => $ignore,
            'source' => $this->match(TokenType::TABLE) ? $this->parseTable() : null,
        ]), null, $comments);
    }

    protected function parseInsertTable(): ?Expression
    {
        $this_ = $this->parseTable(schema: true, parsePartition: true);

        if ($this_ instanceof Table && $this->match(TokenType::ALIAS, false)) {
            $this_->set('alias', $this->parseTableAlias());
        }

        return $this_;
    }

    protected function parseKill(): Kill
    {
        $kind = $this->matchTexts(['CONNECTION' => true, 'QUERY' => true]) ? Builder::var($this->prevText()) : null;

        return $this->expression(new Kill(['this' => $this->parsePrimary(), 'kind' => $kind]));
    }

    protected function parseOnConflict(): ?OnConflict
    {
        $conflict = $this->matchTextSeq('ON', 'CONFLICT');
        $duplicate = $this->matchTextSeq('ON', 'DUPLICATE', 'KEY');

        if (! $conflict && ! $duplicate) {
            return null;
        }

        $conflictKeys = null;
        $constraint = null;

        if ($conflict) {
            if ($this->matchTextSeq('ON', 'CONSTRAINT')) {
                $constraint = $this->parseIdVar();
            } elseif ($this->match(TokenType::L_PAREN)) {
                $conflictKeys = $this->parseCsv(fn (): ?Expression => $this->parseIndexedColumn());
                $this->matchRParen();
            }
        }

        $indexPredicate = $this->parseWhere();

        $action = $this->parseVarFromOptions($this->t['CONFLICT_ACTIONS']);

        if ($this->prev === TokenType::UPDATE) {
            $this->match(TokenType::SET);
            $expressions = $this->parseCsv(fn (): ?Expression => $this->parseUpdateAssignment());
        } else {
            $expressions = null;
        }

        return $this->expression(new OnConflict([
            'duplicate' => $duplicate,
            'expressions' => $expressions,
            'action' => $action,
            'conflict_keys' => $conflictKeys,
            'index_predicate' => $indexPredicate,
            'constraint' => $constraint,
            'where' => $this->parseWhere(),
        ]));
    }

    protected function parseReturning(): ?Returning
    {
        if (! $this->match(TokenType::RETURNING)) {
            return null;
        }

        return $this->expression(new Returning([
            'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseExpression()),
            'into' => $this->match(TokenType::INTO) ? $this->parseTablePart() : null,
        ]));
    }

    protected function parseRow(): ?Expression
    {
        if (! $this->match(TokenType::FORMAT)) {
            return null;
        }

        return $this->parseRowFormat();
    }

    protected function parseSerdeProperties(bool $with = false): ?SerdeProperties
    {
        $index = $this->index;
        $with = $with || $this->matchTextSeq('WITH');

        if (! $this->match(TokenType::SERDE_PROPERTIES)) {
            $this->retreat($index);

            return null;
        }

        return $this->expression(new SerdeProperties([
            'expressions' => $this->parseWrappedProperties(),
            'with_' => $with,
        ]));
    }

    protected function parseRowFormat(bool $matchRow = false): ?Expression
    {
        if ($matchRow && ! $this->matchPair(TokenType::ROW, TokenType::FORMAT)) {
            return null;
        }

        if ($this->matchTextSeq('SERDE')) {
            $this_ = $this->parseString();
            $serdeProperties = $this->parseSerdeProperties();

            return $this->expression(new RowFormatSerdeProperty(['this' => $this_, 'serde_properties' => $serdeProperties]));
        }

        $this->matchTextSeq('DELIMITED');

        $kwargs = [];

        if ($this->matchTextSeq('FIELDS', 'TERMINATED', 'BY')) {
            $kwargs['fields'] = $this->parseString();

            if ($this->matchTextSeq('ESCAPED', 'BY')) {
                $kwargs['escaped'] = $this->parseString();
            }
        }

        if ($this->matchTextSeq('COLLECTION', 'ITEMS', 'TERMINATED', 'BY')) {
            $kwargs['collection_items'] = $this->parseString();
        }

        if ($this->matchTextSeq('MAP', 'KEYS', 'TERMINATED', 'BY')) {
            $kwargs['map_keys'] = $this->parseString();
        }

        if ($this->matchTextSeq('LINES', 'TERMINATED', 'BY')) {
            $kwargs['lines'] = $this->parseString();
        }

        if ($this->matchTextSeq('NULL', 'DEFINED', 'AS')) {
            $kwargs['null'] = $this->parseString();
        }

        return $this->expression(new RowFormatDelimitedProperty($kwargs));
    }

    protected function parseLoad(): Expression
    {
        if ($this->matchTextSeq('DATA')) {
            $local = $this->matchTextSeq('LOCAL');
            $this->matchTextSeq('INPATH');
            $inpath = $this->parseString();
            $overwrite = $this->match(TokenType::OVERWRITE);
            $temp = null;

            if ($this->match(TokenType::INTO)) {
                $temp = $this->match(TokenType::TEMPORARY);
                $this->match(TokenType::TABLE);
            }

            return $this->expression(new LoadData([
                'this' => $this->parseTable(schema: true),
                'local' => $local,
                'overwrite' => $overwrite,
                'temp' => $temp,
                'inpath' => $inpath,
                'files' => $this->matchTextSeq('FROM', 'FILES') ? new Properties(['expressions' => $this->parseWrappedProperties()]) : null,
                'partition' => $this->parsePartition(),
                'input_format' => $this->matchTextSeq('INPUTFORMAT') ? $this->parseString() : null,
                'serde' => $this->matchTextSeq('SERDE') ? $this->parseString() : null,
            ]));
        }

        return $this->parseAsCommand($this->index - 1);
    }

    protected function parseDelete(): Delete
    {
        $hint = $this->parseHint();

        // This handles MySQL's "Multiple-Table Syntax"
        // https://dev.mysql.com/doc/refman/8.0/en/delete.html
        $tables = null;

        if (! $this->match(TokenType::FROM, false)) {
            $tables = $this->parseCsv(fn (): ?Expression => $this->parseTable());
            $tables = $tables !== [] ? $tables : null;
        }

        $returning = $this->parseReturning();

        return $this->expression(new Delete([
            'hint' => $hint,
            'tables' => $tables,
            'this' => $this->match(TokenType::FROM) ? $this->parseTable(joins: true) : null,
            'using' => $this->match(TokenType::USING) ? $this->parseCsv(fn (): ?Expression => $this->parseTable(joins: true)) : null,
            'cluster' => $this->match(TokenType::ON) ? $this->parseOnProperty() : null,
            'where' => $this->parseWhere(),
            'returning' => $returning ?? $this->parseReturning(),
            'order' => $this->parseOrder(),
            'limit' => $this->parseLimit(),
        ]));
    }

    protected function parseUpdateAssignment(): ?Expression
    {
        $this_ = $this->parseComparison();

        if ($this->match(TokenType::EQ)) {
            $comments = $this->prevComments;
            $this_ = $this->expression(new EQ(['this' => $this_, 'expression' => $this->parseDisjunction()]), null, $comments);
        }

        return $this_;
    }

    protected function parseUpdate(): Update
    {
        $hint = $this->parseHint();
        $kwargs = [
            'hint' => $hint,
            'this' => $this->parseTable(joins: true, aliasTokens: $this->t['UPDATE_ALIAS_TOKENS']),
        ];

        while ($this->curr !== 0) {
            if ($this->match(TokenType::SET)) {
                $kwargs['expressions'] = $this->parseCsv(fn (): ?Expression => $this->parseUpdateAssignment());
            } elseif ($this->match(TokenType::RETURNING, false)) {
                $kwargs['returning'] = $this->parseReturning();
            } elseif ($this->match(TokenType::FROM, false)) {
                $from = $this->parseFrom(joins: true);
                $table = $from?->args['this'] ?? null;

                if ($table instanceof Subquery && $this->match(TokenType::JOIN, false)) {
                    $joins = $this->parseJoins();
                    $table->set('joins', $joins !== [] ? $joins : null);
                }

                $kwargs['from_'] = $from;
            } elseif ($this->match(TokenType::WHERE, false)) {
                $kwargs['where'] = $this->parseWhere();
            } elseif ($this->match(TokenType::ORDER_BY, false)) {
                $kwargs['order'] = $this->parseOrder();
            } elseif ($this->match(TokenType::LIMIT, false)) {
                $kwargs['limit'] = $this->parseLimit();
            } else {
                break;
            }
        }

        return $this->expression(new Update($kwargs));
    }

    protected function parseUse(): UseExpr
    {
        return $this->expression(new UseExpr([
            'kind' => $this->parseVarFromOptions($this->t['USABLES'], raiseUnmatched: false),
            'this' => $this->parseTable(schema: false),
        ]));
    }

    protected function parseUncache(): Uncache
    {
        if (! $this->match(TokenType::TABLE)) {
            $this->raiseError('Expecting TABLE after UNCACHE');
        }

        return $this->expression(new Uncache(['exists' => $this->parseExists(), 'this' => $this->parseTable(schema: true)]));
    }

    protected function parseCache(): Cache
    {
        $lazy = $this->matchTextSeq('LAZY');
        $this->match(TokenType::TABLE);
        $table = $this->parseTable(schema: true);

        $options = [];

        if ($this->matchTextSeq('OPTIONS')) {
            $this->matchLParen();
            $k = $this->parseString();
            $this->match(TokenType::EQ);
            $v = $this->parseString();
            $options = [$k, $v];
            $this->matchRParen();
        }

        $this->match(TokenType::ALIAS);

        return $this->expression(new Cache([
            'this' => $table,
            'lazy' => $lazy,
            'options' => $options,
            'expression' => $this->parseSelect(nested: true),
        ]));
    }

    protected function parsePartition(): ?Partition
    {
        if (! $this->matchTexts($this->t['PARTITION_KEYWORDS'])) {
            return null;
        }

        return $this->expression(new Partition([
            'subpartition' => strtoupper($this->prevText()) === 'SUBPARTITION',
            'expressions' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseDisjunction()),
        ]));
    }

    protected function parseValue(bool $values = true): ?Tuple
    {
        $parseValueExpression = function (): ?Expression {
            if (($this->dialect->settings['SUPPORTS_VALUES_DEFAULT'] ?? false) === true && $this->match(TokenType::DEFAULT)) {
                return Builder::var(strtoupper($this->prevText()));
            }

            return $this->parseExpression();
        };

        if ($this->match(TokenType::L_PAREN)) {
            $expressions = $this->parseCsv($parseValueExpression);
            $this->matchRParen();

            return $this->expression(new Tuple(['expressions' => $expressions]));
        }

        // In some dialects we can have VALUES 1, 2 which results in 1 column & 2 rows.
        $expression = $this->parseExpression();

        if ($expression !== null) {
            return $this->expression(new Tuple(['expressions' => [$expression]]));
        }

        return null;
    }
}
