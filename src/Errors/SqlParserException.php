<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Errors;

use RuntimeException;

/**
 * Base class of every exception thrown by the package (sqlglot: SqlglotError).
 */
class SqlParserException extends RuntimeException {}
