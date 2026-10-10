<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Errors;

/**
 * Raised when a schema mapping is malformed or a lookup in it is ambiguous (sqlglot SchemaError).
 */
class SchemaError extends SqlParserException {}
