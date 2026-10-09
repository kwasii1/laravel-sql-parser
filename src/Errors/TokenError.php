<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Errors;

use Throwable;

/**
 * Raised when SQL can't be split into tokens, e.g. an unterminated string.
 */
class TokenError extends SqlParserException
{
    public function __construct(string $message, public readonly ?int $start = null, public readonly ?int $end = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
