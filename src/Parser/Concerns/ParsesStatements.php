<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Parser\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Block;
use LaravelSqlParser\LaravelSqlParser\Expressions\CloneExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Comment;
use LaravelSqlParser\LaravelSqlParser\Expressions\Create;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Drop;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\MergeTreeTTL;
use LaravelSqlParser\LaravelSqlParser\Expressions\MergeTreeTTLAction;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\ReturnExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\SequenceProperties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Subquery;
use LaravelSqlParser\LaravelSqlParser\Expressions\ToTableProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\TriggerEvent;
use LaravelSqlParser\LaravelSqlParser\Expressions\TriggerExecute;
use LaravelSqlParser\LaravelSqlParser\Expressions\TriggerProperties;
use LaravelSqlParser\LaravelSqlParser\Expressions\TriggerReferencing;
use LaravelSqlParser\LaravelSqlParser\Expressions\UserDefinedFunction;
use LaravelSqlParser\LaravelSqlParser\Expressions\WhileBlock;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Statement-level grammar: statement dispatch, COMMENT, DROP, CREATE and triggers
 * (sqlglot parser.py, from _parse_comment to _parse_property_before).
 */
trait ParsesStatements
{
    protected function parseComment(bool $allowExists = true): Expression
    {
        $start = $this->index - 1;
        $exists = $allowExists ? $this->parseExists() : null;

        $this->match(TokenType::ON);

        $materialized = $this->matchTextSeq('MATERIALIZED');
        $kind = $this->matchSet($this->t['CREATABLES']) ? $this->index - 1 : null;

        if ($kind === null) {
            return $this->parseAsCommand($start);
        }

        $kindType = $this->types[$kind];

        if ($kindType === TokenType::FUNCTION || $kindType === TokenType::PROCEDURE) {
            $this_ = $this->parseUserDefinedFunction($kindType);
        } elseif ($kindType === TokenType::TABLE) {
            $this_ = $this->parseTable(aliasTokens: $this->t['COMMENT_TABLE_ALIAS_TOKENS']);
        } elseif ($kindType === TokenType::COLUMN) {
            $this_ = $this->parseColumn();
        } else {
            $this_ = $this->parseTableParts(schema: true);
        }

        $this->match(TokenType::IS);

        return $this->expression(new Comment([
            'this' => $this_,
            'kind' => $this->texts[$kind],
            'expression' => $this->parseString(),
            'exists' => $exists,
            'materialized' => $materialized,
        ]));
    }

    protected function parseToTable(): ToTableProperty
    {
        $table = $this->parseTableParts(schema: true);

        return $this->expression(new ToTableProperty(['this' => $table]));
    }

    // https://clickhouse.com/docs/en/engines/table-engines/mergetree-family/mergetree#mergetree-table-ttl
    protected function parseTtl(): Expression
    {
        $parseTtlAction = function (): ?Expression {
            $this_ = $this->parseBitwise();

            if ($this->matchTextSeq('DELETE')) {
                return $this->expression(new MergeTreeTTLAction(['this' => $this_, 'delete' => true]));
            }

            if ($this->matchTextSeq('RECOMPRESS')) {
                return $this->expression(new MergeTreeTTLAction(['this' => $this_, 'recompress' => $this->parseBitwise()]));
            }

            if ($this->matchTextSeq('TO', 'DISK')) {
                return $this->expression(new MergeTreeTTLAction(['this' => $this_, 'to_disk' => $this->parseString()]));
            }

            if ($this->matchTextSeq('TO', 'VOLUME')) {
                return $this->expression(new MergeTreeTTLAction(['this' => $this_, 'to_volume' => $this->parseString()]));
            }

            return $this_;
        };

        $expressions = $this->parseCsv($parseTtlAction);
        $where = $this->parseWhere();
        $group = $this->parseGroup();

        $aggregates = null;

        if ($group !== null && $this->match(TokenType::SET)) {
            $aggregates = $this->parseCsv(fn (): ?Expression => $this->parseSetItem());
        }

        return $this->expression(new MergeTreeTTL([
            'expressions' => $expressions,
            'where' => $where,
            'group' => $group,
            'aggregates' => $aggregates,
        ]));
    }

    protected function parseCondition(): ?Expression
    {
        return $this->parseWrapped(fn (): ?Expression => $this->parseExpression(), optional: true);
    }

    protected function parseBlock(): Block
    {
        return $this->expression(new Block([
            'expressions' => $this->parseBatchStatements(fn (): ?Expression => $this->parseStatement()),
        ]));
    }

    protected function parseWhileblock(): WhileBlock
    {
        return $this->expression(new WhileBlock([
            'this' => $this->parseCondition(),
            'body' => $this->parseBlock(),
        ]));
    }

    protected function parseStatement(): ?Expression
    {
        if ($this->curr === 0) {
            return null;
        }

        if ($this->matchSet($this->t['STATEMENT_PARSERS'])) {
            $comments = $this->prevComments;
            $statement = $this->callHandler($this->t['STATEMENT_PARSERS'][$this->prev]);
            $statement?->addComments($comments, prepend: true);

            return $statement;
        }

        if ($this->matchSet($this->commandTokens)) {
            return $this->parseCommand();
        }

        if ($this->matchTextSeq('WHILE')) {
            return $this->parseWhileblock();
        }

        $expression = $this->parseExpression();
        $expression = $expression !== null ? $this->parseSetOperations($expression) : $this->parseSelect();

        if ($expression instanceof Subquery && $this->match(TokenType::PIPE_GT, false)) {
            $expression = $this->parsePipeSyntaxQuery($expression);
        }

        return $this->parseQueryModifiers($expression);
    }

    protected function parseDrop(bool $exists = false): Expression
    {
        $start = $this->index - 1;
        $temporary = $this->match(TokenType::TEMPORARY);
        $materialized = $this->matchTextSeq('MATERIALIZED');
        $iceberg = $this->matchTextSeq('ICEBERG');

        $kind = $this->matchSet($this->t['CREATABLES']) ? strtoupper($this->prevText()) : null;

        if ($kind === null || ($iceberg && $kind !== 'TABLE')) {
            return $this->parseAsCommand($start);
        }

        $concurrently = $this->matchTextSeq('CONCURRENTLY');
        $ifExists = $exists || $this->parseExists();

        if ($kind === 'COLUMN') {
            $tables = $this->parseColumn();
        } elseif ($kind === 'TABLE' || $kind === 'VIEW') {
            $tables = $this->parseCsv(fn (): ?Expression => $this->parseTableParts(schema: true));
        } else {
            $tables = $this->parseTableParts(schema: true, isDbReference: $kind === 'SCHEMA');
        }

        $cluster = $this->match(TokenType::ON) ? $this->parseOnProperty() : null;

        $expressions = $this->match(TokenType::L_PAREN, false)
            ? $this->parseWrappedCsv(fn (): ?Expression => $this->parseTypes())
            : null;

        $cascadeOrRestrict = $this->matchTexts(['CASCADE' => true, 'RESTRICT' => true]) ? strtoupper($this->prevText()) : null;

        return $this->expression(new Drop([
            'exists' => $ifExists,
            'tables' => $tables === null ? [] : (is_array($tables) ? $tables : [$tables]),
            'expressions' => $expressions,
            'kind' => $this->dialect->settings['CREATABLE_KIND_MAPPING'][$kind] ?? $kind,
            'temporary' => $temporary,
            'materialized' => $materialized,
            'cascade' => $cascadeOrRestrict === 'CASCADE',
            'restrict' => $cascadeOrRestrict === 'RESTRICT',
            'constraints' => $this->matchTextSeq('CONSTRAINTS'),
            'purge' => $this->matchTextSeq('PURGE'),
            'cluster' => $cluster,
            'concurrently' => $concurrently,
            'sync' => $this->matchTextSeq('SYNC'),
            'iceberg' => $iceberg,
            'force' => $this->matchTextSeq('FORCE'),
        ]));
    }

    protected function parseExists(bool $not = false): bool
    {
        return $this->matchTextSeq('IF')
            && (! $not || $this->match(TokenType::NOT))
            && $this->match(TokenType::EXISTS);
    }

    protected function parseCreate(): Expression
    {
        // Note: this can't be null because we've matched a statement parser
        $start = $this->index - 1;

        $replace = $this->types[$start] === TokenType::REPLACE
            || $this->matchPair(TokenType::OR, TokenType::REPLACE)
            || $this->matchPair(TokenType::OR, TokenType::ALTER);
        $refresh = $this->matchPair(TokenType::OR, TokenType::REFRESH);

        $unique = $this->match(TokenType::UNIQUE);

        if ($this->matchTextSeq('CLUSTERED', 'COLUMNSTORE')) {
            $clustered = true;
        } elseif ($this->matchTextSeq('NONCLUSTERED', 'COLUMNSTORE') || $this->matchTextSeq('COLUMNSTORE')) {
            $clustered = false;
        } else {
            $clustered = null;
        }

        if ($this->matchPair(TokenType::TABLE, TokenType::FUNCTION, false)) {
            $this->advance();
        }

        $properties = null;
        $createToken = $this->matchSet($this->t['CREATABLES']) ? $this->index - 1 : null;

        if ($createToken === null) {
            // exp.Properties.Location.POST_CREATE
            $properties = $this->parseProperties();
            $createToken = $this->matchSet($this->t['CREATABLES']) ? $this->index - 1 : null;

            if ($properties === null || $createToken === null) {
                return $this->parseAsCommand($start);
            }
        }

        $createTokenType = $this->types[$createToken];

        $concurrently = $this->matchTextSeq('CONCURRENTLY');
        $exists = $this->parseExists(not: true);
        $this_ = null;
        $expression = null;
        $indexes = null;
        $noSchemaBinding = null;
        $begin = null;
        $clone = null;

        $extendProps = function (?Properties $tempProps) use (&$properties): void {
            if ($properties !== null && $tempProps !== null) {
                foreach ($tempProps->argList('expressions') as $property) {
                    $properties->append('expressions', $property);
                }
            } elseif ($tempProps !== null) {
                $properties = $tempProps;
            }
        };

        if ($createTokenType === TokenType::FUNCTION || $createTokenType === TokenType::PROCEDURE) {
            $this_ = $this->parseUserDefinedFunction($createTokenType);

            // exp.Properties.Location.POST_SCHEMA ("schema" here is the UDF's type signature)
            $extendProps($this->parseProperties());

            $expression = $this->match(TokenType::ALIAS) ? $this->parseHeredoc() : null;

            if (
                $expression === null
                && $createTokenType === TokenType::FUNCTION
                && $this_ instanceof UserDefinedFunction
                && $this_->flag('wrapped')
            ) {
                $preTableIndex = $this->index;
                $isTable = $this->match(TokenType::TABLE);

                $expression = $this->parseExpression();
                $overloadMode = $expression !== null && $this->curr === TokenType::COMMA && $this->next === TokenType::L_PAREN;

                if (! $overloadMode) {
                    $this->retreat($preTableIndex);
                    $isTable = false;
                    $expression = null;
                }
            } else {
                $isTable = false;
                $overloadMode = false;
            }

            $extendProps($this->parseFunctionProperties());

            if ($expression === null) {
                if ($this->match(TokenType::COMMAND)) {
                    $expression = $this->parseAsCommand($this->index - 1);
                } else {
                    $begin = $this->match(TokenType::BEGIN);
                    $return = $this->matchTextSeq('RETURN');

                    if ($this->match(TokenType::STRING, false)) {
                        // Takes care of BigQuery's JavaScript UDF definitions that end in an OPTIONS property
                        $expression = $this->parseString();
                        $extendProps($this->parseProperties());
                    } else {
                        $expression = $createTokenType === TokenType::FUNCTION
                            ? $this->parseUserDefinedFunctionExpression()
                            : $this->parseBlock();
                    }

                    if ($return) {
                        $expression = $this->expression(new ReturnExpr(['this' => $expression]));
                    }
                }
            }

            if ($overloadMode) {
                $expression = $this->parseMacroOverloads($this_, $expression, $isTable);
            }
        } elseif ($createTokenType === TokenType::INDEX) {
            // Postgres allows anonymous indexes, eg. CREATE INDEX IF NOT EXISTS ON t(c)
            if (! $this->match(TokenType::ON)) {
                $index = $this->parseIdVar();
                $anonymous = false;
            } else {
                $index = null;
                $anonymous = true;
            }

            $this_ = $this->parseIndex(index: $index, anonymous: $anonymous);
        } elseif (
            ($createTokenType === TokenType::CONSTRAINT && $this->match(TokenType::TRIGGER))
            || $createTokenType === TokenType::TRIGGER
        ) {
            $isConstraint = $createTokenType === TokenType::CONSTRAINT;

            if ($isConstraint) {
                $createToken = $this->index - 1;
            }

            $triggerName = $this->parseIdVar();

            if ($triggerName === null) {
                return $this->parseAsCommand($start);
            }

            $timingVar = $this->parseVarFromOptions($this->t['TRIGGER_TIMING'], raiseUnmatched: false);
            $timing = $timingVar?->this();

            if ($timing === null || $timing === '') {
                return $this->parseAsCommand($start);
            }

            $events = $this->parseTriggerEvents();

            if (! $this->match(TokenType::ON)) {
                $this->raiseError('Expected ON in trigger definition');
            }

            $table = $this->parseTableParts();
            $referencedTable = $this->match(TokenType::FROM) ? $this->parseTableParts() : null;
            [$deferrable, $initially] = $this->parseTriggerDeferrable();
            $referencing = $this->parseTriggerReferencing();
            $forEach = $this->parseTriggerForEach();
            $when = $this->matchTextSeq('WHEN') ? $this->parseWrapped(fn (): ?Expression => $this->parseDisjunction(), optional: true) : null;
            $execute = $this->parseTriggerExecute();

            if ($execute === null) {
                return $this->parseAsCommand($start);
            }

            $triggerProps = $this->expression(new TriggerProperties([
                'table' => $table,
                'timing' => $timing,
                'events' => $events,
                'execute' => $execute,
                'constraint' => $isConstraint,
                'referenced_table' => $referencedTable,
                'deferrable' => $deferrable,
                'initially' => $initially,
                'referencing' => $referencing,
                'for_each' => $forEach,
                'when' => $when,
            ]));

            $this_ = $triggerName;
            $extendProps(new Properties(['expressions' => [$triggerProps]]));
        } elseif ($createTokenType === TokenType::TYPE) {
            $this_ = $this->parseTableParts(schema: true);

            if ($this_ === null || ! $this->match(TokenType::ALIAS)) {
                return $this->parseAsCommand($start);
            }

            if ($this->match(TokenType::ENUM)) {
                $expression = new DataType([
                    'this' => DType::ENUM,
                    'expressions' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseString()),
                ]);
            } elseif ($this->match(TokenType::L_PAREN, false)) {
                $expression = $this->parseSchema();
            } else {
                return $this->parseAsCommand($start);
            }
        } elseif (isset($this->t['DB_CREATABLES'][$createTokenType])) {
            $tableParts = $this->parseTableParts(schema: true, isDbReference: $createTokenType === TokenType::SCHEMA);

            // exp.Properties.Location.POST_NAME
            $this->match(TokenType::COMMA);
            $extendProps($this->parseProperties(before: true));

            $this_ = $this->parseSchema($tableParts);

            // exp.Properties.Location.POST_SCHEMA and POST_WITH
            $extendProps($this->parseProperties());

            $hasAlias = $this->match(TokenType::ALIAS);

            if (! $this->matchSet($this->t['DDL_SELECT_TOKENS'], false)) {
                // exp.Properties.Location.POST_ALIAS
                $extendProps($this->parseProperties());
            }

            if ($createTokenType === TokenType::SEQUENCE) {
                $expression = $this->parseTypes();
                $props = $this->parseProperties();

                if ($props !== null) {
                    $sequenceProps = new SequenceProperties;
                    $options = [];

                    foreach ($props->argList('expressions') as $prop) {
                        if ($prop instanceof SequenceProperties) {
                            foreach ($prop->args as $arg => $value) {
                                if ($arg === 'options') {
                                    array_push($options, ...(is_array($value) ? $value : []));
                                } else {
                                    $sequenceProps->set($arg, $value);
                                }
                            }
                            $prop->pop();
                        }
                    }

                    if ($options !== []) {
                        $sequenceProps->set('options', $options);
                    }

                    $props->append('expressions', $sequenceProps);
                    $extendProps($props);
                }
            } else {
                $expression = $this->parseDdlSelect();

                // Some dialects also support using a table as an alias instead of a SELECT.
                // Here we fallback to this as an alternative.
                if ($expression === null && $hasAlias) {
                    $expression = $this->tryParse(fn (): ?Expression => $this->parseTableParts());
                }
            }

            if ($createTokenType === TokenType::TABLE) {
                // exp.Properties.Location.POST_EXPRESSION
                $extendProps($this->parseProperties());

                $indexes = [];
                while (true) {
                    $index = $this->parseIndex();

                    // exp.Properties.Location.POST_INDEX
                    $extendProps($this->parseProperties());

                    if ($index === null) {
                        break;
                    }

                    $this->match(TokenType::COMMA);
                    $indexes[] = $index;
                }
            } elseif ($createTokenType === TokenType::VIEW) {
                if ($this->matchTextSeq('WITH', 'NO', 'SCHEMA', 'BINDING')) {
                    $noSchemaBinding = true;
                }
            } elseif ($createTokenType === TokenType::SINK || $createTokenType === TokenType::SOURCE) {
                $extendProps($this->parseProperties());
            }

            $shallow = $this->matchTextSeq('SHALLOW');

            if ($this->matchTexts($this->t['CLONE_KEYWORDS'])) {
                $copy = strtolower($this->prevText()) === 'copy';
                $clone = $this->expression(new CloneExpr([
                    'this' => $this->parseTable(schema: true),
                    'shallow' => $shallow,
                    'copy' => $copy,
                ]));
            }
        }

        if ($this->curr !== 0 && ! $this->matchSet([TokenType::R_PAREN => true, TokenType::COMMA => true], false)) {
            return $this->parseAsCommand($start);
        }

        $createKindText = strtoupper($this->texts[$createToken]);

        return $this->expression(new Create([
            'this' => $this_,
            'kind' => $this->dialect->settings['CREATABLE_KIND_MAPPING'][$createKindText] ?? $createKindText,
            'replace' => $replace,
            'refresh' => $refresh,
            'unique' => $unique,
            'expression' => $expression,
            'exists' => $exists,
            'properties' => $properties,
            'indexes' => $indexes,
            'no_schema_binding' => $noSchemaBinding,
            'begin' => $begin,
            'clone' => $clone,
            'concurrently' => $concurrently,
            'clustered' => $clustered,
        ]));
    }

    protected function parseSequenceProperties(): ?SequenceProperties
    {
        $seq = new SequenceProperties;

        $options = [];
        $index = $this->index;

        while ($this->curr !== 0) {
            $this->match(TokenType::COMMA);

            if ($this->matchTextSeq('INCREMENT')) {
                $this->matchTextSeq('BY');
                $this->matchTextSeq('=');
                $seq->set('increment', $this->parseTerm());
            } elseif ($this->matchTextSeq('MINVALUE')) {
                $seq->set('minvalue', $this->parseTerm());
            } elseif ($this->matchTextSeq('MAXVALUE')) {
                $seq->set('maxvalue', $this->parseTerm());
            } elseif ($this->matchTextSeq('START')) {
                $this->matchTextSeq('WITH');
                $this->matchTextSeq('=');
                $seq->set('start', $this->parseTerm());
            } elseif ($this->matchTextSeq('CACHE')) {
                // T-SQL allows empty CACHE which is initialized dynamically
                $seq->set('cache', $this->parseNumber() ?? true);
            } elseif ($this->matchTextSeq('OWNED', 'BY')) {
                // "OWNED BY NONE" is the default
                $seq->set('owned', $this->matchTextSeq('NONE') ? null : $this->parseColumn());
            } else {
                $option = $this->parseVarFromOptions($this->t['CREATE_SEQUENCE'], raiseUnmatched: false);

                if ($option !== null) {
                    $options[] = $option;
                } else {
                    break;
                }
            }
        }

        $seq->set('options', $options !== [] ? $options : null);

        return $this->index === $index ? null : $seq;
    }

    /**
     * @return list<TriggerEvent>
     */
    protected function parseTriggerEvents(): array
    {
        $events = [];

        while (true) {
            $eventType = $this->matchSet($this->t['TRIGGER_EVENTS']) ? strtoupper($this->prevText()) : null;

            if ($eventType === null) {
                $this->raiseError('Expected trigger event (INSERT, UPDATE, DELETE, TRUNCATE)');
            }

            $columns = $eventType === 'UPDATE' && $this->matchTextSeq('OF')
                ? $this->parseCsv(fn (): ?Expression => $this->parseColumn())
                : null;

            $events[] = $this->expression(new TriggerEvent(['this' => $eventType, 'columns' => $columns]));

            if (! $this->match(TokenType::OR)) {
                break;
            }
        }

        return $events;
    }

    /**
     * @return array{0: mixed, 1: string|null}
     */
    protected function parseTriggerDeferrable(): array
    {
        $deferrableVar = $this->parseVarFromOptions($this->t['TRIGGER_DEFERRABLE'], raiseUnmatched: false);
        $deferrable = $deferrableVar?->this();

        $initially = null;

        if ($deferrable !== null && $this->matchTextSeq('INITIALLY')) {
            $initially = $this->matchTexts(['IMMEDIATE' => true, 'DEFERRED' => true]) ? strtoupper($this->prevText()) : null;
        }

        return [$deferrable, $initially];
    }

    protected function parseTriggerReferencingClause(string $keyword): ?Expression
    {
        if (! $this->matchTextSeq($keyword)) {
            return null;
        }

        if (! $this->matchTextSeq('TABLE')) {
            $this->raiseError("Expected TABLE after {$keyword} in REFERENCING clause");
        }

        $this->matchTextSeq('AS');

        return $this->parseIdVar();
    }

    protected function parseTriggerReferencing(): ?TriggerReferencing
    {
        if (! $this->matchTextSeq('REFERENCING')) {
            return null;
        }

        $oldAlias = null;
        $newAlias = null;

        while (true) {
            if (($alias = $this->parseTriggerReferencingClause('OLD')) !== null) {
                if ($oldAlias !== null) {
                    $this->raiseError('Duplicate OLD clause in REFERENCING');
                }
                $oldAlias = $alias;
            } elseif (($alias = $this->parseTriggerReferencingClause('NEW')) !== null) {
                if ($newAlias !== null) {
                    $this->raiseError('Duplicate NEW clause in REFERENCING');
                }
                $newAlias = $alias;
            } else {
                break;
            }
        }

        if ($oldAlias === null && $newAlias === null) {
            $this->raiseError('REFERENCING clause requires at least OLD TABLE or NEW TABLE');
        }

        return $this->expression(new TriggerReferencing(['old' => $oldAlias, 'new' => $newAlias]));
    }

    protected function parseTriggerForEach(): ?string
    {
        if (! $this->matchTextSeq('FOR', 'EACH')) {
            return null;
        }

        return $this->matchTexts(['ROW' => true, 'STATEMENT' => true]) ? strtoupper($this->prevText()) : null;
    }

    protected function parseTriggerExecute(): ?TriggerExecute
    {
        if (! $this->match(TokenType::EXECUTE)) {
            return null;
        }

        if (! $this->matchSet([TokenType::FUNCTION => true, TokenType::PROCEDURE => true])) {
            $this->raiseError('Expected FUNCTION or PROCEDURE after EXECUTE');
        }

        $funcCall = $this->parseColumn();

        return $this->expression(new TriggerExecute(['this' => $funcCall]));
    }
}
