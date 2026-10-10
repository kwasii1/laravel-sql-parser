<?php

declare(strict_types=1);

namespace Tools\Generator;

use RuntimeException;

final class TokenTypes
{
    /** @var array<string, int> */
    private array $values = [];

    /**
     * @param  array<string, mixed>  $spec
     */
    public function __construct(array $spec)
    {
        foreach ($spec['token_types'] as $position => $name) {
            $this->values[$name] = $position + 1;
        }
    }

    public function value(string $name): int
    {
        return $this->values[$name] ?? throw new RuntimeException("Unknown token type {$name}");
    }

    public function name(int $value): string
    {
        $name = array_search($value, $this->values, true);

        if ($name === false) {
            throw new RuntimeException("Unknown token type value {$value}");
        }

        return $name;
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        $namespace = Support::NAMESPACE.'\\Tokenizer';
        $constants = '';

        foreach ($this->values as $name => $value) {
            $constants .= "    public const int {$name} = {$value};\n\n";
        }

        $names = Support::export(array_flip($this->values), 2);

        $php = Support::fileHeader($namespace, 'sqlglot TokenType')."\n"
            ."/**\n * Token type identifiers. Plain ints (not an enum) so they can be used as array keys.\n */\n"
            ."final class TokenType\n{\n"
            .$constants
            ."    /**\n     * Token type names keyed by value, for error messages and debugging.\n     */\n"
            ."    public const array NAMES = {$names};\n\n"
            ."    public static function name(int \$type): string\n    {\n        return self::NAMES[\$type] ?? 'UNKNOWN';\n    }\n}\n";

        return ['src/Tokenizer/TokenType.php' => $php];
    }
}
