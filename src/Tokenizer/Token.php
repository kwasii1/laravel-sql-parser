<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Tokenizer;

/**
 * A single token, as returned by the public tokenize() API.
 *
 * The parser itself reads tokens from a TokenStream and never builds these objects.
 */
final readonly class Token
{
    /**
     * @param  list<string>  $comments
     */
    public function __construct(
        public int $type,
        public string $text,
        public int $line = 1,
        public int $col = 1,
        public int $start = 0,
        public int $end = 0,
        public array $comments = [],
    ) {}

    public function typeName(): string
    {
        return TokenType::name($this->type);
    }
}
