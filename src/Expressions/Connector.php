<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Expressions;

/**
 * Marks boolean connectors (AND, OR, XOR).
 *
 * sqlglot: exp.Connector (a mixin; PHP models it as an interface).
 */
interface Connector extends Binary {}
