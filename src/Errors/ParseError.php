<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Errors;

use Throwable;

/**
 * Raised when tokens can't be parsed into an expression tree.
 *
 * `$errors` holds structured details of every collected error: description, line, col,
 * start_context, highlight, end_context and into_expression.
 */
class ParseError extends SqlParserException
{
    /**
     * @param  list<array<string, mixed>>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Combine several errors into one exception (sqlglot ParseError.new / concat_messages).
     *
     * @param  list<ParseError>  $errors
     */
    public static function combine(array $errors, int $maxErrors): self
    {
        $messages = array_map(static fn (ParseError $error): string => $error->getMessage(), array_slice($errors, 0, $maxErrors));
        $remaining = count($errors) - $maxErrors;

        if ($remaining > 0) {
            $messages[] = "... and {$remaining} more";
        }

        $details = [];
        foreach ($errors as $error) {
            array_push($details, ...$error->errors);
        }

        return new self(implode("\n\n", $messages), $details);
    }
}
