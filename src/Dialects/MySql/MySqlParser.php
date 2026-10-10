<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\MySql;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Add;
use Kwasii\LaravelSqlParser\Expressions\AlterIndex;
use Kwasii\LaravelSqlParser\Expressions\Anonymous;
use Kwasii\LaravelSqlParser\Expressions\AutoIncrementProperty;
use Kwasii\LaravelSqlParser\Expressions\BinaryColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\ColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\ColumnDef;
use Kwasii\LaravelSqlParser\Expressions\ColumnPrefix;
use Kwasii\LaravelSqlParser\Expressions\ComputedColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\ConvertTimezone;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DateDiff;
use Kwasii\LaravelSqlParser\Expressions\Day;
use Kwasii\LaravelSqlParser\Expressions\DayOfMonth;
use Kwasii\LaravelSqlParser\Expressions\DayOfWeek;
use Kwasii\LaravelSqlParser\Expressions\DayOfYear;
use Kwasii\LaravelSqlParser\Expressions\DropPrimaryKey;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\GeneratedAsIdentityColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\IndexColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\IndexConstraintOption;
use Kwasii\LaravelSqlParser\Expressions\InvisibleColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\JSONArrayContains;
use Kwasii\LaravelSqlParser\Expressions\Length;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\LockProperty;
use Kwasii\LaravelSqlParser\Expressions\ModifyColumn;
use Kwasii\LaravelSqlParser\Expressions\Month;
use Kwasii\LaravelSqlParser\Expressions\Paren;
use Kwasii\LaravelSqlParser\Expressions\Partition;
use Kwasii\LaravelSqlParser\Expressions\PartitionByListProperty;
use Kwasii\LaravelSqlParser\Expressions\PartitionByRangeProperty;
use Kwasii\LaravelSqlParser\Expressions\PartitionList;
use Kwasii\LaravelSqlParser\Expressions\PartitionRange;
use Kwasii\LaravelSqlParser\Expressions\RenameIndex;
use Kwasii\LaravelSqlParser\Expressions\Schema;
use Kwasii\LaravelSqlParser\Expressions\SchemaCommentProperty;
use Kwasii\LaravelSqlParser\Expressions\SetItem;
use Kwasii\LaravelSqlParser\Expressions\Show;
use Kwasii\LaravelSqlParser\Expressions\Soundex;
use Kwasii\LaravelSqlParser\Expressions\StrToDate;
use Kwasii\LaravelSqlParser\Expressions\StrToTime;
use Kwasii\LaravelSqlParser\Expressions\TimeToStr;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsToDate;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsToTimestamp;
use Kwasii\LaravelSqlParser\Expressions\UniqueColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\VarExpr;
use Kwasii\LaravelSqlParser\Expressions\Week;
use Kwasii\LaravelSqlParser\Expressions\WeekOfYear;
use Kwasii\LaravelSqlParser\Expressions\Year;
use Kwasii\LaravelSqlParser\Expressions\ZeroFillColumnConstraint;
use Kwasii\LaravelSqlParser\Parser\Parser;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * MySQL parser (sqlglot parsers/mysql.py).
 */
class MySqlParser extends Parser
{
    public const string TABLES = MySqlParserTables::class;

    /** All specifiers for time parts (as opposed to date parts) in DATE_FORMAT strings. */
    private const array TIME_SPECIFIERS = [
        'f' => true, 'H' => true, 'h' => true, 'I' => true, 'i' => true, 'k' => true,
        'l' => true, 'p' => true, 'r' => true, 'S' => true, 's' => true, 'T' => true,
    ];

    private static function hasTimeSpecifier(string $dateFormat): bool
    {
        $length = strlen($dateFormat);

        for ($i = 0; $i < $length; $i++) {
            if ($dateFormat[$i] === '%') {
                $i++;

                if ($i < $length && isset(self::TIME_SPECIFIERS[$dateFormat[$i]])) {
                    return true;
                }
            }
        }

        return false;
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $args
     */
    protected function strToDate(array $args): Expression
    {
        $mysqlDateFormat = $args[1] ?? null;
        $dateFormat = $this->dialect->formatTime($mysqlDateFormat instanceof Expression ? $mysqlDateFormat : null);
        $this_ = $args[0] ?? null;

        if ($mysqlDateFormat instanceof Expression && self::hasTimeSpecifier($mysqlDateFormat->name())) {
            return new StrToTime(['this' => $this_, 'format' => $dateFormat]);
        }

        return new StrToDate(['this' => $this_, 'format' => $dateFormat]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildConvertTz(array $args): Expression
    {
        return new ConvertTimezone(['source_tz' => $args[1] ?? null, 'target_tz' => $args[2] ?? null, 'timestamp' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDate(array $args): Expression
    {
        return new TsOrDsToDate(['this' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDatediff(array $args): Expression
    {
        return new DateDiff(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'date_part_boundary' => true]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDateFormat(array $args): Expression
    {
        $format = $args[1] ?? null;

        return new TimeToStr([
            'this' => new TsOrDsToTimestamp(['this' => $args[0] ?? null]),
            'format' => Dialect::get('mysql')->formatTime($format instanceof Expression ? $format : null),
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDay(array $args): Expression
    {
        return new Day(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDayofmonth(array $args): Expression
    {
        return new DayOfMonth(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDayofweek(array $args): Expression
    {
        return new DayOfWeek(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDayofyear(array $args): Expression
    {
        return new DayOfYear(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildLength(array $args): Expression
    {
        return new Length(['this' => $args[0] ?? null, 'binary' => true]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildMonth(array $args): Expression
    {
        return new Month(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildMonthname(array $args): Expression
    {
        return new TimeToStr(['this' => new TsOrDsToDate(['this' => $args[0] ?? null]), 'format' => Literal::string('%B')]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildToDays(array $args): Expression
    {
        return Builder::paren(new Add([
            'this' => new DateDiff([
                'this' => new TsOrDsToDate(['this' => $args[0] ?? null]),
                'expression' => new TsOrDsToDate(['this' => Literal::string('0000-01-01')]),
                'unit' => Builder::var('DAY'),
            ]),
            'expression' => Literal::number(1),
        ]), copy: false);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildWeek(array $args): Expression
    {
        return new Week(['this' => new TsOrDsToDate(['this' => $args[0] ?? null]), 'mode' => $args[1] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildWeekofyear(array $args): Expression
    {
        return new WeekOfYear(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildYear(array $args): Expression
    {
        return new Year(['this' => new TsOrDsToDate(['this' => $args[0] ?? null])]);
    }

    // ---------------------------------------------------------------------------------------
    // Dispatch table handlers
    // ---------------------------------------------------------------------------------------

    protected function funcValues(): Expression
    {
        // https://dev.mysql.com/doc/refman/5.7/en/miscellaneous-functions.html#function_values
        return $this->expression(new Anonymous(['this' => 'VALUES', 'expressions' => [$this->parseIdVar()]]));
    }

    protected function rangeMemberOf(?Expression $this_): ?Expression
    {
        return $this->expression(new JSONArrayContains([
            'this' => $this_,
            'expression' => $this->parseWrapped(fn (): ?Expression => $this->parseExpression()),
        ]));
    }

    /**
     * SHOW parsers: sqlglot's _show_parser(*args, **kwargs) closures.
     *
     * @param  list<string>  $args
     * @param  array<string, mixed>  $kwargs
     */
    protected function showParser(array $args = [], array $kwargs = []): Show
    {
        return $this->parseShowMysql(
            $args[0] ?? '',
            target: $kwargs['target'] ?? false,
            full: $kwargs['full'] ?? null,
            global: $kwargs['global_'] ?? null,
        );
    }

    protected function propertyLock(): Expression
    {
        return $this->parsePropertyAssignment(LockProperty::class);
    }

    protected function setPersist(): ?Expression
    {
        return $this->parseSetItemAssignment('PERSIST');
    }

    protected function setPersistOnly(): ?Expression
    {
        return $this->parseSetItemAssignment('PERSIST_ONLY');
    }

    protected function setCharacterSet(): Expression
    {
        return $this->parseSetItemCharset('CHARACTER SET');
    }

    protected function setCharset(): Expression
    {
        return $this->parseSetItemCharset('CHARACTER SET');
    }

    protected function constraintBinary(): Expression
    {
        return $this->expression(new BinaryColumnConstraint);
    }

    protected function constraintFulltext(): Expression
    {
        return $this->parseIndexConstraint(kind: 'FULLTEXT');
    }

    protected function constraintSpatial(): Expression
    {
        return $this->parseIndexConstraint(kind: 'SPATIAL');
    }

    protected function constraintZerofill(): Expression
    {
        return $this->expression(new ZeroFillColumnConstraint);
    }

    protected function constraintInvisible(): Expression
    {
        return $this->expression(new InvisibleColumnConstraint);
    }

    protected function alterChange(): ?Expression
    {
        return $this->parseAlterTableModify(rename: true);
    }

    protected function alterAutoIncrement(): Expression
    {
        return $this->parsePropertyAssignment(AutoIncrementProperty::class);
    }

    protected function alterComment(): Expression
    {
        return $this->parsePropertyAssignment(SchemaCommentProperty::class);
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseColumnConstraint(): ?Expression
    {
        if ($this->match(TokenType::KEY)) {
            return $this->expression(new ColumnConstraint(['kind' => $this->parsePrimaryKey()]));
        }

        return parent::parseColumnConstraint();
    }

    /**
     * parseRange() also reacts to SOUNDS LIKE, and SOUNDS is a plain word.
     *
     * @return array<int, true>
     */
    protected function operandContinuationTokens(): array
    {
        return parent::operandContinuationTokens() + [TokenType::VAR => true];
    }

    protected function parseRange(?Expression $this_ = null): ?Expression
    {
        $this_ ??= $this->parseBitwise();

        if ($this->matchTextSeq('SOUNDS', 'LIKE')) {
            $this_ = $this->expression(new EQ([
                'this' => $this->expression(new Soundex(['this' => $this_])),
                'expression' => $this->expression(new Soundex(['this' => $this->parseBitwise()])),
            ]));

            // MySQL evaluates = and IS left to right because they have the same precedence.
            if ($this->match(TokenType::IS, false)) {
                $this_ = $this->expression(new Paren(['this' => $this_]));
            }
        }

        return parent::parseRange($this_);
    }

    protected function parseAlterTableRename(): ?Expression
    {
        if ($this->matchTexts(['INDEX' => true, 'KEY' => true])) {
            $old = $this->parseField(anyToken: true);
            $this->matchTextSeq('TO');
            $new = $this->parseField(anyToken: true);

            return $this->expression(new RenameIndex(['this' => $old, 'to' => $new]));
        }

        return parent::parseAlterTableRename();
    }

    protected function parseAlterDropAction(): ?Expression
    {
        if ($this->matchPair(TokenType::DROP, TokenType::PRIMARY_KEY)) {
            return $this->expression(new DropPrimaryKey);
        }

        return parent::parseAlterDropAction();
    }

    protected function parseAlterTableModify(bool $rename = false): ?Expression
    {
        // MODIFY [COLUMN]            col_name      column_definition [FIRST | AFTER col_name]
        // CHANGE [COLUMN] old_col_name new_col_name column_definition [FIRST | AFTER col_name]
        $this->match(TokenType::COLUMN);

        $column = $this->parseField(anyToken: true);

        if ($column === null) {
            return null;
        }

        $renameFrom = null;

        if ($rename) {
            $renameFrom = $column;
            $column = $this->parseField(anyToken: true);

            if ($column === null) {
                return null;
            }
        }

        $columnDef = $this->parseColumnDef($column);

        if (! $columnDef instanceof ColumnDef) {
            return null;
        }

        return $this->expression(new ModifyColumn(['this' => $columnDef, 'rename_from' => $renameFrom]));
    }

    protected function parseGeneratedAsIdentity(): Expression
    {
        $this_ = parent::parseGeneratedAsIdentity();

        if ($this->matchTexts(['STORED' => true, 'VIRTUAL' => true])) {
            $persisted = strtoupper($this->prevText()) === 'STORED';

            if ($this_ instanceof ComputedColumnConstraint) {
                $this_->set('persisted', $persisted);
            } elseif ($this_ instanceof GeneratedAsIdentityColumnConstraint) {
                $this_ = $this->expression(new ComputedColumnConstraint(['this' => $this_->args['expression'] ?? null, 'persisted' => $persisted]));
            }
        }

        return $this_;
    }

    protected function parsePrimaryKeyPart(): ?Expression
    {
        $this_ = $this->parseIdVar();

        if (! $this->match(TokenType::L_PAREN)) {
            return $this_;
        }

        $expression = $this->parseNumber();
        $this->matchRParen();

        return $this->expression(new ColumnPrefix(['this' => $this_, 'expression' => $expression]));
    }

    protected function parseIndexConstraintPart(): ?Expression
    {
        // key_part: {col_name [(length)] | (expr)}, so only a prefixed column is followed by "("
        if (! $this->match(TokenType::L_PAREN, false) && $this->next === TokenType::L_PAREN) {
            return $this->parsePrimaryKeyPart();
        }

        return $this->parseDisjunction();
    }

    protected function parseIndexKeyPart(): ?Expression
    {
        // Only keep the Ordered wrapper for an explicit ASC/DESC, otherwise the implicit
        // nulls_first leaks into other dialects as NULLS FIRST
        $ordered = $this->parseOrdered(fn (): ?Expression => $this->parseIndexConstraintPart());

        return $ordered !== null && ($ordered->args['desc'] ?? null) === null ? ($ordered->args['this'] ?? null) : $ordered;
    }

    protected function parseIndexConstraint(?string $kind = null): IndexColumnConstraint
    {
        if ($kind !== null) {
            $this->matchTexts(['INDEX' => true, 'KEY' => true]);
        }

        $this_ = $this->parseIdVar(anyToken: false);
        $indexType = $this->match(TokenType::USING) && $this->advanceAny() !== null ? $this->prevText() : null;
        $expressions = $this->parseWrappedCsv(fn (): ?Expression => $this->parseIndexKeyPart());

        return $this->expression(new IndexColumnConstraint([
            'this' => $this_,
            'expressions' => $expressions,
            'kind' => $kind,
            'index_type' => $indexType,
            'options' => $this->parseIndexConstraintOptions(),
        ]));
    }

    /**
     * @return list<IndexConstraintOption>
     */
    protected function parseIndexConstraintOptions(): array
    {
        $options = [];

        while (true) {
            if ($this->matchTextSeq('KEY_BLOCK_SIZE')) {
                $this->match(TokenType::EQ);
                $opt = new IndexConstraintOption(['key_block_size' => $this->parseNumber()]);
            } elseif ($this->match(TokenType::USING)) {
                $opt = new IndexConstraintOption(['using' => $this->advanceAny() !== null ? $this->prevText() : null]);
            } elseif ($this->matchTextSeq('WITH', 'PARSER')) {
                $opt = new IndexConstraintOption(['parser' => $this->parseVar(anyToken: true)]);
            } elseif ($this->match(TokenType::COMMENT)) {
                $opt = new IndexConstraintOption(['comment' => $this->parseString()]);
            } elseif ($this->matchTextSeq('VISIBLE')) {
                $opt = new IndexConstraintOption(['visible' => true]);
            } elseif ($this->matchTextSeq('INVISIBLE')) {
                $opt = new IndexConstraintOption(['visible' => false]);
            } elseif ($this->matchTextSeq('ENGINE_ATTRIBUTE')) {
                $this->match(TokenType::EQ);
                $opt = new IndexConstraintOption(['engine_attr' => $this->parseString()]);
            } elseif ($this->matchTextSeq('SECONDARY_ENGINE_ATTRIBUTE')) {
                $this->match(TokenType::EQ);
                $opt = new IndexConstraintOption(['secondary_engine_attr' => $this->parseString()]);
            } else {
                break;
            }

            $options[] = $opt;
        }

        return $options;
    }

    protected function parseUnique(): UniqueColumnConstraint
    {
        $this->matchTexts(['KEY' => true, 'INDEX' => true]);
        $this_ = $this->parseUniqueKey();
        $indexType = $this->parseIndexType();

        // UNIQUE [INDEX | KEY] [index_name] [index_type] (key_part,...) [index_option] ...
        if (! $this->match(TokenType::L_PAREN, false)) {
            return $this->expression(new UniqueColumnConstraint(['this' => $this_, 'index_type' => $indexType]));
        }

        $expressions = $this->parseWrappedCsv(fn (): ?Expression => $this->parseIndexKeyPart());

        return $this->expression(new UniqueColumnConstraint([
            'this' => $this->expression(new Schema(['this' => $this_, 'expressions' => $expressions])),
            'index_type' => $indexType ?? $this->parseIndexType(),
            'options' => $this->parseIndexConstraintOptions(),
        ]));
    }

    protected function parseIndexType(): ?string
    {
        if ($this->match(TokenType::USING) && $this->advanceAny() !== null) {
            return $this->prevText();
        }

        return null;
    }

    protected function parseShowMysql(string $this_, bool|string $target = false, ?bool $full = null, ?bool $global = null): Show
    {
        $json = $this->matchTextSeq('JSON');

        if ($target !== false) {
            if (is_string($target)) {
                $this->matchTextSeq(...explode(' ', $target));
            }

            $targetId = $this->parseIdVar();
        } else {
            $targetId = null;
        }

        $index = $this->index;
        $log = null;

        if ($this->matchTextSeq('IN')) {
            $log = $this->parseString();

            if ($log === null) {
                $this->retreat($index);
            }
        }

        $position = null;
        $db = null;

        if ($this_ === 'BINLOG EVENTS' || $this_ === 'RELAYLOG EVENTS') {
            $position = $this->matchTextSeq('FROM') ? $this->parseNumber() : null;
        } elseif ($this->match(TokenType::FROM) || $this->matchTextSeq('IN')) {
            $db = $this->parseIdVar();
        } elseif ($this->match(TokenType::DOT)) {
            $db = $targetId;
            $targetId = $this->parseIdVar();
        }

        $channel = $this->matchTextSeq('FOR', 'CHANNEL') ? $this->parseIdVar() : null;
        $like = $this->matchTextSeq('LIKE') ? $this->parseString() : null;
        $where = $this->parseWhere();

        if ($this_ === 'PROFILE') {
            $types = $this->parseCsv(fn (): ?Expression => $this->parseVarFromOptions($this->t['PROFILE_TYPES']));
            $query = $this->matchTextSeq('FOR', 'QUERY') ? $this->parseNumber() : null;
            $offset = $this->matchTextSeq('OFFSET') ? $this->parseNumber() : null;
            $limit = $this->matchTextSeq('LIMIT') ? $this->parseNumber() : null;
        } else {
            $types = null;
            $query = null;
            [$offset, $limit] = $this->parseOldstyleLimit();
        }

        $mutex = $this->matchTextSeq('MUTEX') ? true : null;
        $mutex = $this->matchTextSeq('STATUS') ? false : $mutex;

        $forTable = $this->matchTextSeq('FOR', 'TABLE') ? $this->parseIdVar() : null;
        $forGroup = $this->matchTextSeq('FOR', 'GROUP') ? $this->parseString() : null;
        $forUser = $this->matchTextSeq('FOR', 'USER') ? $this->parseString() : null;
        $forRole = $this->matchTextSeq('FOR', 'ROLE') ? $this->parseString() : null;
        $intoOutfile = $this->matchTextSeq('INTO', 'OUTFILE') ? $this->parseString() : null;

        return $this->expression(new Show([
            'this' => $this_,
            'target' => $targetId,
            'full' => $full,
            'log' => $log,
            'position' => $position,
            'db' => $db,
            'channel' => $channel,
            'like' => $like,
            'where' => $where,
            'types' => $types,
            'query' => $query,
            'offset' => $offset,
            'limit' => $limit,
            'mutex' => $mutex,
            'for_table' => $forTable,
            'for_group' => $forGroup,
            'for_user' => $forUser,
            'for_role' => $forRole,
            'into_outfile' => $intoOutfile,
            'json' => $json,
            'global_' => $global,
        ]));
    }

    /**
     * @return array{0: ?Expression, 1: ?Expression} [offset, limit]
     */
    protected function parseOldstyleLimit(): array
    {
        $limit = null;
        $offset = null;

        if ($this->matchTextSeq('LIMIT')) {
            $parts = $this->parseCsv(fn (): ?Expression => $this->parseNumber());

            if (count($parts) === 1) {
                $limit = $parts[0];
            } elseif (count($parts) === 2) {
                $limit = $parts[1];
                $offset = $parts[0];
            }
        }

        return [$offset, $limit];
    }

    protected function parseSetItemCharset(string $kind): Expression
    {
        $this_ = $this->parseString() ?? $this->parseUnquotedField();

        return $this->expression(new SetItem(['this' => $this_, 'kind' => $kind]));
    }

    /**
     * Preserve quoting when a charset name has characters that require it (e.g. spaces); safe
     * names unwrap to a bare Var so roundtrips remain minimal.
     */
    protected function parseCharsetName(): ?Expression
    {
        $identifier = $this->parseIdentifier();

        if ($identifier !== null) {
            $name = $identifier->name();

            return preg_match('/^[_a-zA-Z]\w*$/', $name) === 1 ? new VarExpr(['this' => $name]) : $identifier;
        }

        return $this->parseVar(tokens: [TokenType::BINARY => true]);
    }

    protected function parseSetItemNames(): Expression
    {
        $charset = $this->parseString() ?? $this->parseUnquotedField();
        $collate = $this->matchTextSeq('COLLATE') ? ($this->parseString() ?? $this->parseUnquotedField()) : null;

        return $this->expression(new SetItem(['this' => $charset, 'collate' => $collate, 'kind' => 'NAMES']));
    }

    protected function parseType(bool $parseInterval = true, bool $fallbackToIdentifier = false): ?Expression
    {
        // mysql binary is special and can work anywhere, even in order by operations
        // it operates like a no paren func
        if ($this->match(TokenType::BINARY, false)) {
            $dataType = $this->parseTypes(checkFunc: true, allowIdentifiers: false);

            if ($dataType instanceof DataType) {
                return $this->expression(new Cast(['this' => $this->parseColumn(), 'to' => $dataType]));
            }
        }

        return parent::parseType(parseInterval: $parseInterval, fallbackToIdentifier: $fallbackToIdentifier);
    }

    protected function parseAlterTableAlterIndex(): AlterIndex
    {
        $index = $this->parseField(anyToken: true);

        if ($this->matchTextSeq('VISIBLE')) {
            $visible = true;
        } elseif ($this->matchTextSeq('INVISIBLE')) {
            $visible = false;
        } else {
            $visible = null;
        }

        return $this->expression(new AlterIndex(['this' => $index, 'visible' => $visible]));
    }

    /**
     * @return Expression|list<Expression>|null
     */
    protected function parsePartitionProperty(): Expression|array|null
    {
        if ($this->matchTextSeq('RANGE')) {
            $partitionClass = PartitionByRangeProperty::class;
            $valueParser = fn (): ?Expression => $this->parsePartitionRangeValue();
        } elseif ($this->matchTextSeq('LIST')) {
            $partitionClass = PartitionByListProperty::class;
            $valueParser = fn (): Expression => $this->parsePartitionListValue();
        } else {
            return null;
        }

        $partitionExpressions = $this->parseWrappedCsv(fn (): ?Expression => $this->parseAssignment());

        // For Doris and Starrocks
        if (! $this->peekTextSeq('(', 'PARTITION')) {
            return $partitionExpressions;
        }

        $createExpressions = $this->parseWrappedCsv($valueParser);

        return $this->expression(new $partitionClass(['partition_expressions' => $partitionExpressions, 'create_expressions' => $createExpressions]));
    }

    protected function parsePartitionRangeValue(): ?Expression
    {
        $this->matchTextSeq('PARTITION');
        $name = $this->parseIdVar();

        if (! $this->matchTextSeq('VALUES', 'LESS', 'THAN')) {
            return $name;
        }

        $values = $this->parseWrappedCsv(fn (): ?Expression => $this->parseExpression());

        if (count($values) === 1 && $values[0] instanceof Column && strtoupper($values[0]->name()) === 'MAXVALUE') {
            $values = [Builder::var('MAXVALUE')];
        }

        $partRange = $this->expression(new PartitionRange(['this' => $name, 'expressions' => $values]));

        return $this->expression(new Partition(['expressions' => [$partRange]]));
    }

    protected function parsePartitionListValue(): Partition
    {
        $this->matchTextSeq('PARTITION');
        $name = $this->parseIdVar();
        $this->matchTextSeq('VALUES', 'IN');
        $values = $this->parseWrappedCsv(fn (): ?Expression => $this->parseExpression());
        $partList = $this->expression(new PartitionList(['this' => $name, 'expressions' => $values]));

        return $this->expression(new Partition(['expressions' => [$partList]]));
    }

    protected function parsePrimaryKey(bool $wrappedOptional = false, bool $inProps = false, bool $namedPrimaryKey = false): Expression
    {
        return parent::parsePrimaryKey(wrappedOptional: $wrappedOptional, inProps: $inProps, namedPrimaryKey: true);
    }
}
