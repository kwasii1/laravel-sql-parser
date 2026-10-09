<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Errors;

/**
 * Raised when an expression can't be generated in the target dialect.
 */
class UnsupportedError extends SqlParserException {}
