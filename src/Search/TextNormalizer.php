<?php

declare(strict_types=1);

namespace App\Search;

/**
 * The one definition of "the same text" for searching: lower case, no accents or diaeresis. The text index stores its text
 * through clean() and fold() and the terms are normalised the same way before they reach the database, so a plain LIKE on
 * the stored column behaves identically on PostgreSQL, MySQL and SQLite (the per-engine UNACCENT() of the name searches
 * does not: SQLite has none).
 */
final class TextNormalizer
{
    /** The LIKE escape character: not a backslash, which MySQL, PostgreSQL and SQLite do not all treat alike inside a string literal. */
    public const string LIKE_ESCAPE = '!';

    /**
     * Text as it is stored: valid UTF-8, composed (NFC), without NUL or other control characters (PostgreSQL refuses a NUL
     * in a text column, MySQL an invalid byte sequence, and PDF extraction produces both) and with every run of
     * whitespace turned into one space.
     */
    public static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text) ?? $text;
        $composed = \Normalizer::normalize($text, \Normalizer::FORM_C);
        $text     = \is_string($composed) ? $composed : $text;
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return trim($collapsed ?? $text);
    }

    /**
     * Lower case and without accents, with exactly as many characters as the input: an offset found in the folded text is
     * the same offset in the original, which is how a snippet is cut from the readable text. Meant for text that went through
     * clean(); a character that would not fold one for one (decomposed accents, special case mappings) is folded on its own.
     */
    public static function fold(string $text): string
    {
        $folded = self::foldWhole($text);
        if (mb_strlen($folded) === mb_strlen($text)) {
            return $folded;
        }

        return preg_replace_callback('/./su', static function (array $m): string {
            $one = self::foldWhole($m[0]);

            return mb_strlen($one) === 1 ? $one : (mb_substr($one, 0, 1) !== '' ? mb_substr($one, 0, 1) : $m[0]);
        }, $text) ?? $folded;
    }

    /** A query term, or any text compared with one: cleaned and folded. */
    public static function normalize(string $text): string
    {
        return self::fold(self::clean($text));
    }

    /**
     * The words of a query, normalised, without repeats, in the order typed.
     *
     * @return list<string>
     */
    public static function terms(string $query): array
    {
        $words = preg_split('/ /', self::normalize($query), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : array_values(array_unique($words));
    }

    /** "%term%" with %, _ and the escape character escaped, for `LIKE :p ESCAPE '!'`. */
    public static function likeContains(string $term): string
    {
        return '%' . strtr($term, [self::LIKE_ESCAPE => self::LIKE_ESCAPE . self::LIKE_ESCAPE, '%' => self::LIKE_ESCAPE . '%', '_' => self::LIKE_ESCAPE . '_']) . '%';
    }

    private static function foldWhole(string $text): string
    {
        // Without a decomposition "é" is one character and the accent cannot be told apart from the letter.
        $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
        $stripped   = preg_replace('/\p{Mn}+/u', '', \is_string($decomposed) ? $decomposed : $text) ?? $text;

        return mb_strtolower($stripped, 'UTF-8');
    }
}
