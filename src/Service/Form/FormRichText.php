<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * The value of a «texto largo» field is rich text (HTML cleaned by the sanitizer); one stored before it was, or typed by a client that
 * knows nothing of it, is plain text. These are the conversions between the two ways of reading the same value.
 */
final class FormRichText
{
    /** The most characters of HTML a value keeps (the sanitizer's own limit). */
    public const int MAX = 50000;

    /** Whether the value has tags in it (otherwise it is plain text). */
    public static function isHtml(string $value): bool
    {
        return $value !== strip_tags($value);
    }

    /** The HTML of a value: itself when it is HTML, and the plain text as paragraphs (a blank line splits them, a line break is a `<br>`) when not. */
    public static function html(string $value): string
    {
        $value = trim($value);
        if ($value === '' || self::isHtml($value)) {
            return $value;
        }
        $paragraphs = [];
        foreach (preg_split('/\R{2,}/', $value) ?: [] as $paragraph) {
            $lines        = array_map(static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES | ENT_HTML5, 'UTF-8'), preg_split('/\R/', trim($paragraph)) ?: []);
            $paragraphs[] = '<p>' . implode('<br>', $lines) . '</p>';
        }

        return implode('', $paragraphs);
    }

    /** What the value says as plain text, paragraphs and list items on lines of their own (for a comparison, a Word file, a title). */
    public static function plain(string $value): string
    {
        return self::isHtml($value) ? implode("\n", FormOfficeWriter::paragraphs($value)) : trim($value);
    }
}
