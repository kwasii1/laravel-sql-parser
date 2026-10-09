<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\BigQuery;

use LaravelSqlParser\LaravelSqlParser\Expressions\AIForecast;
use LaravelSqlParser\LaravelSqlParser\Expressions\ArrayExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Bracket;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\ClusterProperty;
use LaravelSqlParser\LaravelSqlParser\Expressions\Column;
use LaravelSqlParser\LaravelSqlParser\Expressions\Contains;
use LaravelSqlParser\LaravelSqlParser\Expressions\Date;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateFromParts;
use LaravelSqlParser\LaravelSqlParser\Expressions\Datetime;
use LaravelSqlParser\LaravelSqlParser\Expressions\DatetimeTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Export;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\FeaturesAtTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\ForIn;
use LaravelSqlParser\LaravelSqlParser\Expressions\Func;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateBool;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateDouble;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateEmbedding;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateInt;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateTable;
use LaravelSqlParser\LaravelSqlParser\Expressions\GenerateText;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONArray;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONFormat;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONKeyValue;
use LaravelSqlParser\LaravelSqlParser\Expressions\JSONStripNulls;
use LaravelSqlParser\LaravelSqlParser\Expressions\Kwarg;
use LaravelSqlParser\LaravelSqlParser\Expressions\LastDay;
use LaravelSqlParser\LaravelSqlParser\Expressions\Length;
use LaravelSqlParser\LaravelSqlParser\Expressions\Levenshtein;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Lower;
use LaravelSqlParser\LaravelSqlParser\Expressions\LowerHex;
use LaravelSqlParser\LaravelSqlParser\Expressions\MakeInterval;
use LaravelSqlParser\LaravelSqlParser\Expressions\MD5;
use LaravelSqlParser\LaravelSqlParser\Expressions\MD5Digest;
use LaravelSqlParser\LaravelSqlParser\Expressions\MLForecast;
use LaravelSqlParser\LaravelSqlParser\Expressions\MLTranslate;
use LaravelSqlParser\LaravelSqlParser\Expressions\NetFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\Normalize;
use LaravelSqlParser\LaravelSqlParser\Expressions\ParseDatetime;
use LaravelSqlParser\LaravelSqlParser\Expressions\ParseTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\Predict;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\RegexpExtract;
use LaravelSqlParser\LaravelSqlParser\Expressions\SafeFunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\SHA2Digest;
use LaravelSqlParser\LaravelSqlParser\Expressions\Split;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToDate;
use LaravelSqlParser\LaravelSqlParser\Expressions\StrToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\Table;
use LaravelSqlParser\LaravelSqlParser\Expressions\Time;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeFromParts;
use LaravelSqlParser\LaravelSqlParser\Expressions\Timestamp;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampFromParts;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimestampTrunc;
use LaravelSqlParser\LaravelSqlParser\Expressions\TimeToStr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Translate;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToDatetime;
use LaravelSqlParser\LaravelSqlParser\Expressions\TsOrDsToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\UnixToTime;
use LaravelSqlParser\LaravelSqlParser\Expressions\Unnest;
use LaravelSqlParser\LaravelSqlParser\Expressions\VarExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\VectorSearch;
use LaravelSqlParser\LaravelSqlParser\Expressions\WeekStart;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;

/**
 * BigQuery parser (sqlglot parsers/bigquery.py).
 */
class BigQueryParser extends Parser
{
    public const string TABLES = BigQueryParserTables::class;

    private const array MAKE_INTERVAL_KWARGS = ['year', 'month', 'day', 'hour', 'minute', 'second'];

    /** Stands in for a domain dot while splitting a qualified name; can't occur in an identifier. */
    private const string DOMAIN_DOT = "\0";

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * In BigQuery, a bare WEEK date part is equivalent to WEEK(SUNDAY).
     *
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    private static function normalizeBareWeek(Expression $expression): Expression
    {
        $unit = $expression->args['unit'] ?? null;

        if (($unit instanceof Literal || $unit instanceof VarExpr) && strtoupper($unit->name()) === 'WEEK') {
            $expression->set('unit', new WeekStart(['this' => Builder::var('SUNDAY')]));
        }

        return $expression;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildContainsSubstring(array $args): Contains
    {
        return new Contains([
            'this' => new Lower(['this' => $args[0] ?? null]),
            'expression' => new Lower(['this' => $args[1] ?? null]),
            'json_scope' => $args[2] ?? null,
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDate(array $args): Expression
    {
        return count($args) === 3 ? DateFromParts::fromArgList($args) : Date::fromArgList($args);
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildDateDiff(array $args, string $exprType): Expression
    {
        return self::normalizeBareWeek(new $exprType([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'unit' => $args[2] ?? null,
            'date_part_boundary' => true,
        ]));
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDateTrunc(array $args): Expression
    {
        return self::normalizeBareWeek(new DateTrunc(['unit' => $args[1] ?? null, 'this' => $args[0] ?? null, 'zone' => $args[2] ?? null]));
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDatetime(array $args): Expression
    {
        return match (count($args)) {
            1 => TsOrDsToDatetime::fromArgList($args),
            2 => Datetime::fromArgList($args),
            default => TimestampFromParts::fromArgList($args),
        };
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildDatetimeTrunc(array $args): Expression
    {
        return self::normalizeBareWeek(DatetimeTrunc::fromArgList($args));
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTimestampTrunc(array $args): Expression
    {
        return self::normalizeBareWeek(TimestampTrunc::fromArgList($args));
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildLastDay(array $args): Expression
    {
        return self::normalizeBareWeek(LastDay::fromArgList($args));
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildExtractJsonWithDefaultPath(array $args, string $exprType): Expression
    {
        if (count($args) === 1) {
            $args[] = Literal::string('$');
        }

        return $this->buildExtractJsonWithPath($args, $exprType);
    }

    /**
     * FORMAT_DATE(fmt, x [, zone]) and friends.
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildFormatTime(array $args, string $exprType): Expression
    {
        $formattedTime = $this->buildFormattedTime([new $exprType(['this' => $args[1] ?? null]), $args[0] ?? null], TimeToStr::class);
        $formattedTime->set('zone', $args[2] ?? null);

        return $formattedTime;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildJsonStripNulls(array $args): JSONStripNulls
    {
        $expression = new JSONStripNulls(['this' => $args[0] ?? null]);

        foreach (array_slice($args, 1) as $arg) {
            if ($arg instanceof Kwarg && ($arg->args['this'] ?? null) instanceof Expression) {
                $expression->set(strtolower($arg->args['this']->name()), $arg);
            } else {
                $expression->set('expression', $arg);
            }
        }

        return $expression;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildLevenshtein(array $args): Levenshtein
    {
        $maxDist = $args[2] ?? null;

        return new Levenshtein([
            'this' => $args[0] ?? null,
            'expression' => $args[1] ?? null,
            'max_dist' => $maxDist instanceof Expression ? ($maxDist->args['expression'] ?? null) : null,
        ]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildParseDate(array $args): Expression
    {
        $this_ = $this->buildFormattedTime([$args[1] ?? null, $args[0] ?? null], StrToDate::class);
        $this_->set('default_year', Literal::number(1970));

        return $this_;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildParseTimestamp(array $args): Expression
    {
        $this_ = $this->buildFormattedTime([$args[1] ?? null, $args[0] ?? null], StrToTime::class);
        $this_->set('zone', $args[2] ?? null);
        $this_->set('default_year', Literal::number(1970));

        return $this_;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildParseDatetime(array $args): Expression
    {
        $this_ = $this->buildFormattedTime([$args[1] ?? null, $args[0] ?? null], ParseDatetime::class);
        $this_->set('default_year', Literal::number(1970));

        return $this_;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildParseTime(array $args): Expression
    {
        return $this->buildFormattedTime([$args[1] ?? null, $args[0] ?? null], ParseTime::class);
    }

    /**
     * REGEXP_EXTRACT and friends: a pattern with exactly one capturing group extracts group 1.
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildRegexpExtractBigquery(array $args, string $exprType, ?Expression $defaultGroup = null): Expression
    {
        $pattern = $args[1] ?? null;
        $group = $pattern instanceof Expression && self::regexGroupCount($pattern->name()) === 1;

        $kwargs = [
            'this' => $args[0] ?? null,
            'expression' => $pattern,
            'position' => $args[2] ?? null,
            'occurrence' => $args[3] ?? null,
            'group' => $group ? Literal::number(1) : $defaultGroup,
        ];

        if ($exprType === RegexpExtract::class) {
            $kwargs['null_if_pos_overflow'] = $this->dialect->settings['REGEXP_EXTRACT_POSITION_OVERFLOW_RETURNS_NULL'];
        }

        return new $exprType($kwargs);
    }

    /**
     * The number of capturing groups in a regular expression (Python re.compile(p).groups), or -1
     * if it doesn't compile.
     */
    private static function regexGroupCount(string $pattern): int
    {
        $regex = '/(?:'.str_replace('/', '\/', $pattern).')|/u';

        if (@preg_match($regex, '', $matches, PREG_UNMATCHED_AS_NULL) === false) {
            return -1;
        }

        return count(array_filter(array_keys($matches), 'is_int')) - 1;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTime(array $args): Expression
    {
        return match (count($args)) {
            1 => new TsOrDsToTime(['this' => $args[0]]),
            2 => Time::fromArgList($args),
            default => TimeFromParts::fromArgList($args),
        };
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTimestamp(array $args): Expression
    {
        $timestamp = Timestamp::fromArgList($args);
        $timestamp->set('with_tz', true);

        return $timestamp;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildToHex(array $args): Expression
    {
        $arg = $args[0] ?? null;

        return $arg instanceof MD5Digest ? new MD5(['this' => $arg->args['this'] ?? null]) : new LowerHex(['this' => $arg]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildLength(array $args): Length
    {
        return new Length(['this' => $args[0] ?? null, 'binary' => true]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildNormalizeAndCasefold(array $args): Normalize
    {
        return new Normalize(['this' => $args[0] ?? null, 'form' => $args[1] ?? null, 'is_casefold' => true]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSha256(array $args): SHA2Digest
    {
        return new SHA2Digest(['this' => $args[0] ?? null, 'length' => Literal::number(256)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSha512(array $args): SHA2Digest
    {
        return new SHA2Digest(['this' => $args[0] ?? null, 'length' => Literal::number(512)]);
    }

    /**
     * https://cloud.google.com/bigquery/docs/reference/standard-sql/string_functions#split
     *
     * @param  list<mixed>  $args
     */
    protected function buildSplit(array $args): Split
    {
        return new Split(['this' => $args[0] ?? null, 'expression' => $args[1] ?? Literal::string(',')]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTimestampMicros(array $args): UnixToTime
    {
        return new UnixToTime(['this' => $args[0] ?? null, 'scale' => Literal::number(6)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTimestampMillis(array $args): UnixToTime
    {
        return new UnixToTime(['this' => $args[0] ?? null, 'scale' => Literal::number(3)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTimestampSeconds(array $args): UnixToTime
    {
        return new UnixToTime(['this' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildToJson(array $args): JSONFormat
    {
        return new JSONFormat(['this' => $args[0] ?? null, 'options' => $args[1] ?? null, 'to_json' => true]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildWeek(array $args): WeekStart
    {
        $day = $args[0] ?? null;

        return new WeekStart(['this' => Builder::var($day instanceof Expression ? $day->name() : (string) $day)]);
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTION_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function funcArray(): Expression
    {
        return $this->expression(new ArrayExpr(['expressions' => [$this->parseStatement()], 'struct_name_inheritance' => true]));
    }

    protected function funcJsonArray(): Expression
    {
        return $this->expression(new JSONArray(['expressions' => $this->parseCsv(fn (): ?Expression => $this->parseBitwise())]));
    }

    protected function funcPredict(): Expression
    {
        return $this->parseMl(Predict::class);
    }

    protected function funcGenerateEmbedding(): Expression
    {
        return $this->parseMl(GenerateEmbedding::class);
    }

    protected function funcGenerateTextEmbedding(): Expression
    {
        return $this->parseMl(GenerateEmbedding::class, ['is_text' => true]);
    }

    protected function funcGenerateText(): Expression
    {
        return $this->parseGenerate(GenerateText::class);
    }

    protected function funcGenerateTable(): Expression
    {
        return $this->parseGenerate(GenerateTable::class);
    }

    protected function funcGenerateBool(): Expression
    {
        return $this->parseGenerate(GenerateBool::class);
    }

    protected function funcGenerateInt(): Expression
    {
        return $this->parseGenerate(GenerateInt::class);
    }

    protected function funcGenerateDouble(): Expression
    {
        return $this->parseGenerate(GenerateDouble::class);
    }

    protected function parseMakeInterval(): MakeInterval
    {
        $expression = new MakeInterval;

        foreach (self::MAKE_INTERVAL_KWARGS as $argKey) {
            $value = $this->parseLambda();

            if ($value === null) {
                break;
            }

            // Positional arguments come first, optionally followed by named ones in any order,
            // e.g. MAKE_INTERVAL(1, minute => 5, day => 2)
            if ($value instanceof Kwarg && ($value->args['this'] ?? null) instanceof Expression) {
                $argKey = $value->args['this']->name();
            }

            $expression->set($argKey, $value);
            $this->match(TokenType::COMMA);
        }

        return $expression;
    }

    /**
     * @param  class-string<Expression>  $exprType
     * @param  array<string, mixed>  $kwargs
     */
    private function parseMl(string $exprType, array $kwargs = []): Expression
    {
        $this->matchTextSeq('MODEL');
        $this_ = $this->parseTable();
        $this->match(TokenType::COMMA);
        $this->matchTextSeq('TABLE');

        // Some functions, e.g. ML.FORECAST, take a STRUCT argument but not a TABLE / SELECT one
        $expression = $this->match(TokenType::STRUCT, false) ? null : $this->parseTable();
        $this->match(TokenType::COMMA);

        return $this->expression(new $exprType(['this' => $this_, 'expression' => $expression, 'params_struct' => $this->parseBitwise()] + $kwargs));
    }

    /**
     * @param  class-string<Expression>  $exprType
     */
    private function parseGenerate(string $exprType): Expression
    {
        $this->matchTextSeq('MODEL');
        $this_ = $this->parseTable();
        $this->match(TokenType::COMMA);

        if ($this->matchTextSeq('TABLE') || $this->match(TokenType::L_PAREN, false)) {
            $expression = $this->parseTable();
        } else {
            $expression = $this->parseBitwise();
        }

        $paramsStruct = $this->match(TokenType::COMMA) ? $this->parseBitwise() : false;

        return $this->expression(new $exprType(['this' => $this_, 'expression' => $expression, 'params_struct' => $paramsStruct]));
    }

    /**
     * Whether the function name is qualified with ML, i.e. ML.<name>(...).
     */
    private function isMlFunction(): bool
    {
        $index = $this->index - 4;

        return $index >= 0 && strtoupper($this->texts[$index] ?? '') === 'ML';
    }

    protected function parseTranslate(): Expression
    {
        if ($this->isMlFunction()) {
            return $this->parseMl(MLTranslate::class);
        }

        return Translate::fromArgList($this->parseFunctionArgs());
    }

    protected function parseForecast(): Expression
    {
        if ($this->isMlFunction()) {
            return $this->parseMl(MLForecast::class);
        }

        // AI.FORECAST is a table-valued function: TABLE <table> or a parenthesized query,
        // followed by named arguments
        $this->match(TokenType::TABLE);
        $this_ = $this->parseTable();

        if ($this_ === null) {
            $this->raiseError('Expected table or query statement');
        }

        $expression = $this->expression(new AIForecast(['this' => $this_]));

        while ($this->match(TokenType::COMMA)) {
            $arg = $this->parseLambda();

            if ($arg instanceof Kwarg && ($arg->args['this'] ?? null) instanceof Expression) {
                $expression->set($arg->args['this']->name(), $arg);
            } else {
                $this->raiseError('Expected key => value syntax for AI.FORECAST, got '.($arg !== null ? $arg->sql() : 'None'));

                break;
            }
        }

        return $expression;
    }

    protected function parseFeaturesAtTime(): FeaturesAtTime
    {
        $this->match(TokenType::TABLE);
        $expression = $this->expression(new FeaturesAtTime(['this' => $this->parseTable()]));

        while ($this->match(TokenType::COMMA)) {
            $arg = $this->parseLambda();

            // The Kwarg's name is the arg it sets, e.g. "num_rows => 1" sets num_rows
            if ($arg !== null && ($arg->args['this'] ?? null) instanceof Expression) {
                $expression->set($arg->args['this']->name(), $arg);
            }
        }

        return $expression;
    }

    protected function parseVectorSearch(): VectorSearch
    {
        $this->match(TokenType::TABLE);
        $baseTable = $this->parseTable();
        $this->match(TokenType::COMMA);
        $columnToSearch = $this->parseBitwise();
        $this->match(TokenType::COMMA);
        $this->match(TokenType::TABLE);
        $queryTable = $this->parseTable();

        $expression = $this->expression(new VectorSearch(['this' => $baseTable, 'column_to_search' => $columnToSearch, 'query_table' => $queryTable]));

        while ($this->match(TokenType::COMMA)) {
            // query_column_to_search can be named or positional
            if ($this->match(TokenType::STRING, false)) {
                $expression->set('query_column_to_search', $this->parseString());
            } else {
                $arg = $this->parseLambda();

                if ($arg !== null && ($arg->args['this'] ?? null) instanceof Expression) {
                    $expression->set($arg->args['this']->name(), $arg);
                }
            }
        }

        return $expression;
    }

    // ---------------------------------------------------------------------------------------
    // CONSTRAINT / STATEMENT parsers
    // ---------------------------------------------------------------------------------------

    protected function constraintOptions(): Expression
    {
        $options = $this->parseWithProperty();

        return new Properties(['expressions' => is_array($options) ? $options : ($options === null ? [] : [$options])]);
    }

    protected function statementElse(): Expression
    {
        return $this->parseAsCommand($this->index - 1);
    }

    protected function statementEnd(): Expression
    {
        return $this->parseAsCommand($this->index - 1);
    }

    protected function parseForIn(): Expression
    {
        $index = $this->index;
        $this_ = $this->parseRange();
        $this->matchTextSeq('DO');

        if ($this->match(TokenType::COMMAND)) {
            $this->retreat($index);

            return $this->parseAsCommand($this->index - 1);
        }

        return $this->expression(new ForIn(['this' => $this_, 'expression' => $this->parseStatement()]));
    }

    protected function parseExportData(): Export
    {
        $this->matchTextSeq('DATA');

        return $this->expression(new Export([
            'connection' => $this->matchTextSeq('WITH', 'CONNECTION') ? $this->parseTableParts() : false,
            'options' => $this->parseProperties(),
            'this' => $this->matchTextSeq('AS') ? $this->parseSelect(nested: true) : false,
        ]));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseTablePart(bool $schema = false): ?Expression
    {
        $this_ = parent::parseTablePart(schema: $schema) ?? $this->parseNumber();

        // https://cloud.google.com/bigquery/docs/reference/standard-sql/lexical#table_names
        if ($this_ instanceof Identifier) {
            $tableName = $this_->name();

            while ($this->match(TokenType::DASH, false) && $this->next !== 0) {
                $start = $this->index;

                while ($this->isConnected() && ! $this->matchSet($this->t['DASHED_TABLE_PART_FOLLOW_TOKENS'], false)) {
                    $this->advance();
                }

                if ($start === $this->index) {
                    break;
                }

                $tableName .= $this->findSql($start, $this->index - 1);
            }

            $this_ = self::withPositions(new Identifier(['this' => $tableName, 'quoted' => $this_->args['quoted'] ?? null]), $this_);
        } elseif ($this_ instanceof Literal) {
            $tableName = $this_->name();

            if ($this->isConnected() && $this->parseVar(anyToken: true) !== null) {
                $tableName .= $this->prevText();
            }

            $this_ = self::withPositions(new Identifier(['this' => $tableName, 'quoted' => true]), $this_);
        }

        return $this_;
    }

    protected function parseTableParts(bool $schema = false, bool $isDbReference = false, bool $wildcard = false, bool $fast = false): ?Expression
    {
        $table = parent::parseTableParts(schema: $schema, isDbReference: $isDbReference, wildcard: true, fast: $fast);

        if (! $table instanceof Table) {
            return $table;
        }

        // proj-1.db.tbl: "1." is tokenized as a float, so it's unraveled here
        if ($table->text('catalog') === '') {
            $db = $table->args['db'] ?? null;

            if ($db instanceof Identifier && $db->name() !== '') {
                $parts = explode('.', $db->name());

                if (count($parts) === 2 && ! $db->quoted()) {
                    $table->set('catalog', self::withPositions(new Identifier(['this' => $parts[0]]), $db));
                    $table->set('db', self::withPositions(new Identifier(['this' => $parts[1]]), $db));
                }
            } else {
                $previousThis = $table->args['this'] ?? null;
                $parts = explode('.', $table->name());

                if (count($parts) === 2 && $previousThis instanceof Identifier && ! $previousThis->quoted()) {
                    $table->set('db', self::withPositions(new Identifier(['this' => $parts[0]]), $previousThis));
                    $table->set('this', self::withPositions(new Identifier(['this' => $parts[1]]), $previousThis));
                }
            }
        }

        $alias = null;
        $tableThis = $table->args['this'] ?? null;
        $hasDot = false;

        foreach ($table->parts() as $part) {
            if (str_contains($part->name(), '.')) {
                $hasDot = true;
            }
        }

        if ($tableThis instanceof Identifier && $hasDot) {
            $alias = $tableThis;
            $names = array_map(static fn (Expression $p): string => $p->name(), $table->parts());
            $identifiers = array_map(
                static fn (?string $p): ?Identifier => $p === null ? null : Builder::toIdentifier($p, quoted: true),
                self::splitQualifiedName(implode('.', $names), 3),
            );
            [$catalog, $db, $thisId] = [$identifiers[0], $identifiers[1], $identifiers[2] ?? null];
            $rest = array_values(array_filter(array_slice($identifiers, 3)));

            foreach ([$catalog, $db, $thisId] as $part) {
                if ($part !== null) {
                    self::withPositions($part, $tableThis);
                }
            }

            $this_ = $thisId;

            if ($rest !== [] && $this_ !== null) {
                $this_ = Dot::build([$this_, ...$rest]);
            }

            $table = new Table(['this' => $this_, 'db' => $db, 'catalog' => $catalog, 'pivots' => $table->args['pivots'] ?? null]);
            $table->meta['quoted_table'] = true;
        }

        // INFORMATION_SCHEMA views must be qualified by a region or dataset, so without a project
        // the INFORMATION_SCHEMA.X part is one (quoted) identifier. Otherwise the table would look
        // like it has a catalog when that's really the region / dataset, and mixing 3-part names
        // with 4-part information schema views breaks the schema module.
        // See: https://cloud.google.com/bigquery/docs/information-schema-intro#syntax
        $tableParts = $table->parts();
        $count = count($tableParts);

        if ($count > 1 && strtoupper($tableParts[$count - 2]->name()) === 'INFORMATION_SCHEMA') {
            // Aliased so qualified columns keep working: an actual alias coming up overwrites it,
            // and without one this only exposes the name the view can be referenced by
            $aliasIdentifier = $alias ?? $tableParts[$count - 1];
            Builder::alias($table, $aliasIdentifier instanceof Identifier ? $aliasIdentifier : $aliasIdentifier->name(), table: true, copy: false);

            $infoSchemaView = $tableParts[$count - 2]->name().'.'.$tableParts[$count - 1]->name();
            $table->set('this', new Identifier(['this' => $infoSchemaView, 'quoted' => true]));
            $table->set('db', $tableParts[$count - 3] ?? null);
            $table->set('catalog', $tableParts[$count - 4] ?? null);
        }

        return $table;
    }

    /**
     * Split a dotted reference (`project.dataset.table`) into a fixed number of parts, the first
     * being the project. Domain-scoped (legacy) project IDs, `domain.com:project-id`, have dots
     * that belong to the domain, not the path; a project ID itself can't contain dots, so every
     * such dot comes before the colon.
     * See: https://docs.cloud.google.com/artifact-registry/docs/docker/names#domain
     *
     * @return list<string|null>
     */
    private static function splitQualifiedName(string $name, int $minNumWords): array
    {
        $colon = strpos($name, ':');
        $masked = $colon !== false && str_contains(substr($name, 0, $colon), '.');

        if ($masked) {
            $name = str_replace('.', self::DOMAIN_DOT, substr($name, 0, $colon)).substr($name, $colon);
        }

        $words = explode('.', $name);
        $words = [...array_fill(0, max(0, $minNumWords - count($words)), null), ...$words];

        return $masked
            ? array_map(static fn (?string $p): ?string => $p === null ? null : str_replace(self::DOMAIN_DOT, '.', $p), $words)
            : $words;
    }

    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    private static function withPositions(Expression $expression, Expression $other): Expression
    {
        foreach (['line', 'col', 'start', 'end'] as $key) {
            if (isset($other->meta[$key])) {
                $expression->meta[$key] = $other->meta[$key];
            }
        }

        return $expression;
    }

    protected function parseColumn(): ?Expression
    {
        $column = parent::parseColumn();

        if ($column instanceof Column) {
            $parts = $column->parts();
            $hasDot = false;

            foreach ($parts as $part) {
                if (str_contains($part->name(), '.')) {
                    $hasDot = true;
                }
            }

            if ($hasDot) {
                $names = array_map(static fn (Expression $p): string => $p->name(), $parts);
                $identifiers = array_map(
                    static fn (?string $p): ?Identifier => $p === null ? null : Builder::toIdentifier($p, quoted: true),
                    self::splitQualifiedName(implode('.', $names), 4),
                );
                [$catalog, $db, $table, $thisId] = [$identifiers[0], $identifiers[1], $identifiers[2], $identifiers[3] ?? null];
                $rest = array_values(array_filter(array_slice($identifiers, 4)));
                $this_ = $thisId;

                if ($rest !== [] && $this_ !== null) {
                    $this_ = Dot::build([$this_, ...$rest]);
                }

                $column = new Column(['this' => $this_, 'table' => $table, 'db' => $db, 'catalog' => $catalog]);
                $column->meta['quoted_column'] = true;
            }
        }

        return $column;
    }

    protected function parseClusterProperty(): ClusterProperty
    {
        return $this->expression(new ClusterProperty(['expressions' => $this->parseCsv(fn (): ?Expression => $this->parseColumn())]));
    }

    protected function parseJsonObject(bool $agg = false): Expression
    {
        $jsonObject = parent::parseJsonObject();
        $pair = $jsonObject->argList('expressions')[0] ?? null;

        // BigQuery's "signature 2" of JSON_OBJECT, JSON_OBJECT([keys], [values])
        // https://cloud.google.com/bigquery/docs/reference/standard-sql/json_functions#json_object_signature2
        if ($pair !== null && ($pair->args['this'] ?? null) instanceof ArrayExpr && ($pair->args['expression'] ?? null) instanceof ArrayExpr) {
            $keys = $pair->args['this']->argList('expressions');
            $values = $pair->args['expression']->argList('expressions');
            $pairs = [];

            foreach ($keys as $i => $key) {
                if (! isset($values[$i])) {
                    break;
                }

                $pairs[] = new JSONKeyValue(['this' => $key, 'expression' => $values[$i]]);
            }

            $jsonObject->set('expressions', $pairs);
        }

        return $jsonObject;
    }

    protected function parseBracket(?Expression $this_ = null): ?Expression
    {
        $bracket = parent::parseBracket($this_);

        if ($bracket instanceof ArrayExpr) {
            $bracket->set('struct_name_inheritance', true);
        }

        if ($this_ === $bracket) {
            return $bracket;
        }

        if ($bracket instanceof Bracket) {
            foreach ($bracket->argList('expressions') as $expression) {
                $name = strtoupper($expression->name());
                $expressions = $expression->argList('expressions');

                if (! isset($this->t['BRACKET_OFFSETS'][$name]) || $expressions === []) {
                    break;
                }

                [$offset, $safe] = $this->t['BRACKET_OFFSETS'][$name];
                $bracket->set('offset', $offset);
                $bracket->set('safe', $safe);
                $expression->replace($expressions[0]);
            }
        }

        return $bracket;
    }

    protected function parseUnnest(bool $withAlias = true): ?Unnest
    {
        $unnest = parent::parseUnnest(withAlias: $withAlias);

        if ($unnest === null) {
            return null;
        }

        $unnestExpr = $unnest->argList('expressions')[0] ?? null;

        if ($unnestExpr !== null) {
            $this->dialect->annotateTypes($unnestExpr);
            $type = $unnestExpr->type();

            // Unnesting an array of structs explodes the top-level struct fields, unlike dialects
            // such as DuckDB which only flatten the array
            if ($type !== null && $unnestExpr->isType(DType::ARRAY)) {
                foreach ($type->argList('expressions') as $element) {
                    if ($element->isType(DType::STRUCT)) {
                        $unnest->set('explode_array', true);

                        break;
                    }
                }
            }
        }

        return $unnest;
    }

    protected function parseColumnOps(?Expression $this_): ?Expression
    {
        $funcIndex = $this->index + 1;
        $this_ = parent::parseColumnOps($this_);

        if ($this_ instanceof Dot && ($this_->args['expression'] ?? null) instanceof Func) {
            $prefix = strtoupper(($this_->args['this'] ?? null) instanceof Expression ? $this_->args['this']->name() : '');
            $func = match ($prefix) {
                'NET' => NetFunc::class,
                'SAFE' => SafeFunc::class,
                default => null,
            };

            if ($func !== null) {
                // Retreat to parse a known function instead of the anonymous one the base column
                // ops parser builds (anonymous_func=True)
                $this->retreat($funcIndex);
                $this_ = new $func(['this' => $this->parseFunction(anyToken: true)]);
            } elseif ($prefix === 'AI' || $prefix === 'ML') {
                // AI.* and ML.* calls can have custom signatures that need the function parsers
                $this->retreat($funcIndex);
                $parsed = $this->parseFunction(anyToken: true);

                if ($parsed !== null) {
                    $this_ = $this->expression(new Dot(['this' => $this_->args['this'], 'expression' => $parsed]));
                }
            }
        }

        return $this_;
    }
}
