<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Parser;

use Closure;
use LaravelSqlParser\LaravelSqlParser\Dialects\Dialect;
use LaravelSqlParser\LaravelSqlParser\Errors\ErrorLevel;
use LaravelSqlParser\LaravelSqlParser\Errors\ParseError;
use LaravelSqlParser\LaravelSqlParser\Expressions\Command;
use LaravelSqlParser\LaravelSqlParser\Expressions\EndStatement;
use LaravelSqlParser\LaravelSqlParser\Expressions\Expression;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenStream;
use LaravelSqlParser\LaravelSqlParser\Tokenizer\TokenType;
use ReflectionClass;
use TypeError;

/**
 * Turns a token stream into expression trees (a port of sqlglot's Parser).
 *
 * The grammar lives in the Concerns traits, in the same order as sqlglot's parser.py. Dialect
 * parsers extend this class and point TABLES at their generated tables class.
 *
 * Tokens are read straight from the TokenStream's parallel arrays. A "token" passed between
 * methods is its index into those arrays; `$curr`, `$next` and `$prev` hold token types, with
 * 0 meaning "no token" (sqlglot's SENTINEL).
 */
class Parser
{
    use Concerns\BuildsDialectFunctions;
    use Concerns\HandlesTables;
    use Concerns\ParsesDdl;
    use Concerns\ParsesDml;
    use Concerns\ParsesExpressions;
    use Concerns\ParsesFunctions;
    use Concerns\ParsesPipeSyntax;
    use Concerns\ParsesProperties;
    use Concerns\ParsesQueries;
    use Concerns\ParsesStatements;
    use Concerns\ParsesTables;
    use Concerns\ParsesTypes;

    /** @var class-string */
    public const string TABLES = ParserTables::class;

    /**
     * Handler for warnings (ErrorLevel::Warn errors and unsupported-syntax fallbacks).
     *
     * @var (Closure(string): void)|null
     */
    public static ?Closure $warningHandler = null;

    public string $sql = '';

    /** True while a parse is in progress, so nested parses (e.g. hints) get their own parser. */
    public bool $busy = false;

    /** @var list<ParseError> */
    public array $errors = [];

    /** @var array<string, mixed> every constant of static::TABLES */
    protected array $t;

    /** @var array<int, true> */
    protected array $idVarTokens;

    /** @var array<int, true> */
    protected array $funcTokens;

    /** @var array<int, true> */
    protected array $typeTokens;

    /** @var array<int, true> */
    protected array $textMatchExcludedTokens;

    /** @var array<array-key, mixed> a set keyed by token type */
    protected array $commandTokens;

    // Token stream (global arrays; the current chunk is [chunkStart, chunkEnd)).

    /** @var list<int> */
    protected array $types = [];

    /** @var list<string> */
    protected array $texts = [];

    /** @var list<int> */
    protected array $starts = [];

    /** @var list<int> */
    protected array $ends = [];

    /** @var array<int, list<string>> */
    protected array $tokenComments = [];

    protected int $index = 0;

    protected int $chunkStart = 0;

    protected int $chunkEnd = 0;

    /** Type of the current token, 0 if none. */
    protected int $curr = 0;

    /** Type of the next token, 0 if none. */
    protected int $next = 0;

    /** Type of the previous token, 0 if none. */
    protected int $prev = 0;

    /** @var list<string> */
    protected array $prevComments = [];

    /** @var list<array{0: int, 1: int}> */
    protected array $chunks = [];

    protected int $chunkIndex = 0;

    protected int $nodeCount = 0;

    protected int $pipeCteCounter = 0;

    /** @var array<class-string, array<string, mixed>> */
    private static array $tableCache = [];

    /**
     * Tokens that can follow an operand and continue an operator level between disjunction and
     * factor; any other token ends the expression (see parseDisjunction()).
     *
     * @var array<int, true>
     */
    protected array $operandContinuations = [];

    /** @var array<class-string, array<int, true>> */
    private static array $continuationCache = [];

    public function __construct(
        public readonly Dialect $dialect,
        public ErrorLevel $errorLevel = ErrorLevel::Immediate,
        public int $errorMessageContext = 100,
        public int $maxErrors = 3,
        public int $maxNodes = -1,
    ) {
        $this->t = self::$tableCache[static::TABLES] ??= (new ReflectionClass(static::TABLES))->getConstants();
        $this->idVarTokens = $this->t['ID_VAR_TOKENS'];
        $this->funcTokens = $this->t['FUNC_TOKENS'];
        $this->typeTokens = $this->t['TYPE_TOKENS'];
        $this->textMatchExcludedTokens = $this->t['TEXT_MATCH_EXCLUDED_TOKENS'];
        $this->commandTokens = $dialect->tokenizerTable('COMMANDS');
        $this->operandContinuations = self::$continuationCache[static::class] ??= $this->operandContinuationTokens();
    }

    /**
     * Every token an operator level reacts to after an operand. A dialect that overrides one of
     * those levels to react to more tokens must add them here.
     *
     * @return array<int, true>
     */
    protected function operandContinuationTokens(): array
    {
        $tokens = [
            TokenType::NOT => true,
            TokenType::ISNULL => true,
            TokenType::NOTNULL => true,
            TokenType::DPIPE => true,
            TokenType::DQMARK => true,
            TokenType::LT => true,
            TokenType::GT => true,
        ];

        foreach (['DISJUNCTION', 'CONJUNCTION', 'EQUALITY', 'COMPARISON', 'RANGE_PARSERS', 'BITWISE', 'JSON_OPERATORS', 'TERM', 'FACTOR', 'EXPONENT'] as $table) {
            foreach ($this->t[$table] as $type => $_) {
                $tokens[$type] = true;
            }
        }

        return $tokens;
    }

    public function reset(): void
    {
        $this->sql = '';
        $this->errors = [];
        $this->types = $this->texts = $this->starts = $this->ends = $this->tokenComments = [];
        $this->index = $this->chunkStart = $this->chunkEnd = 0;
        $this->curr = $this->next = $this->prev = 0;
        $this->prevComments = [];
        $this->chunks = [];
        $this->chunkIndex = 0;
        $this->nodeCount = 0;
        $this->pipeCteCounter = 0;
    }

    // ---------------------------------------------------------------------------------------
    // Entry points
    // ---------------------------------------------------------------------------------------

    /**
     * Parse tokens into one tree per SQL statement.
     *
     * @return list<Expression|null>
     */
    public function parse(TokenStream $tokens, ?string $sql = null): array
    {
        return $this->runParse(fn (): ?Expression => $this->parseStatement(), $tokens, $sql);
    }

    /**
     * Parse tokens into the given expression type(s), trying each in turn.
     *
     * @param  class-string|list<class-string>  $types
     * @return list<Expression|null>
     */
    public function parseInto(string|array $types, TokenStream $tokens, ?string $sql = null): array
    {
        $errors = [];

        foreach ((array) $types as $type) {
            $handler = $this->t['EXPRESSION_PARSERS'][$type] ?? null;

            if ($handler === null) {
                throw new TypeError("No parser registered for {$type}");
            }

            try {
                return $this->runParse(fn (): ?Expression => $this->callHandler($handler), $tokens, $sql);
            } catch (ParseError $e) {
                $details = $e->errors;

                if (isset($details[0])) {
                    $details[0]['into_expression'] = $type;
                }
                $errors[] = new ParseError($e->getMessage(), $details, $e);
            }
        }

        $description = $sql ?? 'tokens';
        $merged = [];
        foreach ($errors as $error) {
            array_push($merged, ...$error->errors);
        }

        throw new ParseError("Failed to parse '{$description}' into ".implode(', ', (array) $types), $merged, end($errors) ?: null);
    }

    /**
     * Log or raise collected errors, depending on the error level.
     */
    public function checkErrors(): void
    {
        if ($this->errorLevel === ErrorLevel::Warn) {
            foreach ($this->errors as $error) {
                self::warn($error->getMessage());
            }
        } elseif ($this->errorLevel === ErrorLevel::Raise && $this->errors !== []) {
            throw ParseError::combine($this->errors, $this->maxErrors);
        }
    }

    /**
     * One of the parser's settings / tables (e.g. 'IDENTIFY_PIVOT_STRINGS'), for the generator.
     */
    public function table(string $name): mixed
    {
        return $this->t[$name] ?? null;
    }

    public static function warn(string $message): void
    {
        if (self::$warningHandler !== null) {
            (self::$warningHandler)($message);
        }
    }

    /**
     * @param  Closure(): (Expression|null)  $parseMethod
     * @return list<Expression|null>
     */
    protected function runParse(Closure $parseMethod, TokenStream $tokens, ?string $sql): array
    {
        $this->busy = true;

        try {
            return $this->parseTokens($parseMethod, $tokens, $sql);
        } finally {
            $this->busy = false;
        }
    }

    /**
     * @param  Closure(): (Expression|null)  $parseMethod
     * @return list<Expression|null>
     */
    private function parseTokens(Closure $parseMethod, TokenStream $tokens, ?string $sql): array
    {
        $this->reset();
        $this->sql = $sql ?? $tokens->sql;
        $this->types = $tokens->types;
        $this->texts = $tokens->texts;
        $this->starts = $tokens->starts;
        $this->ends = $tokens->ends;
        $this->tokenComments = $tokens->comments;

        $total = count($this->types);
        $chunks = [];
        $start = 0;

        for ($i = 0; $i < $total; $i++) {
            if ($this->types[$i] === TokenType::SEMICOLON) {
                $chunks[] = [$start, $i];

                if (isset($this->tokenComments[$i])) {
                    $chunks[] = [$i, $i + 1];
                }

                $start = $i + 1;

                if ($i === $total - 1) {
                    $start = -1;
                }
            }
        }

        if ($start !== -1) {
            $chunks[] = [$start, $total];
        }

        // sqlglot starts with an empty chunk and only opens a new one after a semicolon that isn't
        // the last token, so "SELECT 1;" yields one chunk and ";" alone yields one empty chunk.
        $this->chunks = $chunks === [] ? [[0, 0]] : $chunks;

        return $this->parseBatchStatements($parseMethod, false);
    }

    /**
     * @param  Closure(): (Expression|null)  $parseMethod
     * @return list<Expression|null>
     */
    protected function parseBatchStatements(Closure $parseMethod, bool $sepFirstStatement = true): array
    {
        $expressions = [];

        // Chunkification binds if/while statements with the first statement of the body
        if ($sepFirstStatement) {
            $this->match(TokenType::BEGIN);
            $expressions[] = $parseMethod();
        }

        $chunksLength = count($this->chunks);

        while ($this->chunkIndex < $chunksLength) {
            $this->advanceChunk();

            if ($this->match(TokenType::ELSE, false)) {
                return $expressions;
            }

            if ($expressions !== [] && $this->next === 0 && $this->match(TokenType::END)) {
                $expressions[] = new EndStatement;

                continue;
            }

            $expressions[] = $parseMethod();

            if ($this->index < $this->chunkEnd) {
                $this->raiseError('Invalid expression / Unexpected token');
            }

            $this->checkErrors();
        }

        return $expressions;
    }

    // ---------------------------------------------------------------------------------------
    // Token navigation
    // ---------------------------------------------------------------------------------------

    /**
     * @phpstan-impure
     */
    protected function advance(int $times = 1): void
    {
        $index = $this->index + $times;
        $this->index = $index;
        $end = $this->chunkEnd;
        $this->curr = $index < $end ? $this->types[$index] : 0;
        $this->next = $index + 1 < $end ? $this->types[$index + 1] : 0;

        if ($index > $this->chunkStart) {
            $this->prev = $this->types[$index - 1];
            $this->prevComments = $this->tokenComments[$index - 1] ?? [];
        } else {
            $this->prev = 0;
            $this->prevComments = [];
        }
    }

    /**
     * @phpstan-impure
     */
    protected function advanceChunk(): void
    {
        [$this->chunkStart, $this->chunkEnd] = $this->chunks[$this->chunkIndex];
        $this->index = $this->chunkStart - 1;
        $this->chunkIndex++;
        $this->advance();
    }

    /**
     * @phpstan-impure
     */
    protected function retreat(int $index): void
    {
        if ($index !== $this->index) {
            $this->advance($index - $this->index);
        }
    }

    /** Text of the current token ('' if none). */
    protected function currText(): string
    {
        return $this->curr !== 0 ? $this->texts[$this->index] : '';
    }

    /** Text of the previous token ('' if none). */
    protected function prevText(): string
    {
        return $this->prev !== 0 ? $this->texts[$this->index - 1] : '';
    }

    /** Text of the next token ('' if none). */
    protected function nextText(): string
    {
        return $this->next !== 0 ? $this->texts[$this->index + 1] : '';
    }

    /** Index of the previous token, or null. */
    protected function prevToken(): ?int
    {
        return $this->prev !== 0 ? $this->index - 1 : null;
    }

    /** Index of the current token, or null. */
    protected function currToken(): ?int
    {
        return $this->curr !== 0 ? $this->index : null;
    }

    /**
     * @return list<string>
     */
    protected function currComments(): array
    {
        return $this->curr !== 0 ? ($this->tokenComments[$this->index] ?? []) : [];
    }

    protected function addComments(?Expression $expression): void
    {
        if ($expression !== null && $this->prevComments !== []) {
            $expression->addComments($this->prevComments);
            $this->prevComments = [];
        }
    }

    /**
     * @phpstan-impure
     */
    protected function match(int $type, bool $advance = true, ?Expression $expression = null): bool
    {
        if ($this->curr === $type) {
            if ($advance) {
                $this->advance();
            }

            $this->addComments($expression);

            return true;
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $types  a set keyed by token type
     *
     * @phpstan-impure
     */
    protected function matchSet(array $types, bool $advance = true): bool
    {
        if ($this->curr !== 0 && isset($types[$this->curr])) {
            if ($advance) {
                $this->advance();
            }

            return true;
        }

        return false;
    }

    /**
     * @phpstan-impure
     */
    protected function matchPair(int $a, int $b, bool $advance = true): bool
    {
        if ($this->curr === $a && $this->next === $b) {
            if ($advance) {
                $this->advance(2);
            }

            return true;
        }

        return false;
    }

    /**
     * Match the current token's uppercased text against a set of texts.
     *
     * @param  array<string, mixed>  $texts  a set (or map) keyed by uppercase text
     *
     * @phpstan-impure
     */
    protected function matchTexts(array $texts, bool $advance = true): bool
    {
        if (
            $this->curr !== 0
            && ! isset($this->textMatchExcludedTokens[$this->curr])
            && isset($texts[strtoupper($this->texts[$this->index])])
        ) {
            if ($advance) {
                $this->advance();
            }

            return true;
        }

        return false;
    }

    /**
     * Match a sequence of (uppercase) texts.
     *
     * @phpstan-impure
     */
    protected function matchTextSeq(string ...$texts): bool
    {
        $index = $this->index;

        foreach ($texts as $text) {
            if ($this->curr !== 0 && ! isset($this->textMatchExcludedTokens[$this->curr]) && strtoupper($this->texts[$this->index]) === $text) {
                $this->advance();
            } else {
                $this->retreat($index);

                return false;
            }
        }

        return true;
    }

    /**
     * Like matchTextSeq() but never advances.
     */
    protected function peekTextSeq(string ...$texts): bool
    {
        $index = $this->index;
        $matched = $this->matchTextSeq(...$texts);
        $this->retreat($index);

        return $matched;
    }

    protected function isConnected(): bool
    {
        return $this->prev !== 0 && $this->curr !== 0 && $this->starts[$this->index] === $this->ends[$this->index - 1] + 1;
    }

    protected function findSql(int $start, int $end): string
    {
        return substr($this->sql, $this->starts[$start], $this->ends[$end] + 1 - $this->starts[$start]);
    }

    // ---------------------------------------------------------------------------------------
    // Errors and node construction
    // ---------------------------------------------------------------------------------------

    public function raiseError(string $message, ?int $token = null): void
    {
        $token ??= $this->currToken() ?? $this->prevToken();
        $start = $token !== null ? $this->starts[$token] : 0;
        $end = $token !== null ? $this->ends[$token] : -1;
        [$line, $col] = $token !== null ? self::lineCol($this->sql, $end) : [1, 1];

        [$formatted, $startContext, $highlight, $endContext] = self::highlightSql($this->sql, $start, $end, $this->errorMessageContext);

        $error = new ParseError("{$message}. Line {$line}, Col: {$col}.\n  {$formatted}", [[
            'description' => $message,
            'line' => $line,
            'col' => $col,
            'start_context' => $startContext,
            'highlight' => $highlight,
            'end_context' => $endContext,
            'into_expression' => null,
        ]]);

        if ($this->errorLevel === ErrorLevel::Immediate) {
            throw $error;
        }

        $this->errors[] = $error;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function lineCol(string $sql, int $offset): array
    {
        return (new TokenStream($sql))->position($offset);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private static function highlightSql(string $sql, int $start, int $end, int $context): array
    {
        $startContext = '';
        $endContext = '';
        $parts = [];
        $previousEnd = 0;
        $firstStart = 0;

        if ($start > 0) {
            $firstStart = $start;
            $startContext = substr($sql, max(0, $start - $context), $start - max(0, $start - $context));
            $parts[] = $startContext;
            $previousEnd = $start;
        }

        $highlightStart = max($start, $previousEnd);
        $highlightEnd = $end + 1;

        if ($highlightStart < $highlightEnd) {
            $parts[] = "\e[4m".substr($sql, $highlightStart, $highlightEnd - $highlightStart)."\e[0m";
            $previousEnd = $highlightEnd;
        }

        if ($previousEnd < strlen($sql)) {
            $endContext = substr($sql, $previousEnd, $context);
            $parts[] = $endContext;
        }

        return [implode('', $parts), $startContext, substr($sql, $firstStart, $previousEnd - $firstStart), $endContext];
    }

    /**
     * @template T of Expression
     *
     * @param  T  $expression
     * @param  list<mixed>|null  $args
     * @return T
     */
    public function validateExpression(Expression $expression, ?array $args = null): Expression
    {
        if ($this->maxNodes > -1) {
            $this->nodeCount++;

            if ($this->nodeCount > $this->maxNodes) {
                $this->raiseError("Maximum number of AST nodes ({$this->maxNodes}) exceeded");
            }
        }

        // Most node types require no arguments, so there is nothing to check unless args are given
        if ($this->errorLevel !== ErrorLevel::Ignore && ($expression::REQUIRED_ARGS !== [] || $args !== null)) {
            foreach ($expression->errorMessages($args) as $message) {
                $this->raiseError($message);
            }
        }

        return $expression;
    }

    /**
     * Finish building a node: attach positions and comments and validate it.
     *
     * @template T of Expression
     *
     * @param  T  $instance
     * @param  list<string>|null  $comments
     * @return T
     */
    public function expression(Expression $instance, ?int $token = null, ?array $comments = null): Expression
    {
        if ($token !== null) {
            $instance->meta ??= [];
            $instance->meta['start'] = $this->starts[$token];
            $instance->meta['end'] = $this->ends[$token];
        }

        if ($comments !== null && $comments !== []) {
            $instance->addComments($comments);
        } elseif ($this->prevComments !== []) {
            $instance->addComments($this->prevComments);
            $this->prevComments = [];
        }

        if (! $instance::IS_PRIMITIVE) {
            $this->validateExpression($instance);
        }

        return $instance;
    }

    /**
     * Run a parse method with errors raised immediately; on failure (or with $retreat) rewind.
     *
     * @template T
     *
     * @param  Closure(): T  $parseMethod
     * @return T|null
     */
    protected function tryParse(Closure $parseMethod, bool $retreat = false): mixed
    {
        $index = $this->index;
        $errorLevel = $this->errorLevel;
        $this->errorLevel = ErrorLevel::Immediate;
        $result = null;

        try {
            $result = $parseMethod();
        } catch (ParseError) {
            $result = null;
        } finally {
            if ($result === null || $result === false || $result === [] || $retreat) {
                $this->retreat($index);
            }

            $this->errorLevel = $errorLevel;
        }

        return $result;
    }

    protected function warnUnsupported(): void
    {
        if ($this->chunkEnd - $this->chunkStart <= 1) {
            return;
        }

        $sql = substr($this->findSql($this->chunkStart, $this->chunkEnd - 1), 0, $this->errorMessageContext);

        self::warn("'{$sql}' contains unsupported syntax. Falling back to parsing as a 'Command'.");
    }

    protected function parseCommand(): Command
    {
        $this->warnUnsupported();
        $comments = $this->prevComments;

        return $this->expression(new Command([
            'this' => strtoupper($this->prevText()),
            'expression' => $this->parseString(),
        ]), null, $comments);
    }
}
