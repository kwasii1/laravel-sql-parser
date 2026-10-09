<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Tokenizer;

/**
 * The tokenizer's output, stored as parallel arrays (struct-of-arrays) instead of one object
 * per token: it's cheaper to build and to read in the parser's hot loop.
 *
 * Token i has type $types[i], text $texts[i] and spans bytes $starts[i]..$ends[i] (inclusive)
 * of $sql. Comments are sparse, keyed by token index. Line and column are derived from byte
 * offsets on demand.
 */
final class TokenStream
{
    /** @var list<int> */
    public array $types = [];

    /** @var list<string> */
    public array $texts = [];

    /** @var list<int> */
    public array $starts = [];

    /** @var list<int> */
    public array $ends = [];

    /** @var array<int, list<string>> */
    public array $comments = [];

    public function __construct(public readonly string $sql = '') {}

    public function count(): int
    {
        return count($this->types);
    }

    public function token(int $index): Token
    {
        [$line, $col] = $this->position($this->ends[$index]);

        return new Token(
            $this->types[$index],
            $this->texts[$index],
            $line,
            $col,
            $this->starts[$index],
            $this->ends[$index],
            $this->comments[$index] ?? [],
        );
    }

    /**
     * @return list<Token>
     */
    public function tokens(): array
    {
        $tokens = [];
        $count = count($this->types);

        for ($i = 0; $i < $count; $i++) {
            $tokens[] = $this->token($i);
        }

        return $tokens;
    }

    /**
     * Line (1-based) and column (1-based, in characters) of a byte offset.
     *
     * A "\r\n" pair counts as a single line break, like sqlglot.
     *
     * @return array{0: int, 1: int}
     */
    public function position(int $offset): array
    {
        $sql = $this->sql;
        $prefix = substr($sql, 0, max(0, $offset + 1));
        $line = 1 + substr_count($prefix, "\n") + substr_count($prefix, "\r") - substr_count($prefix, "\r\n");
        $lastBreak = max(strrpos($prefix, "\n") === false ? -1 : (int) strrpos($prefix, "\n"), strrpos($prefix, "\r") === false ? -1 : (int) strrpos($prefix, "\r"));
        $column = substr($prefix, $lastBreak + 1);

        return [$line, max(1, mb_strlen($column, 'UTF-8'))];
    }
}
