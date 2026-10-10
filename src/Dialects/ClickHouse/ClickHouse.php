<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\ClickHouse;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Expressions\Builder;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Identifier;
use Kwasii\LaravelSqlParser\Expressions\Tuple;

/**
 * The ClickHouse dialect (sqlglot dialects/clickhouse.py).
 */
class ClickHouse extends Dialect
{
    public const string NAME = 'clickhouse';

    public const string SETTINGS = ClickHouseDialectTables::class;

    public const string TOKENIZER_TABLES = ClickHouseTokenizerTables::class;

    public const string JSON_PATH_TOKENIZER_TABLES = ClickHouseJsonPathTokenizerTables::class;

    public const string PARSER = ClickHouseParser::class;

    public const string GENERATOR = ClickHouseGenerator::class;

    public const string TYPE_TABLES = ClickHouseTypeTables::class;

    /**
     * VALUES may embed its structure, e.g. VALUES('person String, place String', ('Noah', 'Paris')),
     * whose column names are the aliases; otherwise they're c1, c2, ...
     *
     * @return list<Identifier>
     */
    public function generateValuesAliases(Expression $expression): array
    {
        $values = ($expression->argList('expressions')[0] ?? null)?->argList('expressions') ?? [];
        $aliases = [];

        if (count($values) > 1 && $values[0]->isString() && $values[1] instanceof Tuple) {
            foreach (explode(',', $values[0]->name()) as $coldef) {
                $aliases[] = Builder::toIdentifier(explode(' ', trim($coldef))[0]) ?? new Identifier(['this' => '']);
            }

            return $aliases;
        }

        $count = isset($values[0]) ? count($values[0]->argList('expressions')) : 0;

        for ($i = 1; $i <= $count; $i++) {
            $aliases[] = new Identifier(['this' => "c{$i}", 'quoted' => false]);
        }

        return $aliases;
    }
}
