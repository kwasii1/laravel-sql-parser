<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Dialects;

use InvalidArgumentException;
use LaravelSqlParser\LaravelSqlParser\Errors\ErrorLevel;
use LaravelSqlParser\LaravelSqlParser\Errors\ParseError;
use LaravelSqlParser\LaravelSqlParser\Errors\TokenError;
use LaravelSqlParser\LaravelSqlParser\Expressions\Block;
use LaravelSqlParser\LaravelSqlParser\Expressions\Builder;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Expressions\Func;
use LaravelSqlParser\LaravelSqlParser\Expressions\Identifier;
use LaravelSqlParser\LaravelSqlParser\Expressions\Literal;
use LaravelSqlParser\LaravelSqlParser\Generator\Generator;
use LaravelSqlParser\LaravelSqlParser\Parser\Parser;
use LaravelSqlParser\LaravelSqlParser\Support\JsonPath;
use LaravelSqlParser\LaravelSqlParser\Support\TimeFormat;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\JsonPathTokenizerTables;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\Token;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\Tokenizer;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenizerTables;
use ReflectionClass;

/**
 * A SQL dialect: ties a tokenizer, parser and generator together with the dialect's settings
 * (sqlglot: Dialect). This base class is sqlglot's default dialect.
 *
 * Get instances through Dialect::get(), which caches one instance per dialect.
 */
class Dialect
{
    /** sqlglot's name for the dialect ('' for the base dialect). */
    public const string NAME = '';

    /** @var class-string generated settings tables */
    public const string SETTINGS = DialectTables::class;

    /** @var class-string generated tokenizer tables */
    public const string TOKENIZER_TABLES = TokenizerTables::class;

    /** @var class-string generated JSONPath tokenizer tables */
    public const string JSON_PATH_TOKENIZER_TABLES = JsonPathTokenizerTables::class;

    /** @var class-string<Parser> */
    public const string PARSER = Parser::class;

    /** @var class-string<Generator> */
    public const string GENERATOR = Generator::class;

    /**
     * Built-in dialects keyed by name and alias.
     *
     * @var array<string, class-string<Dialect>>
     */
    public const array DIALECTS = [
        '' => Dialect::class,
        'base' => Dialect::class,
        'mysql' => MySql\MySql::class,
        'mariadb' => MariaDb\MariaDb::class,
        'postgres' => Postgres\Postgres::class,
        'postgresql' => Postgres\Postgres::class,
        'pgsql' => Postgres\Postgres::class,
        'sqlite' => Sqlite\Sqlite::class,
        'tsql' => TSql\TSql::class,
        'sqlsrv' => TSql\TSql::class,
        'sqlserver' => TSql\TSql::class,
        'mssql' => TSql\TSql::class,
    ];

    /** @var array<string, class-string<Dialect>> */
    private static array $registered = [];

    /** @var array<class-string<Dialect>, Dialect> */
    private static array $instances = [];

    /** @var array<class-string, array<string, mixed>> */
    private static array $settingsCache = [];

    /** @var array<string, mixed> every constant of static::SETTINGS */
    public readonly array $settings;

    /**
     * The targeted database version (sqlglot's `version` dialect setting); unset means the latest.
     *
     * @var array{0: int, 1: int, 2: int}
     */
    public array $version = [PHP_INT_MAX, 0, 0];

    /** Overrides the NORMALIZATION_STRATEGY setting (sqlglot's `normalization_strategy` dialect setting). */
    public ?string $normalizationStrategy = null;

    private ?Tokenizer $tokenizer = null;

    private ?Tokenizer $jsonPathTokenizer = null;

    /** @var array<string, Parser> */
    private array $parsers = [];

    final public function __construct()
    {
        $this->settings = self::$settingsCache[static::SETTINGS] ??= (new ReflectionClass(static::SETTINGS))->getConstants();
    }

    /**
     * Resolve a dialect by name, alias, class or instance; null means the base dialect.
     */
    public static function get(string|self|null $dialect = null): self
    {
        if ($dialect instanceof self) {
            return $dialect;
        }

        $name = strtolower(trim($dialect ?? ''));
        $class = self::$registered[$name] ?? self::DIALECTS[$name] ?? null;

        if ($class === null && is_subclass_of($dialect ?? '', self::class)) {
            $class = $dialect;
        }

        if ($class === null) {
            throw new InvalidArgumentException("Unknown dialect '{$name}'.");
        }

        return self::$instances[$class] ??= new $class;
    }

    /**
     * Register a custom dialect class under a name.
     *
     * @param  class-string<Dialect>  $class
     */
    public static function register(string $name, string $class): void
    {
        if (! is_subclass_of($class, self::class) && $class !== self::class) {
            throw new InvalidArgumentException("{$class} must extend ".self::class.'.');
        }

        self::$registered[strtolower($name)] = $class;
        unset(self::$instances[$class]);
    }

    /**
     * Transpile SQL from one dialect to another; returns one string per statement.
     *
     * @param  array<string, mixed>  $options  generator options (pretty, identify, ...)
     * @return list<string>
     */
    public static function transpile(string $sql, string|self|null $read = null, string|self|null $write = null, array $options = [], ErrorLevel $errorLevel = ErrorLevel::Immediate): array
    {
        $reader = self::get($read);
        $writer = $write === null ? $reader : self::get($write);
        $out = [];

        foreach ($reader->parse($sql, $errorLevel) as $expression) {
            $out[] = $expression === null ? '' : $writer->generate($expression, $options, false);
        }

        return $out;
    }

    public function tokenizer(): Tokenizer
    {
        return $this->tokenizer ??= new Tokenizer(static::TOKENIZER_TABLES);
    }

    public function jsonPathTokenizer(): Tokenizer
    {
        return $this->jsonPathTokenizer ??= new Tokenizer(static::JSON_PATH_TOKENIZER_TABLES);
    }

    /**
     * Convert a time format in this dialect to the canonical (Python strftime) format (sqlglot
     * Dialect.format_time). A raw string is a quoted SQL string literal, e.g. "'%Y-%m-%d'".
     */
    public function formatTime(string|Expression|null $expression): ?Expression
    {
        if (is_string($expression)) {
            // the time formats are quoted
            return Literal::string((string) TimeFormat::convert(substr($expression, 1, -1), $this->settings['TIME_MAPPING'], $this->settings['TIME_TRIE']));
        }

        if ($expression instanceof Expression && $expression->isString()) {
            return Literal::string((string) TimeFormat::convert($expression->name(), $this->settings['TIME_MAPPING'], $this->settings['TIME_TRIE']));
        }

        return $expression;
    }

    /**
     * Parse a JSON path literal into a JSONPath expression (sqlglot Dialect.to_json_path); other
     * expressions, and paths that aren't valid JSONPath, are returned unchanged.
     */
    public function toJsonPath(?Expression $path): ?Expression
    {
        if ($path instanceof Literal) {
            $pathText = $path->name();

            if ($path->isNumber()) {
                $pathText = "[{$pathText}]";
            }

            try {
                return JsonPath::parse($pathText, $this);
            } catch (ParseError|TokenError $e) {
                if (($this->settings['STRICT_JSON_PATH_SYNTAX'] ?? false) === true && ! str_starts_with(ltrim($pathText), 'lax') && ! str_starts_with(ltrim($pathText), 'strict')) {
                    Parser::warn('Invalid JSON path syntax. '.$e->getMessage());
                }
            }
        }

        return $path;
    }

    /**
     * @return list<Token>
     */
    public function tokenize(string $sql): array
    {
        return $this->tokenizer()->tokenize($sql)->tokens();
    }

    /**
     * A parser for this dialect. Parsers are reused between calls; a parser that's busy (a parse
     * started while another is still running, e.g. a hint parsed from a comment) gets a sibling.
     */
    public function parser(ErrorLevel $errorLevel = ErrorLevel::Immediate): Parser
    {
        $parser = $this->parsers[$errorLevel->value] ??= new (static::PARSER)($this, $errorLevel);

        return $parser->busy ? new (static::PARSER)($this, $errorLevel) : $parser;
    }

    /**
     * Parse SQL into one expression tree per statement.
     *
     * @return list<Expression|null>
     */
    public function parse(string $sql, ErrorLevel $errorLevel = ErrorLevel::Immediate): array
    {
        $tokens = $this->tokenizer()->tokenize($sql);
        $gc = gc_enabled();

        if ($gc) {
            gc_disable();
        }

        try {
            return $this->parser($errorLevel)->parse($tokens, $sql);
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
    }

    /**
     * Parse a single statement; several statements are wrapped in a Block.
     */
    public function parseOne(string $sql, ErrorLevel $errorLevel = ErrorLevel::Immediate): Expression
    {
        $expressions = array_values(array_filter($this->parse($sql, $errorLevel), static fn (?Expression $e): bool => $e !== null));

        if ($expressions === []) {
            throw new ParseError("No expression was parsed from '{$sql}'");
        }

        if (count($expressions) === 1) {
            return $expressions[0];
        }

        return new Block(['expressions' => $expressions]);
    }

    /**
     * Parse SQL into a specific expression type (sqlglot parse_one(sql, into=...)).
     *
     * @param  class-string|list<class-string>  $types
     */
    public function parseInto(string|array $types, string $sql, ErrorLevel $errorLevel = ErrorLevel::Immediate): Expression
    {
        $tokens = $this->tokenizer()->tokenize($sql);
        $result = $this->parser($errorLevel)->parseInto($types, $tokens, $sql);

        if (! isset($result[0])) {
            throw new ParseError("No expression was parsed from '{$sql}'");
        }

        return $result[0];
    }

    /**
     * Generate SQL for an expression in this dialect.
     *
     * @param  array<string, mixed>  $options
     */
    public function generate(Expression $expression, array $options = [], bool $copy = true): string
    {
        /** @var Generator $generator */
        $generator = new (static::GENERATOR)($this, $options);

        return $generator->generate($expression, $copy);
    }

    public function setting(string $name): mixed
    {
        return $this->settings[$name] ?? null;
    }

    /**
     * @return array<int|string, mixed>
     */
    public function tokenizerTable(string $name): array
    {
        $value = constant(static::TOKENIZER_TABLES.'::'.$name);

        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function jsonPathTokenizerTable(string $name): array
    {
        $constant = static::JSON_PATH_TOKENIZER_TABLES.'::'.$name;
        $value = defined($constant) ? constant($constant) : [];

        return is_array($value) ? $value : [];
    }

    public function name(): string
    {
        return static::NAME;
    }

    public function normalizationStrategy(): string
    {
        return $this->normalizationStrategy ?? (string) $this->settings['NORMALIZATION_STRATEGY'];
    }

    /**
     * Normalize an identifier the way this dialect resolves it (e.g. Postgres lowercases unquoted
     * identifiers, Snowflake uppercases them).
     *
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public function normalizeIdentifier(Expression $expression): Expression
    {
        $strategy = $this->normalizationStrategy();

        if (
            $expression instanceof Identifier
            && $strategy !== 'CASE_SENSITIVE'
            && (! $expression->quoted() || in_array($strategy, ['CASE_INSENSITIVE', 'CASE_INSENSITIVE_UPPERCASE'], true))
        ) {
            $text = (string) ($expression->args['this'] ?? '');
            $ascii = ($this->settings['ASCII_ONLY_NORMALIZATION'] ?? false) === true;

            if ($strategy === 'UPPERCASE' || $strategy === 'CASE_INSENSITIVE_UPPERCASE') {
                $normalized = $ascii ? strtoupper($text) : mb_strtoupper($text, 'UTF-8');
            } else {
                $normalized = $ascii ? strtolower($text) : mb_strtolower($text, 'UTF-8');
            }

            $expression->set('this', $normalized);
        }

        return $expression;
    }

    /**
     * Whether text contains case-sensitive characters under this dialect's rules.
     */
    public function caseSensitive(string $text): bool
    {
        $strategy = $this->normalizationStrategy();

        if ($strategy === 'CASE_INSENSITIVE') {
            return false;
        }

        $pattern = $strategy === 'UPPERCASE' ? '/\p{Ll}/u' : '/\p{Lu}/u';

        return preg_match($pattern, $text) === 1;
    }

    /**
     * Whether an identifier may be quoted: always when it's already quoted; with identify true,
     * unless it names a function; with 'safe' / 'unsafe', depending on whether it's case-sensitive.
     */
    public function canQuote(Identifier $identifier, string|bool $identify = 'safe'): bool
    {
        if ($identifier->quoted()) {
            return true;
        }

        if ($identify === false || $identify === '') {
            return false;
        }

        if ($identifier->parent instanceof Func) {
            return false;
        }

        if ($identify === true) {
            return true;
        }

        $text = (string) ($identifier->args['this'] ?? '');
        $isSafe = ! $this->caseSensitive($text) && Builder::isSafeIdentifier($text);

        return match ($identify) {
            'safe' => $isSafe,
            'unsafe' => ! $isSafe,
            default => throw new InvalidArgumentException("Unexpected argument for identify: '{$identify}'"),
        };
    }

    /**
     * Quote an identifier if needed (always when $identify is true).
     *
     * @template T of Expression
     *
     * @param  T  $expression
     * @return T
     */
    public function quoteIdentifier(Expression $expression, bool $identify = true): Expression
    {
        if ($expression instanceof Identifier) {
            $expression->set('quoted', $this->canQuote($expression, $identify ?: 'unsafe'));
        }

        return $expression;
    }
}
