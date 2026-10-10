<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\ClickHouse;

use Kwasii\LaravelSqlParser\Expressions\AlterModifySqlSecurity;
use Kwasii\LaravelSqlParser\Expressions\Anonymous;
use Kwasii\LaravelSqlParser\Expressions\AnonymousAggFunc;
use Kwasii\LaravelSqlParser\Expressions\Apply;
use Kwasii\LaravelSqlParser\Expressions\ArrayExpr;
use Kwasii\LaravelSqlParser\Expressions\AssumeColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\AutoRefreshProperty;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\CheckColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Columns;
use Kwasii\LaravelSqlParser\Expressions\CombinedAggFunc;
use Kwasii\LaravelSqlParser\Expressions\CombinedParameterizedAgg;
use Kwasii\LaravelSqlParser\Expressions\CountIf;
use Kwasii\LaravelSqlParser\Expressions\CTE;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DefinerProperty;
use Kwasii\LaravelSqlParser\Expressions\Detach;
use Kwasii\LaravelSqlParser\Expressions\Dot;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\EngineProperty;
use Kwasii\LaravelSqlParser\Expressions\Explode;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Extract;
use Kwasii\LaravelSqlParser\Expressions\FinalExpr;
use Kwasii\LaravelSqlParser\Expressions\GenerateSeries;
use Kwasii\LaravelSqlParser\Expressions\GroupConcat;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\IfExpr;
use Kwasii\LaravelSqlParser\Expressions\In;
use Kwasii\LaravelSqlParser\Expressions\IndexColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Join;
use Kwasii\LaravelSqlParser\Expressions\JSONCast;
use Kwasii\LaravelSqlParser\Expressions\Length;
use Kwasii\LaravelSqlParser\Expressions\Limit;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\NestedJSONSelect;
use Kwasii\LaravelSqlParser\Expressions\Not;
use Kwasii\LaravelSqlParser\Expressions\OnCluster;
use Kwasii\LaravelSqlParser\Expressions\ParameterizedAgg;
use Kwasii\LaravelSqlParser\Expressions\Partition;
use Kwasii\LaravelSqlParser\Expressions\PartitionedByProperty;
use Kwasii\LaravelSqlParser\Expressions\PartitionId;
use Kwasii\LaravelSqlParser\Expressions\Placeholder;
use Kwasii\LaravelSqlParser\Expressions\ProjectionDef;
use Kwasii\LaravelSqlParser\Expressions\PropertyEQ;
use Kwasii\LaravelSqlParser\Expressions\Quantile;
use Kwasii\LaravelSqlParser\Expressions\RegexpExtract;
use Kwasii\LaravelSqlParser\Expressions\ReplacePartition;
use Kwasii\LaravelSqlParser\Expressions\SHA2;
use Kwasii\LaravelSqlParser\Expressions\Split;
use Kwasii\LaravelSqlParser\Expressions\StrPosition;
use Kwasii\LaravelSqlParser\Expressions\StrToDate;
use Kwasii\LaravelSqlParser\Expressions\Struct;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\TimestampTrunc;
use Kwasii\LaravelSqlParser\Expressions\Tuple;
use Kwasii\LaravelSqlParser\Expressions\UuidProperty;
use Kwasii\LaravelSqlParser\Expressions\VarExpr;
use Kwasii\LaravelSqlParser\Expressions\VarMap;
use Kwasii\LaravelSqlParser\Expressions\Window;
use Kwasii\LaravelSqlParser\Parser\Parser;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * ClickHouse parser (sqlglot parsers/clickhouse.py).
 */
class ClickHouseParser extends Parser
{
    public const string TABLES = ClickHouseParserTables::class;

    /** Aggregate functions, which may carry combinator suffixes (sumIf, uniqMerge, ...). */
    public const array AGG_FUNCTIONS = [
        'count', 'min', 'max', 'sum', 'avg', 'any', 'stddevPop', 'stddevSamp', 'varPop', 'varSamp',
        'corr', 'covarPop', 'covarSamp', 'entropy', 'exponentialMovingAverage', 'intervalLengthSum',
        'kolmogorovSmirnovTest', 'mannWhitneyUTest', 'median', 'rankCorr', 'sumKahan', 'studentTTest',
        'welchTTest', 'anyHeavy', 'anyLast', 'boundingRatio', 'first_value', 'last_value', 'argMin',
        'argMax', 'avgWeighted', 'topK', 'approx_top_sum', 'topKWeighted', 'deltaSum',
        'deltaSumTimestamp', 'groupArray', 'groupArrayLast', 'groupConcat', 'groupUniqArray',
        'groupArrayInsertAt', 'groupArrayMovingAvg', 'groupArrayMovingSum', 'groupArraySample',
        'groupBitAnd', 'groupBitOr', 'groupBitXor', 'groupBitmap', 'groupBitmapAnd', 'groupBitmapOr',
        'groupBitmapXor', 'sumWithOverflow', 'sumMap', 'minMap', 'maxMap', 'skewSamp', 'skewPop',
        'kurtSamp', 'kurtPop', 'uniq', 'uniqExact', 'uniqCombined', 'uniqCombined64', 'uniqHLL12',
        'uniqTheta', 'quantile', 'quantiles', 'quantileExact', 'quantileExactInclusive',
        'quantilesExact', 'quantilesExactExclusive', 'quantileExactLow', 'quantilesExactLow',
        'quantileExactHigh', 'quantilesExactHigh', 'quantileExactWeighted', 'quantilesExactWeighted',
        'quantileTiming', 'quantilesTiming', 'quantileTimingWeighted', 'quantilesTimingWeighted',
        'quantileDeterministic', 'quantilesDeterministic', 'quantileTDigest', 'quantilesTDigest',
        'quantileTDigestWeighted', 'quantilesTDigestWeighted', 'quantileBFloat16', 'quantilesBFloat16',
        'quantileBFloat16Weighted', 'quantilesBFloat16Weighted', 'simpleLinearRegression',
        'stochasticLinearRegression', 'stochasticLogisticRegression', 'categoricalInformationValue',
        'contingency', 'cramersV', 'cramersVBiasCorrected', 'theilsU', 'maxIntersections',
        'maxIntersectionsPosition', 'meanZTest', 'quantileInterpolatedWeighted',
        'quantilesInterpolatedWeighted', 'quantileGK', 'quantilesGK', 'sparkBar', 'sumCount',
        'largestTriangleThreeBuckets', 'histogram', 'sequenceMatch', 'sequenceCount', 'windowFunnel',
        'retention', 'uniqUpTo', 'sequenceNextNode', 'exponentialTimeDecayedAvg',
    ];

    /** Combinator suffixes, longest first so compound ones (SimpleState) win over their parts (State). */
    public const array AGG_FUNCTIONS_SUFFIXES = [
        'SimpleState', 'MergeState', 'OrDefault', 'Distinct', 'Resample', 'ArrayIf', 'ForEach',
        'OrNull', 'ArgMin', 'ArgMax', 'Array', 'State', 'Merge', 'If', 'Map',
    ];

    /** @var array<string, array{0: string, 1: string|null}>|null every 0- and 1-suffix aggregate name */
    private static ?array $aggFuncMapping = null;

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $exprType
     */
    protected function buildDatetimeFormat(array $args, string $exprType): Expression
    {
        $expression = $this->buildFormattedTime($args, $exprType);
        $timezone = $args[2] ?? null;

        if ($timezone instanceof Expression) {
            $expression->set('zone', $timezone);
        }

        return $expression;
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildCountIf(array $args): Expression
    {
        if (count($args) === 1) {
            return new CountIf(['this' => $args[0]]);
        }

        return new CombinedAggFunc(['this' => 'countIf', 'expressions' => $args]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildStrToDate(array $args): Expression
    {
        if (count($args) === 3) {
            return new Anonymous(['this' => 'STR_TO_DATE', 'expressions' => $args]);
        }

        return Builder::cast(StrToDate::fromArgList($args), new DataType(['this' => DType::DATETIME]));
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTimestampTruncClickhouse(array $args, string $unit): TimestampTrunc
    {
        return new TimestampTrunc(['this' => $args[0] ?? null, 'unit' => Builder::var($unit), 'zone' => $args[1] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSplitByChar(array $args): Expression
    {
        $sep = $args[0] ?? null;

        if ($sep instanceof Literal && $sep->isString() && strlen($sep->name()) === 1) {
            return $this->buildSplit($args, Split::class);
        }

        return new Anonymous(['this' => 'splitByChar', 'expressions' => $args]);
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $expClass
     */
    protected function buildSplit(array $args, string $expClass): Expression
    {
        return new $expClass(['this' => $args[1] ?? null, 'expression' => $args[0] ?? null, 'limit' => $args[2] ?? null]);
    }

    /**
     * Other dialects can't express arrayMap((x, y) -> x + y, arr1, arr2), which iterates over
     * several arrays at once, so that stays an anonymous function.
     *
     * @param  list<mixed>  $args
     * @param  class-string<Expression>  $expClass
     */
    protected function buildArrayLambdaFunc(array $args, string $expClass, string $name): Expression
    {
        if (count($args) > 2) {
            return new Anonymous(['this' => $name, 'expressions' => $args]);
        }

        return new $expClass(['this' => $args[1] ?? null, 'expression' => $args[0] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     * @param  class-string<Expression>|null  $exprType
     */
    protected function buildRegexpExtract(array $args, ?string $exprType = null): RegexpExtract
    {
        return new RegexpExtract(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'group' => $args[2] ?? null]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildRegexpSubstr(array $args): RegexpExtract
    {
        return $this->buildRegexpExtract($args);
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
    protected function buildSha256(array $args): SHA2
    {
        return new SHA2(['this' => $args[0] ?? null, 'length' => Literal::number(256)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildSha512(array $args): SHA2
    {
        return new SHA2(['this' => $args[0] ?? null, 'length' => Literal::number(512)]);
    }

    /**
     * @param  list<mixed>  $args
     */
    protected function buildTrimright(array $args): Expression
    {
        return $this->buildTrim($args, isLeft: false);
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTION_PARSERS
    // ---------------------------------------------------------------------------------------

    protected function funcArrayjoin(): Expression
    {
        return $this->expression(new Explode(['this' => $this->parseExpression()]));
    }

    protected function funcTuple(): Expression
    {
        return Struct::fromArgList($this->parseFunctionArgs(alias: true));
    }

    protected function funcAnd(): Expression
    {
        return $this->parseConnectorFunction(static fn (array $args): Expression => Builder::and($args));
    }

    protected function funcOr(): Expression
    {
        return $this->parseConnectorFunction(static fn (array $args): Expression => Builder::or($args));
    }

    protected function funcXor(): Expression
    {
        return Builder::xor($this->parseFunctionArgs(alias: false));
    }

    protected function funcView(): Expression
    {
        return $this->expression(new Anonymous(['this' => 'view', 'expressions' => [$this->parseSelect()]]));
    }

    protected function parseGroupConcat(): ?Expression
    {
        $args = $this->parseCsv(fn (): ?Expression => $this->parseLambda());
        $params = $this->parseFuncParams();

        if ($params !== null && $params !== []) {
            // groupConcat(sep [, limit])(expr)
            $separator = $args[0] ?? null;
            $limit = $args[1] ?? null;
            $this_ = $params[0];

            if ($limit !== null) {
                $this_ = new Limit(['this' => $this_, 'expression' => $limit]);
            }

            return $this->expression(new GroupConcat(['this' => $this_, 'separator' => $separator]));
        }

        // groupConcat(expr)
        return $this->expression(new GroupConcat(['this' => $args[0] ?? null]));
    }

    protected function parseQuantile(): Quantile
    {
        $this_ = $this->parseLambda();
        $params = $this->parseFuncParams();

        if ($params !== null && $params !== []) {
            return $this->expression(new Quantile(['this' => $params[0], 'quantile' => $this_]));
        }

        return $this->expression(new Quantile(['this' => $this_, 'quantile' => Literal::number('0.5')]));
    }

    protected function parseColumns(): Expression
    {
        $this_ = $this->expression(new Columns(['this' => $this->parseLambda()]));

        while ($this->next !== 0 && $this->matchTextSeq(')', 'APPLY', '(')) {
            $this->match(TokenType::R_PAREN);
            $this_ = new Apply(['this' => $this_, 'expression' => $this->parseVar(anyToken: true)]);
        }

        return $this_;
    }

    // ---------------------------------------------------------------------------------------
    // PROPERTY / RANGE / COLUMN / MODIFIER / CONSTRAINT / ALTER / PLACEHOLDER / STATEMENT parsers
    // ---------------------------------------------------------------------------------------

    protected function parseEngineProperty(): EngineProperty
    {
        $this->match(TokenType::EQ);

        return $this->expression(new EngineProperty(['this' => $this->parseField(anyToken: true, anonymousFunc: true)]));
    }

    protected function propertyUuid(): Expression
    {
        return $this->expression(new UuidProperty(['this' => $this->parseString()]));
    }

    protected function parseAutoRefreshProperty(): ?AutoRefreshProperty
    {
        $index = $this->index - 1;
        $cadence = $this->matchTexts(['EVERY' => true, 'AFTER' => true]) ? strtoupper($this->prevText()) : null;
        $interval = $cadence !== null ? $this->parseInterval(requireInterval: false, parseFunctionUnit: false) : null;

        if ($cadence !== null && $interval === null) {
            $this->retreat($index);

            return null;
        }

        $offset = null;

        if ($this->matchTextSeq('OFFSET')) {
            $offset = $this->parseInterval(requireInterval: false, parseFunctionUnit: false);

            if ($offset === null) {
                $this->retreat($index);

                return null;
            }
        }

        $randomize = null;

        if ($this->matchTextSeq('RANDOMIZE', 'FOR')) {
            $randomize = $this->parseInterval(requireInterval: false, parseFunctionUnit: false);

            if ($randomize === null) {
                $this->retreat($index);

                return null;
            }
        }

        $dependencies = null;

        if ($this->matchTextSeq('DEPENDS', 'ON')) {
            $dependencies = $this->parseCsv(fn (): ?Expression => $this->parseTableParts(schema: true));

            if ($dependencies === []) {
                $this->retreat($index);

                return null;
            }
        }

        if ($cadence === null && $dependencies === null) {
            $this->retreat($index);

            return null;
        }

        $settings = $this->matchTextSeq('SETTINGS') ? $this->parseSettingsProperty() : null;

        return $this->expression(new AutoRefreshProperty([
            'this' => $interval,
            'cadence' => $cadence,
            'offset' => $offset,
            'randomize' => $randomize,
            'expressions' => $dependencies,
            'settings' => $settings,
            'append' => $this->matchTextSeq('APPEND'),
        ]));
    }

    protected function rangeGlobal(?Expression $this_): ?Expression
    {
        $index = $this->index - 1;
        $isNegated = $this->match(TokenType::NOT);

        if (! $this->match(TokenType::IN)) {
            $this->retreat($index);

            return null;
        }

        $in = $this->parseIn($this_);
        $in->set('is_global', true);

        return $isNegated ? $this->expression(new Not(['this' => $in])) : $in;
    }

    protected function columnOpDotcaret(?Expression $this_, ?Expression $field): Expression
    {
        return $this->expression(new NestedJSONSelect(['this' => $this_, 'expression' => $field]));
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierSettings(): array
    {
        $this->advance();

        return ['settings', $this->parseCsv(fn (): ?Expression => $this->parseAssignment())];
    }

    /** @return array{0: string, 1: mixed} */
    protected function modifierFormat(): array
    {
        $this->advance();

        return ['format', $this->parseIdVar()];
    }

    protected function parseIndexConstraint(?string $kind = null): IndexColumnConstraint
    {
        // INDEX name1 expr TYPE type1(args) GRANULARITY value
        $this_ = $this->parseIdVar();
        $expression = $this->parseAssignment();
        $indexType = $this->matchTextSeq('TYPE') ? ($this->parseFunction() ?? $this->parseVar()) : false;
        $granularity = $this->matchTextSeq('GRANULARITY') ? $this->parseTerm() : false;

        return $this->expression(new IndexColumnConstraint([
            'this' => $this_,
            'expression' => $expression,
            'index_type' => $indexType,
            'granularity' => $granularity,
        ]));
    }

    private function parseWrappedSelectOrAssignment(): ?Expression
    {
        return $this->parseWrapped(fn (): ?Expression => $this->parseSelect() ?? $this->parseAssignment(), optional: true);
    }

    protected function parseCheckConstraint(): ?CheckColumnConstraint
    {
        return $this->expression(new CheckColumnConstraint(['this' => $this->parseWrappedSelectOrAssignment()]));
    }

    protected function parseAssumeConstraint(): ?AssumeColumnConstraint
    {
        return $this->expression(new AssumeColumnConstraint(['this' => $this->parseWrappedSelectOrAssignment()]));
    }

    protected function parseAlterTableReplace(): ?Expression
    {
        $partition = $this->parsePartition();

        if ($partition === null || ! $this->match(TokenType::FROM)) {
            return null;
        }

        return $this->expression(new ReplacePartition(['expression' => $partition, 'source' => $this->parseTableParts()]));
    }

    protected function parseAlterTableAlter(): ?Expression
    {
        // MODIFY forms other than MODIFY COLUMN (SQL SECURITY, ORDER BY, TTL, COMMENT, ...) are
        // parsed as properties, since ALTER can now reach this path too
        if (strtoupper($this->prevText()) === 'MODIFY' && ! $this->match(TokenType::COLUMN, false)) {
            $properties = $this->parseProperties();

            if ($properties !== null) {
                return $this->expression(new AlterModifySqlSecurity(['expressions' => $properties->argList('expressions')]));
            }

            return null;
        }

        // https://clickhouse.com/docs/sql-reference/statements/alter/column#modify-column
        $alter = parent::parseAlterTableAlter();

        return $this->curr !== 0 ? null : $alter;
    }

    protected function parseQueryParameter(): ?Expression
    {
        // SELECT {abc: UInt32} or FROM {table: Identifier}
        // https://clickhouse.com/docs/en/sql-reference/syntax#defining-and-using-query-parameters
        $index = $this->index;
        $this_ = $this->parseIdVar();
        $this->match(TokenType::COLON);
        $kind = $this->parseTypes(checkFunc: false, allowIdentifiers: false) ?? ($this->matchTextSeq('IDENTIFIER') ? 'Identifier' : null);

        if ($kind === null) {
            $this->retreat($index);

            return null;
        }

        if (! $this->match(TokenType::R_BRACE)) {
            $this->raiseError('Expecting }');
        }

        if ($this_ instanceof Identifier && ! $this_->quoted()) {
            $this_ = Builder::var($this_->name());
        }

        return $this->expression(new Placeholder(['this' => $this_, 'kind' => $kind]));
    }

    protected function parseDetach(): Detach
    {
        $kind = $this->matchSet($this->t['DB_CREATABLES']) ? strtoupper($this->prevText()) : false;
        $exists = $this->parseExists();
        $this_ = $this->parseTableParts();

        return $this->expression(new Detach([
            'this' => $this_,
            'kind' => $kind,
            'exists' => $exists,
            'cluster' => $this->match(TokenType::ON) ? $this->parseOnProperty() : null,
            'permanent' => $this->matchTextSeq('PERMANENTLY'),
            'sync' => $this->matchTextSeq('SYNC'),
        ]));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseUserDefinedFunctionExpression(): ?Expression
    {
        // https://clickhouse.com/docs/en/sql-reference/statements/create/function
        return $this->parseLambda();
    }

    protected function parseTypes(bool $checkFunc = false, bool $schema = false, bool $allowIdentifiers = true, bool $withCollation = false): ?Expression
    {
        $dtype = parent::parseTypes(checkFunc: $checkFunc, schema: $schema, allowIdentifiers: $allowIdentifiers, withCollation: $withCollation);

        if ($dtype instanceof DataType && ($dtype->args['nullable'] ?? null) !== true) {
            // Every type is non-nullable by default in ClickHouse, unless marked as nullable. The
            // marker helps transpile types from other dialects, e.g. CAST(x AS TEXT) becomes
            // CAST(x AS Nullable(String)), which doesn't fail on NULLs.
            $dtype->set('nullable', false);
        }

        return $dtype;
    }

    protected function parseExtract(): Expression
    {
        $index = $this->index;
        $this_ = $this->parseBitwise();

        if ($this->match(TokenType::FROM)) {
            $this->retreat($index);

            return parent::parseExtract();
        }

        // extract and regexpExtract have different semantics, so extract(foo, bar) stays anonymous
        $this->match(TokenType::COMMA);

        return $this->expression(new Anonymous(['this' => 'extract', 'expressions' => [$this_, $this->parseBitwise()]]));
    }

    protected function parseAssignment(): ?Expression
    {
        $this_ = parent::parseAssignment();

        if ($this->match(TokenType::PLACEHOLDER)) {
            return $this->expression(new IfExpr([
                'this' => $this_,
                'true' => $this->parseAssignment(),
                'false' => $this->match(TokenType::COLON) ? $this->parseAssignment() : false,
            ]));
        }

        return $this_;
    }

    protected function parseBracket(?Expression $this_ = null): ?Expression
    {
        if ($this_ !== null) {
            $bracketJsonType = null;

            while ($this->matchPair(TokenType::L_BRACKET, TokenType::R_BRACKET)) {
                $bracketJsonType = new DataType([
                    'this' => DType::ARRAY,
                    'expressions' => [$bracketJsonType ?? new DataType(['this' => DType::JSON, 'nullable' => false])],
                    'nested' => true,
                ]);
            }

            if ($bracketJsonType !== null) {
                return $this->expression(new JSONCast(['this' => $this_, 'to' => $bracketJsonType]));
            }
        }

        $lBrace = $this->match(TokenType::L_BRACE, false);
        $bracket = parent::parseBracket($this_);

        if ($lBrace && $bracket instanceof Struct) {
            $keys = new ArrayExpr(['expressions' => []]);
            $values = new ArrayExpr(['expressions' => []]);
            $varmap = new VarMap(['keys' => $keys, 'values' => $values]);

            foreach ($bracket->argList('expressions') as $expression) {
                if (! $expression instanceof PropertyEQ) {
                    break;
                }

                $keys->append('expressions', Literal::string($expression->name()));
                $values->append('expressions', $expression->args['expression'] ?? null);
            }

            return $varmap;
        }

        return $bracket;
    }

    protected function parseTable(
        bool $schema = false,
        bool $joins = false,
        ?array $aliasTokens = null,
        bool $parseBracket = false,
        bool $isDbReference = false,
        bool $parsePartition = false,
        bool $consumePipe = false,
    ): ?Expression {
        $this_ = parent::parseTable(schema: $schema, joins: $joins, aliasTokens: $aliasTokens, parseBracket: $parseBracket, isDbReference: $isDbReference);

        if ($this_ instanceof Table) {
            $inner = $this_->args['this'] ?? null;
            $alias = $this_->args['alias'] ?? null;

            if ($inner instanceof GenerateSeries && $alias instanceof Expression && $alias->argList('columns') === []) {
                $alias->set('columns', [Builder::toIdentifier('generate_series')]);
            }
        }

        if ($this->match(TokenType::FINAL)) {
            $this_ = $this->expression(new FinalExpr(['this' => $this_]));
        }

        return $this_;
    }

    protected function parsePosition(bool $haystackFirst = false): StrPosition
    {
        return parent::parsePosition(haystackFirst: true);
    }

    protected function parseCte(): ?Expression
    {
        // WITH <identifier> AS <subquery expression>
        $cte = $this->tryParse(fn (): ?Expression => parent::parseCte());

        if ($cte === null) {
            // WITH <expression> AS <identifier>
            $cte = $this->expression(new CTE(['this' => $this->parseAssignment(), 'alias' => $this->parseTableAlias(), 'scalar' => true]));
        }

        return $cte;
    }

    protected function parseJoinParts(): array
    {
        $isGlobal = $this->match(TokenType::GLOBAL) ? $this->index - 1 : null;
        $kindPre = $this->matchSet($this->t['JOIN_KINDS']) ? $this->index - 1 : null;
        $side = $this->matchSet($this->t['JOIN_SIDES']) ? $this->index - 1 : null;
        $kind = $this->matchSet($this->t['JOIN_KINDS']) ? $this->index - 1 : null;

        return [$isGlobal, $side ?? $kind, $kindPre ?? $kind];
    }

    protected function parseJoin(bool $skipJoinToken = false, bool $parseBracket = false, ?array $aliasTokens = null): ?Join
    {
        $join = parent::parseJoin(skipJoinToken: $skipJoinToken, parseBracket: true, aliasTokens: $aliasTokens);

        if ($join !== null) {
            $method = $join->args['method'] ?? null;
            $join->set('method', null);
            $join->set('global_', $method);

            // tbl ARRAY JOIN arr <-- arr is a column reference, not a table
            // https://clickhouse.com/docs/en/sql-reference/statements/select/array-join
            if ($join->text('kind') === 'ARRAY') {
                foreach ($join->findAll(Table::class) as $table) {
                    $table->replace($table->toColumn());
                }
            }
        }

        return $join;
    }

    protected function parseFunction(?array $functions = null, bool $anonymous = false, bool $optionalParens = true, bool $anyToken = false): ?Expression
    {
        $expression = parent::parseFunction(functions: $functions, anonymous: $anonymous, optionalParens: $optionalParens, anyToken: $anyToken);
        $func = $expression instanceof Window ? ($expression->args['this'] ?? null) : $expression;

        // Aggregate functions can be split in 2 parts: <func_name><suffix[es]>
        $parts = $func instanceof Anonymous ? self::resolveAgg($func->name()) : null;

        if ($parts === null) {
            return $expression;
        }

        $params = $this->parseFuncParams($func);

        if ($parts[1] !== []) {
            $class = $params !== null && $params !== [] ? CombinedParameterizedAgg::class : CombinedAggFunc::class;
        } else {
            $class = $params !== null && $params !== [] ? ParameterizedAgg::class : AnonymousAggFunc::class;
        }

        $instance = new $class(['this' => $func->args['this'] ?? null, 'expressions' => $func->argList('expressions')]);

        if ($params !== null && $params !== []) {
            $instance->set('params', $params);
        }

        $func = $this->expression($instance);

        if ($expression instanceof Window) {
            // The window's function was parsed as Anonymous; fix its type
            $expression->set('this', $func);

            return $expression;
        }

        if ($params !== null && $params !== []) {
            // The params stood between the call and a following window spec
            return $this->parseWindow($func);
        }

        return $func;
    }

    /**
     * ClickHouse allows chaining several combinators on an aggregate function, e.g. sumIfMerge:
     * the function name and its suffixes, innermost first, or null for any other function.
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function resolveAgg(string $name): ?array
    {
        $mapping = self::$aggFuncMapping ??= self::buildAggFuncMapping();
        $suffixes = [];

        while (! isset($mapping[$name])) {
            $stripped = false;

            foreach (self::AGG_FUNCTIONS_SUFFIXES as $suffix) {
                if (str_ends_with($name, $suffix) && strlen($name) !== strlen($suffix)) {
                    array_unshift($suffixes, $suffix);
                    $name = substr($name, 0, -strlen($suffix));
                    $stripped = true;

                    break;
                }
            }

            if (! $stripped) {
                return null;
            }
        }

        [$aggFuncName, $innerSuffix] = $mapping[$name];

        if ($innerSuffix !== null) {
            array_unshift($suffixes, $innerSuffix);
        }

        return [$aggFuncName, $suffixes];
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    private static function buildAggFuncMapping(): array
    {
        $mapping = [];

        foreach (self::AGG_FUNCTIONS_SUFFIXES as $suffix) {
            foreach (self::AGG_FUNCTIONS as $function) {
                $mapping[$function.$suffix] = [$function, $suffix];
            }
        }

        foreach (self::AGG_FUNCTIONS as $function) {
            $mapping[$function] = [$function, null];
        }

        return $mapping;
    }

    /**
     * @return list<Expression>|null
     */
    protected function parseFuncParams(?Expression $this_ = null): ?array
    {
        if ($this->matchPair(TokenType::R_PAREN, TokenType::L_PAREN)) {
            return $this->parseCsv(fn (): ?Expression => $this->parseLambda());
        }

        if ($this->match(TokenType::L_PAREN)) {
            $params = $this->parseCsv(fn (): ?Expression => $this->parseLambda());
            $this->matchRParen($this_);

            return $params;
        }

        return null;
    }

    protected function parseWrappedIdVars(bool $optional = false): array
    {
        return parent::parseWrappedIdVars(optional: true);
    }

    protected function parseColumnDef(?Expression $this_, bool $computedColumn = true): ?Expression
    {
        if ($this->match(TokenType::DOT)) {
            return new Dot(['this' => $this_, 'expression' => $this->parseIdVar()]);
        }

        return parent::parseColumnDef($this_, computedColumn: $computedColumn);
    }

    protected function parsePrimaryKey(bool $wrappedOptional = false, bool $inProps = false, bool $namedPrimaryKey = false): Expression
    {
        return parent::parsePrimaryKey(wrappedOptional: $wrappedOptional || $inProps, inProps: $inProps, namedPrimaryKey: $namedPrimaryKey);
    }

    protected function parseOnProperty(): ?Expression
    {
        $index = $this->index;

        if ($this->matchTextSeq('CLUSTER')) {
            $this_ = $this->parseString() ?? $this->parseIdVar();

            if ($this_ !== null) {
                return $this->expression(new OnCluster(['this' => $this_]));
            }

            $this->retreat($index);
        }

        return null;
    }

    protected function parsePartition(): ?Partition
    {
        // https://clickhouse.com/docs/en/sql-reference/statements/alter/partition#how-to-set-partition-expression
        if (! $this->match(TokenType::PARTITION)) {
            return null;
        }

        if ($this->matchTextSeq('ID')) {
            // PARTITION ID <string_value>
            $expressions = [$this->expression(new PartitionId(['this' => $this->parseString()]))];
        } else {
            $expressions = $this->parseExpressions();
        }

        return $this->expression(new Partition(['expressions' => $expressions]));
    }

    protected function parseDefiner(): ?DefinerProperty
    {
        $this->match(TokenType::EQ);

        if ($this->match(TokenType::CURRENT_USER)) {
            return new DefinerProperty(['this' => new VarExpr(['this' => strtoupper($this->prevText())])]);
        }

        return new DefinerProperty(['this' => $this->parseString()]);
    }

    private function parseProjectionDef(): ?ProjectionDef
    {
        if (! $this->match(TokenType::PROJECTION)) {
            return null;
        }

        return $this->expression(new ProjectionDef([
            'this' => $this->parseIdVar(),
            'expression' => $this->parseWrapped(fn (): ?Expression => $this->parseStatement()),
        ]));
    }

    protected function parseConstraint(): ?Expression
    {
        return parent::parseConstraint() ?? $this->parseProjectionDef();
    }

    protected function parseAlias(?Expression $this_, bool $explicit = false): ?Expression
    {
        // "SELECT <expr> APPLY(...)" is a query modifier, so APPLY isn't <expr>'s alias here;
        // "SELECT <expr> apply" still is
        if ($this->matchPair(TokenType::APPLY, TokenType::L_PAREN, false)) {
            return $this_;
        }

        return parent::parseAlias($this_, explicit: $explicit);
    }

    protected function parseExpression(): ?Expression
    {
        $this_ = parent::parseExpression();

        // ClickHouse allows a "SELECT <expr> [APPLY(func)] [...]]" modifier
        while ($this->matchPair(TokenType::APPLY, TokenType::L_PAREN)) {
            $this_ = new Apply(['this' => $this_, 'expression' => $this->parseVar(anyToken: true)]);
            $this->match(TokenType::R_PAREN);
        }

        return $this_;
    }

    protected function parseValue(bool $values = true): ?Tuple
    {
        $value = parent::parseValue(values: $values);

        if ($value === null) {
            return null;
        }

        // SELECT * FROM VALUES (1, 2, 3) is a table with a single column in ClickHouse, so the
        // values are canonicalized into a tuple of tuples. A single-value tuple is generated as
        // (x), which parses back into a Paren, so it's unwrapped here to keep this idempotent.
        $expressions = $value->argList('expressions');
        $last = $expressions === [] ? null : $expressions[count($expressions) - 1];

        if ($values && $last !== null && ! $last instanceof Tuple) {
            $value->set('expressions', array_map(
                fn (Expression $e): Expression => $this->expression(new Tuple(['expressions' => [$e->unnest()]])),
                $expressions,
            ));
        }

        return $value;
    }

    protected function parsePartitionedBy(): PartitionedByProperty
    {
        // ClickHouse allows custom expressions as the partition key
        // https://clickhouse.com/docs/engines/table-engines/mergetree-family/custom-partitioning-key
        return $this->expression(new PartitionedByProperty(['this' => $this->parseAssignment()]));
    }
}
