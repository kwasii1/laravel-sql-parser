<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\RisingWave;

use Kwasii\LaravelSqlParser\Dialects\Postgres\Postgres;

/**
 * The RisingWave dialect (sqlglot dialects/risingwave.py).
 */
class RisingWave extends Postgres
{
    public const string NAME = 'risingwave';

    public const string SETTINGS = RisingWaveDialectTables::class;

    public const string TOKENIZER_TABLES = RisingWaveTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = RisingWaveJsonPathTokenizerTables::class;

    public const string PARSER = RisingWaveParser::class;

    public const string GENERATOR = RisingWaveGenerator::class;

    public const string TYPE_TABLES = RisingWaveTypeTables::class;
}
