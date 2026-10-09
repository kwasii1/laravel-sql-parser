<?php

declare(strict_types=1);

use LaravelSqlParser\LaravelSqlParser\Tests\TestCase;

/*
 * Only feature tests boot Laravel. Unit tests exercise the framework-agnostic core and run
 * as plain PHP, which keeps the thousands of fixture-driven cases fast.
 */
uses(TestCase::class)->in('Feature');
