<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Dune;

use Kwasii\LaravelSqlParser\Dialects\Trino\TrinoGenerator;
use Kwasii\LaravelSqlParser\Expressions\Expression;

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
