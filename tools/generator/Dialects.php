<?php

declare(strict_types=1);

namespace Tools\Generator;

use RuntimeException;

final class Dialects
{
    /** @var array<string, array<string, mixed>> effective (inheritance-resolved) specs */
    private array $effective = [];

    /** @var array<string, true> lowercased method names of the parser / generator classes being generated */
    private array $ownMethods = [];

    /** @var array<string, array<string, true>> lowercased method names of every dialect's parser / generator */
    private array $allMethods = [];

    /** @var array<string, true> lowercased expression keys */
    private array $expressionKeys = [];

    /** @var array<string, true> lowercased camelCase names of the shared (sqlglot core) helper functions */
    private array $sharedFunctions = [];

    /** The section ('parser' or 'generator') whose tables are being generated. */
    private string $section = 'parser';

    /**
     * @param  array<string, array<string, mixed>>  $specs  raw specs; children only hold diffs
     * @param  array<string, string>  $enabled  sqlglot dialect name => PHP class name
     * @param  array<string, array{parser: list<string>, generator: list<string>}>  $methods  methods each sqlglot class defines
     * @param  list<string>  $expressionKeys  every expression class's key
     */
    public function __construct(private readonly array $specs, private readonly TokenTypes $tokens, private readonly array $enabled, private readonly array $methods = [], array $expressionKeys = [])
    {
        foreach ($expressionKeys as $key) {
            $this->expressionKeys[strtolower($key)] = true;
        }

        foreach ($specs as $spec) {
            foreach (['parser', 'generator'] as $section) {
                $this->collectSharedFunctions($spec[$section] ?? []);
            }
        }

        foreach ($methods as $sections) {
            foreach ($sections as $section => $names) {
                foreach ($names as $name) {
                    $this->allMethods[$section][strtolower(self::camel(ltrim($name, '_')))] = true;
                }
            }
        }
    }

    private const array SHARED_MODULES = ['sqlglot.parser', 'sqlglot.generator', 'sqlglot.dialects.dialect', 'sqlglot.transforms'];

    private function collectSharedFunctions(mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        if (isset($value['@fn']) && in_array($value['module'] ?? null, self::SHARED_MODULES, true)) {
            $function = explode('.', (string) $value['@fn'])[0];
            $this->sharedFunctions[strtolower(self::camel(ltrim($function, '_')))] = true;
        }

        foreach ($value as $item) {
            $this->collectSharedFunctions($item);
        }
    }

    /**
     * Lowercased PHP names of the methods a dialect's parser / generator class has (its own and inherited).
     *
     * @return array<string, true>
     */
    private function classMethods(string $name, string $section): array
    {
        $methods = [];

        for ($dialect = $name; $dialect !== null; $dialect = $this->specs[$dialect]['parent']) {
            foreach ($this->methods[$dialect][$section] ?? [] as $method) {
                $methods[strtolower(self::camel(ltrim($method, '_')))] = true;
            }
        }

        return $methods;
    }

    /**
     * @return array<string, mixed>
     */
    public function effective(string $name): array
    {
        if (isset($this->effective[$name])) {
            return $this->effective[$name];
        }

        $spec = $this->specs[$name] ?? throw new RuntimeException("Unknown dialect {$name}");

        if ($spec['parent'] === null) {
            return $this->effective[$name] = $spec;
        }

        $resolved = $this->effective($spec['parent']);
        $resolved['name'] = $spec['name'];
        $resolved['class'] = $spec['class'];
        $resolved['parent'] = $spec['parent'];

        foreach (['settings', 'tokenizer', 'jsonpath_tokenizer', 'parser', 'generator'] as $section) {
            foreach ($spec[$section] as $key => $value) {
                if ($key === '@removed') {
                    foreach ($value as $removed) {
                        unset($resolved[$section][$removed]);
                    }

                    continue;
                }

                $resolved[$section][$key] = $this->applyPatch($resolved[$section][$key] ?? null, $value);
            }
        }

        return $this->effective[$name] = $resolved;
    }

    private function applyPatch(mixed $base, mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('@patch', $value)) {
            $entries = [];
            foreach ($base['@map'] ?? [] as [$key, $item]) {
                $entries[json_encode($key)] = [$key, $item];
            }
            foreach ($value['@delete'] ?? [] as $key) {
                unset($entries[json_encode($key)]);
            }
            foreach ($value['@patch'] as [$key, $item]) {
                $entries[json_encode($key)] = [$key, $item];
            }

            return ['@map' => array_values($entries)];
        }

        if (is_array($value) && array_key_exists('@add', $value)) {
            $items = [];
            foreach ($base['@set'] ?? [] as $item) {
                $items[json_encode($item)] = $item;
            }
            foreach ($value['@remove'] as $item) {
                unset($items[json_encode($item)]);
            }
            foreach ($value['@add'] as $item) {
                $items[json_encode($item)] = $item;
            }

            return ['@set' => array_values($items)];
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function files(): array
    {
        $files = [];

        foreach ($this->enabled as $name => $class) {
            [$path, $namespace, $tablesClass] = $this->location($name, $class, 'Tokenizer');
            $files[$path] = $this->tokenizerTables($name, $namespace, $tablesClass);

            [$path, $namespace, $tablesClass] = $this->location($name, $class, 'JsonPathTokenizer');
            $files[$path] = $this->tokenizerTables($name, $namespace, $tablesClass, 'jsonpath_tokenizer');

            [$path, $namespace, $tablesClass] = $this->location($name, $class, 'Dialect');
            $files[$path] = $this->settingsTables($name, $namespace, $tablesClass);

            [$path, $namespace, $tablesClass] = $this->location($name, $class, 'Parser');
            $files[$path] = $this->parserTables($name, $namespace, $tablesClass);

            [$path, $namespace, $tablesClass] = $this->location($name, $class, 'Generator');
            $files[$path] = $this->parserTables($name, $namespace, $tablesClass, 'generator');
        }

        return $files;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function location(string $name, string $class, string $component): array
    {
        if ($name === 'base') {
            $directory = match ($component) {
                'Dialect' => 'Dialects',
                'JsonPathTokenizer' => 'Tokenizer',
                default => $component,
            };

            return ["src/{$directory}/{$component}Tables.php", Support::NAMESPACE.'\\'.$directory, "{$component}Tables"];
        }

        return [
            "src/Dialects/{$class}/{$class}{$component}Tables.php",
            Support::NAMESPACE."\\Dialects\\{$class}",
            "{$class}{$component}Tables",
        ];
    }

    private function decode(mixed $value): mixed
    {
        return Support::decode($value, $this->tokens);
    }

    /**
     * @param  list<mixed>  $items
     * @return array<array-key, true>
     */
    private static function set(array $items): array
    {
        $set = [];
        foreach ($items as $item) {
            $set[$item] = true;
        }
        ksort($set);

        return $set;
    }

    private function tokenizerTables(string $name, string $namespace, string $class, string $section = 'tokenizer'): string
    {
        $spec = $this->effective($name);
        $tokenizer = $spec[$section];
        $settings = $spec['settings'];

        $singleTokens = $this->decode($tokenizer['SINGLE_TOKENS']);
        $keywords = $this->decode($tokenizer['KEYWORDS']);
        $quotes = $this->decode($tokenizer['_QUOTES']);
        $identifiers = $this->decode($tokenizer['_IDENTIFIERS']);
        $formatStrings = $this->decode($tokenizer['_FORMAT_STRINGS']);
        $comments = $this->decode($tokenizer['_COMMENTS']);
        $varSingleTokens = self::set($this->decode($tokenizer['VAR_SINGLE_TOKENS']));

        ksort($keywords);
        ksort($singleTokens);

        $trieKeys = [];
        foreach ([...array_keys($keywords), ...array_keys($comments), ...array_keys($quotes), ...array_keys($formatStrings)] as $key) {
            $key = (string) $key;

            if (str_contains($key, ' ') || strpbrk($key, implode('', array_map('strval', array_keys($singleTokens)))) !== false) {
                $trieKeys[strtoupper($key)] = true;
            }
        }
        ksort($trieKeys);

        $trie = [];
        foreach (array_keys($trieKeys) as $key) {
            $node = &$trie;
            foreach (str_split((string) $key) as $char) {
                $node[$char] ??= [];
                $node = &$node[$char];
            }
            $node[''] = true;
            unset($node);
        }

        // Bytes that end an unquoted word: single tokens that can't appear inside a var, plus ASCII
        // whitespace (Python's str.isspace() also counts \x1C-\x1F).
        $varStops = " \t\n\r\v\f\x1C\x1D\x1E\x1F";
        foreach (array_keys($singleTokens) as $char) {
            if (! isset($varSingleTokens[$char])) {
                $varStops .= $char;
            }
        }

        $trieStarts = [];
        foreach (array_keys($trie) as $char) {
            $trieStarts[(string) $char] = true;
            $trieStarts[strtolower((string) $char)] = true;
        }
        ksort($trieStarts);

        $tokenComment = fn (array $map): array => array_map(fn (int $type): string => $this->tokens->name($type), array_filter($map, 'is_int'));

        $numericEscapes = $this->decode($tokenizer['NUMERIC_ESCAPES']);
        $formatStringComments = array_map(fn (array $entry): string => $this->tokens->name($entry[1]), $formatStrings);

        $tables = [
            'SINGLE_TOKENS' => [$singleTokens, $tokenComment($singleTokens)],
            'KEYWORDS' => [$keywords, $tokenComment($keywords)],
            'QUOTES' => [$quotes, []],
            'IDENTIFIERS' => [$identifiers, []],
            'FORMAT_STRINGS' => [$formatStrings, $formatStringComments],
            'STRING_ESCAPES' => [self::set($this->decode($tokenizer['_STRING_ESCAPES'])), []],
            'STRING_ESCAPES_LIST' => [$this->decode($tokenizer['STRING_ESCAPES']), []],
            'BYTE_STRING_ESCAPES' => [self::set($this->decode($tokenizer['_BYTE_STRING_ESCAPES'])), []],
            'IDENTIFIER_ESCAPES' => [self::set($this->decode($tokenizer['_IDENTIFIER_ESCAPES'])), []],
            'ESCAPE_FOLLOW_CHARS' => [self::set($this->decode($tokenizer['_ESCAPE_FOLLOW_CHARS'])), []],
            'COMMENTS' => [$comments, []],
            'COMMANDS' => [$commands = self::set($this->decode($tokenizer['COMMANDS'])), $this->names($commands)],
            'COMMAND_PREFIX_TOKENS' => [$prefix = self::set($this->decode($tokenizer['COMMAND_PREFIX_TOKENS'])), $this->names($prefix)],
            'TOKENS_PRECEDING_HINT' => [$hint = self::set($this->decode($tokenizer['TOKENS_PRECEDING_HINT'])), $this->names($hint)],
            'NUMERIC_LITERALS' => [$this->decode($tokenizer['NUMERIC_LITERALS']), []],
            'VAR_SINGLE_TOKENS' => [$varSingleTokens, []],
            'NUMERIC_ESCAPES' => [$numericEscapes, []],
            'UNESCAPED_SEQUENCES' => [$this->decode($settings['UNESCAPED_SEQUENCES']), []],
            'KEYWORD_TRIE' => [$trie, []],
            'KEYWORD_TRIE_STARTS' => [$trieStarts, []],
        ];

        $scalars = [
            'NESTED_COMMENTS' => $tokenizer['NESTED_COMMENTS'],
            'HINT_START' => $tokenizer['HINT_START'],
            'HAS_BIT_STRINGS' => $this->decode($tokenizer['BIT_STRINGS']) !== [],
            'HAS_HEX_STRINGS' => $this->decode($tokenizer['HEX_STRINGS']) !== [],
            'STRING_ESCAPES_ALLOWED_IN_RAW_STRINGS' => $tokenizer['STRING_ESCAPES_ALLOWED_IN_RAW_STRINGS'],
            'HEREDOC_TAG_IS_IDENTIFIER' => $tokenizer['HEREDOC_TAG_IS_IDENTIFIER'],
            'HEREDOC_STRING_ALTERNATIVE' => $this->decode($tokenizer['HEREDOC_STRING_ALTERNATIVE']),
            'NUMBERS_CAN_BE_UNDERSCORE_SEPARATED' => $settings['NUMBERS_CAN_BE_UNDERSCORE_SEPARATED'],
            'NUMBERS_CAN_HAVE_DECIMALS' => $tokenizer['NUMBERS_CAN_HAVE_DECIMALS'],
            'IDENTIFIERS_CAN_START_WITH_DIGIT' => $settings['IDENTIFIERS_CAN_START_WITH_DIGIT'],
            'DROP_UNKNOWN_ESCAPES' => $tokenizer['DROP_UNKNOWN_ESCAPES'],
            'VAR_STOP_CHARS' => $varStops,
        ];

        if (isset($tokenizer['VAR_TOKENS'])) {
            $tables['VAR_TOKENS'] = [$varTokens = self::set($this->decode($tokenizer['VAR_TOKENS'])), $this->names($varTokens)];
        }

        $body = '';
        foreach ($scalars as $constant => $value) {
            $type = is_bool($value) ? 'bool' : (is_int($value) ? 'int' : 'string');
            $body .= "    public const {$type} {$constant} = ".Support::export($value, 2).";\n\n";
        }
        foreach ($tables as $constant => [$value, $comments]) {
            $body .= "    public const array {$constant} = ".Support::export($value, 2, $comments).";\n\n";
        }

        $extends = $name === 'base' ? '' : '';

        return Support::fileHeader($namespace, "sqlglot {$tokenizer['class']} ({$name})")."\n"
            ."/**\n * Literal ".($section === 'tokenizer' ? 'tokenizer' : 'JSONPath tokenizer')." settings for the {$name} dialect.\n */\n"
            ."final class {$class}{$extends}\n{\n"
            .rtrim($body)."\n}\n";
    }

    /** Method-name prefixes for handlers derived from lambdas in sqlglot's parser dispatch tables. */
    private const array HANDLER_PREFIXES = [
        'FUNCTIONS' => 'build',
        'STATEMENT_PARSERS' => 'statement',
        'EXPRESSION_PARSERS' => 'parseInto',
        'UNARY_PARSERS' => 'unary',
        'STRING_PARSERS' => 'string',
        'NUMERIC_PARSERS' => 'numeric',
        'PRIMARY_PARSERS' => 'primary',
        'PLACEHOLDER_PARSERS' => 'placeholder',
        'RANGE_PARSERS' => 'range',
        'PIPE_SYNTAX_TRANSFORM_PARSERS' => 'pipe',
        'PROPERTY_PARSERS' => 'property',
        'CONSTRAINT_PARSERS' => 'constraint',
        'ALTER_PARSERS' => 'alter',
        'ALTER_ALTER_PARSERS' => 'alterAlter',
        'NO_PAREN_FUNCTION_PARSERS' => 'noParenFunction',
        'FUNCTION_PARSERS' => 'func',
        'QUERY_MODIFIER_PARSERS' => 'modifier',
        'SET_PARSERS' => 'set',
        'SHOW_PARSERS' => 'show',
        'TYPE_LITERAL_PARSERS' => 'typeLiteral',
        'TYPE_CONVERTERS' => 'convertType',
        'COLUMN_OPERATORS' => 'columnOp',
        'JSON_OPERATORS' => 'jsonOp',
        'LAMBDAS' => 'lambda',
        'ANALYZE_EXPRESSION_PARSERS' => 'analyze',
        'TRANSFORMS' => 'transform',
        'AFTER_HAVING_MODIFIER_TRANSFORMS' => 'afterHaving',
    ];

    /**
     * Tables whose handlers are called without arguments. Only here can a lambda that just calls
     * `self._parse_x()` be replaced by a direct reference to parseX(): elsewhere the dispatcher
     * passes arguments (a token, the left operand, ...) that parseX() might misread.
     */
    private const array NO_ARGUMENT_TABLES = [
        'STATEMENT_PARSERS', 'EXPRESSION_PARSERS', 'UNARY_PARSERS', 'PLACEHOLDER_PARSERS',
        'PROPERTY_PARSERS', 'CONSTRAINT_PARSERS', 'ALTER_PARSERS', 'ALTER_ALTER_PARSERS',
        'NO_PAREN_FUNCTION_PARSERS', 'FUNCTION_PARSERS', 'SET_PARSERS', 'SHOW_PARSERS',
        'ANALYZE_EXPRESSION_PARSERS',
    ];

    /** Parser attributes rebuilt here (tries) or not needed in PHP. */
    private const array SKIPPED_PARSER_ATTRIBUTES = ['SHOW_TRIE', 'SET_TRIE'];

    private function parserTables(string $name, string $namespace, string $class, string $section = 'parser'): string
    {
        $spec = $this->effective($name);
        $parent = $spec['parent'] !== null ? $this->effective($spec['parent']) : null;
        $this->ownMethods = $this->classMethods($name, $section);
        $this->section = $section;
        $body = '';
        $handlers = [];
        $overridden = [];
        $skipped = [];

        foreach ($spec[$section] as $attribute => $value) {
            if ($attribute === 'class' || in_array($attribute, self::SKIPPED_PARSER_ATTRIBUTES, true)) {
                continue;
            }

            if (self::isDispatchTable($value)) {
                $table = [];
                $comments = [];
                $parentEntries = [];
                foreach ($parent[$section][$attribute]['@map'] ?? [] as [$key, $entry]) {
                    $parentEntries[json_encode($key)] = $entry;
                }

                foreach ($value['@map'] as [$key, $entry]) {
                    $phpKey = $this->literal($key);

                    if (! is_int($phpKey) && ! is_string($phpKey)) {
                        throw new RuntimeException("{$name} {$attribute}: unsupported key ".json_encode($key));
                    }

                    $handler = $this->handler($attribute, $key, $entry);
                    $table[$phpKey] = $handler;

                    if (is_int($phpKey) && isset($key['@token'])) {
                        $comments[$phpKey] = $key['@token'];
                    }

                    $method = is_array($handler) ? $handler[0] : $handler;

                    if (is_string($method) && ! str_contains($method, '\\')) {
                        $handlers[$method] = true;

                        $parentEntry = $parentEntries[json_encode($key)] ?? null;

                        if ($parent !== null && $parentEntry !== $entry && self::isLambda($entry) && ! is_array($handler) && ! str_starts_with($method, 'parse')) {
                            $overridden[$method] = true;
                        }
                    }
                }

                $body .= "    public const array {$attribute} = ".Support::export($table, 2, $comments).";\n\n";

                continue;
            }

            $literal = $this->literal($value);

            if ($literal === self::NOT_LITERAL) {
                $skipped[] = $attribute;

                continue;
            }

            $body .= $this->constant($attribute, $literal);
        }

        if ($section === 'parser') {
            foreach (['SHOW_PARSERS' => 'SHOW_TRIE', 'SET_PARSERS' => 'SET_TRIE'] as $source => $trie) {
                $words = [];
                foreach ($spec['parser'][$source]['@map'] ?? [] as [$key]) {
                    $words[] = explode(' ', (string) $key);
                }
                $body .= $this->constant($trie, self::wordTrie($words));
            }
        }

        ksort($handlers);
        ksort($overridden);
        $body .= "    /**\n     * Every handler method the dispatch tables above refer to.\n     */\n";
        $body .= '    public const array HANDLERS = '.Support::export($handlers, 2).";\n\n";
        $body .= "    /**\n     * Handlers whose sqlglot lambda differs from the parent dialect's, so this dialect's parser\n     * must declare them itself.\n     */\n";
        $body .= '    public const array OVERRIDDEN_HANDLERS = '.Support::export($overridden, 2).";\n\n";

        if ($skipped !== []) {
            fwrite(STDERR, "{$name} {$section} attributes skipped (not literal): ".implode(', ', $skipped)."\n");
        }

        return Support::fileHeader($namespace, "sqlglot {$spec[$section]['class']} ({$name})")."\n"
            ."/**\n * Literal {$section} tables for the {$name} dialect.\n *\n"
            ." * Dispatch tables map a token type or keyword to a handler: a method name, a list of\n"
            ." * [method, ...extra arguments], or (FUNCTIONS only) a Func class built with fromArgList().\n */\n"
            ."final class {$class}\n{\n"
            .rtrim($body)."\n}\n";
    }

    private static function isDispatchTable(mixed $value): bool
    {
        if (! is_array($value) || ! array_key_exists('@map', $value)) {
            return false;
        }

        foreach ($value['@map'] as [, $entry]) {
            if (is_array($entry) && isset($entry['@fn'])) {
                return true;
            }
        }

        return false;
    }

    private static function isLambda(mixed $entry): bool
    {
        return is_array($entry) && isset($entry['@fn']) && str_ends_with((string) $entry['@fn'], '<lambda>');
    }

    /**
     * The PHP handler for one dispatch table entry.
     *
     * @return string|list<mixed>|null
     */
    private function handler(string $table, mixed $key, mixed $entry): string|array|null
    {
        if ($entry === null) {
            return null;
        }

        if (! is_array($entry) || ! isset($entry['@fn'])) {
            $literal = $this->literal($entry);

            if (is_string($literal)) {
                return $literal;
            }

            throw new RuntimeException("{$table}: unsupported handler ".json_encode($entry));
        }

        $fn = (string) $entry['@fn'];

        if ($fn === 'Func.from_arg_list' && isset($entry['bound'])) {
            return Support::NAMESPACE.'\\Expressions\\'.Expressions::phpName($entry['bound']);
        }

        $closure = $entry['closure'] ?? [];

        if (is_array($closure) && isset($closure['diagnostic_by_arg'], $closure['func'])) {
            // A handler wrapped in sqlglot's @unsupported_args(...) decorator
            $diagnostics = [];
            foreach ($closure['diagnostic_by_arg']['@map'] ?? [] as [$arg, $diagnostic]) {
                $diagnostics[(string) $arg] = $diagnostic;
            }

            return ['unsupportedArgsHandler', 'diagnosticByArg' => $diagnostics, 'func' => $this->handler($table, $key, $closure['func'])];
        }

        $parts = explode('.', $fn);

        if (count($parts) === 3 && $parts[1] === '<locals>' && $parts[2] === '<lambda>') {
            // A lambda returned by a module-level factory, e.g. rename_func("X")
            $handler = [$this->functionName($parts[0], (string) ($entry['module'] ?? ''))];

            foreach ($closure as $variable => $value) {
                $handler[self::camel((string) $variable)] = $this->closureValue($table, $key, (string) $variable, $value);
            }

            return $handler;
        }

        if (str_ends_with($fn, '<lambda>')) {
            $source = (string) ($entry['source'] ?? '');

            if (in_array($table, self::NO_ARGUMENT_TABLES, true) && preg_match('/^lambda [^:]*:\s*self\.(_parse_[a-z0-9_]+)\(\)\s*,?\s*(#.*)?$/s', trim(self::lambdaBody($source)), $m) === 1) {
                return self::camel($m[1]);
            }

            $prefix = self::HANDLER_PREFIXES[$table] ?? self::camel(strtolower($table));

            return $prefix.ucfirst(self::camel($this->keyName($key)));
        }

        if (str_contains($fn, '<locals>')) {
            $outer = $parts[0] === '<locals>' ? '' : $parts[count($parts) - 3] ?? $parts[0];
            $inner = end($parts);
            $method = str_starts_with((string) $inner, '_parse_') ? self::camel((string) $inner) : $this->functionName($outer, (string) ($entry['module'] ?? ''));
            $handler = [$method];

            // Closure variables become named arguments, so PHP signatures don't depend on order.
            foreach ($entry['closure'] ?? [] as $variable => $value) {
                $handler[self::camel((string) $variable)] = $this->closureValue($table, $key, (string) $variable, $value);
            }

            return $handler;
        }

        // Class methods (e.g. Generator.foo_sql) keep their name; module functions may need renaming.
        return count($parts) > 1 ? self::camel((string) end($parts)) : $this->functionName((string) end($parts), (string) ($entry['module'] ?? ''));
    }

    /**
     * The PHP method name for a module-level helper function (sqlglot's dialect.py, transforms.py or
     * a dialect module). PHP method names are case-insensitive and the generator dispatches on
     * `<key>Sql` methods, so a helper that would clash with a method of the class (e.g.
     * dialect.trim_sql vs Generator.trim_sql, mysql.date_add_sql vs Generator.dateadd_sql) or look
     * like a dispatch method (dialect.timestamptrunc_sql) gets a "Handler" suffix. Helpers shared
     * by every dialect (dialect.py, transforms.py) are checked against every dialect's methods.
     */
    private function functionName(string $function, string $module): string
    {
        $method = self::camel(ltrim($function, '_'));
        $lower = strtolower($method);
        $shared = in_array($module, self::SHARED_MODULES, true);

        if (! $shared && isset($this->sharedFunctions[$lower])) {
            // A dialect module's private helper named like a shared one, e.g. sqlite's
            // _build_json_extract vs parser.build_json_extract: suffix the dialect's name
            return $method.ucfirst(self::camel((string) substr(strrchr($module, '.') ?: $module, 1)));
        }

        $clashes = $shared ? isset($this->allMethods[$this->section][$lower]) : isset($this->ownMethods[$lower]);

        if (! $clashes && $this->section === 'generator' && str_ends_with($lower, 'sql')) {
            $clashes = isset($this->expressionKeys[substr($lower, 0, -3)]);
        }

        return $clashes ? $method.'Handler' : $method;
    }

    private function closureValue(string $table, mixed $key, string $variable, mixed $value): mixed
    {
        if (is_array($value) && isset($value['@fn'])) {
            return $this->handler($table, $this->keyName($key).'_'.$variable, $value);
        }

        if (is_array($value) && array_is_list($value) && $value !== [] && isset($value[0]['@fn'])) {
            return array_map(fn (mixed $item): mixed => $this->closureValue($table, $key, $variable, $item), $value);
        }

        $literal = $this->literal($value);

        if ($literal === self::NOT_LITERAL) {
            throw new RuntimeException("{$table}: closure variable {$variable} isn't literal: ".json_encode($value));
        }

        return $literal;
    }

    /** The lambda part of a dict entry's source line, e.g. `TokenType.X: lambda self: ...`. */
    private static function lambdaBody(string $source): string
    {
        $position = strpos($source, 'lambda ');

        return $position === false ? $source : substr($source, $position);
    }

    private function keyName(mixed $key): string
    {
        if (is_array($key)) {
            return (string) ($key['@token'] ?? $key['@exp'] ?? $key['@dtype'] ?? json_encode($key));
        }

        return (string) $key;
    }

    private static function camel(string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', trim($name, '_'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($words as $i => $word) {
            $word = ctype_upper(str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], '', $word)) || $word === strtoupper($word) ? strtolower($word) : $word;
            $out .= $i === 0 ? lcfirst($word) : ucfirst($word);
        }

        return $out;
    }

    /**
     * A trie over multi-word keys; each node maps an uppercase word to a child, '' marks an end.
     *
     * @param  list<list<string>>  $keys
     * @return array<array-key, mixed>
     */
    private static function wordTrie(array $keys): array
    {
        $trie = [];

        foreach ($keys as $words) {
            $node = &$trie;
            foreach ($words as $word) {
                $node[$word] ??= [];
                $node = &$node[$word];
            }
            $node[''] = true;
            unset($node);
        }

        return $trie;
    }

    /** Settings that only matter to the optimizer, or are rebuilt here from other settings. */
    private const array SKIPPED_SETTINGS = [
        'COERCES_TO', 'EXPRESSION_METADATA', 'TIME_TRIE', 'FORMAT_TRIE', 'INVERSE_TIME_TRIE',
        'INVERSE_FORMAT_TRIE', 'SUPPORTED_SETTINGS',
    ];

    private function settingsTables(string $name, string $namespace, string $class): string
    {
        $spec = $this->effective($name);
        $body = '';
        $skipped = [];

        foreach ($spec['settings'] as $constant => $value) {
            if (in_array($constant, self::SKIPPED_SETTINGS, true)) {
                continue;
            }

            $literal = $this->literal($value);

            if ($literal === self::NOT_LITERAL) {
                $skipped[] = $constant;

                continue;
            }

            $body .= $this->constant($constant, $literal);
        }

        foreach (['TIME_MAPPING', 'FORMAT_MAPPING', 'INVERSE_TIME_MAPPING', 'INVERSE_FORMAT_MAPPING'] as $mapping) {
            $keys = array_map('strval', array_keys($this->literal($spec['settings'][$mapping]) ?: []));
            $body .= $this->constant(str_replace('MAPPING', 'TRIE', $mapping), self::trie($keys));
        }

        if ($skipped !== []) {
            fwrite(STDERR, "{$name} dialect settings skipped (not literal): ".implode(', ', $skipped)."\n");
        }

        return Support::fileHeader($namespace, "sqlglot {$spec['class']} dialect settings ({$name})")."\n"
            ."/**\n * Literal dialect settings for the {$name} dialect.\n */\n"
            ."final class {$class}\n{\n"
            .rtrim($body)."\n}\n";
    }

    private const string NOT_LITERAL = "\0not-literal";

    /**
     * Convert a tagged spec value into a literal PHP value, or NOT_LITERAL if it holds callables
     * or anything else that can't live in a literal constant. Sets become [item => true] maps.
     */
    private function literal(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            // Tuples of token types are only ever used for membership tests: emit them as sets.
            if ($value !== [] && array_filter($value, static fn (mixed $item): bool => ! is_array($item) || ! isset($item['@token'])) === []) {
                return $this->literal(['@set' => $value]);
            }

            $out = [];
            foreach ($value as $item) {
                $item = $this->literal($item);

                if ($item === self::NOT_LITERAL) {
                    return self::NOT_LITERAL;
                }
                $out[] = $item;
            }

            return $out;
        }

        if (isset($value['@fn']) || isset($value['@repr'])) {
            return self::NOT_LITERAL;
        }

        if (isset($value['@token'])) {
            return $this->tokens->value($value['@token']);
        }

        if (isset($value['@dtype'])) {
            return $value['@dtype'];
        }

        if (isset($value['@enum'])) {
            return substr($value['@enum'], strpos($value['@enum'], '.') + 1);
        }

        if (isset($value['@exp'])) {
            return Support::NAMESPACE.'\\Expressions\\'.Expressions::phpName($value['@exp']);
        }

        if (array_key_exists('@set', $value)) {
            $set = [];
            foreach ($value['@set'] as $item) {
                $item = $this->literal($item);

                if (! is_int($item) && ! is_string($item)) {
                    return self::NOT_LITERAL;
                }
                $set[$item] = true;
            }
            ksort($set);

            return $set;
        }

        if (array_key_exists('@map', $value)) {
            $map = [];
            foreach ($value['@map'] as [$key, $item]) {
                $key = $this->literal($key);
                $item = $this->literal($item);

                if ((! is_int($key) && ! is_string($key)) || $item === self::NOT_LITERAL) {
                    return self::NOT_LITERAL;
                }
                $map[$key] = $item;
            }

            return $map;
        }

        return self::NOT_LITERAL;
    }

    private function constant(string $name, mixed $value): string
    {
        $type = match (true) {
            $value === null => '?string',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            default => 'array',
        };

        return "    public const {$type} {$name} = ".Support::export($value, 2).";\n\n";
    }

    /**
     * A character trie with '' marking the end of a key.
     *
     * @param  list<string>  $keys
     * @return array<array-key, mixed>
     */
    private static function trie(array $keys): array
    {
        sort($keys);
        $trie = [];

        foreach ($keys as $key) {
            $node = &$trie;
            foreach (str_split($key) as $char) {
                $node[$char] ??= [];
                $node = &$node[$char];
            }
            $node[''] = true;
            unset($node);
        }

        return $trie;
    }

    /**
     * @param  array<int, true>  $set
     * @return array<int, string>
     */
    private function names(array $set): array
    {
        $names = [];
        foreach (array_keys($set) as $type) {
            $names[$type] = $this->tokens->name($type);
        }

        return $names;
    }
}
