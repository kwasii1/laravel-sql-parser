<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects\MariaDb;

use LaravelSqlParser\LaravelSqlParser\Dialects\MySql\MySql;

/**
 * The MariaDB dialect. sqlglot has no MariaDB dialect, so this is MySQL with MariaDB's
 * differences (see MariaDbGenerator); MySQL syntax parses the same way.
 */
class MariaDb extends MySql
{
    public const string NAME = 'mariadb';

    public const string GENERATOR = MariaDbGenerator::class;
}
