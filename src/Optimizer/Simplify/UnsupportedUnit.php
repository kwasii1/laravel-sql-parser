<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Optimizer\Simplify;

use RuntimeException;

/**
 * A date unit simplify can't do arithmetic with (sqlglot simplify.UnsupportedUnit).
 *
 * @internal
 */
final class UnsupportedUnit extends RuntimeException {}
