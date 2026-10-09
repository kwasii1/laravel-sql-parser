<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Console\Commands;

use Illuminate\Console\Command;

class LaravelSqlParserCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'laravel-sql-parser:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package laravel-sql-parser.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('LaravelSqlParser placeholder command executed.');

        return self::SUCCESS;
    }
}
