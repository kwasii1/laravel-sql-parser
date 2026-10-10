<?php

declare(strict_types=1);

namespace Kwasii\LaravelSqlParser\Support;

use Kwasii\LaravelSqlParser\Dialects\Dialect;
use Kwasii\LaravelSqlParser\Errors\ParseError;
use Kwasii\LaravelSqlParser\Expressions\Expression;
use Kwasii\LaravelSqlParser\Expressions\JSONPath as JSONPathExpression;
use Kwasii\LaravelSqlParser\Expressions\JSONPathFilter;
use Kwasii\LaravelSqlParser\Expressions\JSONPathKey;
use Kwasii\LaravelSqlParser\Expressions\JSONPathRecursive;
use Kwasii\LaravelSqlParser\Expressions\JSONPathRoot;
use Kwasii\LaravelSqlParser\Expressions\JSONPathScript;
use Kwasii\LaravelSqlParser\Expressions\JSONPathSelector;
use Kwasii\LaravelSqlParser\Expressions\JSONPathSlice;
use Kwasii\LaravelSqlParser\Expressions\JSONPathSubscript;
use Kwasii\LaravelSqlParser\Expressions\JSONPathUnion;
use Kwasii\LaravelSqlParser\Expressions\JSONPathWildcard;
use Kwasii\LaravelSqlParser\Tokenizer\TokenStream;
use Kwasii\LaravelSqlParser\Tokenizer\TokenType;

/**
 * Parses JSONPath strings such as `$.a[0].b` into JSONPath expressions (sqlglot jsonpath.parse).
 */
final class JsonPath
{
    private TokenStream $tokens;

    private int $size;

    private int $i = 0;

    /** @var array<array-key, mixed> a set keyed by token type */
    private array $varTokens;

    private function __construct(private readonly string $path, private readonly Dialect $dialect)
    {
        $this->tokens = $dialect->jsonPathTokenizer()->tokenize($path);
        $this->size = count($this->tokens->types);
        $this->varTokens = $dialect->jsonPathTokenizerTable('VAR_TOKENS');
    }

    public static function parse(string $path, Dialect $dialect): JSONPathExpression
    {
        return (new self($path, $dialect))->parsePath();
    }

    private function curr(): ?int
    {
        return $this->i < $this->size ? $this->tokens->types[$this->i] : null;
    }

    private function prevText(): string
    {
        return $this->tokens->texts[$this->i - 1];
    }

    private function error(string $message): ParseError
    {
        return new ParseError("{$message} at index {$this->i}: {$this->path}");
    }

    private function match(int $type, bool $raiseUnmatched = false): bool
    {
        if ($this->curr() === $type) {
            $this->i++;

            return true;
        }

        if ($raiseUnmatched) {
            throw $this->error('Expected TokenType.'.TokenType::name($type));
        }

        return false;
    }

    /**
     * @param  array<int, true>  $types
     */
    private function matchSet(array $types): bool
    {
        $curr = $this->curr();

        if ($curr !== null && isset($types[$curr])) {
            $this->i++;

            return true;
        }

        return false;
    }

    private function parseLiteral(): string|int|Expression|false
    {
        if ($this->match(TokenType::STRING) || $this->match(TokenType::IDENTIFIER)) {
            return $this->prevText();
        }

        if ($this->match(TokenType::STAR)) {
            return new JSONPathWildcard;
        }

        if ($this->match(TokenType::PLACEHOLDER) || $this->match(TokenType::L_PAREN)) {
            $script = $this->prevText() === '(';
            $start = $this->i;

            while (true) {
                if ($this->match(TokenType::L_BRACKET)) {
                    $this->parseBracket();
                }

                $curr = $this->curr();

                if ($curr === TokenType::R_BRACKET || $curr === null) {
                    break;
                }

                $this->i++;
            }

            $class = $script ? JSONPathScript::class : JSONPathFilter::class;
            $end = $this->i < $this->size ? $this->tokens->ends[$this->i] : $this->tokens->ends[$this->size - 1];
            $startOffset = $this->tokens->starts[$start];

            return new $class(['this' => substr($this->path, $startOffset, $end - $startOffset)]);
        }

        $number = $this->match(TokenType::DASH) ? '-' : '';

        if ($this->match(TokenType::NUMBER)) {
            $number .= $this->prevText();
        }

        if ($number !== '') {
            return (int) $number;
        }

        return false;
    }

    private function parseSlice(): string|int|Expression|false
    {
        $start = $this->parseLiteral();
        $end = $this->match(TokenType::COLON) ? $this->parseLiteral() : null;
        $step = $this->match(TokenType::COLON) ? $this->parseLiteral() : null;

        if ($end === null && $step === null) {
            return $start;
        }

        return new JSONPathSlice(['start' => $start, 'end' => $end, 'step' => $step]);
    }

    private function parseBracket(): Expression
    {
        $literal = $this->parseSlice();

        if ($literal === false) {
            throw $this->error('Cannot have empty segment');
        }

        $indexes = [$literal];

        while ($this->match(TokenType::COMMA)) {
            $literal = $this->parseSlice();

            if ($literal !== false) {
                $indexes[] = $literal;
            }
        }

        if (count($indexes) === 1) {
            if (is_string($indexes[0])) {
                $node = new JSONPathKey(['this' => $indexes[0]]);
            } elseif ($indexes[0] instanceof JSONPathScript || $indexes[0] instanceof JSONPathFilter) {
                $node = new JSONPathSelector(['this' => $indexes[0]]);
            } else {
                $node = new JSONPathSubscript(['this' => $indexes[0]]);
            }
        } else {
            $node = new JSONPathUnion(['expressions' => $indexes]);
        }

        $this->match(TokenType::R_BRACKET, raiseUnmatched: true);

        return $node;
    }

    private function parseVarText(): string
    {
        $prevIndex = $this->i - 2;

        while ($this->matchSet($this->varTokens)) {
            // consume the whole run of var tokens
        }

        $start = $prevIndex < 0 ? 0 : $this->tokens->ends[$prevIndex] + 1;

        if ($this->i >= $this->size) {
            return substr($this->path, $start);
        }

        return substr($this->path, $start, $this->tokens->starts[$this->i] - $start);
    }

    private function parsePath(): JSONPathExpression
    {
        $this->match(TokenType::DOLLAR);
        $expressions = [new JSONPathRoot];

        while ($this->curr() !== null) {
            if ($this->match(TokenType::DOT) || $this->match(TokenType::COLON)) {
                $recursive = $this->prevText() === '..';

                if ($this->matchSet($this->varTokens)) {
                    $value = $this->parseVarText();
                } elseif ($this->match(TokenType::IDENTIFIER)) {
                    $value = $this->prevText();
                } elseif ($this->match(TokenType::STAR)) {
                    $value = new JSONPathWildcard;
                } else {
                    $value = null;
                }

                if ($recursive) {
                    $expressions[] = new JSONPathRecursive(['this' => $value]);
                } elseif ($value !== null && $value !== '') {
                    $expressions[] = new JSONPathKey(['this' => $value]);
                } elseif ($this->dialect->settings['JSON_PATH_SINGLE_DOT_IS_WILDCARD'] !== true) {
                    throw $this->error('Expected key name or * after DOT');
                }
            } elseif ($this->match(TokenType::L_BRACKET)) {
                $expressions[] = $this->parseBracket();
            } elseif ($this->matchSet($this->varTokens)) {
                $expressions[] = new JSONPathKey(['this' => $this->parseVarText()]);
            } elseif ($this->match(TokenType::IDENTIFIER)) {
                $expressions[] = new JSONPathKey(['this' => $this->prevText()]);
            } elseif ($this->match(TokenType::STAR)) {
                $expressions[] = new JSONPathWildcard;
            } else {
                throw $this->error('Unexpected TokenType.'.TokenType::name((int) $this->curr()));
            }
        }

        return new JSONPathExpression(['expressions' => $expressions]);
    }
}
