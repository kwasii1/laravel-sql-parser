<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\TSql;

use DateTimeImmutable;
use Kwasii\LaravelSqlParser\Expressions\Alias;
use Kwasii\LaravelSqlParser\Expressions\AlterSet;
use Kwasii\LaravelSqlParser\Expressions\Anonymous;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\Commit;
use Kwasii\LaravelSqlParser\Expressions\Convert;
use Kwasii\LaravelSqlParser\Expressions\Count;
use Kwasii\LaravelSqlParser\Expressions\Create;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DateAdd;
use Kwasii\LaravelSqlParser\Expressions\Day;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Execute;
use Kwasii\LaravelSqlParser\Expressions\ExecuteSql;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Extract;
use Kwasii\LaravelSqlParser\Expressions\ForClause;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\IfBlock;
use Kwasii\LaravelSqlParser\Expressions\Into;
use Kwasii\LaravelSqlParser\Expressions\JSONArrayAgg;
use Kwasii\LaravelSqlParser\Expressions\JSONExtract;
use Kwasii\LaravelSqlParser\Expressions\LastDay;
use Kwasii\LaravelSqlParser\Expressions\Left;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\MD5;
use Kwasii\LaravelSqlParser\Expressions\Month;
use Kwasii\LaravelSqlParser\Expressions\NumberToStr;
use Kwasii\LaravelSqlParser\Expressions\Partition;
use Kwasii\LaravelSqlParser\Expressions\PartitionRange;
use Kwasii\LaravelSqlParser\Expressions\Pow;
use Kwasii\LaravelSqlParser\Expressions\Properties;
use Kwasii\LaravelSqlParser\Expressions\QueryOption;
use Kwasii\LaravelSqlParser\Expressions\ReturnsProperty;
use Kwasii\LaravelSqlParser\Expressions\Right;
use Kwasii\LaravelSqlParser\Expressions\Rollback;
use Kwasii\LaravelSqlParser\Expressions\Schema;
use Kwasii\LaravelSqlParser\Expressions\ScopeResolution;
use Kwasii\LaravelSqlParser\Expressions\SHA;
use Kwasii\LaravelSqlParser\Expressions\SHA2;
use Kwasii\LaravelSqlParser\Expressions\SplitPart;
use Kwasii\LaravelSqlParser\Expressions\StoredProcedure;
use Kwasii\LaravelSqlParser\Expressions\StrPosition;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\TemporaryProperty;
use Kwasii\LaravelSqlParser\Expressions\TimeFromParts;
use Kwasii\LaravelSqlParser\Expressions\TimestampFromParts;
use Kwasii\LaravelSqlParser\Expressions\TimestampTrunc;
use Kwasii\LaravelSqlParser\Expressions\TimeStrToTime;
use Kwasii\LaravelSqlParser\Expressions\TimeToStr;
use Kwasii\LaravelSqlParser\Expressions\Transaction;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsToDate;
use Kwasii\LaravelSqlParser\Expressions\UniqueColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Update;
use Kwasii\LaravelSqlParser\Expressions\UserDefinedFunction;
use Kwasii\LaravelSqlParser\Expressions\VarExpr;
use Kwasii\LaravelSqlParser\Expressions\XMLKeyValueOption;
use Kwasii\LaravelSqlParser\Expressions\Year;
use Kwasii\LaravelSqlParser\Parser\Parser;
use Kwasii\LaravelSqlParser\Support\TimeFormat;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * T-SQL parser (sqlglot parsers/tsql.py).
 */
class TSqlParser extends Parser
{
    public const string TABLES = TSqlParserTables::class;

    private const array FULL_FORMAT_TIME_MAPPING = [
        'weekday' => '%A',
        'dw' => '%A',
        'w' => '%A',
        'month' => '%B',
        'mm' => '%B',
        'm' => '%B',
    ];

    private const string DATE_FMT_RE = '/([dD]{1,2})|([mM]{1,2})|([yY]{1,4})|([hH]{1,2})|([sS]{1,2})|([fF]{2,7})/';

    /** N = Numeric, C = Currency */
    private const array TRANSPILE_SAFE_NUMBER_FMT = ['N' => true, 'C' => true];

    /**
     * OPTION (...) query hints; OPTIMIZE FOR (@variable ...) and TABLE HINT aren't supported.
     */
    private const array OPTIONS = [
        'DISABLE_OPTIMIZED_PLAN_FORCING' => [],
        'FAST' => [],
        'IGNORE_NONCLUSTERED_COLUMNSTORE_INDEX' => [],
        'LABEL' => [],
        'MAXDOP' => [],
        'MAXRECURSION' => [],
        'MAX_GRANT_PERCENT' => [],
        'MIN_GRANT_PERCENT' => [],
        'NO_PERFORMANCE_SPOOL' => [],
        'QUERYTRACEON' => [],
        'RECOMPILE' => [],
        'CONCAT' => ['UNION'],
        'DISABLE' => ['EXTERNALPUSHDOWN', 'SCALEOUTEXECUTION'],
        'EXPAND' => ['VIEWS'],
        'FORCE' => ['EXTERNALPUSHDOWN', 'ORDER', 'SCALEOUTEXECUTION'],
        'HASH' => ['GROUP', 'JOIN', 'UNION'],
        'KEEP' => ['PLAN'],
        'KEEPFIXED' => ['PLAN'],
        'LOOP' => ['JOIN'],
        'MERGE' => ['JOIN', 'UNION'],
        'OPTIMIZE' => [['FOR', 'UNKNOWN']],
        'ORDER' => ['GROUP'],
        'PARAMETERIZATION' => ['FORCED', 'SIMPLE'],
        'ROBUST' => ['PLAN'],
        'USE' => ['PLAN'],
    ];

    private const array FOR_XML_OPTIONS = [
        'AUTO' => [],
        'EXPLICIT' => [],
        'TYPE' => [],
        'ELEMENTS' => ['XSINIL', 'ABSENT'],
        'BINARY' => ['BASE64'],
    ];

    /** FOR JSON { AUTO | PATH } [, ROOT [ ( 'name' ) ] ] [, INCLUDE_NULL_VALUES ] [, WITHOUT_ARRAY_WRAPPER ] */
    private const array FOR_JSON_OPTIONS = [
        'AUTO' => [],
        'PATH' => [],
        'INCLUDE_NULL_VALUES' => [],
        'WITHOUT_ARRAY_WRAPPER' => [],
    ];

    private const array COLUMN_DEFINITION_MODES = ['OUT' => true, 'OUTPUT' => true, 'READONLY' => true];

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $args
     */
    protected function buildCharindex(array $args): Expression
    {
        return new StrPosition(['this' => $args[1] ?? null, 'substr' => $args[0] ?? null, 'position' => $args[2] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildCount(array $args): Expression
    {
        return new Count(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1), 'big_int' => false]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildCountBig(array $args): Expression
    {
        return new Count(['this' => $args[0] ?? null, 'expressions' => array_slice($args, 1), 'big_int' => true]);
    }

    /**
     * DATEDIFF / DATEDIFF_BIG (sqlglot tsql _build_date_delta).
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $expClass
     * @param  array<string, string>|null  $unitMapping
     */
    protected function buildDateDeltaTsql(array $args, string $expClass, ?array $unitMapping = null, bool $bigInt = false): Expression
    {
        $unit = $args[0] ?? null;

        if ($unit instanceof Expression && $unitMapping !== null && $unitMapping !== []) {
            $unit = Builder::var($unitMapping[strtolower($unit->name())] ?? $unit->name());
        }

        $startDate = $args[1] ?? null;

        if ($startDate instanceof Expression && $startDate->isNumber()) {
            // Numeric types are valid DATETIME values
            if ($startDate->isInt()) {
                $days = (int) $startDate->toPhp();
                $date = (new DateTimeImmutable('1900-01-01'))->modify("{$days} days");
                $startDate = Literal::string($date->format('Y-m-d'));
            } else {
                // Float values aren't converted to equivalent DATETIMEs; that only matters when
                // transpiling to other dialects.
                return new $expClass(['this' => $args[2] ?? null, 'expression' => $startDate, 'unit' => $unit, 'big_int' => $bigInt]);
            }
        }

        return new $expClass([
            'this' => new TimeStrToTime(['this' => $args[2] ?? null]),
            'expression' => new TimeStrToTime(['this' => $startDate]),
            'unit' => $unit,
            'big_int' => $bigInt,
        ]);
    }

    /**
     * DATENAME (sqlglot tsql _build_formatted_time).
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $expClass
     */
    protected function buildFormattedTimeTsql(array $args, string $expClass, ?bool $fullFormatMapping = null): Expression
    {
        $fmt = $args[0] ?? null;

        if ($fmt instanceof Expression) {
            $mapping = $this->dialect->settings['TIME_MAPPING'];
            $mapping = $fullFormatMapping === true ? [...$mapping, ...self::FULL_FORMAT_TIME_MAPPING] : $mapping;
            $fmt = Literal::string((string) TimeFormat::convertWith(strtolower($fmt->name()), $mapping));
        }

        $this_ = $args[1] ?? null;

        if ($this_ instanceof Expression) {
            $this_ = Builder::cast($this_, DType::DATETIME2);
        }

        return new $expClass(['this' => $this_, 'format' => $fmt]);
    }

    /**
     * https://learn.microsoft.com/en-us/sql/t-sql/functions/datetimefromparts-transact-sql
     *
     * @param  list<mixed>  $args
     */
    protected function buildDatetimefromparts(array $args): Expression
    {
        return new TimestampFromParts([
            'year' => $args[0] ?? null,
            'month' => $args[1] ?? null,
            'day' => $args[2] ?? null,
            'hour' => $args[3] ?? null,
            'min' => $args[4] ?? null,
            'sec' => $args[5] ?? null,
            'milli' => $args[6] ?? null,
        ]);
    }

    /**
     * https://learn.microsoft.com/en-us/sql/t-sql/functions/timefromparts-transact-sql
     *
     * @param  list<mixed>  $args
     */
    protected function buildTimefromparts(array $args): Expression
    {
        return new TimeFromParts([
            'hour' => $args[0] ?? null,
            'min' => $args[1] ?? null,
            'sec' => $args[2] ?? null,
            'fractions' => $args[3] ?? null,
            'precision' => $args[4] ?? null,
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDay(array $args): Expression
    {
        return new Day(['this' => new TsOrDsToDate(['this' => $args[0] ?? null, 'default_date' => Literal::string('1900-01-01')])]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildMonth(array $args): Expression
    {
        return new Month(['this' => new TsOrDsToDate(['this' => $args[0] ?? null, 'default_date' => Literal::string('1900-01-01')])]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildYear(array $args): Expression
    {
        return new Year(['this' => new TsOrDsToDate(['this' => $args[0] ?? null, 'default_date' => Literal::string('1900-01-01')])]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildEomonth(array $args): Expression
    {
        $date = new TsOrDsToDate(['this' => $args[0] ?? null]);
        $monthLag = $args[1] ?? null;

        $this_ = $monthLag === null ? $date : new DateAdd(['this' => $date, 'expression' => $monthLag, 'unit' => Builder::var('month')]);

        return new LastDay(['this' => $this_]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildFormat(array $args): Expression
    {
        $this_ = $args[0] ?? null;
        $fmt = $args[1] ?? null;
        $culture = $args[2] ?? null;

        $numberFmt = $fmt instanceof Expression
            && (isset(self::TRANSPILE_SAFE_NUMBER_FMT[$fmt->name()]) || preg_match(self::DATE_FMT_RE, $fmt->name()) !== 1);

        if ($numberFmt) {
            return new NumberToStr(['this' => $this_, 'format' => $fmt, 'culture' => $culture]);
        }

        if ($fmt instanceof Expression) {
            $name = $fmt->name();
            $fmt = Literal::string((string) (strlen($name) === 1
                ? TimeFormat::convertWith($name, $this->dialect->settings['FORMAT_TIME_MAPPING'])
                : TimeFormat::convert($name, $this->dialect->settings['TIME_MAPPING'], $this->dialect->settings['TIME_TRIE'])));
        }

        return new TimeToStr(['this' => $this_, 'format' => $fmt, 'culture' => $culture]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildHashbytes(array &$args): Expression
    {
        [$kind, $data] = $args + [null, null];
        $kind = $kind instanceof Expression && $kind->isString() ? strtoupper($kind->name()) : '';

        if ($kind === 'MD5' || $kind === 'SHA' || $kind === 'SHA1') {
            array_shift($args);
        }

        return match ($kind) {
            'MD5' => new MD5(['this' => $data]),
            'SHA', 'SHA1' => new SHA(['this' => $data]),
            'SHA2_256' => new SHA2(['this' => $data, 'length' => Literal::number(256)]),
            'SHA2_512' => new SHA2(['this' => $data, 'length' => Literal::number(512)]),
            default => Builder::func('HASHBYTES', array_values(array_filter($args, static fn (mixed $a): bool => $a instanceof Expression))),
        };
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildIsnull(array $args): Expression
    {
        return $this->buildCoalesce($args, isNull: true);
    }

    /**
     * JSON_QUERY's path defaults to '$', which returns the input expression.
     *
     * @param  list<mixed>  $args
     */
    protected function buildJsonQuery(array &$args): Expression
    {
        if (count($args) === 1) {
            $args[] = Literal::string('$');
        }

        $jsonExtract = $this->buildExtractJsonWithPath($args, JSONExtract::class);
        $jsonExtract->set('json_query', true);

        return $jsonExtract;
    }

    /**
     * LEN / LEFT / RIGHT take their first argument as text.
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $klass
     */
    protected function buildWithArgAsText(array $args, string $klass): Expression
    {
        $this_ = $args[0] ?? null;

        if ($this_ instanceof Expression && ! $this_->isString()) {
            $this_ = Builder::cast($this_, DType::TEXT);
        }

        $kwargs = ['this' => $this_];
        $expression = $args[1] ?? null;

        if ($expression !== null) {
            $kwargs['expression'] = $expression;
        }

        return new $klass($kwargs);
    }

    /**
     * PARSENAME is stored as SplitPart when all args are literals and the part index is valid.
     *
     * @param  list<mixed>  $args
     */
    protected function buildParsename(array $args): Expression
    {
        if (count($args) === 2 && $args[0] instanceof Literal && $args[1] instanceof Literal) {
            $splitCount = count(explode('.', $args[0]->name()));

            if ($splitCount <= 4) {
                return new SplitPart([
                    'this' => $args[0],
                    'delimiter' => Literal::string('.'),
                    'part_index' => Literal::number($splitCount + 1 - (int) $args[1]->toPhp()),
                ]);
            }
        }

        return new Anonymous(['this' => 'PARSENAME', 'expressions' => $args]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSquare(array $args): Expression
    {
        return new Pow(['this' => $args[0] ?? null, 'expression' => Literal::number(2)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDatetrunc(array $args): Expression
    {
        $unit = $args[0] ?? null;
        $this_ = $args[1] ?? null;

        if ($this_ instanceof Expression && $this_->isString()) {
            $this_ = Builder::cast($this_, DType::DATETIME2);
        }

        return new TimestampTrunc(['this' => $this_, 'unit' => $unit]);
    }

    // ---------------------------------------------------------------------------------------
    // Dispatch table handlers
    // ---------------------------------------------------------------------------------------

    /**
     * @return array{0: string, 1: mixed}
     */
    protected function modifierOption(): array
    {
        return ['options', $this->parseOptions()];
    }

    /**
     * @return array{0: string, 1: mixed}
     */
    protected function modifierFor(): array
    {
        return ['for_', $this->parseFor()];
    }

    protected function rangeDcolon(?Expression $this_): ?Expression
    {
        return $this->expression(new ScopeResolution([
            'this' => $this_,
            'expression' => $this->parseFunction() ?? $this->parseVar(anyToken: true),
        ]));
    }

    protected function funcJsonArrayagg(): Expression
    {
        return $this->expression(new JSONArrayAgg([
            'this' => $this->parseBitwise(),
            'order' => $this->parseOrder(),
            'null_handling' => $this->parseOnHandling('NULL', 'NULL', 'ABSENT'),
        ]));
    }

    /**
     * The DCOLON (::) operator serves as a scope resolution operator in T-SQL.
     */
    protected function columnOpDcolon(?Expression $this_, ?Expression $to): Expression
    {
        if ($to instanceof DataType && ($to->args['this'] ?? null) !== DType::USERDEFINED) {
            return $this->expression(new Cast(['this' => $this_, 'to' => $to]));
        }

        return $this->expression(new ScopeResolution(['this' => $this_, 'expression' => $to]));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseExecute(): Expression
    {
        $returnStatus = null;
        $index = $this->index;

        if ($this->match(TokenType::PARAMETER)) {
            $param = $this->parseParameter();

            if ($this->match(TokenType::EQ)) {
                $returnStatus = $param;
            } else {
                $this->retreat($index);
            }
        }

        $execute = $this->expression(new Execute([
            'this' => $this->parseTable(schema: true),
            'expressions' => $this->parseCsv(fn (): ?Expression => $this->parseExpression()),
            'return_status' => $returnStatus,
        ]));

        if (strtolower($execute->name()) === 'sp_executesql') {
            $execute = $this->expression(new ExecuteSql($execute->args));
        }

        return $execute;
    }

    protected function parseDatepart(): Expression
    {
        $this_ = $this->parseVar(tokens: [TokenType::IDENTIFIER => true]);
        $expression = $this->match(TokenType::COMMA) ? $this->parseBitwise() : null;
        $name = $this->mapDatePart($this_);

        return $this->expression(new Extract(['this' => $name, 'expression' => $expression]));
    }

    protected function parseAlterTableSet(): AlterSet
    {
        return $this->parseWrapped(fn (): AlterSet => parent::parseAlterTableSet());
    }

    protected function parseWrappedSelect(bool $table = false): ?Expression
    {
        if ($this->match(TokenType::MERGE)) {
            $comments = $this->prevComments;
            $merge = $this->parseMerge();
            $merge->addComments($comments, prepend: true);

            return $merge;
        }

        return parent::parseWrappedSelect(table: $table);
    }

    protected function parseDcolon(): ?Expression
    {
        // Use parseTypes() if the first token after :: is a known type, otherwise something like
        // x::varchar(max) could be parsed into a function
        if ($this->matchSet($this->typeTokens, false)) {
            return $this->parseTypes();
        }

        return $this->parseFunction() ?? $this->parseTypes();
    }

    /**
     * @return list<Expression>|null
     */
    protected function parseOptions(): ?array
    {
        if (! $this->match(TokenType::OPTION)) {
            return null;
        }

        return $this->parseWrappedCsv(function (): ?Expression {
            $option = $this->parseVarFromOptions(self::OPTIONS);

            if ($option === null) {
                return null;
            }

            $this->match(TokenType::EQ);

            return $this->expression(new QueryOption(['this' => $option, 'expression' => $this->parsePrimaryOrVar()]));
        });
    }

    protected function parseKeyValueOption(): XMLKeyValueOption
    {
        $this_ = $this->parsePrimaryOrVar();
        $expression = $this->match(TokenType::L_PAREN, false)
            ? $this->parseWrapped(fn (): ?Expression => $this->parseString())
            : null;

        return new XMLKeyValueOption(['this' => $this_, 'expression' => $expression]);
    }

    /**
     * @param  array<string, list<mixed>>  $options
     */
    protected function parseForClauseOption(array $options): Expression
    {
        return $this->expression(new QueryOption([
            'this' => $this->parseVarFromOptions($options, raiseUnmatched: false) ?? $this->parseKeyValueOption(),
        ]));
    }

    protected function parseFor(): ?ForClause
    {
        if ($this->matchPair(TokenType::FOR, TokenType::XML)) {
            return $this->expression(new ForClause([
                'kind' => 'XML',
                'expressions' => $this->parseCsv(fn (): Expression => $this->parseForClauseOption(self::FOR_XML_OPTIONS)),
            ]));
        }

        if ($this->matchPair(TokenType::FOR, TokenType::JSON)) {
            return $this->expression(new ForClause([
                'kind' => 'JSON',
                'expressions' => $this->parseCsv(fn (): Expression => $this->parseForClauseOption(self::FOR_JSON_OPTIONS)),
            ]));
        }

        // FOR BROWSE: a bare keyword without options
        if ($this->matchTextSeq('FOR', 'BROWSE')) {
            return $this->expression(new ForClause(['kind' => 'BROWSE']));
        }

        return null;
    }

    /**
     * T-SQL supports `alias = expression` in the projection list, so EQ projections become aliases.
     *
     * @return array{0: list<Expression>, 1: list<Expression>|null}
     */
    protected function parseProjections(): array
    {
        [$projections] = parent::parseProjections();

        $converted = [];

        foreach ($projections as $projection) {
            $left = $projection->args['this'] ?? null;

            $converted[] = $projection instanceof EQ && $left instanceof Column
                ? Builder::alias($projection->argExpr('expression') ?? Builder::null(), $left->args['this'] instanceof Identifier ? $left->args['this'] : $left->name(), copy: false)
                : $projection;
        }

        return [$converted, null];
    }

    /**
     * COMMIT [ TRAN | TRANSACTION ] [ name ] [ WITH ( DELAYED_DURABILITY = { OFF | ON } ) ]
     * ROLLBACK { TRAN | TRANSACTION } [ name | savepoint ]
     */
    protected function parseCommitOrRollback(): Expression
    {
        $rollback = $this->prev === TokenType::ROLLBACK;

        $this->matchTexts(['TRAN' => true, 'TRANSACTION' => true]);
        $this_ = $this->parseIdVar();

        if ($rollback) {
            return $this->expression(new Rollback(['this' => $this_]));
        }

        $durability = null;

        if ($this->matchPair(TokenType::WITH, TokenType::L_PAREN)) {
            $this->matchTextSeq('DELAYED_DURABILITY');
            $this->match(TokenType::EQ);

            if ($this->matchTextSeq('OFF')) {
                $durability = false;
            } else {
                $this->match(TokenType::ON);
                $durability = true;
            }

            $this->matchRParen();
        }

        return $this->expression(new Commit(['this' => $this_, 'durability' => $durability]));
    }

    /**
     * BEGIN { TRAN | TRANSACTION } [ { transaction_name | @tran_name_variable } [ WITH MARK [ 'description' ] ] ]
     */
    protected function parseTransaction(): Expression
    {
        if ($this->matchTexts(['TRAN' => true, 'TRANSACTION' => true])) {
            $transaction = $this->expression(new Transaction(['this' => $this->parseIdVar()]));

            if ($this->matchTextSeq('WITH', 'MARK')) {
                $transaction->set('mark', $this->parseString());
            }

            return $transaction;
        }

        return $this->parseAsCommand($this->index - 1);
    }

    protected function parseReturns(): ReturnsProperty
    {
        $table = $this->parseIdVar(anyToken: false, tokens: $this->t['RETURNS_TABLE_TOKENS']);
        $returns = parent::parseReturns();
        $returns->set('table', $table);

        return $returns;
    }

    protected function parseConvert(bool $strict, ?bool $safe = null): ?Expression
    {
        $this_ = $this->parseTypes();
        $this->match(TokenType::COMMA);
        $args = [$this_, ...$this->parseCsv(fn (): ?Expression => $this->parseAssignment())];
        $convert = Convert::fromArgList($args);
        $convert->set('safe', $safe);

        return $convert;
    }

    protected function parseColumnDef(?Expression $this_, bool $computedColumn = true): ?Expression
    {
        $this_ = parent::parseColumnDef($this_, computedColumn: $computedColumn);

        if ($this_ === null) {
            return null;
        }

        if ($this->match(TokenType::EQ)) {
            $this_->set('default', $this->parseDisjunction());
        }

        if ($this->matchTexts(self::COLUMN_DEFINITION_MODES)) {
            $this_->set('output', $this->prevText());
        }

        return $this_;
    }

    protected function parseUserDefinedFunction(?int $kind = null): ?Expression
    {
        $this_ = parent::parseUserDefinedFunction(kind: $kind);

        if ($kind === TokenType::FUNCTION || $this_ instanceof UserDefinedFunction) {
            return $this_;
        }

        if ($kind === TokenType::PROCEDURE && $this_ !== null) {
            $expressions = $this_->argList('expressions');

            if ($expressions === [] && ! $this->matchSet([TokenType::ALIAS => true, TokenType::WITH => true], false)) {
                $expressions = $this->parseCsv(fn (): ?Expression => $this->parseFunctionParameter());
            }

            return $this->expression(new StoredProcedure([
                'this' => $this_ instanceof Table ? $this_ : ($this_->args['this'] ?? null),
                'expressions' => $expressions,
                'wrapped' => $this_->args['wrapped'] ?? null,
            ]));
        }

        return $this->expression(new UserDefinedFunction(['this' => $this_]));
    }

    protected function parseIntoClause(): ?Into
    {
        $into = parent::parseIntoClause();
        $table = $into?->find(Table::class);

        if ($table !== null && (($table->args['this'] ?? null)?->flag('temporary') ?? false)) {
            // Promote the temporary property from the Identifier to the Into expression
            $into->set('temporary', true);
        }

        return $into;
    }

    protected function parseIdVar(bool $anyToken = true, ?array $tokens = null): ?Expression
    {
        $isTemporary = $this->match(TokenType::HASH);
        $isGlobal = $isTemporary && $this->match(TokenType::HASH);

        $this_ = parent::parseIdVar(anyToken: $anyToken, tokens: $tokens);

        if ($this_ !== null) {
            if ($isGlobal) {
                $this_->set('global_', true);
            } elseif ($isTemporary) {
                $this_->set('temporary', true);
            }
        }

        return $this_;
    }

    protected function parseTableParts(bool $schema = false, bool $isDbReference = false, bool $wildcard = false, bool $fast = false): ?Expression
    {
        $table = parent::parseTableParts(schema: $schema, isDbReference: $isDbReference, wildcard: $wildcard, fast: $fast);
        $identifier = $table?->args['this'] ?? null;

        if ($table instanceof Table && $identifier instanceof Identifier) {
            $tableName = $table->name();

            if (str_starts_with($tableName, '##')) {
                $identifier->set('this', substr($tableName, 2));
                $identifier->set('global_', true);
            } elseif (str_starts_with($tableName, '#')) {
                $identifier->set('this', substr($tableName, 1));
                $identifier->set('temporary', true);
            }
        }

        return $table;
    }

    protected function parseCreate(): Expression
    {
        $create = parent::parseCreate();

        if ($create instanceof Create) {
            $target = $create->args['this'] ?? null;
            $table = $target instanceof Schema ? ($target->args['this'] ?? null) : $target;

            if ($table instanceof Table && (($table->args['this'] ?? null)?->flag('temporary') ?? false)) {
                if (! ($create->args['properties'] ?? null) instanceof Properties) {
                    $create->set('properties', new Properties(['expressions' => []]));
                }

                $create->args['properties']->append('expressions', new TemporaryProperty);
            }
        }

        return $create;
    }

    protected function parseIf(): ?Expression
    {
        $this_ = $this->parseCondition();
        $true = $this->parseBlock();
        $false = $this->match(TokenType::ELSE) ? $this->parseBlock() : null;

        return $this->expression(new IfBlock(['this' => $this_, 'true' => $true, 'false' => $false]));
    }

    protected function parseUnique(): UniqueColumnConstraint
    {
        if ($this->matchTexts(['CLUSTERED' => true, 'NONCLUSTERED' => true])) {
            $this_ = $this->callHandler($this->t['CONSTRAINT_PARSERS'][strtoupper($this->prevText())]);
        } else {
            $this_ = $this->parseSchema($this->parseIdVar(anyToken: false));
        }

        return $this->expression(new UniqueColumnConstraint(['this' => $this_]));
    }

    protected function parseUpdate(): Update
    {
        $expression = parent::parseUpdate();
        $expression->set('options', $this->parseOptions());

        return $expression;
    }

    protected function parsePartition(): ?Partition
    {
        if (! $this->matchTextSeq('WITH', '(', 'PARTITIONS')) {
            return null;
        }

        $parseRange = function (): ?Expression {
            $low = $this->parseBitwise();
            $high = $this->matchTextSeq('TO') ? $this->parseBitwise() : null;

            return $high !== null ? $this->expression(new PartitionRange(['this' => $low, 'expression' => $high])) : $low;
        };

        $partition = $this->expression(new Partition(['expressions' => $this->parseWrappedCsv($parseRange)]));
        $this->matchRParen();

        return $partition;
    }

    protected function parseAlterTableAlter(): ?Expression
    {
        $expression = parent::parseAlterTableAlter();

        if ($expression !== null) {
            $collation = $expression->args['collate'] ?? null;

            if ($collation instanceof Column && ($identifier = $collation->args['this'] ?? null) instanceof Identifier) {
                $collation->set('this', new VarExpr(['this' => $identifier->name()]));
            }

            if (($expression->args['dtype'] ?? null) !== null) {
                if ($this->matchPair(TokenType::NOT, TokenType::NULL)) {
                    $expression->set('allow_null', false);
                } elseif ($this->match(TokenType::NULL)) {
                    $expression->set('allow_null', true);
                }
            }
        }

        return $expression;
    }

    protected function parsePrimaryKeyPart(): ?Expression
    {
        return $this->parseOrdered();
    }
}
