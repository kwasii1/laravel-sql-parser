<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\DuckDB;

use Kwasii\LaravelSqlParser\Expressions\Abs;
use Kwasii\LaravelSqlParser\Expressions\Alias;
use Kwasii\LaravelSqlParser\Expressions\AnyValue;
use Kwasii\LaravelSqlParser\Expressions\ApproxQuantile;
use Kwasii\LaravelSqlParser\Expressions\ArrayExpr;
use Kwasii\LaravelSqlParser\Expressions\ArrayIntersect;
use Kwasii\LaravelSqlParser\Expressions\ArrayPrepend;
use Kwasii\LaravelSqlParser\Expressions\Attach;
use Kwasii\LaravelSqlParser\Expressions\AttachOption;
use Kwasii\LaravelSqlParser\Expressions\Bracket;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DateDiff;
use Kwasii\LaravelSqlParser\Expressions\Decode;
use Kwasii\LaravelSqlParser\Expressions\Detach;
use Kwasii\LaravelSqlParser\Expressions\Encode;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\GenerateSeries;
use Kwasii\LaravelSqlParser\Expressions\Getbit;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\IgnoreNulls;
use Kwasii\LaravelSqlParser\Expressions\Install;
use Kwasii\LaravelSqlParser\Expressions\JSONArray;
use Kwasii\LaravelSqlParser\Expressions\Lambda;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Map;
use Kwasii\LaravelSqlParser\Expressions\PercentileCont;
use Kwasii\LaravelSqlParser\Expressions\PercentileDisc;
use Kwasii\LaravelSqlParser\Expressions\Placeholder;
use Kwasii\LaravelSqlParser\Expressions\PositionalColumn;
use Kwasii\LaravelSqlParser\Expressions\Properties;
use Kwasii\LaravelSqlParser\Expressions\Quantile;
use Kwasii\LaravelSqlParser\Expressions\RegexpReplace;
use Kwasii\LaravelSqlParser\Expressions\ReturnsProperty;
use Kwasii\LaravelSqlParser\Expressions\Schema;
use Kwasii\LaravelSqlParser\Expressions\SHA2;
use Kwasii\LaravelSqlParser\Expressions\Show;
use Kwasii\LaravelSqlParser\Expressions\SortArray;
use Kwasii\LaravelSqlParser\Expressions\TableAlias;
use Kwasii\LaravelSqlParser\Expressions\TableSample;
use Kwasii\LaravelSqlParser\Expressions\TimestampFromParts;
use Kwasii\LaravelSqlParser\Expressions\ToMap;
use Kwasii\LaravelSqlParser\Expressions\UnixToTime;
use Kwasii\LaravelSqlParser\Parser\Parser;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * DuckDB parser (sqlglot parsers/duckdb.py).
 */
class DuckDBParser extends Parser
{
    public const string TABLES = DuckDBParserTables::class;

    // ---------------------------------------------------------------------------------------
    // FUNCTIONS
    // ---------------------------------------------------------------------------------------

    /** @param  list<mixed>  $args */
    protected function buildSortArrayDesc(array $args): SortArray
    {
        return new SortArray(['this' => $args[0] ?? null, 'asc' => Builder::false()]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArrayPrependDuckdb(array $args): ArrayPrepend
    {
        return new ArrayPrepend(['this' => $args[1] ?? null, 'expression' => $args[0] ?? null]);
    }

    /** @param  list<mixed>  $args */
    protected function buildDateDiff(array $args): DateDiff
    {
        return new DateDiff(['this' => $args[2] ?? null, 'expression' => $args[1] ?? null, 'unit' => $args[0] ?? null]);
    }

    /**
     * GENERATE_SERIES / RANGE; the start defaults to 0.
     * https://duckdb.org/docs/sql/functions/nested.html#range-functions
     *
     * @param  list<mixed>  $args
     */
    protected function buildGenerateSeries(array $args, bool $endExclusive = false): GenerateSeries
    {
        if (count($args) === 1) {
            array_unshift($args, Literal::number('0'));
        }

        $series = GenerateSeries::fromArgList($args);
        $series->set('is_end_exclusive', $endExclusive);

        return $series;
    }

    /** @param  list<mixed>  $args */
    protected function buildMakeTimestamp(array $args): Expression
    {
        if (count($args) === 1) {
            return new UnixToTime(['this' => $args[0], 'scale' => Literal::number(6)]);
        }

        return new TimestampFromParts([
            'year' => $args[0] ?? null, 'month' => $args[1] ?? null, 'day' => $args[2] ?? null,
            'hour' => $args[3] ?? null, 'min' => $args[4] ?? null, 'sec' => $args[5] ?? null,
        ]);
    }

    /** @param  list<mixed>  $args */
    protected function buildAnyValue(array $args): IgnoreNulls
    {
        return new IgnoreNulls(['this' => AnyValue::fromArgList($args)]);
    }

    /** @param  list<mixed>  $args */
    protected function buildArrayIntersect(array $args): Expression
    {
        return new ArrayIntersect(['expressions' => $args]);
    }

    /** @param  list<mixed>  $args */
    protected function buildDecode(array $args): Decode
    {
        return new Decode(['this' => $args[0] ?? null, 'charset' => Literal::string('utf-8')]);
    }

    /** @param  list<mixed>  $args */
    protected function buildEncode(array $args): Encode
    {
        return new Encode(['this' => $args[0] ?? null, 'charset' => Literal::string('utf-8')]);
    }

    /** @param  list<mixed>  $args */
    protected function buildEpochMs(array $args): UnixToTime
    {
        return new UnixToTime(['this' => $args[0] ?? null, 'scale' => Literal::number(3)]);
    }

    /** @param  list<mixed>  $args */
    protected function buildGetBit(array $args): Getbit
    {
        return new Getbit(['this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'zero_is_msb' => true]);
    }

    /** @param  list<mixed>  $args */
    protected function buildJsonArray(array $args): JSONArray
    {
        return new JSONArray(['expressions' => $args]);
    }

    /** @param  list<mixed>  $args */
    protected function buildListValue(array $args): ArrayExpr
    {
        return new ArrayExpr(['expressions' => $args]);
    }

    /** @param  list<mixed>  $args */
    protected function buildRegexpReplace(array $args): RegexpReplace
    {
        return new RegexpReplace([
            'this' => $args[0] ?? null, 'expression' => $args[1] ?? null, 'replacement' => $args[2] ?? null,
            'modifiers' => $args[3] ?? null, 'single_replace' => true,
        ]);
    }

    /** @param  list<mixed>  $args */
    protected function buildSha256(array $args): SHA2
    {
        return new SHA2(['this' => $args[0] ?? null, 'length' => Literal::number(256)]);
    }

    /**
     * TYPE_CONVERTERS: TEXT takes no length.
     */
    protected function convertTextType(DataType $dtype): DataType
    {
        $dtype->set('expressions', null);

        return $dtype;
    }

    // ---------------------------------------------------------------------------------------
    // FUNCTION / NO_PAREN / PLACEHOLDER / STATEMENT / SET / SHOW parsers
    // ---------------------------------------------------------------------------------------

    protected function funcApproxQuantile(): Expression
    {
        return $this->parseDistinctArgFunction(ApproxQuantile::class);
    }

    protected function funcQuantile(): Expression
    {
        return $this->parseDistinctArgFunction(Quantile::class);
    }

    protected function funcQuantileCont(): Expression
    {
        return $this->parseDistinctArgFunction(PercentileCont::class);
    }

    protected function funcQuantileDisc(): Expression
    {
        return $this->parseDistinctArgFunction(PercentileDisc::class);
    }

    /**
     * @x is ABS(x).
     */
    protected function noParenFunction(): Expression
    {
        return new Abs(['this' => $this->parseBitwise()]);
    }

    protected function placeholderParameter(): ?Expression
    {
        if ($this->match(TokenType::NUMBER) || $this->matchSet($this->t['ID_VAR_TOKENS'])) {
            return $this->expression(new Placeholder(['this' => $this->prevText()]));
        }

        return null;
    }

    protected function statementDetach(): Expression
    {
        return $this->parseAttachDetach(isAttach: false);
    }

    protected function setVariable(): ?Expression
    {
        return $this->parseSetItemAssignment('VARIABLE');
    }

    /**
     * @param  list<string>  $args
     * @param  array<string, mixed>  $kwargs
     */
    protected function showParser(array $args, array $kwargs = []): Show
    {
        $from = $this->match(TokenType::FROM) ? $this->parseTable(schema: true) : null;

        return $this->expression(new Show(['this' => $args[0], 'from_' => $from]));
    }

    protected function parseMap(): Expression
    {
        if ($this->match(TokenType::L_BRACE, false)) {
            return $this->expression(new ToMap(['this' => $this->parseBracket()]));
        }

        $args = $this->parseWrappedCsv(fn (): ?Expression => $this->parseAssignment());

        return $this->expression(new Map(['keys' => $args[0] ?? null, 'values' => $args[1] ?? null]));
    }

    protected function parseAttachDetach(bool $isAttach = true): Expression
    {
        $this->match(TokenType::DATABASE);
        $exists = $this->parseExists(not: $isAttach);
        $this_ = $this->parseAlias($this->parsePrimaryOrVar(), explicit: true);
        $expressions = $this->match(TokenType::L_PAREN, false)
            ? $this->parseWrappedCsv(fn (): Expression => $this->expression(new AttachOption([
                'this' => $this->parseVar(anyToken: true),
                'expression' => $this->parseField(anyToken: true),
            ])))
            : null;

        return $isAttach
            ? $this->expression(new Attach(['this' => $this_, 'exists' => $exists, 'expressions' => $expressions]))
            : $this->expression(new Detach(['this' => $this_, 'exists' => $exists]));
    }

    /**
     * FORCE is only followed by INSTALL or CHECKPOINT; CHECKPOINT falls back to a command.
     */
    protected function parseForce(): Expression
    {
        if (! $this->match(TokenType::INSTALL)) {
            return $this->parseAsCommand($this->index - 1);
        }

        return $this->parseInstall(force: true);
    }

    protected function parseInstall(bool $force = false): Expression
    {
        return $this->expression(new Install([
            'this' => $this->parseIdVar(),
            'from_' => $this->match(TokenType::FROM) ? $this->parseVarOrString() : null,
            'force' => $force,
        ]));
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function parseFunctionProperties(): ?Properties
    {
        if ($this->match(TokenType::TABLE)) {
            return new Properties(['expressions' => [new ReturnsProperty(['this' => new Schema(['this' => Builder::var('TABLE')]), 'is_table' => true])]]);
        }

        return parent::parseFunctionProperties();
    }

    protected function parseLambda(bool $alias = false): ?Expression
    {
        $index = $this->index;

        if (! $this->matchTextSeq('LAMBDA')) {
            return parent::parseLambda(alias: $alias);
        }

        $expressions = $this->parseCsv(fn (): ?Expression => $this->parseLambdaArg());

        if (! $this->match(TokenType::COLON)) {
            $this->retreat($index);

            return null;
        }

        $this_ = $this->replaceLambda($this->parseAssignment(), $expressions);

        return $this->expression(new Lambda(['this' => $this_, 'expressions' => $expressions, 'colon' => true]));
    }

    /**
     * DuckDB supports prefix aliases, e.g. foo: 1.
     */
    protected function parseExpression(): ?Expression
    {
        if ($this->next === TokenType::COLON) {
            $alias = $this->parseIdVar(tokens: $this->t['ALIAS_TOKENS']);
            $this->match(TokenType::COLON);
            $comments = $this->prevComments;
            $this_ = $this->parseAssignment();

            // The comment in `alias: expr /* comment */` moves next to the alias
            if ($this_ instanceof Expression) {
                array_push($comments, ...$this_->popComments());
            }

            return $this->expression(new Alias(['this' => $this_, 'alias' => $alias]), null, $comments);
        }

        return parent::parseExpression();
    }

    /**
     * DuckDB supports prefix aliases for tables too, e.g. FROM foo: bar.
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
        $alias = null;
        $comments = [];

        if ($this->next === TokenType::COLON) {
            $alias = $this->parseTableAlias(aliasTokens: $aliasTokens ?? $this->t['TABLE_ALIAS_TOKENS']);
            $this->match(TokenType::COLON);
            $comments = $this->prevComments;
        }

        $table = parent::parseTable(
            schema: $schema,
            joins: $joins,
            aliasTokens: $aliasTokens,
            parseBracket: $parseBracket,
            isDbReference: $isDbReference,
            parsePartition: $parsePartition,
        );

        if ($table instanceof Expression && $alias instanceof TableAlias) {
            // The comment in `alias: table /* comment */` moves next to the alias
            array_push($comments, ...$table->popComments());
            $alias->comments = [...$alias->popComments(), ...$comments];
            $table->set('alias', $alias);
        }

        return $table;
    }

    /**
     * https://duckdb.org/docs/sql/samples.html
     */
    protected function parseTableSample(bool $asModifier = false): ?TableSample
    {
        $sample = parent::parseTableSample(asModifier: $asModifier);

        if ($sample !== null && ! $sample->flag('method')) {
            $sample->set('method', Builder::var($sample->flag('size') ? 'RESERVOIR' : 'SYSTEM'));
        }

        return $sample;
    }

    protected function parseBracket(?Expression $this_ = null): ?Expression
    {
        $bracket = parent::parseBracket($this_);

        // https://duckdb.org/2025/02/05/announcing-duckdb-120.html#breaking-changes
        if ($this->dialect->version < [1, 2, 0] && $bracket instanceof Bracket) {
            $bracket->set('returns_list_for_maps', true);
        }

        return $bracket;
    }

    protected function parseStructTypes(bool $typeRequired = false): ?Expression
    {
        return $this->parseFieldDef();
    }

    /**
     * @param  list<Expression>  $aggregations
     * @return list<string>
     */
    protected function pivotColumnNames(array $aggregations): array
    {
        if (count($aggregations) === 1) {
            return parent::pivotColumnNames($aggregations);
        }

        // Unaliased aggregations become suffixes (col_avg(foo)); their identifiers are unquoted,
        // since the pivot parser quotes the whole name again
        $names = [];

        foreach ($aggregations as $aggregation) {
            if ($aggregation instanceof Alias) {
                $names[] = $aggregation->alias();

                continue;
            }

            $unquoted = $aggregation->transform(static fn (Expression $node): Expression => $node instanceof Identifier ? new Identifier(['this' => $node->name(), 'quoted' => false]) : $node);
            $names[] = $unquoted?->sql('duckdb', ['normalize_functions' => 'lower']) ?? '';
        }

        return $names;
    }

    protected function parsePrimary(): ?Expression
    {
        if ($this->matchPair(TokenType::HASH, TokenType::NUMBER)) {
            return new PositionalColumn(['this' => Literal::number($this->prevText())]);
        }

        return parent::parsePrimary();
    }
}
