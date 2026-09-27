<?php

declare(strict_types=1);

namespace App\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Lets the "app.rich_text" sanitizer (config/packages/html_sanitizer.yaml) keep a paragraph's
 * alignment — the one thing the Quill editor's align toolbar button writes as inline style
 * (rich_editor_controller.js, Quill 2's default "style" align attributor) — without opening up
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
        return ['p', 'h2', 'blockquote', 'li'];
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
