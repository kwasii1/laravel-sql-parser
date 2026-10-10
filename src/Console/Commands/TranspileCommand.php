<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Kwasii\LaravelSqlParser\Errors\SqlParserException;
use Kwasii\LaravelSqlParser\LaravelSqlParser;

class TranspileCommand extends Command
{
    /** @var string */
    protected $signature = 'sql:transpile
        {sql? : The SQL to transpile; read from --file or standard input when omitted}
        {--from= : The dialect the SQL is written in (default: the configured dialect)}
        {--to= : The dialect to write (default: the same as --from)}
        {--file= : Read the SQL from a file}
        {--pretty : Format the output over several lines}
        {--ast : Print the parsed expression tree instead of SQL}';

    /** @var string */
    protected $description = 'Transpile SQL from one dialect to another';

    public function handle(LaravelSqlParser $parser): int
    {
        $sql = $this->readSql();

        if ($sql === null || trim($sql) === '') {
            $this->components->error('No SQL given. Pass it as an argument, with --file, or on standard input.');

            return self::FAILURE;
        }

        $from = $this->stringOption('from');
        $to = $this->stringOption('to');

        try {
            if ($this->option('ast') === true) {
                foreach ($parser->parse($sql, $from) as $expression) {
                    $this->line((string) json_encode($expression?->toTree(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                }

                return self::SUCCESS;
            }

            $statements = $parser->transpile($sql, $from, $to, ['pretty' => $this->option('pretty') === true]);
        } catch (SqlParserException|InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(implode(";\n\n", $statements).(count($statements) > 1 ? ';' : ''));

        return self::SUCCESS;
    }

    private function readSql(): ?string
    {
        $argument = $this->argument('sql');

        if (is_string($argument)) {
            return $argument;
        }

        $file = $this->stringOption('file');

        if ($file !== null) {
            if (! is_file($file) || ! is_readable($file)) {
                $this->components->error("Cannot read [{$file}].");

                return null;
            }

            return (string) file_get_contents($file);
        }

        // Standard input, unless it's an interactive terminal waiting for typing
        if (! defined('STDIN') || stream_isatty(STDIN)) {
            return null;
        }

        $input = stream_get_contents(STDIN);

        return is_string($input) ? $input : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
