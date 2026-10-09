<?php

declare(strict_types=1);

namespace Tools\Generator;

use RuntimeException;

final class Expressions
{
    /**
     * sqlglot class names that are PHP reserved words, or otherwise unusable as PHP class names.
     */
    private const array RENAMES = [
        'And' => 'AndExpr',
        'Array' => 'ArrayExpr',
        'Case' => 'CaseExpr',
        'Clone' => 'CloneExpr',
        'Declare' => 'DeclareExpr',
        'Final' => 'FinalExpr',
        'If' => 'IfExpr',
        'List' => 'ListExpr',
        'Match' => 'MatchExpr',
        'Null' => 'NullExpr',
        'Or' => 'OrExpr',
        'Return' => 'ReturnExpr',
        'String' => 'StringExpr',
        'Try' => 'TryExpr',
        'Use' => 'UseExpr',
        'Var' => 'VarExpr',
        'Xor' => 'XorExpr',
        '_TimeUnit' => 'TimeUnitExpression',
        'Expr' => 'Expression',
    ];

    /** Defaults declared on the hand-written Expression base class. */
    private const array BASE_DEFAULTS = [
        'ARG_TYPES' => ['this' => true],
        'REQUIRED_ARGS' => ['this'],
        'IS_VAR_LEN_ARGS' => false,
        'VAR_LEN_ARG_KEY' => 'expressions',
        'IS_PRIMITIVE' => false,
        'HASH_RAW_ARGS' => false,
        'IS_SUBQUERY' => false,
        'IS_CAST' => false,
        'IS_DATA_TYPE' => false,
        'SQL_NAMES' => [],
    ];

    /** @var array<string, array<string, mixed>> sqlglot name => spec */
    private array $classes = [];

    /** @var list<array{0: string, 1: string}> */
    private array $dtypes;

    /**
     * @param  array<string, mixed>  $spec
     */
    public function __construct(array $spec)
    {
        foreach ($spec['expressions'] as $class) {
            $this->classes[$class['name']] = $class;
        }

        $this->dtypes = $spec['dtypes'];
    }

    public static function phpName(string $name): string
    {
        return self::RENAMES[$name] ?? $name;
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->classes as $name => $class) {
            if ($class['mixin'] || $name === 'Expression') {
                continue;
            }

            $files['src/Expressions/'.self::phpName($name).'.php'] = $this->classFile($class);
        }

        $files['src/Expressions/DType.php'] = $this->dtypeFile();
        $files['src/Expressions/ExpressionRegistry.php'] = $this->registryFile();

        return $files;
    }

    /**
     * @param  array<string, mixed>  $class
     */
    private function classFile(array $class): string
    {
        $namespace = Support::NAMESPACE.'\\Expressions';
        $php = self::phpName($class['name']);
        $bases = $class['bases'];
        $parent = self::phpName($bases[0]);

        if ($this->classes[$bases[0]]['mixin'] ?? false) {
            throw new RuntimeException("{$class['name']} has a mixin as its first base");
        }

        $interfaces = [];
        foreach (array_slice($bases, 1) as $base) {
            if (! ($this->classes[$base]['mixin'] ?? false)) {
                throw new RuntimeException("{$class['name']} has concrete secondary base {$base}");
            }
            $interfaces[] = self::phpName($base);
        }

        $traits = [];
        $introduced = array_diff($this->mixinClosure($class['name']), $this->mixinClosure($bases[0]));
        foreach ($introduced as $mixin) {
            if ($this->hasBehavior($mixin)) {
                $traits[] = 'Concerns\\'.self::phpName($mixin).'Behavior';
            }
        }

        if ($this->hasBehavior($class['name'])) {
            $traits[] = 'Concerns\\'.$php.'Behavior';
        }

        $constants = ['KEY' => $class['key']];
        $parentValues = $this->effectiveConstants($bases[0]);

        foreach ($this->effectiveConstants($class['name']) as $constant => $value) {
            if ($parentValues[$constant] !== $value) {
                $constants[$constant] = $value;
            }
        }

        $body = '';
        foreach ($traits as $trait) {
            $body .= "    use {$trait};\n";
        }
        $body .= $traits !== [] ? "\n" : '';

        foreach ($constants as $constant => $value) {
            $type = match (true) {
                is_bool($value) => 'bool',
                is_string($value) => 'string',
                default => 'array',
            };
            $body .= "    public const {$type} {$constant} = ".Support::export($value, 2).";\n\n";
        }

        foreach ($class['constants'] ?? [] as $constant => $value) {
            $decoded = $this->decodeConstant($value);

            if ($decoded === null) {
                continue;
            }
            $body .= "    public const array {$constant} = ".Support::export($decoded, 2).";\n\n";
        }

        $doc = trim((string) $class['doc']);
        $docblock = "/**\n";

        if ($doc !== '') {
            foreach (explode("\n", wordwrap(str_replace("\n", ' ', $doc), 96)) as $line) {
                $docblock .= ' * '.rtrim($line)."\n";
            }
            $docblock .= " *\n";
        }
        $docblock .= " * sqlglot: exp.{$class['name']} (expressions/{$class['module']}.py)\n */\n";

        sort($interfaces);
        $implements = $interfaces === [] ? '' : ' implements '.implode(', ', $interfaces);

        return Support::fileHeader($namespace, "sqlglot exp.{$class['name']}")."\n"
            .$docblock
            ."class {$php} extends {$parent}{$implements}\n{\n"
            .rtrim($body)."\n}\n";
    }

    /**
     * @return array<string, mixed>
     */
    private function effectiveConstants(string $name): array
    {
        if ($name === 'Expression') {
            return self::BASE_DEFAULTS;
        }

        $class = $this->classes[$name];
        $argTypes = [];
        foreach ($class['arg_types'] as [$arg, $required]) {
            $argTypes[$arg] = $required;
        }

        return [
            'ARG_TYPES' => $argTypes,
            'REQUIRED_ARGS' => array_keys(array_filter($argTypes)),
            'IS_VAR_LEN_ARGS' => $class['is_var_len_args'],
            'VAR_LEN_ARG_KEY' => $class['var_len_arg_key'],
            'IS_PRIMITIVE' => $class['is_primitive'],
            'HASH_RAW_ARGS' => $class['hash_raw_args'],
            'IS_SUBQUERY' => $class['is_subquery'],
            'IS_CAST' => $class['is_cast'],
            'IS_DATA_TYPE' => $class['is_data_type'],
            'SQL_NAMES' => $class['sql_names'] ?? [],
        ];
    }

    /**
     * All mixins a class implements, directly or through other mixins or its parents.
     *
     * @return list<string>
     */
    private function mixinClosure(string $name): array
    {
        if ($name === 'Expression' || ! isset($this->classes[$name])) {
            return [];
        }

        $out = [];
        foreach ($this->classes[$name]['bases'] as $base) {
            if ($this->classes[$base]['mixin'] ?? false) {
                if ($base !== 'Expr') {
                    $out[] = $base;
                }
            }
            array_push($out, ...$this->mixinClosure($base));
        }

        return array_values(array_unique($out));
    }

    private function hasBehavior(string $name): bool
    {
        return is_file(dirname(__DIR__, 2).'/src/Expressions/Concerns/'.self::phpName($name).'Behavior.php');
    }

    private function decodeConstant(mixed $value): mixed
    {
        if (is_array($value) && isset($value['@set'])) {
            $items = [];
            foreach ($value['@set'] as $item) {
                if (! is_array($item) || ! isset($item['@dtype'])) {
                    return null;
                }
                $items[] = $item['@dtype'];
            }
            sort($items);

            return $items;
        }

        if (is_array($value) && isset($value['@map'])) {
            $map = [];
            $fqcn = static fn (string $name): string => Support::NAMESPACE.'\\Expressions\\'.self::phpName($name);
            foreach ($value['@map'] as [$key, $item]) {
                if (is_array($key) && isset($key['@exp'])) {
                    $key = $fqcn($key['@exp']);
                } elseif (! is_string($key)) {
                    return null;
                }

                if (is_array($item) && isset($item['@exp'])) {
                    $item = $fqcn($item['@exp']);
                } elseif (! is_scalar($item)) {
                    return null;
                }
                $map[$key] = $item;
            }

            return $map;
        }

        return null;
    }

    private function dtypeFile(): string
    {
        $namespace = Support::NAMESPACE.'\\Expressions';
        $constants = '';
        $values = [];

        foreach ($this->dtypes as [$name, $value]) {
            $constants .= "    public const string {$name} = ".Support::exportString($name).";\n\n";
            $values[$name] = $value;
        }

        return Support::fileHeader($namespace, 'sqlglot DType')."\n"
            ."/**\n * Data type names, stored as the `this` arg of DataType nodes (sqlglot DType member names).\n */\n"
            ."final class DType\n{\n"
            .$constants
            ."    /**\n     * Default SQL spelling of each data type (sqlglot DType values).\n     */\n"
            .'    public const array VALUES = '.Support::export($values, 2).";\n}\n";
    }

    private function registryFile(): string
    {
        $namespace = Support::NAMESPACE.'\\Expressions';
        $byKey = [];
        $functions = [];

        foreach ($this->classes as $name => $class) {
            if ($class['mixin'] || $name === 'Expression') {
                continue;
            }

            $byKey[$class['key']] = self::phpName($name);

            if ($class['is_func']) {
                foreach ($class['sql_names'] as $sqlName) {
                    $functions[$sqlName] ??= self::phpName($name);
                }
            }
        }

        ksort($byKey);
        ksort($functions);

        $export = static function (array $map): string {
            $lines = [];
            foreach ($map as $key => $class) {
                $lines[] = '        '.Support::exportString((string) $key).' => '.$class.'::class,';
            }

            return "[\n".implode("\n", $lines)."\n    ]";
        };

        return Support::fileHeader($namespace, 'sqlglot expression classes')."\n"
            ."final class ExpressionRegistry\n{\n"
            ."    /**\n     * Expression classes keyed by their sqlglot key.\n     *\n     * @var array<string, class-string<Expression>>\n     */\n"
            .'    public const array BY_KEY = '.$export($byKey).";\n\n"
            ."    /**\n     * Function classes keyed by every SQL name they're known by (sqlglot FUNCTION_BY_NAME).\n     *\n     * @var array<string, class-string<Expression>>\n     */\n"
            .'    public const array FUNCTIONS = '.$export($functions).";\n}\n";
    }
}
