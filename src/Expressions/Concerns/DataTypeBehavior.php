<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use InvalidArgumentException;
use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Errors\ErrorLevel;
use LaravelSqlParser\LaravelSqlParser\Errors\ParseError;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Dot;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;

/**
 * @mixin DataType
 */
trait DataTypeBehavior
{
    /**
     * Build a DataType from a DType name (e.g. DType::INT), a SQL type string (e.g. 'varchar(10)'),
     * an identifier (user-defined type) or another DataType.
     *
     * @param  array<string, mixed>  $args
     */
    public static function build(DataType|Identifier|Dot|string $type, string|Dialect|null $dialect = null, bool $udt = false, bool $copy = true, array $args = []): DataType
    {
        if (is_string($type)) {
            if (isset(DType::VALUES[$type])) {
                $dataType = new DataType(['this' => $type]);

                foreach ($args as $key => $value) {
                    $dataType->set($key, $value);
                }

                return $dataType;
            }

            return self::fromStr($type, $dialect, $udt, $args);
        }

        if (($type instanceof Identifier || $type instanceof Dot) && $udt) {
            return new DataType(['this' => DType::USERDEFINED, 'kind' => $type, ...$args]);
        }

        if ($type instanceof DataType) {
            return $copy ? $type->copy() : $type;
        }

        throw new InvalidArgumentException('Invalid data type: '.get_debug_type($type));
    }

    /**
     * Parse a SQL type string.
     *
     * @param  array<string, mixed>  $args
     */
    public static function fromStr(string $type, string|Dialect|null $dialect = null, bool $udt = false, array $args = []): DataType
    {
        if (strtoupper($type) === 'UNKNOWN') {
            return new DataType(['this' => DType::UNKNOWN, ...$args]);
        }

        try {
            $dataType = Dialect::get($dialect)->parseInto(DataType::class, $type, ErrorLevel::Ignore);

            if (! $dataType instanceof DataType) {
                throw new ParseError("Failed to parse '{$type}' into a data type");
            }

            foreach ($args as $key => $value) {
                $dataType->set($key, $value);
            }

            return $dataType;
        } catch (ParseError $e) {
            if ($udt) {
                return new DataType(['this' => DType::USERDEFINED, 'kind' => $type, ...$args]);
            }

            throw $e;
        }
    }

    /**
     * Whether this data type is one of the given types (DType names, SQL strings or DataTypes).
     */
    public function isType(string|DataType ...$types): bool
    {
        $selfIsNullable = $this->args['nullable'] ?? null;

        foreach ($types as $type) {
            $other = self::build($type, udt: true, copy: false);

            if (
                $other->argList('expressions') !== []
                || ($this->args['this'] ?? null) === DType::USERDEFINED
                || ($other->args['this'] ?? null) === DType::USERDEFINED
            ) {
                $matches = $this->equals($other);
            } else {
                $matches = ($this->args['this'] ?? null) === ($other->args['this'] ?? null);
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    public function type(): DataType
    {
        return $this;
    }
}
