<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Wraps every case-insensitive occurrence of $query inside $text with <mark>, to highlight which
 * part of a document (name, upload profile, uploader) matched the section's local search box.
 * Escapes $text itself, so callers pass the raw value directly — no separate |escape needed, and
 * the filter is declared html-safe so no |raw is needed either.
 */
final class HighlightExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('highlight', $this->highlight(...), ['is_safe' => ['html']]),
        ];
    }

    public function highlight(?string $text, string $query): string
    {
        $text = (string) $text;
        $escaped = htmlspecialchars($text, ENT_QUOTES);

        $query = trim($query);
        if ($query === '') {
            return $escaped;
        }

        // Match against the escaped text using the identically-escaped query, so the highlighted
        // span always lands on real character boundaries of the (already HTML-safe) output —
        // no need to translate offsets between a raw and an escaped version of $text.
        $escapedQuery = htmlspecialchars($query, ENT_QUOTES);
        $pattern      = '/(' . preg_quote($escapedQuery, '/') . ')/iu';

        return preg_replace($pattern, '<mark class="rounded-sm bg-amber-200 px-0.5">$1</mark>', $escaped) ?? $escaped;
    }
}
