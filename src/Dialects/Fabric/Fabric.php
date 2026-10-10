<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Fabric;

use Kwasii\LaravelSqlParser\Dialects\TSql\TSql;

/**
 * The Microsoft Fabric dialect (sqlglot dialects/fabric.py).
 */
class Fabric extends TSql
{
    public const string NAME = 'fabric';

    public const string SETTINGS = FabricDialectTables::class;

    public const string TOKENIZER_TABLES = FabricTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = FabricJsonPathTokenizerTables::class;

    public const string PARSER = FabricParser::class;

    public const string GENERATOR = FabricGenerator::class;

    public const string TYPE_TABLES = FabricTypeTables::class;
}
