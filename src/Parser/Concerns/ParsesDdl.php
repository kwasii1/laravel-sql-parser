<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Parser\Concerns;

use Kwasii\LaravelSqlParser\Expressions\AddConstraint;
use Kwasii\LaravelSqlParser\Expressions\AddPartition;
use Kwasii\LaravelSqlParser\Expressions\Alter;
use Kwasii\LaravelSqlParser\Expressions\AlterColumn;
use Kwasii\LaravelSqlParser\Expressions\AlterDistStyle;
use Kwasii\LaravelSqlParser\Expressions\AlterRename;
use Kwasii\LaravelSqlParser\Expressions\AlterSession;
use Kwasii\LaravelSqlParser\Expressions\AlterSet;
use Kwasii\LaravelSqlParser\Expressions\AlterSortKey;
use Kwasii\LaravelSqlParser\Expressions\Analyze;
use Kwasii\LaravelSqlParser\Expressions\AnalyzeColumns;
use Kwasii\LaravelSqlParser\Expressions\AnalyzeDelete;
use Kwasii\LaravelSqlParser\Expressions\AnalyzeHistogram;
use Kwasii\LaravelSqlParser\Expressions\AnalyzeListChainedRows;
use Kwasii\LaravelSqlParser\Expressions\AnalyzeSample;
use Kwasii\LaravelSqlParser\Expressions\AnalyzeStatistics;
use Kwasii\LaravelSqlParser\Expressions\AnalyzeValidate;
use Kwasii\LaravelSqlParser\Expressions\AnalyzeWith;
use Kwasii\LaravelSqlParser\Expressions\AutoIncrementColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\AutoRefreshProperty;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\CaseSpecificColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\CharacterSetColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\CheckColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\ColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\ColumnDef;
use Kwasii\LaravelSqlParser\Expressions\ColumnPosition;
use Kwasii\LaravelSqlParser\Expressions\Command;
use Kwasii\LaravelSqlParser\Expressions\CompressColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\ComputedColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Constraint;
use Kwasii\LaravelSqlParser\Expressions\Copy;
use Kwasii\LaravelSqlParser\Expressions\CopyParameter;
use Kwasii\LaravelSqlParser\Expressions\Credentials;
use Kwasii\LaravelSqlParser\Expressions\DeclareExpr;
use Kwasii\LaravelSqlParser\Expressions\DeclareItem;
use Kwasii\LaravelSqlParser\Expressions\Drop;
use Kwasii\LaravelSqlParser\Expressions\DropPartition;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\ForeignKey;
use Kwasii\LaravelSqlParser\Expressions\GeneratedAsIdentityColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\GeneratedAsRowColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Grant;
use Kwasii\LaravelSqlParser\Expressions\GrantPrincipal;
use Kwasii\LaravelSqlParser\Expressions\GrantPrivilege;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\InlineLengthColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\InOutColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Insert;
use Kwasii\LaravelSqlParser\Expressions\Merge;
use Kwasii\LaravelSqlParser\Expressions\NotForReplicationColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\NotNullColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\PeriodForSystemTimeConstraint;
use Kwasii\LaravelSqlParser\Expressions\PrimaryKey;
use Kwasii\LaravelSqlParser\Expressions\PrimaryKeyColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Property;
use Kwasii\LaravelSqlParser\Expressions\Reference;
use Kwasii\LaravelSqlParser\Expressions\RenameColumn;
use Kwasii\LaravelSqlParser\Expressions\Revoke;
use Kwasii\LaravelSqlParser\Expressions\Schema;
use Kwasii\LaravelSqlParser\Expressions\Set;
use Kwasii\LaravelSqlParser\Expressions\SetItem;
use Kwasii\LaravelSqlParser\Expressions\TruncateTable;
use Kwasii\LaravelSqlParser\Expressions\UniqueColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Update;
use Kwasii\LaravelSqlParser\Expressions\UsingData;
use Kwasii\LaravelSqlParser\Expressions\VarExpr;
use Kwasii\LaravelSqlParser\Expressions\When;
use Kwasii\LaravelSqlParser\Expressions\Whens;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Schemas, column definitions and constraints, ALTER, ANALYZE, MERGE, SET / SHOW, TRUNCATE,
 * COPY, GRANT / REVOKE and DECLARE.
 */
trait ParsesDdl
{
    protected function parseSchema(?Expression $this_ = null): ?Expression
    {
        $index = $this->index;

        if (! $this->match(TokenType::L_PAREN)) {
            return $this_;
        }

        // Disambiguate between schema and subquery/CTE, e.g. in INSERT INTO table (<expr>),
        // expr can be of both types
        if ($this->matchSet($this->t['SELECT_START_TOKENS'])) {
            $this->retreat($index);

            return $this_;
        }

        $args = $this->parseCsv(fn (): ?Expression => $this->parseConstraint() ?? $this->parseFieldDef());
        $this->matchRParen();

        return $this->expression(new Schema(['this' => $this_, 'expressions' => $args]));
    }

    protected function parseFieldDef(): ?Expression
    {
        return $this->parseColumnDef($this->parseField(anyToken: true));
    }

    protected function parseColumnDef(?Expression $this_, bool $computedColumn = true): ?Expression
    {
        // column defs are not really columns, they're identifiers
        if ($this_ instanceof Column) {
            $this_ = $this_->args['this'];
        }

        if (! $computedColumn) {
            $this->match(TokenType::ALIAS);
        }

        $kind = $this->parseTypes(schema: true);

        if ($this->matchTextSeq('FOR', 'ORDINALITY')) {
            return $this->expression(new ColumnDef(['this' => $this_, 'ordinality' => true]));
        }

        $constraints = [];

        if (($kind === null && $this->match(TokenType::ALIAS)) || $this->matchTexts(['ALIAS' => true, 'MATERIALIZED' => true])) {
            // Match storage before _parse_types so STORED is not treated as a data type
            // (needed for typeless columns, e.g. SQLite `b AS (a * 2) STORED`).
            $persisted = strtoupper($this->prevText()) === 'MATERIALIZED';
            $expression = $this->parseDisjunction();

            if (! $persisted) {
                if ($this->matchTextSeq('PERSISTED')) {
                    $persisted = true;
                } elseif ($this->matchTexts(['STORED' => true, 'VIRTUAL' => true])) {
                    $persisted = strtoupper($this->prevText()) === 'STORED';
                }
            }

            $constraintKind = new ComputedColumnConstraint([
                'this' => $expression,
                'persisted' => $persisted,
                'data_type' => $this->matchTextSeq('AUTO') ? new VarExpr(['this' => 'AUTO']) : $this->parseTypes(),
                'not_null' => $this->matchPair(TokenType::NOT, TokenType::NULL),
            ]);
            $constraints[] = $this->expression(new ColumnConstraint(['kind' => $constraintKind]));
        } elseif ($kind === null && $this->matchSet([TokenType::IN => true, TokenType::OUT => true], false)) {
            $constraints[] = $this->expression(new InOutColumnConstraint([
                'input_' => $this->match(TokenType::IN),
                'output' => $this->match(TokenType::OUT),
            ]));
            $kind = $this->parseTypes();
        } elseif (
            $kind !== null
            && $this->match(TokenType::ALIAS, false)
            && ($this->t['WRAPPED_TRANSFORM_COLUMN_CONSTRAINT'] !== true || $this->next === TokenType::L_PAREN)
        ) {
            $this->advance();
            $constraints[] = $this->expression(new ColumnConstraint([
                'kind' => new ComputedColumnConstraint([
                    'this' => $this->parseDisjunction(),
                    'persisted' => $this->matchTexts(['STORED' => true, 'VIRTUAL' => true]) && strtoupper($this->prevText()) === 'STORED',
                ]),
            ]));
        }

        while (true) {
            $constraint = $this->parseColumnConstraint();

            if ($constraint === null) {
                break;
            }

            $constraints[] = $constraint;
        }

        if ($kind === null && $constraints === []) {
            return $this_;
        }

        $position = null;

        if ($this->matchTexts(['FIRST' => true, 'AFTER' => true])) {
            $pos = $this->prevText();
            $position = $this->expression(new ColumnPosition(['this' => $this->parseColumn(), 'position' => $pos]));
        }

        return $this->expression(new ColumnDef(['this' => $this_, 'kind' => $kind, 'constraints' => $constraints, 'position' => $position]));
    }

    protected function parseAutoIncrement(): Expression
    {
        $start = null;
        $increment = null;
        $order = null;

        if ($this->match(TokenType::L_PAREN, false)) {
            $args = $this->parseWrappedCsv(fn (): ?Expression => $this->parseBitwise());
            $start = $args[0] ?? null;
            $increment = $args[1] ?? null;
        }

        // The remaining parts form an unordered bag and any of them can be omitted, in which
        // case the engine falls back to its own default, so they're parsed independently.
        while (true) {
            if ($this->matchTextSeq('START')) {
                $start = $this->parseBitwise();
            } elseif ($this->matchTextSeq('INCREMENT')) {
                $increment = $this->parseBitwise();
            } elseif ($this->matchTextSeq('ORDER')) {
                $order = true;
            } elseif ($this->matchTextSeq('NOORDER')) {
                $order = false;
            } else {
                break;
            }
        }

        if ($start !== null || $increment !== null || $order !== null) {
            return new GeneratedAsIdentityColumnConstraint(['start' => $start, 'increment' => $increment, 'this' => false, 'order' => $order]);
        }

        return new AutoIncrementColumnConstraint;
    }

    protected function parseCheckConstraint(): ?CheckColumnConstraint
    {
        if (! $this->match(TokenType::L_PAREN, false)) {
            return null;
        }

        return $this->expression(new CheckColumnConstraint([
            'this' => $this->parseWrapped(fn (): ?Expression => $this->parseAssignment()),
            'enforced' => $this->matchTextSeq('ENFORCED'),
        ]));
    }

    protected function parseAutoProperty(): ?AutoRefreshProperty
    {
        if (! $this->matchTextSeq('REFRESH')) {
            $this->retreat($this->index - 1);

            return null;
        }

        return $this->expression(new AutoRefreshProperty(['this' => $this->parseVar(upper: true)]));
    }

    protected function parseCompress(): CompressColumnConstraint
    {
        if ($this->match(TokenType::L_PAREN, false)) {
            return $this->expression(new CompressColumnConstraint(['this' => $this->parseWrappedCsv(fn (): ?Expression => $this->parseBitwise())]));
        }

        return $this->expression(new CompressColumnConstraint(['this' => $this->parseBitwise()]));
    }

    protected function parseGeneratedAsIdentity(): Expression
    {
        if ($this->matchTextSeq('BY', 'DEFAULT')) {
            $onNull = $this->matchPair(TokenType::ON, TokenType::NULL);
            $this_ = $this->expression(new GeneratedAsIdentityColumnConstraint(['this' => false, 'on_null' => $onNull]));
        } else {
            $this->matchTextSeq('ALWAYS');
            $this_ = $this->expression(new GeneratedAsIdentityColumnConstraint(['this' => true]));
        }

        $this->match(TokenType::ALIAS);

        if ($this->matchTextSeq('ROW')) {
            $start = $this->matchTextSeq('START');

            if (! $start) {
                $this->match(TokenType::END);
            }

            $hidden = $this->matchTextSeq('HIDDEN');

            return $this->expression(new GeneratedAsRowColumnConstraint(['start' => $start, 'hidden' => $hidden]));
        }

        $identity = $this->matchTextSeq('IDENTITY');

        if ($this->match(TokenType::L_PAREN)) {
            if ($this->matchTextSeq('START', 'WITH')) {
                $this_->set('start', $this->parseBitwise());
            }

            if ($this->matchTextSeq('INCREMENT', 'BY')) {
                $this_->set('increment', $this->parseBitwise());
            }

            if ($this->matchTextSeq('MINVALUE')) {
                $this_->set('minvalue', $this->parseBitwise());
            }

            if ($this->matchTextSeq('MAXVALUE')) {
                $this_->set('maxvalue', $this->parseBitwise());
            }

            if ($this->matchTextSeq('CYCLE')) {
                $this_->set('cycle', true);
            } elseif ($this->matchTextSeq('NO', 'CYCLE')) {
                $this_->set('cycle', false);
            }

            if (! $identity) {
                $this_->set('expression', $this->parseRange());
            } elseif (! $this_->flag('start') && $this->match(TokenType::NUMBER, false)) {
                $args = $this->parseCsv(fn (): ?Expression => $this->parseBitwise());
                $this_->set('start', $args[0] ?? null);
                $this_->set('increment', $args[1] ?? null);
            }

            $this->matchRParen();
        }

        return $this_;
    }

    protected function parseInline(): InlineLengthColumnConstraint
    {
        $this->matchTextSeq('LENGTH');

        return $this->expression(new InlineLengthColumnConstraint(['this' => $this->parseBitwise()]));
    }

    protected function parseNotConstraint(): ?Expression
    {
        if ($this->matchTextSeq('NULL')) {
            return $this->expression(new NotNullColumnConstraint);
        }

        if ($this->matchTextSeq('CASESPECIFIC')) {
            return $this->expression(new CaseSpecificColumnConstraint(['not_' => true]));
        }

        if ($this->matchTextSeq('FOR', 'REPLICATION')) {
            return $this->expression(new NotForReplicationColumnConstraint);
        }

        // Unconsume the `NOT` token
        $this->retreat($this->index - 1);

        return null;
    }

    protected function parseColumnConstraint(): ?Expression
    {
        $this_ = $this->match(TokenType::CONSTRAINT) ? $this->parseIdVar() : null;

        $procedureOptionFollows = $this->match(TokenType::WITH, false)
            && $this->next !== 0
            && isset($this->t['PROCEDURE_OPTIONS'][strtoupper($this->nextText())]);

        $index = $this->index;

        if (! $procedureOptionFollows && $this->matchTexts($this->t['CONSTRAINT_PARSERS'])) {
            $constraint = $this->callHandler($this->t['CONSTRAINT_PARSERS'][strtoupper($this->prevText())]);

            if ($constraint === null) {
                $this->retreat($index);

                return null;
            }

            return $this->expression(new ColumnConstraint(['this' => $this_, 'kind' => $constraint]));
        }

        if ($this->matchTextSeq('CHARACTER', 'SET')) {
            return $this->expression(new ColumnConstraint([
                'this' => $this_,
                'kind' => $this->expression(new CharacterSetColumnConstraint(['this' => $this->parseVarOrString()])),
            ]));
        }

        return $this_;
    }

    protected function parseConstraint(): ?Expression
    {
        if (! $this->match(TokenType::CONSTRAINT)) {
            return $this->parseUnnamedConstraint($this->t['SCHEMA_UNNAMED_CONSTRAINTS']);
        }

        return $this->expression(new Constraint(['this' => $this->parseIdVar(), 'expressions' => $this->parseUnnamedConstraints()]));
    }

    /**
     * @return list<Expression>
     */
    protected function parseUnnamedConstraints(): array
    {
        $constraints = [];

        while (true) {
            $constraint = $this->parseUnnamedConstraint() ?? $this->parseFunction();

            if ($constraint === null) {
                break;
            }

            $constraints[] = $constraint;
        }

        return $constraints;
    }

    /**
     * @param  array<string, mixed>|null  $constraints
     */
    protected function parseUnnamedConstraint(?array $constraints = null): ?Expression
    {
        $index = $this->index;

        if ($this->match(TokenType::IDENTIFIER, false) || ! $this->matchTexts($constraints !== null && $constraints !== [] ? $constraints : $this->t['CONSTRAINT_PARSERS'])) {
            return null;
        }

        $constraintKey = strtoupper($this->prevText());

        if (! isset($this->t['CONSTRAINT_PARSERS'][$constraintKey])) {
            $this->raiseError("No parser found for schema constraint {$constraintKey}.");

            return null;
        }

        $result = $this->callHandler($this->t['CONSTRAINT_PARSERS'][$constraintKey]);

        if ($result === null) {
            $this->retreat($index);
        }

        return $result;
    }

    protected function parseUniqueKey(): ?Expression
    {
        if (
            $this->curr !== 0
            && $this->curr !== TokenType::IDENTIFIER
            && isset($this->t['CONSTRAINT_PARSERS'][strtoupper($this->currText())])
        ) {
            return null;
        }

        return $this->parseIdVar(anyToken: false);
    }

    protected function parseUnique(): UniqueColumnConstraint
    {
        $this->matchTexts(['KEY' => true, 'INDEX' => true]);

        return $this->expression(new UniqueColumnConstraint([
            'nulls' => $this->matchTextSeq('NULLS', 'NOT', 'DISTINCT'),
            'this' => $this->parseSchema($this->parseUniqueKey()),
            'index_type' => $this->match(TokenType::USING) && $this->advanceAny() !== null ? $this->prevText() : null,
            'on_conflict' => $this->parseOnConflict(),
            'options' => $this->parseKeyConstraintOptions(),
        ]));
    }

    /**
     * @return list<string>
     */
    protected function parseKeyConstraintOptions(): array
    {
        $options = [];

        while (true) {
            if ($this->curr === 0) {
                break;
            }

            if ($this->match(TokenType::ON)) {
                $action = null;
                $on = $this->advanceAny() !== null ? $this->prevText() : 'None';

                if ($this->matchTextSeq('NO', 'ACTION')) {
                    $action = 'NO ACTION';
                } elseif ($this->matchTextSeq('CASCADE')) {
                    $action = 'CASCADE';
                } elseif ($this->matchTextSeq('RESTRICT')) {
                    $action = 'RESTRICT';
                } elseif ($this->matchPair(TokenType::SET, TokenType::NULL)) {
                    $action = 'SET NULL';
                } elseif ($this->matchPair(TokenType::SET, TokenType::DEFAULT)) {
                    $action = 'SET DEFAULT';
                } else {
                    $this->raiseError('Invalid key constraint');
                }

                $options[] = "ON {$on} ".($action ?? 'None');
            } else {
                $var = $this->parseVarFromOptions($this->t['KEY_CONSTRAINT_OPTIONS'], raiseUnmatched: false);

                if ($var === null) {
                    break;
                }

                $options[] = $var->name();
            }
        }

        return $options;
    }

    protected function parseReferences(bool $match = true): ?Reference
    {
        if ($match && ! $this->match(TokenType::REFERENCES)) {
            return null;
        }

        $this_ = $this->parseTable(schema: true);
        $options = $this->parseKeyConstraintOptions();

        return $this->expression(new Reference(['this' => $this_, 'expressions' => null, 'options' => $options]));
    }

    protected function parseForeignKey(): ForeignKey
    {
        $expressions = ! $this->match(TokenType::REFERENCES, false) ? $this->parseWrappedIdVars() : null;
        $reference = $this->parseReferences();
        $onOptions = [];

        while ($this->match(TokenType::ON)) {
            if (! $this->matchSet([TokenType::DELETE => true, TokenType::UPDATE => true])) {
                $this->raiseError('Expected DELETE or UPDATE');
            }

            $kind = strtolower($this->prevText());

            if ($this->matchTextSeq('NO', 'ACTION')) {
                $action = 'NO ACTION';
            } elseif ($this->match(TokenType::SET)) {
                $this->matchSet([TokenType::NULL => true, TokenType::DEFAULT => true]);
                $action = 'SET '.strtoupper($this->prevText());
            } else {
                $this->advance();
                $action = strtoupper($this->prevText());
            }

            $onOptions[$kind] = $action;
        }

        return $this->expression(new ForeignKey([
            'expressions' => $expressions,
            'reference' => $reference,
            'options' => $this->parseKeyConstraintOptions(),
            ...$onOptions,
        ]));
    }

    protected function parsePrimaryKeyPart(): ?Expression
    {
        return $this->parseField();
    }

    protected function parsePeriodForSystemTime(): ?PeriodForSystemTimeConstraint
    {
        if (! $this->matchTextSeq('FOR', 'SYSTEM_TIME')) {
            $this->retreat($this->index - 1);

            return null;
        }

        $idVars = $this->parseWrappedIdVars();

        return $this->expression(new PeriodForSystemTimeConstraint(['this' => $idVars[0] ?? null, 'expression' => $idVars[1] ?? null]));
    }

    protected function parsePrimaryKey(bool $wrappedOptional = false, bool $inProps = false, bool $namedPrimaryKey = false): Expression
    {
        $desc = $this->matchSet([TokenType::ASC => true, TokenType::DESC => true]) ? $this->prev === TokenType::DESC : null;

        $this_ = null;

        if (
            $namedPrimaryKey
            && ! isset($this->t['CONSTRAINT_PARSERS'][strtoupper($this->currText())])
            && $this->next === TokenType::L_PAREN
        ) {
            $this_ = $this->parseIdVar();
        }

        if (! $inProps && ! $this->match(TokenType::L_PAREN, false)) {
            return $this->expression(new PrimaryKeyColumnConstraint(['desc' => $desc, 'options' => $this->parseKeyConstraintOptions()]));
        }

        $expressions = $this->parseWrappedCsv(fn (): ?Expression => $this->parsePrimaryKeyPart(), optional: $wrappedOptional);

        return $this->expression(new PrimaryKey([
            'this' => $this_,
            'expressions' => $expressions,
            'include' => $this->parseIndexParams(),
            'options' => $this->parseKeyConstraintOptions(),
        ]));
    }

    protected function parseColumnDefWithExists(): ?ColumnDef
    {
        $start = $this->index;
        $this->match(TokenType::COLUMN);

        $existsColumn = $this->parseExists(not: true);
        $expression = $this->parseFieldDef();

        if (! $expression instanceof ColumnDef) {
            $this->retreat($start);

            return null;
        }

        $expression->set('exists', $existsColumn);

        return $expression;
    }

    protected function parseAddColumn(): ?ColumnDef
    {
        if (strtoupper($this->prevText()) !== 'ADD') {
            return null;
        }

        return $this->parseColumnDefWithExists();
    }

    protected function parseDropColumn(): ?Expression
    {
        $drop = $this->match(TokenType::DROP) ? $this->parseDrop() : null;

        if ($drop !== null && ! $drop instanceof Command) {
            $drop->set('kind', $drop->args['kind'] ?? 'COLUMN');
        }

        return $drop;
    }

    protected function parseAlterDropAction(): ?Expression
    {
        return $this->parseDropColumn();
    }

    // https://docs.aws.amazon.com/athena/latest/ug/alter-table-drop-partition.html
    protected function parseDropPartition(?bool $exists = null): DropPartition
    {
        return $this->expression(new DropPartition([
            'expressions' => $this->parseCsv(fn (): ?Expression => $this->parsePartition()),
            'exists' => $exists,
        ]));
    }

    /**
     * @return list<Expression>
     */
    protected function parseAlterTableAdd(): array
    {
        $parseAddAlteration = function (): ?Expression {
            $this->matchTextSeq('ADD');

            if ($this->matchSet($this->t['ADD_CONSTRAINT_TOKENS'], false)) {
                return $this->expression(new AddConstraint(['expressions' => $this->parseCsv(fn (): ?Expression => $this->parseConstraint())]));
            }

            $columnDef = $this->parseAddColumn();

            if ($columnDef instanceof ColumnDef) {
                return $columnDef;
            }

            $exists = $this->parseExists(not: true);

            if ($this->matchPair(TokenType::PARTITION, TokenType::L_PAREN, false)) {
                return $this->expression(new AddPartition([
                    'exists' => $exists,
                    'this' => $this->parseField(anyToken: true),
                    'location' => $this->peekTextSeq('LOCATION') ? $this->parseProperty() : null,
                ]));
            }

            return null;
        };

        if (
            ! $this->matchSet($this->t['ADD_CONSTRAINT_TOKENS'], false)
            && ($this->dialect->settings['ALTER_TABLE_ADD_REQUIRED_FOR_EACH_COLUMN'] !== true || $this->matchTextSeq('COLUMNS'))
        ) {
            $schema = $this->parseSchema();

            return $schema !== null
                ? [$schema]
                : $this->parseCsv(fn (): ?Expression => $this->parseColumnDefWithExists());
        }

        return $this->parseCsv($parseAddAlteration);
    }

    protected function parseAlterTableAlter(): ?Expression
    {
        if ($this->matchTexts($this->t['ALTER_ALTER_PARSERS'])) {
            return $this->callHandler($this->t['ALTER_ALTER_PARSERS'][strtoupper($this->prevText())]);
        }

        // Many dialects support the ALTER [COLUMN] syntax, so if there is no
        // keyword after ALTER we default to parsing this statement
        $this->match(TokenType::COLUMN);
        $exists = $this->parseExists() ? true : null;
        $column = $this->parseField(anyToken: true);

        if ($this->matchPair(TokenType::DROP, TokenType::DEFAULT)) {
            return $this->expression(new AlterColumn(['this' => $column, 'drop' => true, 'exists' => $exists]));
        }

        if ($this->matchPair(TokenType::SET, TokenType::DEFAULT)) {
            return $this->expression(new AlterColumn(['this' => $column, 'default' => $this->parseDisjunction(), 'exists' => $exists]));
        }

        if ($this->match(TokenType::COMMENT)) {
            return $this->expression(new AlterColumn(['this' => $column, 'comment' => $this->parseString(), 'exists' => $exists]));
        }

        if ($this->matchTextSeq('DROP', 'NOT', 'NULL')) {
            return $this->expression(new AlterColumn(['this' => $column, 'drop' => true, 'allow_null' => true, 'exists' => $exists]));
        }

        if ($this->matchTextSeq('SET', 'NOT', 'NULL')) {
            return $this->expression(new AlterColumn(['this' => $column, 'allow_null' => false, 'exists' => $exists]));
        }

        if ($this->matchTextSeq('SET', 'VISIBLE')) {
            return $this->expression(new AlterColumn(['this' => $column, 'visible' => 'VISIBLE', 'exists' => $exists]));
        }

        if ($this->matchTextSeq('SET', 'INVISIBLE')) {
            return $this->expression(new AlterColumn(['this' => $column, 'visible' => 'INVISIBLE', 'exists' => $exists]));
        }

        $this->matchTextSeq('SET', 'DATA');
        $this->matchTextSeq('TYPE');

        return $this->expression(new AlterColumn([
            'this' => $column,
            'dtype' => $this->parseTypes(),
            'collate' => $this->match(TokenType::COLLATE) ? $this->parseTerm() : null,
            'using' => $this->match(TokenType::USING) ? $this->parseDisjunction() : null,
            'exists' => $exists,
        ]));
    }

    protected function parseAlterDiststyle(): AlterDistStyle
    {
        if ($this->matchTexts(['ALL' => true, 'EVEN' => true, 'AUTO' => true])) {
            return $this->expression(new AlterDistStyle(['this' => Builder::var(strtoupper($this->prevText()))]));
        }

        $this->matchTextSeq('KEY', 'DISTKEY');

        return $this->expression(new AlterDistStyle(['this' => $this->parseColumn()]));
    }

    protected function parseAlterSortkey(?bool $compound = null): AlterSortKey
    {
        if ($compound === true) {
            $this->matchTextSeq('SORTKEY');
        }

        if ($this->match(TokenType::L_PAREN, false)) {
            return $this->expression(new AlterSortKey(['expressions' => $this->parseWrappedIdVars(), 'compound' => $compound]));
        }

        $this->matchTexts(['AUTO' => true, 'NONE' => true]);

        return $this->expression(new AlterSortKey(['this' => Builder::var(strtoupper($this->prevText())), 'compound' => $compound]));
    }

    /**
     * @return list<Expression>
     */
    protected function parseAlterTableDrop(): array
    {
        $index = $this->index - 1;

        $partitionExists = $this->parseExists();

        if ($this->match(TokenType::PARTITION, false)) {
            return $this->parseCsv(fn (): DropPartition => $this->parseDropPartition(exists: $partitionExists));
        }

        $this->retreat($index);

        return $this->parseCsv(fn (): ?Expression => $this->parseAlterDropAction());
    }

    protected function parseAlterTableRename(): ?Expression
    {
        if ($this->match(TokenType::COLUMN) || ($this->t['ALTER_RENAME_REQUIRES_COLUMN'] !== true && ! $this->peekTextSeq('TO'))) {
            $exists = $this->parseExists();
            $oldColumn = $this->parseColumn();
            $to = $this->matchTextSeq('TO');
            $newColumn = $this->parseColumn();

            if ($oldColumn === null || ! $to || $newColumn === null) {
                return null;
            }

            return $this->expression(new RenameColumn(['this' => $oldColumn, 'to' => $newColumn, 'exists' => $exists]));
        }

        $this->matchTextSeq('TO');

        return $this->expression(new AlterRename(['this' => $this->parseTable(schema: true)]));
    }

    protected function parseAlterTableSet(): AlterSet
    {
        $alterSet = $this->expression(new AlterSet);

        if ($this->match(TokenType::L_PAREN, false) || $this->matchTextSeq('TABLE', 'PROPERTIES')) {
            $alterSet->set('expressions', $this->parseWrappedCsv(fn (): ?Expression => $this->parseAssignment()));
        } elseif ($this->peekTextSeq('FILESTREAM_ON')) {
            $alterSet->set('expressions', [$this->parseAssignment()]);
        } elseif ($this->matchTexts(['LOGGED' => true, 'UNLOGGED' => true])) {
            $alterSet->set('option', Builder::var(strtoupper($this->prevText())));
        } elseif ($this->matchTextSeq('WITHOUT') && $this->matchTexts(['CLUSTER' => true, 'OIDS' => true])) {
            $alterSet->set('option', Builder::var('WITHOUT '.strtoupper($this->prevText())));
        } elseif ($this->matchTextSeq('LOCATION')) {
            $alterSet->set('location', $this->parseField());
        } elseif ($this->matchTextSeq('ACCESS', 'METHOD')) {
            $alterSet->set('access_method', $this->parseField());
        } elseif ($this->matchTextSeq('TABLESPACE')) {
            $alterSet->set('tablespace', $this->parseField());
        } elseif ($this->matchTextSeq('FILE', 'FORMAT') || $this->matchTextSeq('FILEFORMAT')) {
            $alterSet->set('file_format', [$this->parseField()]);
        } elseif ($this->matchTextSeq('STAGE_FILE_FORMAT')) {
            $alterSet->set('file_format', $this->parseWrappedOptions());
        } elseif ($this->matchTextSeq('STAGE_COPY_OPTIONS')) {
            $alterSet->set('copy_options', $this->parseWrappedOptions());
        } elseif ($this->matchTextSeq('TAG') || $this->matchTextSeq('TAGS')) {
            $alterSet->set('tag', $this->parseCsv(fn (): ?Expression => $this->parseAssignment()));
        } else {
            if ($this->matchTextSeq('SERDE')) {
                $alterSet->set('serde', $this->parseField());
            }

            $properties = $this->parseWrapped(fn (): ?Expression => $this->parseProperties(), optional: true);
            $alterSet->set('expressions', [$properties]);
        }

        return $alterSet;
    }

    /**
     * Parse ALTER SESSION SET/UNSET statements.
     */
    protected function parseAlterSession(): AlterSession
    {
        if ($this->match(TokenType::SET)) {
            $expressions = $this->parseCsv(fn (): ?Expression => $this->parseSetItemAssignment());

            return $this->expression(new AlterSession(['expressions' => $expressions, 'unset' => false]));
        }

        $this->matchTextSeq('UNSET');
        $expressions = $this->parseCsv(fn (): Expression => $this->expression(new SetItem(['this' => $this->parseIdVar(anyToken: true)])));

        return $this->expression(new AlterSession(['expressions' => $expressions, 'unset' => true]));
    }

    protected function parseAlter(): Expression
    {
        $start = $this->index - 1;

        $iceberg = $this->matchTextSeq('ICEBERG');

        $alterToken = $this->matchSet($this->t['ALTERABLES']) ? $this->index - 1 : null;

        if ($alterToken === null) {
            return $this->parseAsCommand($start);
        }

        if ($iceberg && $this->types[$alterToken] !== TokenType::TABLE) {
            return $this->parseAsCommand($start);
        }

        $exists = $this->parseExists();
        $only = $this->matchTextSeq('ONLY');

        if ($this->types[$alterToken] === TokenType::SESSION) {
            $this_ = null;
            $check = null;
            $cluster = null;
        } else {
            $this_ = $this->parseTable(schema: true, parsePartition: $this->t['ALTER_TABLE_PARTITIONS'] === true);
            $check = $this->matchTextSeq('WITH', 'CHECK');
            $cluster = $this->match(TokenType::ON) ? $this->parseOnProperty() : null;

            if ($this->next !== 0) {
                $this->advance();
            }
        }

        $parser = $this->prev !== 0 ? ($this->t['ALTER_PARSERS'][strtoupper($this->prevText())] ?? null) : null;

        if ($parser !== null) {
            $actions = $this->callHandler($parser);
            $actions = $actions === null ? [] : (is_array($actions) ? $actions : [$actions]);
            $notValid = $this->matchTextSeq('NOT', 'VALID');
            $options = $this->parseCsv(fn (): Expression|array|null => $this->parseProperty());
            $cascade = $this->dialect->settings['ALTER_TABLE_SUPPORTS_CASCADE'] === true && $this->matchTextSeq('CASCADE');

            if ($this->curr === 0 && $actions !== []) {
                return $this->expression(new Alter([
                    'this' => $this_,
                    'kind' => strtoupper($this->texts[$alterToken]),
                    'exists' => $exists,
                    'actions' => $actions,
                    'only' => $only,
                    'options' => $options,
                    'cluster' => $cluster,
                    'not_valid' => $notValid,
                    'check' => $check,
                    'cascade' => $cascade,
                    'iceberg' => $iceberg,
                ]));
            }
        }

        return $this->parseAsCommand($start);
    }

    protected function parseAnalyze(): Expression
    {
        $start = $this->index - 1;

        // https://duckdb.org/docs/sql/statements/analyze
        if ($this->curr === 0) {
            return $this->expression(new Analyze);
        }

        $options = [];

        while ($this->matchTexts($this->t['ANALYZE_STYLES'])) {
            if (strtoupper($this->prevText()) === 'BUFFER_USAGE_LIMIT') {
                $options[] = 'BUFFER_USAGE_LIMIT '.($this->parseNumber()?->sql() ?? 'None');
            } else {
                $options[] = strtoupper($this->prevText());
            }
        }

        $tables = null;
        $innerExpression = null;

        $kind = $this->curr !== 0 ? strtoupper($this->currText()) : null;

        if ($this->match(TokenType::TABLE)) {
            $tables = $this->parseCsv(fn (): ?Expression => $this->parseTableParts());
        } elseif ($this->match(TokenType::INDEX)) {
            $tables = $this->parseTableParts();
        } elseif ($this->matchTextSeq('TABLES')) {
            if ($this->matchSet([TokenType::FROM => true, TokenType::IN => true])) {
                $kind = "{$kind} ".strtoupper($this->prevText());
                $tables = $this->parseTable(schema: true, isDbReference: true);
            }
        } elseif ($this->matchTextSeq('DATABASE')) {
            $tables = $this->parseTable(schema: true, isDbReference: true);
        } elseif ($this->matchTextSeq('CLUSTER')) {
            $tables = $this->parseTable();
        } elseif ($this->matchTexts($this->t['ANALYZE_EXPRESSION_PARSERS'])) {
            // Try matching inner expr keywords before fallback to parse table.
            $kind = null;
            $innerExpression = $this->callHandler($this->t['ANALYZE_EXPRESSION_PARSERS'][strtoupper($this->prevText())]);
        } else {
            // Empty kind  https://prestodb.io/docs/current/sql/analyze.html
            $kind = null;
            $tables = $this->parseCsv(fn (): ?Expression => $this->parseTableParts());
        }

        $partition = $this->tryParse(fn (): ?Expression => $this->parsePartition());

        if ($partition === null && $this->matchTexts($this->t['PARTITION_KEYWORDS'])) {
            return $this->parseAsCommand($start);
        }

        // https://docs.starrocks.io/docs/sql-reference/sql-statements/cbo_stats/ANALYZE_TABLE/
        if ($this->matchTextSeq('WITH', 'SYNC', 'MODE') || $this->matchTextSeq('WITH', 'ASYNC', 'MODE')) {
            $mode = 'WITH '.strtoupper($this->texts[$this->index - 2]).' MODE';
        } else {
            $mode = null;
        }

        if ($this->matchTexts($this->t['ANALYZE_EXPRESSION_PARSERS'])) {
            $innerExpression = $this->callHandler($this->t['ANALYZE_EXPRESSION_PARSERS'][strtoupper($this->prevText())]);
        }

        $properties = $this->parseProperties();

        return $this->expression(new Analyze([
            'kind' => $kind,
            'tables' => $tables === null ? [] : (is_array($tables) ? $tables : [$tables]),
            'mode' => $mode,
            'partition' => $partition,
            'properties' => $properties,
            'expression' => $innerExpression,
            'options' => $options,
        ]));
    }

    // https://spark.apache.org/docs/3.5.1/sql-ref-syntax-aux-analyze-table.html
    protected function parseAnalyzeStatistics(): AnalyzeStatistics
    {
        $this_ = null;
        $kind = strtoupper($this->prevText());
        $option = $this->matchTextSeq('DELTA') ? strtoupper($this->prevText()) : null;
        $expressions = [];

        if (! $this->matchTextSeq('STATISTICS')) {
            $this->raiseError('Expecting token STATISTICS');
        }

        if ($this->matchTextSeq('NOSCAN')) {
            $this_ = 'NOSCAN';
        } elseif ($this->match(TokenType::FOR)) {
            if ($this->matchTextSeq('ALL', 'COLUMNS')) {
                $this_ = 'FOR ALL COLUMNS';
            }

            if ($this->matchTextSeq('COLUMNS')) {
                $this_ = 'FOR COLUMNS';
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseColumnReference());
            }
        } elseif ($this->matchTextSeq('SAMPLE')) {
            $sample = $this->parseNumber();
            $expressions = [$this->expression(new AnalyzeSample([
                'sample' => $sample,
                'kind' => $this->match(TokenType::PERCENT) ? strtoupper($this->prevText()) : null,
            ]))];
        }

        return $this->expression(new AnalyzeStatistics(['kind' => $kind, 'option' => $option, 'this' => $this_, 'expressions' => $expressions]));
    }

    // https://docs.oracle.com/en/database/oracle/oracle-database/21/sqlrf/ANALYZE.html
    protected function parseAnalyzeValidate(): AnalyzeValidate
    {
        $kind = null;
        $this_ = null;
        $expression = null;

        if ($this->matchTextSeq('REF', 'UPDATE')) {
            $kind = 'REF';
            $this_ = 'UPDATE';

            if ($this->matchTextSeq('SET', 'DANGLING', 'TO', 'NULL')) {
                $this_ = 'UPDATE SET DANGLING TO NULL';
            }
        } elseif ($this->matchTextSeq('STRUCTURE')) {
            $kind = 'STRUCTURE';

            if ($this->matchTextSeq('CASCADE', 'FAST')) {
                $this_ = 'CASCADE FAST';
            } elseif ($this->matchTextSeq('CASCADE', 'COMPLETE') && $this->matchTexts(['ONLINE' => true, 'OFFLINE' => true])) {
                $this_ = 'CASCADE COMPLETE '.strtoupper($this->prevText());
                $expression = $this->parseIntoClause();
            }
        }

        return $this->expression(new AnalyzeValidate(['kind' => $kind, 'this' => $this_, 'expression' => $expression]));
    }

    protected function parseAnalyzeColumns(): ?AnalyzeColumns
    {
        $this_ = strtoupper($this->prevText());

        if ($this->matchTextSeq('COLUMNS')) {
            return $this->expression(new AnalyzeColumns(['this' => "{$this_} ".strtoupper($this->prevText())]));
        }

        return null;
    }

    protected function parseAnalyzeDelete(): ?AnalyzeDelete
    {
        $kind = $this->matchTextSeq('SYSTEM') ? strtoupper($this->prevText()) : null;

        if ($this->matchTextSeq('STATISTICS')) {
            return $this->expression(new AnalyzeDelete(['kind' => $kind]));
        }

        return null;
    }

    protected function parseAnalyzeList(): ?AnalyzeListChainedRows
    {
        if ($this->matchTextSeq('CHAINED', 'ROWS')) {
            return $this->expression(new AnalyzeListChainedRows(['expression' => $this->parseIntoClause()]));
        }

        return null;
    }

    // https://dev.mysql.com/doc/refman/8.4/en/analyze-table.html
    protected function parseAnalyzeHistogram(): AnalyzeHistogram
    {
        $this_ = strtoupper($this->prevText());
        $expression = null;
        $expressions = [];
        $updateOptions = null;

        if ($this->matchTextSeq('HISTOGRAM', 'ON')) {
            $expressions = $this->parseCsv(fn (): ?Expression => $this->parseColumnReference());
            $withExpressions = [];

            while ($this->match(TokenType::WITH)) {
                // https://docs.starrocks.io/docs/sql-reference/sql-statements/cbo_stats/ANALYZE_TABLE/
                if ($this->matchTexts(['SYNC' => true, 'ASYNC' => true])) {
                    if ($this->peekTextSeq('MODE')) {
                        $withExpressions[] = strtoupper($this->prevText()).' MODE';
                        $this->advance();
                    }
                } else {
                    $buckets = $this->parseNumber();

                    if ($this->matchTextSeq('BUCKETS')) {
                        $withExpressions[] = ($buckets?->sql() ?? 'None').' BUCKETS';
                    }
                }
            }

            if ($withExpressions !== []) {
                $expression = $this->expression(new AnalyzeWith(['expressions' => $withExpressions]));
            }

            if ($this->matchTexts(['MANUAL' => true, 'AUTO' => true]) && $this->match(TokenType::UPDATE, false)) {
                $updateOptions = strtoupper($this->prevText());
                $this->advance();
            } elseif ($this->matchTextSeq('USING', 'DATA')) {
                $expression = $this->expression(new UsingData(['this' => $this->parseString()]));
            }
        }

        return $this->expression(new AnalyzeHistogram([
            'this' => $this_,
            'expressions' => $expressions,
            'expression' => $expression,
            'update_options' => $updateOptions,
        ]));
    }

    protected function parseMerge(): Merge
    {
        $this->match(TokenType::INTO);
        $target = $this->parseTable();

        if ($target !== null && $this->match(TokenType::ALIAS, false)) {
            $target->set('alias', $this->parseTableAlias());
        }

        $this->match(TokenType::USING);
        $using = $this->parseTable();

        return $this->expression(new Merge([
            'this' => $target,
            'using' => $using,
            'on' => $this->match(TokenType::ON) ? $this->parseDisjunction() : null,
            'using_cond' => $this->match(TokenType::USING) ? $this->parseUsingIdentifiers() : null,
            'whens' => $this->parseWhenMatched(),
            'returning' => $this->parseReturning(),
        ]));
    }

    protected function parseWhenMatched(): Whens
    {
        $whens = [];

        while ($this->match(TokenType::WHEN)) {
            $matched = ! $this->match(TokenType::NOT);
            $this->matchTextSeq('MATCHED');
            $source = $this->matchTextSeq('BY', 'TARGET') ? false : $this->matchTextSeq('BY', 'SOURCE');
            $condition = $this->match(TokenType::AND) ? $this->parseDisjunction() : null;

            $this->match(TokenType::THEN);

            if ($this->match(TokenType::INSERT)) {
                $this_ = $this->parseStar();

                if ($this_ !== null) {
                    $then = $this->expression(new Insert(['this' => $this_]));
                } else {
                    $then = $this->expression(new Insert([
                        'this' => $this->matchTextSeq('ROW') ? Builder::var('ROW') : $this->parseValue(values: false),
                        'expression' => $this->matchTextSeq('VALUES') ? $this->parseValue() : null,
                        'where' => $this->parseWhere(),
                    ]));
                }
            } elseif ($this->match(TokenType::UPDATE)) {
                $expressions = $this->parseStar();

                if ($expressions !== null) {
                    $then = $this->expression(new Update(['expressions' => $expressions]));
                } else {
                    $then = $this->expression(new Update([
                        'expressions' => $this->match(TokenType::SET) ? $this->parseCsv(fn (): ?Expression => $this->parseUpdateAssignment()) : null,
                        'where' => $this->parseWhere(),
                    ]));
                }
            } elseif ($this->match(TokenType::DELETE)) {
                $then = $this->expression(new VarExpr(['this' => $this->prevText()]));
            } else {
                $then = $this->parseVarFromOptions($this->t['CONFLICT_ACTIONS']);
            }

            $whens[] = $this->expression(new When(['matched' => $matched, 'source' => $source, 'condition' => $condition, 'then' => $then]));
        }

        return $this->expression(new Whens(['expressions' => $whens]));
    }

    protected function parseShow(): ?Expression
    {
        $parser = $this->findParser($this->t['SHOW_PARSERS'], $this->t['SHOW_TRIE']);

        if ($parser !== null) {
            return $this->callHandler($parser);
        }

        return $this->parseAsCommand($this->index - 1);
    }

    protected function parseSetItemAssignment(?string $kind = null): ?Expression
    {
        $index = $this->index;

        if (($kind === 'GLOBAL' || $kind === 'SESSION') && $this->matchTextSeq('TRANSACTION')) {
            return $this->parseSetTransaction(global: $kind === 'GLOBAL');
        }

        $left = $this->parsePrimary() ?? $this->parseColumn();
        $assignmentDelimiter = $this->matchTexts($this->t['SET_ASSIGNMENT_DELIMITERS']);

        if ($left === null || ($this->t['SET_REQUIRES_ASSIGNMENT_DELIMITER'] === true && ! $assignmentDelimiter)) {
            $this->retreat($index);

            return null;
        }

        $right = $this->parseStatement() ?? $this->parseIdVar();

        if ($right instanceof Column || $right instanceof Identifier) {
            $right = Builder::var($right->name());
        }

        $this_ = $this->expression(new EQ(['this' => $left, 'expression' => $right]));

        return $this->expression(new SetItem(['this' => $this_, 'kind' => $kind]));
    }

    protected function parseSetTransaction(bool $global = false): Expression
    {
        $this->matchTextSeq('TRANSACTION');
        $characteristics = $this->parseCsv(fn (): ?Expression => $this->parseVarFromOptions($this->t['TRANSACTION_CHARACTERISTICS']));

        return $this->expression(new SetItem(['expressions' => $characteristics, 'kind' => 'TRANSACTION', 'global_' => $global]));
    }

    protected function parseSetItem(): ?Expression
    {
        $parser = $this->findParser($this->t['SET_PARSERS'], $this->t['SET_TRIE']);

        return $parser !== null ? $this->callHandler($parser) : $this->parseSetItemAssignment(kind: null);
    }

    protected function parseSet(bool $unset = false, bool $tag = false): Expression
    {
        $index = $this->index;
        $set = $this->expression(new Set([
            'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseSetItem()),
            'unset' => $unset,
            'tag' => $tag,
        ]));

        if ($this->curr !== 0) {
            $this->retreat($index);

            return $this->parseAsCommand($this->index - 1);
        }

        return $set;
    }

    /**
     * Match a keyword and, optionally, one of its allowed continuations.
     *
     * @param  array<string, mixed>  $options  keyword => list of continuations (a string or a list of words)
     */
    protected function parseVarFromOptions(array $options, bool $raiseUnmatched = true): ?VarExpr
    {
        if ($this->curr === 0) {
            return null;
        }

        $option = strtoupper($this->currText());
        $continuations = ! isset($this->textMatchExcludedTokens[$this->curr]) && array_key_exists($option, $options)
            ? $options[$option]
            : null;

        $index = $this->index;
        $this->advance();

        $matched = false;

        foreach ($continuations ?? [] as $keywords) {
            $keywords = is_string($keywords) ? [$keywords] : $keywords;

            if ($this->matchTextSeq(...$keywords)) {
                $option = "{$option} ".implode(' ', $keywords);
                $matched = true;

                break;
            }
        }

        if (! $matched && ($continuations === null || $continuations !== [])) {
            if ($raiseUnmatched) {
                $this->raiseError("Unknown option {$option}");
            }

            $this->retreat($index);

            return null;
        }

        return Builder::var($option);
    }

    protected function parseAsCommand(int $start): Command
    {
        while ($this->curr !== 0) {
            $this->advance();
        }

        $text = $this->findSql($start, $this->index - 1);
        $size = strlen($this->texts[$start]);
        $this->warnUnsupported();

        return new Command(['this' => substr($text, 0, $size), 'expression' => substr($text, $size)]);
    }

    /**
     * Find a (possibly multi-word) sub-parser for the upcoming tokens (SHOW / SET).
     *
     * @param  array<string, mixed>  $parsers
     * @param  array<array-key, mixed>  $trie
     */
    protected function findParser(array $parsers, array $trie): mixed
    {
        if ($this->curr === 0) {
            return null;
        }

        $index = $this->index;
        $this_ = [];

        while (true) {
            if ($this->curr === 0) {
                break;
            }

            // The current token might be multiple words
            $curr = strtoupper($this->currText());
            $key = explode(' ', $curr);
            $this_[] = $curr;

            $this->advance();

            $failed = false;
            foreach ($key as $word) {
                if (! isset($trie[$word])) {
                    $failed = true;

                    break;
                }

                $trie = $trie[$word];
            }

            if ($failed) {
                break;
            }

            if (isset($trie[''])) {
                return $parsers[implode(' ', $this_)];
            }
        }

        $this->retreat($index);

        return null;
    }

    protected function parseTruncateTable(): ?Expression
    {
        $start = $this->index - 1;

        // Not to be confused with TRUNCATE(number, decimals) function call
        if ($this->match(TokenType::L_PAREN)) {
            $this->retreat($this->index - 2);

            return $this->parseFunction();
        }

        // Clickhouse supports TRUNCATE DATABASE as well
        $isDatabase = $this->match(TokenType::DATABASE);

        $this->match(TokenType::TABLE);

        $exists = $this->parseExists(not: false);

        $expressions = $this->parseCsv(fn (): ?Expression => $this->parseTable(schema: true, isDbReference: $isDatabase));

        $cluster = $this->match(TokenType::ON) ? $this->parseOnProperty() : null;

        if ($this->matchTextSeq('RESTART', 'IDENTITY')) {
            $identity = 'RESTART';
        } elseif ($this->matchTextSeq('CONTINUE', 'IDENTITY')) {
            $identity = 'CONTINUE';
        } else {
            $identity = null;
        }

        if ($this->matchTextSeq('CASCADE') || $this->matchTextSeq('RESTRICT')) {
            $option = $this->prevText();
        } else {
            $option = null;
        }

        $partition = $this->parsePartition();

        // Fallback case
        if ($this->curr !== 0) {
            return $this->parseAsCommand($start);
        }

        return $this->expression(new TruncateTable([
            'expressions' => $expressions,
            'is_database' => $isDatabase,
            'exists' => $exists,
            'cluster' => $cluster,
            'identity' => $identity,
            'option' => $option,
            'partition' => $partition,
        ]));
    }

    /**
     * @return list<Expression>
     */
    protected function parseWrappedOptions(): array
    {
        $this->match(TokenType::EQ);
        $this->match(TokenType::L_PAREN);

        $opts = [];

        while ($this->curr !== 0 && ! $this->match(TokenType::R_PAREN)) {
            if ($this->matchTextSeq('FORMAT_NAME', '=')) {
                // The FORMAT_NAME can be set to an identifier for Snowflake and T-SQL
                $option = $this->parseFormatName();
            } else {
                $option = $this->parseProperty();
            }

            if ($option === null) {
                $this->raiseError('Unable to parse option');

                break;
            }

            foreach (is_array($option) ? $option : [$option] as $o) {
                $opts[] = $o;
            }
        }

        return $opts;
    }

    /**
     * @return list<CopyParameter>
     */
    protected function parseCopyParameters(): array
    {
        $separator = $this->dialect->settings['COPY_PARAMS_ARE_CSV'] === true ? TokenType::COMMA : null;

        $options = [];

        while ($this->curr !== 0 && ! $this->match(TokenType::R_PAREN, false)) {
            $option = $this->parseVar(anyToken: true);
            $prev = strtoupper($this->prevText());

            // Different dialects might separate options and values by white space, "=" and "AS"
            $this->match(TokenType::EQ);
            $this->match(TokenType::ALIAS);

            $param = $this->expression(new CopyParameter(['this' => $option]));

            if (isset($this->t['COPY_INTO_VARLEN_OPTIONS'][$prev]) && $this->match(TokenType::L_PAREN, false)) {
                // Snowflake FILE_FORMAT case, Databricks COPY & FORMAT options
                $param->set('expressions', $this->parseWrappedOptions());
            } elseif ($prev === 'FILE_FORMAT') {
                // T-SQL's external file format case
                $param->set('expression', $this->parseField());
            } elseif ($prev === 'FORMAT' && $this->prev === TokenType::ALIAS && $this->matchTexts(['AVRO' => true, 'JSON' => true])) {
                $param->set('this', Builder::var('FORMAT AS '.strtoupper($this->prevText())));
                $param->set('expression', $this->parseField());
            } else {
                $param->set('expression', $this->parseUnquotedField() ?? $this->parseBracket());
            }

            $options[] = $param;

            if ($separator !== null) {
                $this->match($separator);
            }
        }

        return $options;
    }

    protected function parseCredentials(): ?Credentials
    {
        $expr = $this->expression(new Credentials);

        if ($this->matchTextSeq('STORAGE_INTEGRATION', '=')) {
            $expr->set('storage', $this->parseField());
        }

        if ($this->matchTextSeq('CREDENTIALS')) {
            // Snowflake case: CREDENTIALS = (...), Redshift case: CREDENTIALS <string>
            $creds = $this->match(TokenType::EQ) ? $this->parseWrappedOptions() : $this->parseField();
            $expr->set('credentials', $creds);
        }

        if ($this->matchTextSeq('ENCRYPTION')) {
            $expr->set('encryption', $this->parseWrappedOptions());
        }

        if ($this->matchTextSeq('IAM_ROLE')) {
            $expr->set('iam_role', $this->match(TokenType::DEFAULT) ? Builder::var($this->prevText()) : $this->parseField());
        }

        if ($this->matchTextSeq('REGION')) {
            $expr->set('region', $this->parseField());
        }

        return $expr;
    }

    protected function parseFileLocation(): ?Expression
    {
        return $this->parseField();
    }

    protected function parseCopy(): Expression
    {
        $start = $this->index - 1;

        $this->match(TokenType::INTO);

        $this_ = $this->match(TokenType::L_PAREN, false)
            ? $this->parseSelect(nested: true, parseSubqueryAlias: false)
            : $this->parseTable(schema: true);

        $kind = $this->match(TokenType::FROM) || ! $this->matchTextSeq('TO');

        $files = $this->parseCsv(fn (): ?Expression => $this->parseFileLocation());

        if ($this->match(TokenType::EQ, false)) {
            // Backtrack one token since we've consumed the lhs of a parameter assignment here.
            // This can happen for Snowflake dialect. Instead, we'd like to parse the parameter
            // list via `_parse_wrapped(..)` below.
            $this->advance(-1);
            $files = [];
        }

        $credentials = $this->parseCredentials();

        $this->matchTextSeq('WITH');

        $params = $this->parseWrapped(fn (): array => $this->parseCopyParameters(), optional: true);

        // Fallback case
        if ($this->curr !== 0) {
            return $this->parseAsCommand($start);
        }

        return $this->expression(new Copy(['this' => $this_, 'kind' => $kind, 'credentials' => $credentials, 'files' => $files, 'params' => $params]));
    }

    protected function parseGrantPrivilege(): ?GrantPrivilege
    {
        $privilegeParts = [];

        // Keep consuming consecutive keywords until comma (end of this privilege) or ON
        // (end of privilege list) or L_PAREN (start of column list) are met
        while ($this->curr !== 0 && ! $this->matchSet($this->t['PRIVILEGE_FOLLOW_TOKENS'], false)) {
            $privilegeParts[] = strtoupper($this->currText());
            $this->advance();
        }

        if ($privilegeParts === []) {
            $this->raiseError('Expected privilege');

            return null;
        }

        $this_ = Builder::var(implode(' ', $privilegeParts));
        $expressions = $this->match(TokenType::L_PAREN, false)
            ? $this->parseWrappedCsv(fn (): ?Expression => $this->parseColumn())
            : null;

        return $this->expression(new GrantPrivilege(['this' => $this_, 'expressions' => $expressions]));
    }

    protected function parseGrantPrincipal(): ?GrantPrincipal
    {
        $kind = $this->matchTexts(['ROLE' => true, 'GROUP' => true]) ? strtoupper($this->prevText()) : null;
        $principal = $this->parseIdVar();

        if ($principal === null) {
            return null;
        }

        return $this->expression(new GrantPrincipal(['this' => $principal, 'kind' => $kind]));
    }

    /**
     * @return array{0: list<GrantPrivilege>, 1: string|null, 2: Expression|null}
     */
    protected function parseGrantRevokeCommon(): array
    {
        $privileges = $this->parseCsv(fn (): ?GrantPrivilege => $this->parseGrantPrivilege());

        $this->match(TokenType::ON);
        $kind = $this->matchSet($this->t['CREATABLES']) ? strtoupper($this->prevText()) : null;

        // Attempt to parse the securable e.g. MySQL allows names
        // such as "foo.*", "*.*" which are not easily parseable yet
        $securable = $this->tryParse(fn (): ?Expression => $this->parseTableParts());

        return [$privileges, $kind, $securable];
    }

    protected function parseGrant(): Expression
    {
        $start = $this->index - 1;

        [$privileges, $kind, $securable] = $this->parseGrantRevokeCommon();

        if ($securable === null || ! $this->matchTextSeq('TO')) {
            return $this->parseAsCommand($start);
        }

        $principals = $this->parseCsv(fn (): ?GrantPrincipal => $this->parseGrantPrincipal());

        $grantOption = $this->matchTextSeq('WITH', 'GRANT', 'OPTION');

        if ($this->curr !== 0) {
            return $this->parseAsCommand($start);
        }

        return $this->expression(new Grant([
            'privileges' => $privileges,
            'kind' => $kind,
            'securable' => $securable,
            'principals' => $principals,
            'grant_option' => $grantOption,
        ]));
    }

    protected function parseRevoke(): Expression
    {
        $start = $this->index - 1;

        $grantOption = $this->matchTextSeq('GRANT', 'OPTION', 'FOR');

        [$privileges, $kind, $securable] = $this->parseGrantRevokeCommon();

        if ($securable === null || ! $this->matchTextSeq('FROM')) {
            return $this->parseAsCommand($start);
        }

        $principals = $this->parseCsv(fn (): ?GrantPrincipal => $this->parseGrantPrincipal());

        $cascade = null;

        if ($this->matchTexts(['CASCADE' => true, 'RESTRICT' => true])) {
            $cascade = strtoupper($this->prevText());
        }

        if ($this->curr !== 0) {
            return $this->parseAsCommand($start);
        }

        return $this->expression(new Revoke([
            'privileges' => $privileges,
            'kind' => $kind,
            'securable' => $securable,
            'principals' => $principals,
            'grant_option' => $grantOption,
            'cascade' => $cascade,
        ]));
    }

    protected function parseFormatName(): Property
    {
        // Note: Although not specified in the docs, Snowflake does accept a string/identifier
        // for FILE_FORMAT = <format_name>
        return $this->expression(new Property([
            'this' => Builder::var('FORMAT_NAME'),
            'value' => $this->parseString() ?? $this->parseTableParts(),
        ]));
    }

    protected function parseDeclareitem(): ?DeclareItem
    {
        $this->matchTexts(['VAR' => true, 'VARIABLE' => true]);

        $vars = $this->parseCsv(fn (): ?Expression => $this->parseIdVar());

        if ($vars === []) {
            return null;
        }

        $this->match(TokenType::ALIAS);
        $kind = $this->match(TokenType::TABLE) ? $this->parseSchema() : $this->parseTypes();
        $default = ($this->match(TokenType::DEFAULT) || $this->match(TokenType::EQ)) ? $this->parseBitwise() : null;

        return $this->expression(new DeclareItem(['this' => $vars, 'kind' => $kind, 'default' => $default]));
    }

    protected function parseDeclare(): Expression
    {
        $start = $this->index - 1;
        $replace = $this->matchTextSeq('OR', 'REPLACE');
        $expressions = $this->tryParse(fn (): array => $this->parseCsv(fn (): ?DeclareItem => $this->parseDeclareitem()));

        if ($expressions === null || $expressions === [] || $this->curr !== 0) {
            return $this->parseAsCommand($start);
        }

        return $this->expression(new DeclareExpr(['expressions' => $expressions, 'replace' => $replace]));
    }
}
