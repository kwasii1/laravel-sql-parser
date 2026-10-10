<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Fabric;

use Kwasii\LaravelSqlParser\Dialects\TSql\TSqlParser;
use Kwasii\LaravelSqlParser\Expressions\ColumnDef;
use Kwasii\LaravelSqlParser\Expressions\Create;
use Kwasii\LaravelSqlParser\Expressions\DataType;
use Kwasii\LaravelSqlParser\Expressions\DType;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\Literal;
use Kwasii\LaravelSqlParser\Expressions\Schema;

/**
 * Microsoft Fabric parser (sqlglot parsers/fabric.py).
 */
class FabricParser extends TSqlParser
{
    public const string TABLES = FabricParserTables::class;

    protected function parseCreate(): Expression
    {
        $create = parent::parseCreate();

        if ($create instanceof Create) {
            $schema = $create->args['this'] ?? null;

            // Transform VARCHAR/CHAR without precision to VARCHAR(1)/CHAR(1)
            if (strtoupper($create->text('kind')) === 'TABLE' && $schema instanceof Schema) {
                foreach ($schema->argList('expressions') as $column) {
                    $columnType = $column instanceof ColumnDef ? ($column->args['kind'] ?? null) : null;

                    if (
                        $columnType instanceof DataType
                        && $columnType->isType(DType::VARCHAR, DType::CHAR)
                        && $columnType->argList('expressions') === []
                    ) {
                        // When n isn't specified in a data definition, the default length is 1
                        $columnType->set('expressions', [Literal::number('1')]);
                    }
                }
            }
        }

        return $create;
    }
}
