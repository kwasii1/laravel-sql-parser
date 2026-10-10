<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Exasol;

use Kwasii\LaravelSqlParser\Errors\UnsupportedError;
use Kwasii\LaravelSqlParser\Expressions\AggFunc;
use Kwasii\LaravelSqlParser\Expressions\Alias;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Collate;
use Kwasii\LaravelSqlParser\Expressions\Column;
use Kwasii\LaravelSqlParser\Expressions\Concat;
use Kwasii\LaravelSqlParser\Expressions\CurrentSchema;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DateSub;
use Kwasii\LaravelSqlParser\Expressions\Distinct;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Neg;
use Kwasii\LaravelSqlParser\Expressions\NullExpr;
use Kwasii\LaravelSqlParser\Expressions\Paren;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\Star;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\WeekStart;
use Kwasii\LaravelSqlParser\Expressions\Where;
use Kwasii\LaravelSqlParser\Generator\Generator;
use Kwasii\LaravelSqlParser\Support\Scope;

/**
 * Exasol generator (sqlglot generators/exasol.py).
 */
class ExasolGenerator extends Generator
{
    public const string TABLES = ExasolGeneratorTables::class;

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS lambdas
    // ---------------------------------------------------------------------------------------

    protected function transformCurrentSchema(Expression $e): string
    {
        return 'CURRENT_SCHEMA';
    }

    protected function transformCurrentUser(Expression $e): string
    {
        return 'CURRENT_USER';
    }

    protected function transformDayOfWeek(Expression $e): string
    {
        return 'CAST(TO_CHAR('.$this->sql($e, 'this').", 'D') AS INTEGER)";
    }

    protected function transformGroupConcat(Expression $e): string
    {
        return $this->groupconcatSqlHandler($e, onOverflow: true);
    }

    protected function transformUnixToTime(Expression $e): string
    {
        return $this->func('FROM_POSIX_TIME', $e->args['this'] ?? null);
    }

    protected function transformRank(Expression $e): string
    {
        return 'RANK()';
    }

    protected function transformDenseRank(Expression $e): string
    {
        return 'DENSE_RANK()';
    }

    protected function transformTimeToStr(Expression $e): string
    {
        return $this->func('TO_CHAR', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformToChar(Expression $e): string
    {
        return $this->func('TO_CHAR', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformTsOrDsToDate(Expression $e): string
    {
        return $this->func('TO_DATE', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformStrToTime(Expression $e): string
    {
        return $this->func('TO_DATE', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformAtTimeZone(Expression $e): string
    {
        return $this->func('CONVERT_TZ', $e->args['this'] ?? null, "'UTC'", $e->args['zone'] ?? null);
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'INSTR', supportsPosition: true, supportsOccurrence: true);
    }

    protected function transformCommentColumnConstraint(Expression $e): string
    {
        return 'COMMENT IS '.$this->sql($e, 'this');
    }

    protected function transformQuarter(Expression $e): string
    {
        return 'CEIL(MONTH(TO_DATE('.$this->sql($e, 'this').'))/3)';
    }

    // ---------------------------------------------------------------------------------------
    // Module helpers
    // ---------------------------------------------------------------------------------------

    /** sqlglot _sha2_sql. */
    protected function sha2SqlHandler(Expression $expression): string
    {
        $funcName = $expression->text('length') === '256' ? 'HASH_SHA256' : 'HASH_SHA512';

        return $this->func($funcName, $expression->args['this'] ?? null);
    }

    /** sqlglot _date_diff_sql. */
    protected function dateDiffSqlHandler(Expression $expression): string
    {
        $unit = strtoupper($expression->text('unit'));
        $unit = $unit !== '' ? $unit : 'DAY';

        if (! isset(ExasolParser::DATE_UNITS[$unit])) {
            $this->unsupported("'{$unit}' is not supported in Exasol.");

            return $this->functionFallbackSql($expression);
        }

        return $this->func("{$unit}S_BETWEEN", $expression->args['this'] ?? null, $expression->args['expression'] ?? null);
    }

    /** sqlglot _add_date_sql. */
    protected function addDateSql(Expression $expression): string
    {
        $offset = $expression->args['expression'] ?? null;
        $interval = $offset instanceof Interval ? $offset : null;

        $unit = strtoupper($interval !== null ? $interval->text('unit') : $expression->text('unit'));
        $unit = $unit !== '' ? $unit : 'DAY';

        if (! isset(ExasolParser::DATE_UNITS[$unit])) {
            $this->unsupported("'{$unit}' is not supported in Exasol.");

            return $this->functionFallbackSql($expression);
        }

        $offsetExpr = $interval !== null ? ($interval->args['this'] ?? null) : $offset;

        if ($expression instanceof DateSub) {
            $offsetExpr = new Neg(['this' => $offsetExpr]);
        }

        return $this->func("ADD_{$unit}S", $expression->args['this'] ?? null, $offsetExpr);
    }

    /** sqlglot _trunc_sql. */
    protected function truncSqlExasol(string $kind, Expression $expression): string
    {
        $unitExpr = $expression->args['unit'] ?? null;
        $unit = $unitExpr instanceof WeekStart ? $this->weekstartName($unitExpr) : $expression->text('unit');
        $this_ = $expression->args['this'] ?? null;
        $node = $this_ instanceof Cast ? ($this_->args['this'] ?? null) : $this_;
        $exprSql = $this->sql($node instanceof Expression ? $node : null);

        if ($node instanceof Literal && $node->isString()) {
            $exprSql = $kind === 'TIMESTAMP'
                ? "{$kind} '".str_replace('T', ' ', $node->name())."'"
                : "DATE '".$node->name()."'";
        }

        return "DATE_TRUNC('{$unit}', {$exprSql})";
    }

    /** sqlglot _date_trunc_sql. */
    protected function dateTruncSqlHandler(Expression $expression): string
    {
        return $this->truncSqlExasol('DATE', $expression);
    }

    /** sqlglot _timestamp_trunc_sql. */
    protected function timestampTruncSqlExasol(Expression $expression): string
    {
        return $this->truncSqlExasol('TIMESTAMP', $expression);
    }

    /** sqlglot is_case_insensitive. */
    protected static function isCaseInsensitive(mixed $node): bool
    {
        return $node instanceof Collate && strtoupper($node->text('expression')) === 'UTF8_LCASE';
    }

    /** sqlglot _substring_index_sql. */
    protected function substringIndexSqlHandler(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;
        $delimiter = $expression->args['delimiter'] ?? null;
        $countNode = $expression->args['count'] ?? null;
        $countSql = $this->sql($expression, 'count');
        $num = $countNode instanceof Expression && $countNode->isNumber() ? (float) $countNode->toPhp() : 0.0;

        $haystackSql = $this->sql($this_ instanceof Expression ? $this_ : null);

        if ($num == 0) {
            return $this->func('SUBSTR', $haystackSql, '1', '0');
        }

        $fromRight = $num < 0;
        $direction = $fromRight ? '-1' : '1';
        $occur = $fromRight ? $this->func('ABS', $countSql) : $countSql;

        $delimiterSql = $this->sql($delimiter instanceof Expression ? $delimiter : null);

        $position = $this->func(
            'INSTR',
            self::isCaseInsensitive($this_) ? $this->func('LOWER', $haystackSql) : $haystackSql,
            self::isCaseInsensitive($delimiter) ? $this->func('LOWER', $delimiterSql) : $delimiterSql,
            $direction,
            $occur,
        );
        $nullablePos = $this->func('NULLIF', $position, '0');

        if ($fromRight) {
            $start = $this->func('NVL', "{$nullablePos} + ".$this->func('LENGTH', $delimiterSql), $direction);

            return $this->func('SUBSTR', $haystackSql, $start);
        }

        $length = $this->func('NVL', "{$nullablePos} - 1", $this->func('LENGTH', $haystackSql));

        return $this->func('SUBSTR', $haystackSql, $direction, $length);
    }

    /** The normalized name of an identifier (sqlglot _add_local_prefix_for_aliases._key). */
    protected function aliasKey(Expression $ident): string
    {
        $value = $this->dialect->normalizeIdentifier($ident->copy())->args['this'] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Exasol needs alias references in the same SELECT to be prefixed with LOCAL
     * (sqlglot _add_local_prefix_for_aliases).
     */
    protected function addLocalPrefixForAliases(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        /** @var array<string, Expression> $aliases */
        $aliases = [];

        foreach ($expression->selects() as $sel) {
            $alias = $sel instanceof Alias ? $sel->argExpr('alias') : null;

            if ($alias !== null) {
                $aliases[$this->aliasKey($alias)] = $alias;
            }
        }

        $table = $expression->find(Table::class);
        $tableIdent = $table?->argExpr('this');

        if ($tableIdent !== null && strtoupper($tableIdent->name()) === 'LOCAL' && ! $tableIdent->flag('quoted')) {
            $tableIdent->replace(Builder::toIdentifier(strtoupper($tableIdent->name()), quoted: true));
        }

        $prefixLocal = function (Expression $node, array $visibleAliases): Expression {
            if ($node instanceof Column && $node->text('table') === '') {
                $ident = $node->argExpr('this');
                $alias = $ident !== null ? ($visibleAliases[$this->aliasKey($ident)] ?? null) : null;

                if ($alias instanceof Expression) {
                    return new Column(['this' => $alias, 'table' => Builder::toIdentifier('LOCAL', quoted: false)]);
                }
            }

            return $node;
        };

        foreach (['where', 'group', 'having'] as $key) {
            $arg = $expression->argExpr($key);

            if ($arg !== null) {
                $expression->set($key, $arg->transform(static fn (Expression $node): Expression => $prefixLocal($node, $aliases)));
            }
        }

        /** @var array<string, Expression> $seenAliases */
        $seenAliases = [];
        $newSelects = [];

        foreach ($expression->selects() as $sel) {
            if ($sel instanceof Alias) {
                $inner = $sel->argExpr('this')?->transform(static function (Expression $node) use ($prefixLocal, &$seenAliases): Expression {
                    return $prefixLocal($node, $seenAliases);
                });
                $sel->set('this', $inner);

                $aliasNode = $sel->argExpr('alias');

                if ($aliasNode !== null) {
                    $seenAliases[$this->aliasKey($aliasNode)] = $aliasNode;
                }

                $newSelects[] = $sel;
            } else {
                $newSelects[] = $sel->transform(static function (Expression $node) use ($prefixLocal, &$seenAliases): Expression {
                    return $prefixLocal($node, $seenAliases);
                });
            }
        }

        $expression->set('expressions', $newSelects);

        return $expression;
    }

    protected static function isBareStar(mixed $expr): bool
    {
        return $expr instanceof Star && ($expr->args['this'] ?? null) === null;
    }

    /**
     * Exasol doesn't support a bare * alongside other select items: SELECT *, x FROM t becomes
     * SELECT t.*, x FROM t (sqlglot _qualify_unscoped_star).
     */
    protected function qualifyUnscopedStar(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $selectExpressions = $expression->argList('expressions');

        $hasOtherExpression = false;
        $bareStarExpr = null;

        foreach ($selectExpressions as $expr) {
            $hasBareStar = self::isBareStar($expr);

            if ($hasBareStar && $bareStarExpr === null) {
                $bareStarExpr = $expr;
            } elseif (! $hasBareStar) {
                $hasOtherExpression = true;
            }

            if ($bareStarExpr !== null && $hasOtherExpression) {
                break;
            }
        }

        if (! ($bareStarExpr instanceof Expression && $hasOtherExpression)) {
            return $expression;
        }

        $scope = Scope::build($expression);

        if ($scope === null) {
            return $expression;
        }

        // sqlglot Scope.selected_sources
        $semiAnti = $scope->semiOrAntiJoinTables();
        $selectedSources = [];

        foreach ($scope->references() as [$name, $node]) {
            if (! isset($semiAnti[$name]) && isset($scope->sources[$name]) && ! isset($selectedSources[$name])) {
                $selectedSources[$name] = $node;
            }
        }

        if ($selectedSources === []) {
            return $expression;
        }

        $tableIdentifiers = [];

        foreach ($selectedSources as $sourceName => $sourceExpr) {
            $ident = $sourceExpr instanceof Table ? $sourceExpr->argExpr('this') : null;
            $tableIdentifiers[] = $ident instanceof Identifier ? $ident->copy() : Builder::toIdentifier((string) $sourceName);
        }

        $qualifiedStarColumns = array_map(
            static fn (?Identifier $ident): Column => new Column(['this' => $bareStarExpr->copy(), 'table' => $ident]),
            $tableIdentifiers,
        );

        $newSelectExpressions = [];

        foreach ($selectExpressions as $selectExpr) {
            if (self::isBareStar($selectExpr)) {
                array_push($newSelectExpressions, ...$qualifiedStarColumns);
            } else {
                $newSelectExpressions[] = $selectExpr;
            }
        }

        $expression->set('expressions', $newSelectExpressions);

        return $expression;
    }

    /** sqlglot _group_by_all. */
    protected function groupByAll(Expression $expression): Expression
    {
        if (! $expression instanceof Select) {
            return $expression;
        }

        $group = $expression->argExpr('group');

        if ($group === null || ! $group->flag('all')) {
            return $expression;
        }

        $hasAgg = static function (Expression $proj): bool {
            foreach (Scope::walkInScope($proj) as $node) {
                if ($node instanceof AggFunc) {
                    return true;
                }
            }

            return false;
        };
        $projections = $expression->argList('expressions');

        $isStar = false;

        foreach ($projections as $proj) {
            if ($proj->isStar()) {
                $isStar = true;
            }
        }

        if ($isStar) {
            foreach ($projections as $proj) {
                if ($hasAgg($proj)) {
                    throw new UnsupportedError('GROUP BY ALL with star projection and aggregates is not supported by Exasol');
                }
            }

            $expression->set('distinct', new Distinct);
            $expression->set('group', null);

            return $expression;
        }

        $groupPositions = [];

        foreach ($projections as $i => $proj) {
            if (! $hasAgg($proj)) {
                $groupPositions[] = Literal::number($i + 1);
            }
        }

        if ($groupPositions === []) {
            $expression->set('group', null);

            return $expression;
        }

        $group->set('expressions', $groupPositions);
        $group->set('all', null);

        return $expression;
    }

    // ---------------------------------------------------------------------------------------
    // Overrides
    // ---------------------------------------------------------------------------------------

    protected function selectSql(Select $expression): string
    {
        $processed = $this->qualifyUnscopedStar($expression);
        $processed = $this->addLocalPrefixForAliases($processed);
        $processed = $this->groupByAll($processed);

        return parent::selectSql($processed instanceof Select ? $processed : $expression);
    }

    protected function datatypeSql(DataType $expression): string
    {
        // Exasol supports a fixed default precision of 3 for TIMESTAMP WITH LOCAL TIME ZONE
        if ($expression->isType(DType::TIMESTAMPLTZ)) {
            return 'TIMESTAMP WITH LOCAL TIME ZONE';
        }

        return parent::datatypeSql($expression);
    }

    protected function converttimezoneSql(Expression $expression): string
    {
        return $this->func(
            'CONVERT_TZ',
            $expression->args['timestamp'] ?? null,
            $expression->args['source_tz'] ?? null,
            $expression->args['target_tz'] ?? null,
            $expression->args['options'] ?? null,
        );
    }

    protected function ifSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $true = $this->sql($expression, 'true');
        $false = $this->sql($expression, 'false');

        return "IF {$this_} THEN {$true} ELSE {$false} ENDIF";
    }

    // https://docs.exasol.com/db/latest/sql/open_schema.htm
    protected function useSql(Expression $expression): string
    {
        $kind = $expression->args['kind'] ?? null;

        if ($kind instanceof Expression && strtoupper($this->sql($kind)) !== 'SCHEMA') {
            $this->unsupported("'USE ".$this->sql($kind)."' is not supported in Exasol");

            return parent::useSql($expression);
        }

        return 'OPEN SCHEMA '.$this->sql($expression, 'this');
    }

    // https://docs.exasol.com/db/latest/sql_references/metadata/metadata_system_tables.htm
    protected function showSql(Expression $expression): string
    {
        if ($expression->name() === 'TABLES') {
            $dbName = $expression->text('db');
            $schemaFilter = $dbName !== '' ? Literal::string(strtoupper($dbName)) : new CurrentSchema;

            return $this->sql(new Select([
                'expressions' => [Builder::column('TABLE_NAME')],
                'from_' => new From(['this' => Builder::table('EXA_ALL_TABLES', db: 'SYS')]),
                'where' => new Where(['this' => new EQ(['this' => Builder::column('TABLE_SCHEMA'), 'expression' => $schemaFilter])]),
            ]));
        }

        if ($expression->name() === 'DATABASES') {
            return $this->sql(new Select([
                'expressions' => [Builder::column('SCHEMA_NAME')],
                'from_' => new From(['this' => Builder::table('EXA_SCHEMAS', db: 'SYS')]),
            ]));
        }

        return parent::showSql($expression);
    }

    protected function collateSql(Expression $expression): string
    {
        return $this->sql($expression->argExpr('this'));
    }

    protected function jsonextractSql(Expression $expression): string
    {
        $sql = $this->func(
            'JSON_EXTRACT',
            $expression->args['this'] ?? null,
            $expression->args['expression'] ?? null,
            ...$expression->argList('expressions'),
        );

        $emits = $this->sql($expression, 'emits');

        return $emits !== '' ? "{$sql} EMITS {$emits}" : $sql;
    }

    protected function regexplikeSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'flag');

        if (! $expression->flag('full_match')) {
            $pattern = $expression->argExpr('expression') ?? new NullExpr;

            if ($pattern->isString()) {
                $expression->set('expression', Literal::string('.*'.$pattern->name().'.*'));
            } else {
                $expression->set('expression', new Paren([
                    'this' => new Concat(['expressions' => [Literal::string('.*'), $pattern, Literal::string('.*')]]),
                ]));
            }
        }

        return $this->binary($expression, 'REGEXP_LIKE');
    }
}
