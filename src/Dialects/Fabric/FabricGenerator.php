<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Fabric;

use LaravelSqlParser\LaravelSqlParser\Dialects\TSql\TSqlGenerator;
use LaravelSqlParser\LaravelSqlParser\Expressions\AtTimeZone;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\ColumnDef;
use LaravelSqlParser\LaravelSqlParser\Expressions\Create;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataTypeParam;
use LaravelSqlParser\LaravelSqlParser\Expressions\DateAdd;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Mul;
use LaravelSqlParser\LaravelSqlParser\Expressions\NullExpr;
use LaravelSqlParser\LaravelSqlParser\Expressions\Schema;
use LaravelSqlParser\LaravelSqlParser\Expressions\Select;
use LaravelSqlParser\LaravelSqlParser\Support\Scope;

/**
 * Microsoft Fabric generator (sqlglot generators/fabric.py).
 */
class FabricGenerator extends TSqlGenerator
{
    public const string TABLES = FabricGeneratorTables::class;

    /**
     * Cap a data type's precision to $maxPrecision digits, defaulting to it when there is none
     * (sqlglot _cap_data_type_precision).
     */
    protected static function capDataTypePrecision(DataType $expression, int $maxPrecision = 6): DataType
    {
        $precisionParam = $expression->find(DataTypeParam::class);
        $inner = $precisionParam?->argExpr('this');

        $targetPrecision = $inner !== null && $inner->isInt()
            ? min((int) $inner->name(), $maxPrecision)
            : $maxPrecision;

        return new DataType([
            'this' => $expression->args['this'] ?? null,
            'expressions' => [new DataTypeParam(['this' => Literal::number($targetPrecision)])],
        ]);
    }

    /** The precision of a capped data type (its DataTypeParam), or 6. */
    protected static function cappedPrecision(DataType $dataType): int
    {
        $precision = self::capDataTypePrecision($dataType)->find(DataTypeParam::class)?->argExpr('this');

        return $precision !== null && $precision->isInt() ? (int) $precision->name() : 6;
    }

    /**
     * For transpilation, VARCHAR/CHAR without precision becomes VARCHAR(MAX)/CHAR(MAX)
     * (sqlglot _add_default_precision_to_varchar).
     */
    public function addDefaultPrecisionToVarchar(Expression $expression): Expression
    {
        $schema = $expression->args['this'] ?? null;

        if ($expression instanceof Create && strtoupper($expression->text('kind')) === 'TABLE' && $schema instanceof Schema) {
            foreach ($schema->argList('expressions') as $column) {
                $columnType = $column instanceof ColumnDef ? ($column->args['kind'] ?? null) : null;

                if (
                    $columnType instanceof DataType
                    && $columnType->isType(DType::VARCHAR, DType::CHAR)
                    && $columnType->argList('expressions') === []
                ) {
                    $columnType->set('expressions', [Builder::var('MAX')]);
                }
            }
        }

        return $expression;
    }

    protected function datatypeSql(DataType $expression): string
    {
        // Fabric limits temporal types to max 6 digits precision, defaulting to 6 digits
        if ($expression->isType(...DataType::TEMPORAL_TYPES) && ! $expression->isType(DType::DATE)) {
            $expression = self::capDataTypePrecision($expression);
        }

        return parent::datatypeSql($expression);
    }

    protected function castSql(Expression $expression, ?string $safePrefix = null): string
    {
        // Cast to DATETIMEOFFSET if inside an AT TIME ZONE expression
        $to = $expression->args['to'] ?? null;

        if ($expression->isType(DType::TIMESTAMPTZ) && $to instanceof DataType) {
            $atTimeZone = $expression->findAncestor(AtTimeZone::class, Select::class);

            if (! $atTimeZone instanceof AtTimeZone) {
                return parent::castSql($expression, $safePrefix);
            }

            $precisionValue = self::cappedPrecision($to);
            $this_ = $expression->argExpr('this');

            return 'CAST('.($this_?->sql() ?? '')." AS DATETIMEOFFSET({$precisionValue}))";
        }

        return parent::castSql($expression, $safePrefix);
    }

    protected function attimezoneSql(Expression $expression): string
    {
        // Wrap the AT TIME ZONE expression in a cast to DATETIME2 if it contains a TIMESTAMPTZ
        $timestamptzCast = Scope::findAllInScope($expression, Cast::class)[0] ?? null;
        $to = $timestamptzCast?->args['to'] ?? null;

        if ($to instanceof DataType && $to->isType(DType::TIMESTAMPTZ)) {
            $precision = self::cappedPrecision($to);
            $atTimeZoneSql = parent::attimezoneSql($expression);

            return "CAST({$atTimeZoneSql} AS DATETIME2({$precision}))";
        }

        return parent::attimezoneSql($expression);
    }

    protected function unixtotimeSql(Expression $expression): string
    {
        $scale = $expression->args['scale'] ?? null;
        $timestamp = $expression->argExpr('this') ?? new NullExpr;

        if ($scale !== null && ! ($scale instanceof Literal && $scale->equals(Literal::number(0)))) {
            $scaleSql = $scale instanceof Expression ? $scale->sql() : (is_scalar($scale) ? (string) $scale : '');
            $this->unsupported("UnixToTime scale {$scaleSql} is not supported by Fabric");

            return '';
        }

        // Convert unix timestamp (seconds) to microseconds and round to avoid decimals
        $microseconds = Builder::binop(Mul::class, $timestamp, Literal::number('1e6'));
        $rounded = Builder::func('round', [$microseconds, Literal::number(0)]);
        $roundedMsAsBigint = Builder::cast($rounded, DType::BIGINT);

        // Create the base datetime as '1970-01-01' cast to DATETIME2(6)
        $epochStart = Builder::cast("'1970-01-01'", 'datetime2(6)', dialect: 'fabric');

        return $this->sql(new DateAdd([
            'this' => $epochStart,
            'expression' => $roundedMsAsBigint,
            'unit' => Literal::string('MICROSECONDS'),
        ]));
    }
}
