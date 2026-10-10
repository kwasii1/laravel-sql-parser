<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Tokenizer;

use Kwasii\LaravelSqlParser\Errors\TokenError;
use Throwable;

/**
 * Splits SQL into tokens (a port of sqlglot's TokenizerCore).
 *
 * All dialect behaviour comes from a generated, literal-only tables class such as
 * TokenizerTables or MySqlTokenizerTables. The scanner works on byte offsets: bytes >= 0x80
 * are treated as identifier bytes, so UTF-8 identifiers need no multibyte handling, and
 * Unicode whitespace is recognised explicitly.
 */
final class Tokenizer
{
    /** Python's str.isspace() ASCII set. */
    private const string WHITESPACE = " \t\n\r\v\f\x1C\x1D\x1E\x1F";

    /** Python's str.isspace() code points beyond ASCII, UTF-8 encoded. */
    private const array UNICODE_SPACES = [
        "\u{0085}", "\u{00A0}", "\u{1680}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}", "\u{2004}",
        "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}", "\u{2028}", "\u{2029}",
        "\u{202F}", "\u{205F}", "\u{3000}",
    ];

    /** Lead bytes of UNICODE_SPACES. */
    private const string UNICODE_SPACE_LEADS = "\xC2\xE1\xE2\xE3";

    private const string DIGITS = '0123456789';

    // Settings, copied from the tables class once per tokenizer.

    /** @var array<string, int> */
    private array $singleTokens;

    /** @var array<string, int> */
    private array $keywords;

    /** @var array<string, string> */
    private array $quotes;

    /** @var array<string, string> */
    private array $identifiers;

    /** @var array<string, array{0: string, 1: int}> */
    private array $formatStrings;

    /** @var array<string, true> */
    private array $stringEscapes;

    /** @var array<string, true> */
    private array $byteStringEscapes;

    /** @var array<string, true> */
    private array $identifierEscapes;

    /** @var array<string, true> */
    private array $escapeFollowChars;

    /** @var array<string, string|null> */
    private array $commentDelimiters;

    /** @var array<int, true> */
    private array $commands;

    /** @var array<int, true> */
    private array $commandPrefixTokens;

    /** @var array<int, true> */
    private array $tokensPrecedingHint;

    /** @var array<string, string> */
    private array $numericLiterals;

    /** @var array<string, array{0: int, 1: int, 2: int, 3: int}> */
    private array $numericEscapes;

    /** @var array<string, string> */
    private array $unescapedSequences;

    /** @var array<array-key, mixed> */
    private array $keywordTrie;

    /** @var array<string, true> */
    private array $keywordTrieStarts;

    private bool $nestedComments;

    private string $hintStart;

    private bool $hasBitStrings;

    private bool $hasHexStrings;

    private bool $stringEscapesAllowedInRawStrings;

    private bool $heredocTagIsIdentifier;

    private int $heredocStringAlternative;

    private bool $numbersCanBeUnderscoreSeparated;

    private bool $numbersCanHaveDecimals;

    private bool $identifiersCanStartWithDigit;

    private bool $dropUnknownEscapes;

    private string $varStopChars;

    // Scanner state. `$current` is the offset just past `$char`, as in sqlglot.

    private string $sql = '';

    private int $size = 0;

    private int $start = 0;

    private int $current = 0;

    private string $char = '';

    private string $peek = '';

    private bool $end = false;

    /** @var list<string> */
    private array $pendingComments = [];

    /** Offset of the last char of the previous token (or attached comment); -1 before any token. */
    private int $anchor = -1;

    /** @var list<int> */
    private array $types = [];

    /** @var list<string> */
    private array $texts = [];

    /** @var list<int> */
    private array $starts = [];

    /** @var list<int> */
    private array $ends = [];

    /** @var array<int, list<string>> */
    private array $comments = [];

    /**
     * @param  class-string  $tables  a generated *TokenizerTables class
     */
    public function __construct(string $tables = TokenizerTables::class)
    {
        $this->singleTokens = $tables::SINGLE_TOKENS;
        $this->keywords = $tables::KEYWORDS;
        $this->quotes = $tables::QUOTES;
        $this->identifiers = $tables::IDENTIFIERS;
        $this->formatStrings = $tables::FORMAT_STRINGS;
        $this->stringEscapes = $tables::STRING_ESCAPES;
        $this->byteStringEscapes = $tables::BYTE_STRING_ESCAPES;
        $this->identifierEscapes = $tables::IDENTIFIER_ESCAPES;
        $this->escapeFollowChars = $tables::ESCAPE_FOLLOW_CHARS;
        $this->commentDelimiters = $tables::COMMENTS;
        $this->commands = $tables::COMMANDS;
        $this->commandPrefixTokens = $tables::COMMAND_PREFIX_TOKENS;
        $this->tokensPrecedingHint = $tables::TOKENS_PRECEDING_HINT;
        $this->numericLiterals = $tables::NUMERIC_LITERALS;
        $this->numericEscapes = $tables::NUMERIC_ESCAPES;
        $this->unescapedSequences = $tables::UNESCAPED_SEQUENCES;
        $this->keywordTrie = $tables::KEYWORD_TRIE;
        $this->keywordTrieStarts = $tables::KEYWORD_TRIE_STARTS;
        $this->nestedComments = $tables::NESTED_COMMENTS;
        $this->hintStart = $tables::HINT_START;
        $this->hasBitStrings = $tables::HAS_BIT_STRINGS;
        $this->hasHexStrings = $tables::HAS_HEX_STRINGS;
        $this->stringEscapesAllowedInRawStrings = $tables::STRING_ESCAPES_ALLOWED_IN_RAW_STRINGS;
        $this->heredocTagIsIdentifier = $tables::HEREDOC_TAG_IS_IDENTIFIER;
        $this->heredocStringAlternative = $tables::HEREDOC_STRING_ALTERNATIVE;
        $this->numbersCanBeUnderscoreSeparated = $tables::NUMBERS_CAN_BE_UNDERSCORE_SEPARATED;
        $this->numbersCanHaveDecimals = $tables::NUMBERS_CAN_HAVE_DECIMALS;
        $this->identifiersCanStartWithDigit = $tables::IDENTIFIERS_CAN_START_WITH_DIGIT;
        $this->dropUnknownEscapes = $tables::DROP_UNKNOWN_ESCAPES;
        $this->varStopChars = $tables::VAR_STOP_CHARS;
    }

    public function tokenize(string $sql): TokenStream
    {
        $this->sql = $sql;
        $this->size = strlen($sql);
        // Skip a leading UTF-8 byte order mark (never part of the SQL); offsets stay byte offsets
        // into the original string
        $offset = str_starts_with($sql, "\u{FEFF}") ? 3 : 0;
        $this->start = $offset;
        $this->current = $offset;
        $this->char = '';
        $this->peek = '';
        $this->end = false;
        $this->pendingComments = [];
        $this->anchor = -1;
        $this->types = [];
        $this->texts = [];
        $this->starts = [];
        $this->ends = [];
        $this->comments = [];

        try {
            $this->scan(false);
        } catch (Throwable $e) {
            $start = max($this->current - 50, 0);
            $end = min($this->current + 50, $this->size - 1);
            $context = substr($sql, $start, max(0, $end - $start));

            throw new TokenError("Error tokenizing '{$context}'", $start, $end, $e);
        }

        $stream = new TokenStream($sql);
        $stream->types = $this->types;
        $stream->texts = $this->texts;
        $stream->starts = $this->starts;
        $stream->ends = $this->ends;
        $stream->comments = $this->comments;

        $this->types = $this->texts = $this->starts = $this->ends = $this->comments = [];

        return $stream;
    }

    private function scan(bool $checkSemicolon): void
    {
        $sql = $this->sql;
        $size = $this->size;
        $identifiers = $this->identifiers;

        while ($size > 0 && ! $this->end) {
            $current = $this->current;

            // Skip spaces here rather than iteratively calling advance(), for performance.
            if ($current < $size) {
                $current += strspn($sql, " \t", $current);
            }

            $this->start = $current;
            $this->advance($current > $this->current ? $current - $this->current : 1);

            $char = $this->char;

            if (strpbrk($char, self::WHITESPACE) === false) {
                if ($char >= "\x80" && ($length = $this->unicodeSpaceLength($this->current - 1)) > 0) {
                    $this->advance($length - 1);
                } elseif ($char >= '0' && $char <= '9') {
                    $this->scanNumber();
                } elseif (isset($identifiers[$char])) {
                    $this->scanIdentifier($identifiers[$char]);
                } else {
                    $this->scanKeywords();
                }
            }

            if ($checkSemicolon && $this->peek === ';') {
                break;
            }
        }

        $last = count($this->types) - 1;

        if ($last >= 0 && $this->pendingComments !== []) {
            $this->comments[$last] = [...($this->comments[$last] ?? []), ...$this->pendingComments];
            $this->pendingComments = [];
        }
    }

    /**
     * Length in bytes of the Unicode whitespace character at $offset, or 0.
     */
    private function unicodeSpaceLength(int $offset): int
    {
        if ($offset < 0 || $offset >= $this->size || strpbrk($this->sql[$offset], self::UNICODE_SPACE_LEADS) === false) {
            return 0;
        }

        foreach (self::UNICODE_SPACES as $space) {
            if (substr_compare($this->sql, $space, $offset, strlen($space)) === 0) {
                return strlen($space);
            }
        }

        return 0;
    }

    private function isSpace(string $char, int $offset): bool
    {
        if ($char === '') {
            return false;
        }

        if (strpbrk($char, self::WHITESPACE) !== false) {
            return true;
        }

        return $char >= "\x80" && $this->unicodeSpaceLength($offset) > 0;
    }

    private function advance(int $i = 1): void
    {
        $this->current += $i;
        $this->end = $this->current >= $this->size;
        $this->char = $this->current >= 1 && $this->current <= $this->size ? $this->sql[$this->current - 1] : '';
        $this->peek = $this->end ? '' : $this->sql[$this->current];
    }

    /**
     * Advance past a run of alphanumeric bytes (sqlglot's `_advance(alnum=True)`).
     */
    private function advanceAlnum(): void
    {
        $this->advance();

        if (ctype_alnum($this->char) || $this->char >= "\x80") {
            $run = strspn($this->sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $this->current);

            if ($run > 0) {
                $this->advance($run);
            }
        }
    }

    private function chars(int $size): string
    {
        if ($size === 1) {
            return $this->char;
        }

        $start = $this->current - 1;

        return $start + $size <= $this->size ? substr($this->sql, $start, $size) : '';
    }

    private function text(): string
    {
        return substr($this->sql, $this->start, $this->current - $this->start);
    }

    private function add(int $type, ?string $text = null): void
    {
        $this->anchor = $this->current - 1;

        if ($this->pendingComments !== [] && $type === TokenType::SEMICOLON && $this->types !== []) {
            $last = count($this->types) - 1;
            $this->comments[$last] = [...($this->comments[$last] ?? []), ...$this->pendingComments];
            $this->pendingComments = [];
        }

        $index = count($this->types);
        $this->types[] = $type;
        $this->texts[] = $text ?? substr($this->sql, $this->start, $this->current - $this->start);
        $this->starts[] = $this->start;
        $this->ends[] = $this->current - 1;

        if ($this->pendingComments !== []) {
            $this->comments[$index] = $this->pendingComments;
            $this->pendingComments = [];
        }

        // If a semicolon or BEGIN precedes a command's token, everything after the command's token
        // up to the next semicolon becomes a single string token.
        if (
            isset($this->commands[$type])
            && $this->peek !== ';'
            && ($index === 0 || isset($this->commandPrefixTokens[$this->types[$index - 1]]))
        ) {
            $start = $this->current;
            $count = count($this->types);
            $this->scan(true);
            $this->truncate($count);
            $text = trim(substr($this->sql, $start, $this->current - $start), self::WHITESPACE);

            if ($text !== '') {
                $this->add(TokenType::STRING, $text);
            }
        }
    }

    private function truncate(int $count): void
    {
        if (count($this->types) <= $count) {
            return;
        }

        array_splice($this->types, $count);
        array_splice($this->texts, $count);
        array_splice($this->starts, $count);
        array_splice($this->ends, $count);

        foreach (array_keys($this->comments) as $index) {
            if ($index >= $count) {
                unset($this->comments[$index]);
            }
        }
    }

    private function lastType(): ?int
    {
        $count = count($this->types);

        return $count > 0 ? $this->types[$count - 1] : null;
    }

    private function scanKeywords(): void
    {
        $char = $this->char;
        $singleTokens = $this->singleTokens;

        if (! isset($this->keywordTrieStarts[$char])) {
            if (isset($singleTokens[$char])) {
                $this->add($singleTokens[$char], $char);

                return;
            }

            $this->scanVar();

            return;
        }

        $sql = $this->sql;
        $sqlSize = $this->size;
        $size = 0;
        $word = null;
        $chars = $char;
        $prevSpace = false;
        $skip = false;
        $trie = $this->keywordTrie;
        $singleToken = isset($singleTokens[$char]);

        while (true) {
            if (! $skip) {
                $upper = $char >= 'a' && $char <= 'z' ? strtoupper($char) : $char;

                if (! isset($trie[$upper])) {
                    break;
                }

                $trie = $trie[$upper];

                if (isset($trie[''])) {
                    $word = $chars;
                }
            }

            $end = $this->current + $size;
            $size++;

            if ($end < $sqlSize) {
                $char = $sql[$end];
                $singleToken = $singleToken || isset($singleTokens[$char]);
                $isSpace = $this->isSpace($char, $end);

                if (! $isSpace || ! $prevSpace) {
                    if ($isSpace) {
                        $char = ' ';
                    }

                    $chars .= $char;
                    $prevSpace = $isSpace;
                    $skip = false;
                } else {
                    $skip = true;
                }
            } else {
                $char = '';

                break;
            }
        }

        if ($word !== null) {
            if ($this->scanString($word)) {
                return;
            }

            if ($this->scanComment($word)) {
                return;
            }

            if ($prevSpace || $singleToken || $char === '') {
                $this->advance($size - 1);
                $word = strtoupper($word);
                $this->add($this->keywords[$word], $word);

                return;
            }
        }

        if (isset($singleTokens[$this->char])) {
            $this->add($singleTokens[$this->char], $this->char);

            return;
        }

        $this->scanVar();
    }

    private function scanComment(string $commentStart): bool
    {
        if (! array_key_exists($commentStart, $this->commentDelimiters)) {
            return false;
        }

        $commentStartOffset = $this->current - 1;
        $commentStartSize = strlen($commentStart);
        $commentEnd = $this->commentDelimiters[$commentStart];

        if ($commentEnd !== null) {
            // Skip the comment's start delimiter
            $this->advance($commentStartSize);

            $commentCount = 1;
            $commentEndSize = strlen($commentEnd);

            while (! $this->end) {
                if ($this->chars($commentEndSize) === $commentEnd) {
                    $commentCount--;

                    if ($commentCount === 0) {
                        break;
                    }
                }

                $this->advanceAlnum();

                // Nested comments are allowed by some dialects, e.g. databricks, duckdb, postgres
                if ($this->nestedComments && ! $this->end && $this->chars($commentEndSize) === $commentStart) {
                    $this->advance($commentStartSize);
                    $commentCount++;
                }
            }

            $text = $this->text();
            $this->pendingComments[] = substr($text, $commentStartSize, strlen($text) - $commentStartSize - $commentEndSize + 1);
            $this->advance($commentEndSize - 1);
        } else {
            while (! $this->end && $this->peek !== "\n" && $this->peek !== "\r") {
                $this->advanceAlnum();
            }

            $this->pendingComments[] = (string) substr($this->text(), $commentStartSize);
        }

        $lastType = $this->lastType();

        if ($commentStart === $this->hintStart && $lastType !== null && isset($this->tokensPrecedingHint[$lastType])) {
            $this->add(TokenType::HINT);
        }

        // A leading comment attaches to the next token, a trailing one to the previous token.
        if ($this->anchor >= 0 && $this->types !== [] && strcspn($this->sql, "\r\n", $this->anchor, $commentStartOffset - $this->anchor) === $commentStartOffset - $this->anchor) {
            $last = count($this->types) - 1;
            $this->comments[$last] = [...($this->comments[$last] ?? []), ...$this->pendingComments];
            $this->pendingComments = [];
            $this->anchor = $this->current - 1;
        }

        return true;
    }

    private function scanNumber(): void
    {
        if ($this->char === '0') {
            $peek = strtoupper($this->peek);

            if ($peek === 'B' && $this->hasBitStrings) {
                $this->scanBits();

                return;
            }

            if ($peek === 'X') {
                if ($this->hasHexStrings) {
                    $this->scanHex();
                } else {
                    $this->add(TokenType::NUMBER);
                }

                return;
            }
        }

        $decimal = false;
        $scientific = 0;
        $isUnderscoreSeparated = false;
        $numberText = '';
        $numericLiteral = '';
        $numericType = null;

        while (true) {
            $peek = $this->peek;

            if ($peek !== '' && $peek >= '0' && $peek <= '9') {
                $this->advance(strspn($this->sql, self::DIGITS, $this->current));
            } elseif ($peek === '.' && ! $decimal) {
                if ($this->lastType() === TokenType::PARAMETER || ! $this->numbersCanHaveDecimals) {
                    break;
                }

                $decimal = true;
                $this->advance();
            } elseif (($peek === '-' || $peek === '+') && $scientific === 1) {
                // Only consume +/- if followed by a digit
                $next = $this->sql[$this->current + 1] ?? '';

                if ($next !== '' && $next >= '0' && $next <= '9') {
                    $scientific++;
                    $this->advance();
                } else {
                    break;
                }
            } elseif (($peek === 'e' || $peek === 'E') && $scientific === 0) {
                $scientific++;
                $this->advance();
            } elseif ($peek === '_' && $this->numbersCanBeUnderscoreSeparated) {
                $isUnderscoreSeparated = true;
                $this->advance();
            } elseif ($peek !== '' && ($peek === '_' || ctype_alpha($peek) || $peek >= "\x80")) {
                $numberText = $this->text();

                while ($this->peek !== '' && ! $this->isSpace($this->peek, $this->current) && ! isset($this->singleTokens[$this->peek])) {
                    $numericLiteral .= $this->peek;
                    $this->advance();
                }

                $numericType = $this->keywords[$this->numericLiterals[strtoupper($numericLiteral)] ?? ''] ?? null;

                if ($numericType !== null) {
                    break;
                }

                if ($this->identifiersCanStartWithDigit) {
                    $this->add(TokenType::VAR);

                    return;
                }

                $this->advance(-strlen($numericLiteral));

                break;
            } else {
                break;
            }
        }

        if ($numberText === '') {
            $numberText = $this->text();
        }

        // Normalize inputs such as 100_000 to 100000
        if ($isUnderscoreSeparated) {
            $numberText = str_replace('_', '', $numberText);
        }

        $this->add(TokenType::NUMBER, $numberText);

        // Normalize inputs such as 123L to 123::BIGINT so that they're parsed as casts
        if ($numericType !== null) {
            $this->add(TokenType::DCOLON, '::');
            $this->add($numericType, $numericLiteral);
        }
    }

    private function scanBits(): void
    {
        $this->advance();
        $value = $this->extractValue();

        // If the value isn't valid binary, fall back to tokenizing it as an identifier
        if (preg_match('/^0[bB](?:_?[01])+$/', $value) === 1) {
            $this->add(TokenType::BIT_STRING, substr($value, 2));
        } else {
            $this->add(TokenType::IDENTIFIER);
        }
    }

    private function scanHex(): void
    {
        $this->advance();
        $value = $this->extractValue();

        // If the value isn't valid hexadecimal, fall back to tokenizing it as an identifier
        if (preg_match('/^0[xX](?:_?[0-9a-fA-F])+$/', $value) === 1) {
            $this->add(TokenType::HEX_STRING, substr($value, 2));
        } else {
            $this->add(TokenType::IDENTIFIER);
        }
    }

    private function extractValue(): string
    {
        while (true) {
            $peek = $this->peek;

            if ($peek !== '' && ! $this->isSpace($peek, $this->current) && ! isset($this->singleTokens[$peek])) {
                $this->advanceAlnum();
            } else {
                break;
            }
        }

        return $this->text();
    }

    private function scanString(string $start): bool
    {
        $base = null;
        $tokenType = TokenType::STRING;

        if (isset($this->quotes[$start])) {
            $end = $this->quotes[$start];
        } elseif (isset($this->formatStrings[$start])) {
            [$end, $tokenType] = $this->formatStrings[$start];

            if ($tokenType === TokenType::HEX_STRING) {
                $base = 16;
            } elseif ($tokenType === TokenType::BIT_STRING) {
                $base = 2;
            } elseif ($tokenType === TokenType::HEREDOC_STRING) {
                $this->advance();

                if ($this->char === $end) {
                    $tag = '';
                } else {
                    $tag = $this->extractString($end, null, true, ! $this->heredocTagIsIdentifier);
                }

                if (
                    $tag !== ''
                    && $this->heredocTagIsIdentifier
                    && ($this->end || ctype_digit($tag) || strpbrk($tag, self::WHITESPACE) !== false)
                ) {
                    if (! $this->end) {
                        $this->advance(-1);
                    }

                    $this->advance(-strlen($tag));
                    $this->add($this->heredocStringAlternative);

                    return true;
                }

                $end = $start.$tag.$end;
            }
        } else {
            return false;
        }

        $this->advance(strlen($start));
        $text = $this->extractString(
            $end,
            $tokenType === TokenType::BYTE_STRING ? $this->byteStringEscapes : $this->stringEscapes,
            $tokenType === TokenType::RAW_STRING,
        );

        if ($base !== null && $text !== '') {
            $valid = $base === 16 ? ctype_xdigit(str_replace('_', '', $text)) : strspn($text, '01_') === strlen($text);

            if (! $valid) {
                throw new TokenError("Numeric string contains invalid characters from {$this->start}");
            }
        }

        $this->add($tokenType, $text);

        return true;
    }

    private function scanIdentifier(string $identifierEnd): void
    {
        $this->advance();
        $text = $this->extractString($identifierEnd, [...$this->identifierEscapes, $identifierEnd => true]);
        $this->add(TokenType::IDENTIFIER, $text);
    }

    private function scanVar(): void
    {
        $run = strcspn($this->sql, $this->varStopChars, $this->current);

        // Unicode whitespace also ends a var.
        if ($run > 0) {
            $segment = substr($this->sql, $this->current, $run);

            if (strpbrk($segment, self::UNICODE_SPACE_LEADS) !== false) {
                for ($i = 0; $i < $run; $i++) {
                    if ($this->unicodeSpaceLength($this->current + $i) > 0) {
                        $run = $i;

                        break;
                    }
                }
            }

            if ($run > 0) {
                $this->advance($run);
            }
        }

        $text = substr($this->sql, $this->start, $this->current - $this->start);

        $this->add(
            $this->lastType() === TokenType::PARAMETER
                ? TokenType::VAR
                : ($this->keywords[strtoupper($text)] ?? TokenType::VAR),
        );
    }

    /**
     * Read a numeric escape (\x41, A, \101, ...) starting at $start.
     *
     * @return array{0: int, 1: int} the value (-1 if too few digits) and the end offset
     */
    private function readNumericEscape(int $start, int $base, int $minDigits, int $maxDigits, int $maxValue): array
    {
        $value = 0;
        $end = $start;
        $limit = min($start + $maxDigits, $this->size);

        while ($end < $limit) {
            $char = $this->sql[$end];
            $digit = ctype_xdigit($char) ? (int) hexdec($char) : 16;

            if ($digit >= $base || $value * $base + $digit > $maxValue) {
                break;
            }

            $value = $value * $base + $digit;
            $end++;
        }

        return $end - $start >= $minDigits ? [$value, $end] : [-1, $end];
    }

    /**
     * Decode the numeric escape at the current backslash; returns '' if nothing was decoded.
     */
    private function scanNumericEscape(): string
    {
        $start = $this->current;
        $peek = $this->peek;
        $isOctal = $peek !== '' && $peek >= '0' && $peek <= '7';
        $spec = $this->numericEscapes[$isOctal ? '0' : $peek] ?? null;

        if ($spec === null) {
            return '';
        }

        [$base, $minDigits, $maxDigits, $maxValue] = $spec;
        [$value, $end] = $this->readNumericEscape($isOctal ? $start : $start + 1, $base, $minDigits, $maxDigits, $maxValue);

        // A UTF-16 surrogate is only valid as a high half followed by an escaped low half
        if ($value >= 0xD800 && $value <= 0xDFFF) {
            $low = -1;
            $lowEnd = $end;

            if ($value <= 0xDBFF && substr($this->sql, $end, 2) === '\\'.$peek) {
                [$low, $lowEnd] = $this->readNumericEscape($end + 2, $base, $minDigits, $maxDigits, $maxValue);
            }

            if ($low >= 0xDC00 && $low <= 0xDFFF) {
                $value = 0x10000 + (($value - 0xD800) << 10) + ($low - 0xDC00);
                $end = $lowEnd;
            } else {
                $value = -1;
            }
        }

        if ($value >= 0 && $end < $this->size) {
            $this->advance($end - $start + 1);

            return mb_chr($value, 'UTF-8') ?: '';
        }

        return '';
    }

    /**
     * @param  array<string, true>|null  $escapes
     */
    private function extractString(string $delimiter, ?array $escapes = null, bool $rawString = false, bool $raiseUnmatched = true): string
    {
        $text = '';
        $delimSize = strlen($delimiter);
        $escapes ??= $this->stringEscapes;
        $sql = $this->sql;

        // Fast path for simple strings: no escapes to process.
        if ($delimSize === 1) {
            $pos = $this->current - 1;
            $end = strpos($sql, $delimiter, $pos);

            if (
                $end !== false
                && ($end + 1 >= $this->size || $sql[$end + 1] !== $delimiter || ! isset($escapes[$delimiter]))
                && (! ($this->unescapedSequences !== [] || isset($escapes['\\'])) || strcspn($sql, '\\', $pos, $end - $pos) === $end - $pos)
            ) {
                $this->current = $end + 1;
                $this->end = $this->current >= $this->size;
                $this->char = $sql[$end];
                $this->peek = $this->end ? '' : $sql[$this->current];

                return substr($sql, $pos, $end - $pos);
            }
        }

        while (true) {
            $backslashEscape = ! $rawString && $this->char === '\\' && isset($escapes['\\']);

            // Numeric escapes come first, since they may overlap fixed sequences (e.g. \000 vs \0)
            if ($backslashEscape && $this->numericEscapes !== []) {
                $decoded = $this->scanNumericEscape();

                if ($decoded !== '') {
                    $text .= $decoded;

                    continue;
                }
            }

            if (! $rawString && $this->unescapedSequences !== [] && isset($escapes[$this->char]) && $this->current + 1 < $this->size) {
                $unescaped = $this->unescapedSequences[$this->char.$this->peek] ?? null;

                if ($unescaped !== null) {
                    $this->advance(2);
                    $text .= $unescaped;

                    continue;
                }
            }

            // Not decoded: drop the backslash if configured, unless the string is unterminated
            if ($backslashEscape && $this->dropUnknownEscapes && $this->current + 1 < $this->size) {
                $peek = $this->peek;
                $this->advance(2);
                $text .= $peek;

                continue;
            }

            $isValidCustomEscape = $this->escapeFollowChars !== [] && $this->char === '\\' && ! isset($this->escapeFollowChars[$this->peek]);

            $escapedDelimiter = $this->peek === $delimiter
                || ($delimSize > 1 && $this->peek !== '' && $this->peek === $delimiter[0] && isset($this->quotes[$this->peek]));

            if (
                ($this->stringEscapesAllowedInRawStrings || ! $rawString)
                && isset($escapes[$this->char])
                && ($escapedDelimiter || isset($escapes[$this->peek]) || $isValidCustomEscape)
                && (! isset($this->quotes[$this->char]) || $this->char === $this->peek)
            ) {
                if ($escapedDelimiter) {
                    $text .= $rawString ? $this->char.$this->peek : $this->peek;
                } elseif ($isValidCustomEscape && $this->char !== $this->peek) {
                    $text .= $this->peek;
                } else {
                    $text .= $this->char.$this->peek;
                }

                if ($this->current + 1 < $this->size) {
                    $this->advance(2);
                } else {
                    throw new TokenError("Missing {$delimiter} from {$this->current}");
                }
            } else {
                if ($this->chars($delimSize) === $delimiter) {
                    if ($delimSize > 1) {
                        $this->advance($delimSize - 1);
                    }

                    break;
                }

                if ($this->end) {
                    if (! $raiseUnmatched) {
                        return $text.$this->char;
                    }

                    throw new TokenError("Missing {$delimiter} from {$this->start}");
                }

                $current = $this->current - 1;
                $this->advanceAlnum();
                $text .= substr($sql, $current, $this->current - 1 - $current);
            }
        }

        return $text;
    }
}
