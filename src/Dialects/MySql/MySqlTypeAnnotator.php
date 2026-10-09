<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\MySql;

use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Optimizer\TypeAnnotator;

/**
 * MySQL type inference (sqlglot typing/mysql.py).
 */
class MySqlTypeAnnotator extends TypeAnnotator
{
    private const array COMPRESS_LONGBLOB_TYPES = [
        DType::TEXT, DType::MEDIUMTEXT, DType::LONGTEXT, DType::BLOB, DType::MEDIUMBLOB, DType::LONGBLOB, DType::JSON,
    ];

    private const array COMPRESS_VARBINARY_TYPES = [
        DType::CHAR, DType::VARCHAR, DType::BINARY, DType::VARBINARY, DType::TINYBLOB, DType::ENUM, DType::INT,
        DType::BIGINT, DType::DECIMAL, DType::DOUBLE, DType::DATE, DType::DATETIME,
    ];

    protected function annotateReverse(Expression $expression): Expression
    {
        if (self::argIsType($expression, 'this', DType::BINARY, DType::VARBINARY, DType::UNKNOWN)) {
            return $this->annotateByArgs($expression, ['this']);
        }

        return $this->setType($expression, DType::VARCHAR);
    }

    protected function annotateTruncate(Expression $expression): Expression
    {
        if (self::argIsType($expression, 'this', ...DataType::TEXT_TYPES)) {
            return $this->setType($expression, DType::DOUBLE);
        }

        return $this->annotateByArgs($expression, ['this']);
    }

    protected function annotateRegexpReplace(Expression $expression): Expression
    {
        $hasBinary = false;

        foreach (['this', 'expression', 'replacement'] as $key) {
            $arg = $expression->args[$key] ?? null;

            if ($arg instanceof Expression) {
                if ($arg->isType(DType::UNKNOWN)) {
                    return $this->setType($expression, DType::UNKNOWN);
                }

                $hasBinary = $hasBinary || $arg->isType(...DataType::BINARY_TYPES);
            }
        }

        return $this->setType($expression, $hasBinary ? DType::LONGBLOB : DType::LONGTEXT);
    }

    protected function annotateCompress(Expression $expression): Expression
    {
        return match (true) {
            self::argIsType($expression, 'this', ...self::COMPRESS_VARBINARY_TYPES) => $this->setType($expression, DType::VARBINARY),
            self::argIsType($expression, 'this', ...self::COMPRESS_LONGBLOB_TYPES) => $this->setType($expression, DType::LONGBLOB),
            self::argIsType($expression, 'this', DType::TINYTEXT) => $this->setType($expression, DType::BLOB),
            default => $this->setType($expression, DType::UNKNOWN),
        };
    }

    protected function annotateBitFunc(Expression $expression): Expression
    {
        return match (true) {
            self::argIsType($expression, 'this', DType::UNKNOWN) => $this->setType($expression, DType::UNKNOWN),
            self::argIsType($expression, 'this', ...DataType::BINARY_TYPES) => $this->setType($expression, DType::VARBINARY),
            default => $this->setType($expression, DType::UBIGINT),
        };
    }

    private static function argIsType(Expression $expression, string $key, string ...$types): bool
    {
        $arg = $expression->args[$key] ?? null;

        return $arg instanceof Expression && $arg->isType(...$types);
    }
}
