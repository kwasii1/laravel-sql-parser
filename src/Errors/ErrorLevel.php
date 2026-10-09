<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Errors;

/**
 * How the parser and generator react to errors.
 */
enum ErrorLevel: string
{
    /** Ignore all errors. */
    case Ignore = 'ignore';

    /** Collect errors as warnings and carry on. */
    case Warn = 'warn';

    /** Collect errors and raise a single exception with all of them at the end. */
    case Raise = 'raise';

    /** Raise immediately on the first error. */
    case Immediate = 'immediate';
}
