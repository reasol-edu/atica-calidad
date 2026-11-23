<?php

declare(strict_types=1);

namespace App\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Lets the "app.rich_text" sanitizer (config/packages/html_sanitizer.yaml) keep a paragraph's
 * alignment — the one thing the Tiptap editor's align buttons write as inline style
 * (assets/utils/rich_text_editor.js, the TextAlign extension) — without opening up
 * the `style` attribute in general, which Symfony's sanitizer only allows or drops wholesale, with
 * no CSS-property-level filtering of its own: any other declaration slipped into the same
 * attribute (background/url() tracking pixels, position tricks, …) is discarded here rather than
 * passed through verbatim.
 */
final class RichTextAlignAttributeSanitizer implements AttributeSanitizerInterface
{
    /** @return list<string> */
    public function getSupportedElements(): array
    {
        return ['p', 'h2', 'h3', 'blockquote', 'li'];
    }

    /** @return list<string> */
    public function getSupportedAttributes(): array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if (preg_match('/text-align\s*:\s*(left|center|right|justify)\b/i', $value, $matches) !== 1) {
            return null;
        }

        return 'text-align: ' . strtolower($matches[1]) . ';';
    }
}
