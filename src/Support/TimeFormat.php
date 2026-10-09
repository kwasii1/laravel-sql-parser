<?php

declare(strict_types=1);

namespace LaravelSqlParser\LaravelSqlParser\Support;

/**
 * Converts time format strings between dialects (sqlglot time.format_time).
 *
 * The longest key of the mapping found at each position is replaced; other characters are kept.
 */
final class TimeFormat
{
    /**
     * @param  array<string, string>  $mapping
     * @param  array<array-key, mixed>  $trie  a character trie of the mapping's keys ('' marks an end)
     */
    public static function convert(?string $format, array $mapping, array $trie): ?string
    {
        if ($format === null || $format === '') {
            return null;
        }

        $start = 0;
        $end = 1;
        $size = strlen($format);
        $current = $trie;
        $chunks = [];
        $sym = null;
        $chars = '';

        while ($end <= $size) {
            $chars = substr($format, $start, $end - $start);
            $char = $chars[strlen($chars) - 1];

            if (isset($current[$char])) {
                $current = $current[$char];
                $result = isset($current['']) ? 'exists' : 'prefix';
            } else {
                $result = 'failed';
            }

            if ($result === 'failed') {
                if ($sym !== null) {
                    $end--;
                    $chars = $sym;
                    $sym = null;
                } else {
                    $chars = $chars[0];
                    $end = $start + 1;
                }

                $start += strlen($chars);
                $chunks[] = $chars;
                $current = $trie;
            } elseif ($result === 'exists') {
                $sym = $chars;
            }

            $end++;

            if ($result !== 'failed' && $end > $size) {
                $chunks[] = $chars;
            }
        }

        $out = '';
        foreach ($chunks as $chunk) {
            $out .= $mapping[$chunk] ?? $chunk;
        }

        return $out;
    }

    /**
     * Build the character trie of a mapping's keys, for mappings that aren't pre-generated.
     *
     * @param  array<string, string>  $mapping
     * @return array<array-key, mixed>
     */
    public static function trie(array $mapping): array
    {
        $trie = [];

        foreach (array_keys($mapping) as $key) {
            $trie = self::insert($trie, (string) $key);
        }

        return $trie;
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @return array<array-key, mixed>
     */
    private static function insert(array $node, string $key): array
    {
        if ($key === '') {
            $node[''] = true;

            return $node;
        }

        $child = $node[$key[0]] ?? [];
        $node[$key[0]] = self::insert(is_array($child) ? $child : [], substr($key, 1));

        return $node;
    }

    /**
     * Convert with a mapping whose trie is built (and cached) on demand.
     *
     * @param  array<string, string>  $mapping
     */
    public static function convertWith(?string $format, array $mapping): ?string
    {
        static $tries = [];

        $key = implode("\0", array_keys($mapping));

        return self::convert($format, $mapping, $tries[$key] ??= self::trie($mapping));
    }
}
