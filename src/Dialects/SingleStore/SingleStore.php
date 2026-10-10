<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\SingleStore;

use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySql;
use LaravelSqlParser\LaravelSqlParser\Expressions\Cast;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataType;
use LaravelSqlParser\LaravelSqlParser\Expressions\DataTypeParam;
use LaravelSqlParser\LaravelSqlParser\Expressions\DType;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;

/**
 * The SingleStore dialect (sqlglot dialects/singlestore.py).
 */
class SingleStore extends MySql
{
    public const string NAME = 'singlestore';

    public const string SETTINGS = SingleStoreDialectTables::class;

    public const string TOKENIZER_TABLES = SingleStoreTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = SingleStoreJsonPathTokenizerTables::class;

    public const string PARSER = SingleStoreParser::class;

    public const string GENERATOR = SingleStoreGenerator::class;

    public const string TYPE_TABLES = SingleStoreTypeTables::class;

    /**
     * sqlglot cast_to_time6: CAST(<expression> AS <time_type>(6)).
     */
    public static function castToTime6(mixed $expression, string $timeType = DType::TIME): Cast
    {
        return new Cast([
            'this' => $expression,
            'to' => new DataType(['this' => $timeType, 'expressions' => [new DataTypeParam(['this' => Literal::number(6)])]]),
        ]);
    }
}
