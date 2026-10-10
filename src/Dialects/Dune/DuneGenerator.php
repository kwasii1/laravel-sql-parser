<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\Dune;

use LaravelSqlParser\LaravelSqlParser\Dialects\Trino\TrinoGenerator;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;

/**
 * Dune generator (sqlglot generators/dune.py).
 */
class DuneGenerator extends TrinoGenerator
{
    public const string TABLES = DuneGeneratorTables::class;

    protected function transformHexString(Expression $e): string
    {
        return '0x'.$e->text('this');
    }
}
