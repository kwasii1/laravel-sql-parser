<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Dialects\Athena;

use Kwasii\LaravelSqlParser\Dialects\Trino\TrinoTokenizerTables as Trino;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Athena extensions to the Trino tokenizer (sqlglot dialects/athena.py _TrinoTokenizer): Trino's
 * tables with UNLOAD as a command.
 */
final class AthenaTrinoTokenizerTables
{
    public const bool NESTED_COMMENTS = Trino::NESTED_COMMENTS;

    public const string HINT_START = Trino::HINT_START;

    public const bool HAS_BIT_STRINGS = Trino::HAS_BIT_STRINGS;

    public const bool HAS_HEX_STRINGS = Trino::HAS_HEX_STRINGS;

    public const bool STRING_ESCAPES_ALLOWED_IN_RAW_STRINGS = Trino::STRING_ESCAPES_ALLOWED_IN_RAW_STRINGS;

    public const bool HEREDOC_TAG_IS_IDENTIFIER = Trino::HEREDOC_TAG_IS_IDENTIFIER;

    public const int HEREDOC_STRING_ALTERNATIVE = Trino::HEREDOC_STRING_ALTERNATIVE;

    public const bool NUMBERS_CAN_BE_UNDERSCORE_SEPARATED = Trino::NUMBERS_CAN_BE_UNDERSCORE_SEPARATED;

    public const bool NUMBERS_CAN_HAVE_DECIMALS = Trino::NUMBERS_CAN_HAVE_DECIMALS;

    public const bool IDENTIFIERS_CAN_START_WITH_DIGIT = Trino::IDENTIFIERS_CAN_START_WITH_DIGIT;

    public const bool DROP_UNKNOWN_ESCAPES = Trino::DROP_UNKNOWN_ESCAPES;

    public const string VAR_STOP_CHARS = Trino::VAR_STOP_CHARS;

    public const array SINGLE_TOKENS = Trino::SINGLE_TOKENS;

    public const array KEYWORDS = [
        ...Trino::KEYWORDS,
        'UNLOAD' => TokenType::COMMAND,
    ];

    public const array QUOTES = Trino::QUOTES;

    public const array IDENTIFIERS = Trino::IDENTIFIERS;

    public const array FORMAT_STRINGS = Trino::FORMAT_STRINGS;

    public const array STRING_ESCAPES = Trino::STRING_ESCAPES;

    public const array STRING_ESCAPES_LIST = Trino::STRING_ESCAPES_LIST;

    public const array BYTE_STRING_ESCAPES = Trino::BYTE_STRING_ESCAPES;

    public const array IDENTIFIER_ESCAPES = Trino::IDENTIFIER_ESCAPES;

    public const array ESCAPE_FOLLOW_CHARS = Trino::ESCAPE_FOLLOW_CHARS;

    public const array COMMENTS = Trino::COMMENTS;

    public const array COMMANDS = Trino::COMMANDS;

    public const array COMMAND_PREFIX_TOKENS = Trino::COMMAND_PREFIX_TOKENS;

    public const array TOKENS_PRECEDING_HINT = Trino::TOKENS_PRECEDING_HINT;

    public const array NUMERIC_LITERALS = Trino::NUMERIC_LITERALS;

    public const array VAR_SINGLE_TOKENS = Trino::VAR_SINGLE_TOKENS;

    public const array NUMERIC_ESCAPES = Trino::NUMERIC_ESCAPES;

    public const array UNESCAPED_SEQUENCES = Trino::UNESCAPED_SEQUENCES;

    public const array KEYWORD_TRIE = Trino::KEYWORD_TRIE;

    public const array KEYWORD_TRIE_STARTS = Trino::KEYWORD_TRIE_STARTS;
}
