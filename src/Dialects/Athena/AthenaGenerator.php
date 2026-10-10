<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Athena;

use Kwasii\LaravelSqlParser\Dialects\Trino\TrinoGenerator;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Properties;
use Kwasii\LaravelSqlParser\Expressions\Property;

/**
 * Athena generator for the Trino engine (sqlglot generators/athena.py AthenaTrinoGenerator);
 * statements routed to the Hive engine use AthenaHiveGenerator (see Athena::generate()).
 */
class AthenaGenerator extends TrinoGenerator
{
    public const string TABLES = AthenaGeneratorTables::class;

    private static function isIcebergTable(Properties $properties): bool
    {
        foreach ($properties->argList('expressions') as $p) {
            if ($p instanceof Property && $p->name() === 'table_type') {
                return strtolower($p->text('value')) === 'iceberg';
            }
        }

        return false;
    }

    /**
     * If table_type='iceberg', the LocationProperty is called 'location', otherwise it's called
     * 'external_location'.
     */
    protected function locationPropertySqlHandler(Expression $e): string
    {
        $propName = $e->parent instanceof Properties && self::isIcebergTable($e->parent) ? 'location' : 'external_location';

        return "{$propName}=".$this->sql($e, 'this');
    }

    /**
     * If table_type='iceberg' the partitioning table property is called 'partitioning', for
     * table_type='hive' it's called 'partitioned_by'.
     */
    protected function partitionedByPropertySqlHandler(Expression $e): string
    {
        $propName = $e->parent instanceof Properties && self::isIcebergTable($e->parent) ? 'partitioning' : 'partitioned_by';

        return "{$propName}=".$this->sql($e, 'this');
    }
}
