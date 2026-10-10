<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Postgres;

use Kwasii\LaravelSqlParser\Expressions\Add;
use Kwasii\LaravelSqlParser\Expressions\Any;
use Kwasii\LaravelSqlParser\Expressions\ArrayExpr;
use Kwasii\LaravelSqlParser\Expressions\AutoIncrementColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\CaseExpr;
use Kwasii\LaravelSqlParser\Expressions\Cast;
use Kwasii\LaravelSqlParser\Expressions\Coalesce;
use Kwasii\LaravelSqlParser\Expressions\ColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\ColumnDef;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\Div;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\EQ;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Extract;
use Kwasii\LaravelSqlParser\Expressions\From;
use Kwasii\LaravelSqlParser\Expressions\GeneratedAsIdentityColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\GenerateDateArray;
use Kwasii\LaravelSqlParser\Expressions\GenerateSeries;
use Kwasii\LaravelSqlParser\Expressions\IfExpr;
use Kwasii\LaravelSqlParser\Expressions\InOutColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\IntDiv;
use Kwasii\LaravelSqlParser\Expressions\Interval;
use Kwasii\LaravelSqlParser\Expressions\Is;
use Kwasii\LaravelSqlParser\Expressions\Join;
use Kwasii\LaravelSqlParser\Expressions\JSONPath;
use Kwasii\LaravelSqlParser\Expressions\JSONPathRoot;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Mul;
use Kwasii\LaravelSqlParser\Expressions\NotNullColumnConstraint;
use Kwasii\LaravelSqlParser\Expressions\NullExpr;
use Kwasii\LaravelSqlParser\Expressions\Paren;
use Kwasii\LaravelSqlParser\Expressions\Query;
use Kwasii\LaravelSqlParser\Expressions\Select;
use Kwasii\LaravelSqlParser\Expressions\Sub;
use Kwasii\LaravelSqlParser\Expressions\Table;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsAdd;
use Kwasii\LaravelSqlParser\Expressions\TsOrDsToDate;
use Kwasii\LaravelSqlParser\Expressions\Variadic;
use Kwasii\LaravelSqlParser\Generator\Generator;

/**
 * PostgreSQL generator (sqlglot generators/postgres.py).
 */
class PostgresGenerator extends Generator
{
    public const string TABLES = PostgresGeneratorTables::class;

    private const array DATE_DIFF_FACTOR = [
        'MICROSECOND' => ' * 1000000',
        'MILLISECOND' => ' * 1000',
        'SECOND' => '',
        'MINUTE' => ' / 60',
        'HOUR' => ' / 3600',
        'DAY' => ' / 86400',
    ];

    // ---------------------------------------------------------------------------------------
    // TRANSFORMS handlers
    // ---------------------------------------------------------------------------------------

    protected function dateAddSqlHandler(Expression $expression, string $kind): string
    {
        if ($expression instanceof TsOrDsAdd) {
            $expression = self::tsOrDsAddCast($expression);
        }

        $this_ = $this->sql($expression, 'this');
        $unit = $expression->args['unit'] ?? null;
        $e = $this->simplifyUnlessLiteral($expression->argExpr('expression'));

        if ($e instanceof Interval) {
            return "{$this_} {$kind} ".$this->sql($e);
        }

        if ($e instanceof Literal) {
            $e->set('is_string', true);
        } elseif ($e !== null && $e->isNumber()) {
            $e = Literal::string((string) $e->toPhp());
        } else {
            $intervalTimesValue = new Mul(['this' => new Interval(['this' => Literal::number(1), 'unit' => $unit]), 'expression' => $e]);

            return "{$this_} {$kind} ".$this->sql($intervalTimesValue);
        }

        return "{$this_} {$kind} ".$this->sql(new Interval(['this' => $e, 'unit' => $unit]));
    }

    protected function dayMonthYearSql(Expression $expression): string
    {
        $this_ = $expression->argExpr('this') ?? new NullExpr;
        $value = $this_ instanceof TsOrDsToDate ? ($this_->argExpr('this') ?? new NullExpr) : $this_;
        $defaultDate = $this_->args['default_date'] ?? null;

        if ($value->isType(...DataType::INTEGER_TYPES) && $defaultDate instanceof Expression) {
            $this_ = new Add(['this' => Builder::cast($defaultDate, DType::DATE), 'expression' => $value]);
        }

        return $this->sql(new Extract(['this' => Builder::var($expression::sqlName()), 'expression' => $this_]));
    }

    protected function dateDiffSqlHandler(Expression $expression): string
    {
        $unit = strtoupper($expression->text('unit'));
        $unit = $unit !== '' ? $unit : 'DAY';

        // Dialects like MySQL count crossed day boundaries, which maps to DATE subtraction
        if ($unit === 'DAY' && $expression->flag('date_part_boundary')) {
            $this_ = Builder::cast($expression->argExpr('this') ?? new NullExpr, DType::DATE);
            $expr = Builder::cast($expression->argExpr('expression') ?? new NullExpr, DType::DATE);

            return $this->sql(Builder::paren(new Sub(['this' => $this_, 'expression' => $expr]), copy: false));
        }

        $factor = self::DATE_DIFF_FACTOR[$unit] ?? null;
        $end = 'CAST('.$this->sql($expression, 'this').' AS TIMESTAMP)';
        $start = 'CAST('.$this->sql($expression, 'expression').' AS TIMESTAMP)';

        if ($factor !== null) {
            return "CAST(EXTRACT(epoch FROM {$end} - {$start}){$factor} AS BIGINT)";
        }

        $age = "AGE({$end}, {$start})";

        $unit = match ($unit) {
            'WEEK' => "EXTRACT(days FROM ({$end} - {$start})) / 7",
            'MONTH' => "EXTRACT(year FROM {$age}) * 12 + EXTRACT(month FROM {$age})",
            'QUARTER' => "EXTRACT(year FROM {$age}) * 4 + EXTRACT(month FROM {$age}) / 3",
            'YEAR' => "EXTRACT(year FROM {$age})",
            default => $age,
        };

        return "CAST({$unit} AS BIGINT)";
    }

    protected function substringSqlHandler(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $start = $this->sql($expression, 'start');
        $length = $this->sql($expression, 'length');

        $fromPart = $start !== '' ? " FROM {$start}" : '';
        $forPart = $length !== '' ? " FOR {$length}" : '';

        return "SUBSTRING({$this_}{$fromPart}{$forPart})";
    }

    public function autoIncrementToSerial(Expression $expression): Expression
    {
        $auto = $expression->find(AutoIncrementColumnConstraint::class);

        if ($auto !== null) {
            $auto->parent?->pop();
            $kind = $expression->argExpr('kind');
            $type = $kind?->args['this'] ?? null;

            $serial = match ($type) {
                DType::INT => DType::SERIAL,
                DType::SMALLINT => DType::SMALLSERIAL,
                DType::BIGINT => DType::BIGSERIAL,
                default => null,
            };

            if ($serial !== null) {
                $kind->replace(new DataType(['this' => $serial]));
            }
        }

        return $expression;
    }

    public function serialToGenerated(Expression $expression): Expression
    {
        if (! $expression instanceof ColumnDef) {
            return $expression;
        }

        $kind = $expression->argExpr('kind');

        if ($kind === null) {
            return $expression;
        }

        $dataType = match ($kind->args['this'] ?? null) {
            DType::SERIAL => new DataType(['this' => DType::INT]),
            DType::SMALLSERIAL => new DataType(['this' => DType::SMALLINT]),
            DType::BIGSERIAL => new DataType(['this' => DType::BIGINT]),
            default => null,
        };

        if ($dataType !== null) {
            $kind->replace($dataType);
            $constraints = $expression->argList('constraints');
            $generated = new ColumnConstraint(['kind' => new GeneratedAsIdentityColumnConstraint(['this' => false])]);
            $notnull = new ColumnConstraint(['kind' => new NotNullColumnConstraint]);

            $contains = static function (array $constraints, Expression $needle): bool {
                foreach ($constraints as $constraint) {
                    if ($constraint instanceof Expression && $constraint->equals($needle)) {
                        return true;
                    }
                }

                return false;
            };

            if (! $contains($constraints, $notnull)) {
                array_unshift($constraints, $notnull);
            }

            if (! $contains($constraints, $generated)) {
                array_unshift($constraints, $generated);
            }

            $expression->set('constraints', $constraints);
        }

        return $expression;
    }

    protected function jsonExtractSqlHandler(Expression $expression, string $name, string $op): string
    {
        $path = $expression->args['expression'] ?? null;

        // Single non-literal segment: render as infix, not JSON_EXTRACT_PATH[_TEXT] (jsonb-unsafe).
        if (! ($path instanceof JSONPath || $path instanceof Variadic) && $expression->argList('expressions') === []) {
            return $this->arrowJsonExtractSql($expression, op: $op);
        }

        // JSON_EXTRACT_PATH requires a key, so use an empty variadic array for the root path
        if ($path instanceof JSONPath && count($path->argList('expressions')) === 1 && $path->argList('expressions')[0] instanceof JSONPathRoot) {
            $expression->set('expression', new Variadic(['this' => Literal::string('{}')]));
        }

        if ($expression->flag('only_json_types')) {
            return $this->jsonExtractSegments($expression, $name, quotedIndex: false, op: $op);
        }

        return $this->jsonExtractSegments($expression, $name);
    }

    protected function unixToTimeSqlHandler(Expression $expression): string
    {
        $scale = $expression->args['scale'] ?? null;
        $timestamp = $expression->args['this'] ?? null;

        if ($scale === null || ($scale instanceof Literal && $scale->equals(Literal::number(0)))) {
            return $this->func('TO_TIMESTAMP', $timestamp, $this->formatTime($expression));
        }

        return $this->func(
            'TO_TIMESTAMP',
            new Div(['this' => $timestamp, 'expression' => Builder::func('POW', [Literal::number(10), $scale])]),
            $this->formatTime($expression),
        );
    }

    protected function levenshteinSqlHandler(Expression $expression): string
    {
        return $this->renameFunc($expression, $expression->flag('max_dist') ? 'LEVENSHTEIN_LESS_EQUAL' : 'LEVENSHTEIN');
    }

    protected function versionedAnyvalueSql(Expression $expression): string
    {
        // ANY_VALUE is available from PostgreSQL 16
        if ($this->dialect->version < [16, 0, 0]) {
            return $this->anyValueToMaxSql($expression);
        }

        return $this->renameFunc($expression, 'ANY_VALUE');
    }

    protected function roundSqlHandler(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $decimals = $this->sql($expression, 'decimals');

        if ($decimals === '') {
            return $this->func('ROUND', $this_);
        }

        // ROUND(double precision, integer) is not permitted in Postgres, so it's necessary to cast
        // to decimal before rounding (sqlglot infers the type; here only explicit types count)
        if (($expression->argExpr('this') ?? new NullExpr)->isType(DType::DOUBLE)) {
            $decimalType = new DataType(['this' => DType::DECIMAL, 'expressions' => $expression->argList('expressions')]);
            $this_ = $this->sql(new Cast(['this' => $this_, 'to' => $decimalType]));
        }

        return $this->func('ROUND', $this_, $decimals);
    }

    protected function transformBitwiseXor(Expression $e): string
    {
        return $this->binary($e, '#');
    }

    protected function transformCurrentTimestamp(Expression $e): string
    {
        return 'CURRENT_TIMESTAMP';
    }

    protected function transformCurrentUser(Expression $e): string
    {
        return 'CURRENT_USER';
    }

    protected function transformGroupConcat(Expression $e): string
    {
        return $this->groupconcatSqlHandler($e, funcName: 'STRING_AGG', withinGroup: false);
    }

    protected function transformJSONArrayAgg(Expression $e): string
    {
        return $this->funcWith('JSON_AGG', [$this->sql($e, 'this')], suffix: $this->sql($e, 'order').')');
    }

    protected function transformJSONBExtract(Expression $e): string
    {
        return $this->arrowJsonExtractSql($e, op: '#>');
    }

    protected function transformJSONBExtractScalar(Expression $e): string
    {
        return $this->arrowJsonExtractSql($e, op: '#>>');
    }

    protected function transformParseJSON(Expression $e): string
    {
        return $this->sql(Builder::cast($e->argExpr('this') ?? new NullExpr, DType::JSON));
    }

    protected function transformJSONPathRoot(Expression $e): string
    {
        return '';
    }

    protected function transformJSONPathSubscript(Expression $e): string
    {
        return $this->jsonPathPart($e->args['this'] ?? '');
    }

    protected function transformPartitionedByProperty(Expression $e): string
    {
        return 'PARTITION BY '.$this->sql($e, 'this');
    }

    protected function transformRegexpLike(Expression $e): string
    {
        return $this->binary($e, '~');
    }

    protected function transformRegexpILike(Expression $e): string
    {
        return $this->binary($e, '~*');
    }

    protected function transformRegexpReplace(Expression $e): string
    {
        return $this->func(
            'REGEXP_REPLACE',
            $e->args['this'] ?? null,
            $e->args['expression'] ?? null,
            $e->args['replacement'] ?? null,
            $e->args['position'] ?? null,
            $e->args['occurrence'] ?? null,
            self::regexpReplaceGlobalModifier($e),
        );
    }

    protected function transformStrPosition(Expression $e): string
    {
        return $this->strpositionSqlHandler($e, funcName: 'POSITION');
    }

    protected function transformStrToDate(Expression $e): string
    {
        return $this->func('TO_DATE', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformStrToTime(Expression $e): string
    {
        return $this->func('TO_TIMESTAMP', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformTimeToStr(Expression $e): string
    {
        return $this->func('TO_CHAR', $e->args['this'] ?? null, $this->formatTime($e));
    }

    protected function transformToChar(Expression $e): string
    {
        return $e->flag('format') ? $this->functionFallbackSql($e) : $this->tocharSql($e);
    }

    protected function transformUuid(Expression $e): string
    {
        return 'GEN_RANDOM_UUID()';
    }

    protected function transformTimeToUnix(Expression $e): string
    {
        return $this->func('DATE_PART', Literal::string('epoch'), $e->args['this'] ?? null);
    }

    // ---------------------------------------------------------------------------------------
    // Generator overrides
    // ---------------------------------------------------------------------------------------

    protected function lateralSql(Expression $expression): string
    {
        $sql = parent::lateralSql($expression);

        if (($expression->args['cross_apply'] ?? null) !== null) {
            $sql = "{$sql} ON TRUE";
        }

        return $sql;
    }

    protected function schemacommentpropertySql(Expression $expression): string
    {
        $this->unsupported('Table comments are not supported in the CREATE statement');

        return '';
    }

    protected function commentcolumnconstraintSql(Expression $expression): string
    {
        $this->unsupported('Column comments are not supported in the CREATE statement');

        return '';
    }

    protected function columndefSql(Expression $expression, string $sep = ' '): string
    {
        // PostgreSQL places parameter modes BEFORE parameter name
        $paramConstraint = $expression->find(InOutColumnConstraint::class);

        if ($paramConstraint !== null) {
            $modeSql = $this->sql($paramConstraint);
            // Remove to prevent double-rendering
            $paramConstraint->pop();

            return "{$modeSql} ".parent::columndefSql($expression, $sep);
        }

        return parent::columndefSql($expression, $sep);
    }

    protected function unnestSql(Expression $expression): string
    {
        $args = $expression->argList('expressions');

        if (count($args) === 1 && $args[0] instanceof GenerateDateArray) {
            $generateSeries = new GenerateSeries($args[0]->args);

            if ($expression->parent instanceof From || $expression->parent instanceof Join) {
                $alias = $expression->args['alias'] ?? null;
                $table = Builder::alias(new Table(['this' => $generateSeries]), '_t', table: ['value']);
                $select = new Select([
                    'expressions' => [Builder::maybeParse('value::date')],
                    'from_' => new From(['this' => $table]),
                ]);
                $generateSeries = $select->subquery($alias instanceof Expression ? $alias : '_unnested_generate_series');
            }

            return $this->sql($generateSeries);
        }

        // sqlglot rewrites UNNEST of array<json> into JSON_ARRAY_ELEMENTS, which needs type
        // inference; only explicitly typed values are known here
        if (count($args) === 1 && $args[0]->isType('array<json>')) {
            $this_ = $args[0];

            while ($this_ instanceof Cast) {
                $this_ = $this_->argExpr('this') ?? new NullExpr;
            }

            $argAsJson = $this->sql(Builder::cast($this_, DType::JSON));
            $alias = $this->sql($expression, 'alias');
            $alias = $alias !== '' ? " AS {$alias}" : '';

            if ($expression->flag('offset')) {
                $this->unsupported('Unsupported JSON_ARRAY_ELEMENTS with offset');
            }

            return "JSON_ARRAY_ELEMENTS({$argAsJson}){$alias}";
        }

        return parent::unnestSql($expression);
    }

    /**
     * Forms like ARRAY[1, 2, 3][3] aren't allowed; the ARRAY needs to be wrapped.
     */
    protected function bracketSql(Expression $expression): string
    {
        $this_ = $expression->args['this'] ?? null;

        if ($this_ instanceof ArrayExpr) {
            $expression->set('this', Builder::paren($this_, copy: false));
        }

        return parent::bracketSql($expression);
    }

    protected function matchagainstSql(Expression $expression): string
    {
        $this_ = $this->sql($expression, 'this');
        $expressions = array_map(fn (Expression $e): string => $this->sql($e)." @@ {$this_}", $expression->argList('expressions'));
        $sql = implode(' OR ', $expressions);

        return count($expressions) > 1 ? "({$sql})" : $sql;
    }

    protected function altersetSql(Expression $expression): string
    {
        $exprs = $this->expressions($expression, flat: true);
        $exprs = $exprs !== '' ? "({$exprs})" : '';
        $accessMethod = $this->sql($expression, 'access_method');
        $accessMethod = $accessMethod !== '' ? "ACCESS METHOD {$accessMethod}" : '';
        $tablespace = $this->sql($expression, 'tablespace');
        $tablespace = $tablespace !== '' ? "TABLESPACE {$tablespace}" : '';
        $option = $this->sql($expression, 'option');

        return "SET {$exprs}{$accessMethod}{$tablespace}{$option}";
    }

    protected function datatypeSql(DataType $expression): string
    {
        if ($expression->isType(DType::ARRAY)) {
            if ($expression->argList('expressions') !== []) {
                $values = $this->expressions($expression, key: 'values', flat: true);

                return $this->expressions($expression, flat: true)."[{$values}]";
            }

            return 'ARRAY';
        }

        if ($expression->isType(DType::ENUM)) {
            return 'ENUM ('.$this->expressions($expression, flat: true).')';
        }

        if ($expression->isType(DType::DOUBLE, DType::FLOAT) && $expression->argList('expressions') !== []) {
            // Postgres doesn't support precision for REAL and DOUBLE PRECISION types
            return 'FLOAT('.$this->expressions($expression, flat: true).')';
        }

        return parent::datatypeSql($expression);
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        $this_ = $expression->args['this'] ?? null;
        $to = $expression->args['to'] ?? null;

        // Postgres casts DIV() to decimal for transpilation but when roundtripping it's superfluous
        if ($this_ instanceof IntDiv && $to instanceof DataType && $to->equals(new DataType(['this' => DType::DECIMAL]))) {
            return $this->sql($this_);
        }

        return parent::castSql($expression, safePrefix: $safePrefix);
    }

    protected function arraySql(Expression $expression): string
    {
        $exprs = $expression->argList('expressions');
        $funcName = $this->normalizeFunc('ARRAY');

        if (($exprs[0] ?? null) instanceof Query) {
            return "{$funcName}(".$this->sql($exprs[0]).')';
        }

        return $funcName.$this->inlineArraySql($expression);
    }

    protected function computedcolumnconstraintSql(Expression $expression): string
    {
        return 'GENERATED ALWAYS AS ('.$this->sql($expression, 'this').') STORED';
    }

    protected function isasciiSql(Expression $expression): string
    {
        return '('.$this->sql($expression, 'this')." ~ '^[[:ascii:]]*$')";
    }

    protected function ignorenullsSql(Expression $expression): string
    {
        // https://www.postgresql.org/docs/current/functions-window.html
        $this->unsupported('PostgreSQL does not support IGNORE NULLS.');

        return $this->sql($expression, 'this');
    }

    protected function respectnullsSql(Expression $expression): string
    {
        $this->unsupported('PostgreSQL does not support RESPECT NULLS.');

        return $this->sql($expression, 'this');
    }

    protected function currentschemaSql(Expression $expression): string
    {
        $this->unsupportedArgs($expression, 'this');

        return 'CURRENT_SCHEMA';
    }

    protected function intervalSql(Expression $expression): string
    {
        $unit = strtolower($expression->text('unit'));
        $this_ = $expression->args['this'] ?? null;

        if (str_starts_with($unit, 'quarter') && $this_ instanceof Literal) {
            $this_->replace(Literal::string((string) ((int) $this_->toPhp() * 3)));
            ($expression->args['unit'] ?? null)?->replace(Builder::var('MONTH'));
        }

        return parent::intervalSql($expression);
    }

    protected function placeholderSql(Expression $expression): string
    {
        if ($expression->flag('jdbc')) {
            return '?';
        }

        $this_ = $this->truthy($expression->args['this'] ?? null) ? '('.$expression->name().')' : '';

        return $this->t['NAMED_PLACEHOLDER_TOKEN']."{$this_}s";
    }

    /**
     * DuckDB's LIST_CONTAINS(array, value) as CASE WHEN value IS NULL THEN NULL ELSE
     * COALESCE(value = ANY(array), FALSE) END.
     */
    protected function arraycontainsSql(Expression $expression): string
    {
        $value = $expression->argExpr('expression') ?? new NullExpr;
        $array = $expression->args['this'] ?? null;

        $coalesce = new Coalesce([
            'this' => new EQ(['this' => $value->copy(), 'expression' => new Any(['this' => new Paren(['this' => $array])])]),
            'expressions' => [Builder::false()],
        ]);

        return $this->sql(new CaseExpr([
            'ifs' => [new IfExpr(['this' => new Is(['this' => $value, 'expression' => new NullExpr]), 'true' => new NullExpr])],
            'default' => $coalesce,
        ]));
    }
}
