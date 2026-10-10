<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Errors;

/**
 * Raised when an optimizer rule can't process an expression (sqlglot OptimizeError).
 */
class OptimizeError extends SqlParserException {}
