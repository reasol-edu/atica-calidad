<?php

declare(strict_types=1);

namespace App\Search;

/**
 * Cuts the few words around a match out of a document's text, to show under a search result with the matched words marked.
 *
 * It works on the readable text and its folded twin (TextNormalizer::fold(): same characters, no accents, lower case), which
 * have the same length, so a position found in the folded one is the same position in the other. The result is plain parts
 * ["text", isMatch], never HTML: whoever draws it decides how to mark a match.
 */
final class SnippetBuilder
{
    /**
     * @param string       $text   the readable text, as stored
     * @param string       $folded the same text folded (equal length)
     * @param list<string> $terms  folded query terms, in the order typed
     * @param int          $radius characters kept on each side of the first match
     *
     * @return list<array{0: string, 1: bool}>|null null when no term is in the text
     */
    public static function build(string $text, string $folded, array $terms, int $radius = 70): ?array
    {
        $first = null;
        foreach ($terms as $term) {
            $at = $term === '' ? false : mb_strpos($folded, $term);
            if ($at !== false) {
                $first = [$at, mb_strlen($term)];
                break;
            }
        }
        if ($first === null) {
            return null;
        }

        $length = mb_strlen($text);
        $start  = max(0, $first[0] - $radius);
        $end    = min($length, $first[0] + $first[1] + $radius);
        $start  = self::startAtWord($text, $start, $first[0]);
        $end    = self::endAtWord($text, $end, $first[0] + $first[1], $length);

        $window       = mb_substr($text, $start, $end - $start);
        $foldedWindow = mb_substr($folded, $start, $end - $start);

        $parts = [];
        if ($start > 0) {
            $parts[] = ['…', false];
        }
        foreach (self::split($window, $foldedWindow, $terms) as $part) {
            $parts[] = $part;
        }
        if ($end < $length) {
            $parts[] = ['…', false];
        }

        return $parts;
    }

    /**
     * The window cut into runs, the matches apart.
     *
     * @param list<string> $terms
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function split(string $window, string $foldedWindow, array $terms): array
    {
        $ranges = [];
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            $size   = mb_strlen($term);
            $offset = 0;
            while (($at = mb_strpos($foldedWindow, $term, $offset)) !== false) {
                $ranges[] = [$at, $at + $size];
                $offset   = $at + $size;
            }
        }
        usort($ranges, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $merged = [];
        foreach ($ranges as $range) {
            $last = \count($merged) - 1;
            if ($last >= 0 && $range[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $range[1]);
            } else {
                $merged[] = $range;
            }
        }

        $parts  = [];
        $cursor = 0;
        foreach ($merged as [$from, $to]) {
            if ($from > $cursor) {
                $parts[] = [mb_substr($window, $cursor, $from - $cursor), false];
            }
            $parts[] = [mb_substr($window, $from, $to - $from), true];
            $cursor  = $to;
        }
        if ($cursor < mb_strlen($window)) {
            $parts[] = [mb_substr($window, $cursor), false];
        }

        return $parts;
    }

    /** Moves the start forward to the next word break when it falls inside a word, never past the match. */
    private static function startAtWord(string $text, int $start, int $matchStart): int
    {
        if ($start === 0) {
            return 0;
        }
        for ($i = $start; $i < $matchStart && $i < $start + 20; ++$i) {
            if (mb_substr($text, $i, 1) === ' ') {
                return $i + 1;
            }
        }

        return $start;
    }

    /** Moves the end back to the previous word break when it falls inside a word, never before the match ends. */
    private static function endAtWord(string $text, int $end, int $matchEnd, int $length): int
    {
        if ($end >= $length) {
            return $length;
        }
        for ($i = $end; $i > $matchEnd && $i > $end - 20; --$i) {
            if (mb_substr($text, $i, 1) === ' ') {
                return $i;
            }
        }

        return $end;
    }
}
