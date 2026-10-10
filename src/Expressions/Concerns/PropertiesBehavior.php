<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Expressions\Concerns;

use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Expressions\Properties;
use LaravelSqlParser\LaravelSqlParser\Expressions\Property;

/**
 * @mixin Properties
 */
trait PropertiesBehavior
{
    /**
     * Build properties from a name => value map (sqlglot Properties.from_dict): known names
     * become their property node, others a generic 'name'=value property.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function fromDict(array $properties): static
    {
        $expressions = [];

        foreach ($properties as $key => $value) {
            /** @var class-string<Expression>|null $class */
            $class = self::NAME_TO_PROPERTY[strtoupper((string) $key)] ?? null;
            $expressions[] = $class !== null
                ? new $class(['this' => Builder::convert($value)])
                : new Property(['this' => Literal::string((string) $key), 'value' => Builder::convert($value)]);
        }

        return new static(['expressions' => $expressions]);
    }
}
