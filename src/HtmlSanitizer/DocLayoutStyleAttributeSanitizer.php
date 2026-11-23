<?php

declare(strict_types=1);

namespace App\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Lets the "app.doc_layout" sanitizer (config/packages/html_sanitizer.yaml) keep the inline formatting the header and footer editor
 * writes — alignment, colours, widths, a font size, borders — without opening up `style` in general (Symfony's sanitizer takes the
 * attribute or drops it, it has no CSS-property filter of its own). Every declaration is checked against a list of properties and a
 * pattern for its value; anything else (url(), expression(), position, …) is discarded.
 */
final class DocLayoutStyleAttributeSanitizer implements AttributeSanitizerInterface
{
    private const string COLOUR  = '#[0-9a-fA-F]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(?:,\s*[0-9.]+\s*)?\)|[a-zA-Z]{3,20}';
    private const string LENGTH  = '-?\d+(?:\.\d+)?(?:px|pt|em|rem|%|cm|mm)?';
    private const string BORDER  = '(?:' . self::LENGTH . '|none|solid|dashed|dotted|' . self::COLOUR . ')(?:\s+(?:' . self::LENGTH . '|none|solid|dashed|dotted|' . self::COLOUR . ')){0,3}';

    /** @var array<string, string> property => pattern of the whole value */
    private const array ALLOWED = [
        'text-align'       => 'left|center|right|justify',
        'vertical-align'   => 'top|middle|bottom',
        'color'            => self::COLOUR,
        'background-color' => self::COLOUR,
        'font-size'        => '\d+(?:\.\d+)?(?:px|pt|em|rem|%)',
        'font-weight'      => 'normal|bold|[1-9]00',
        'font-style'       => 'normal|italic',
        'width'            => self::LENGTH,
        'min-width'        => self::LENGTH,
        'height'           => self::LENGTH,
        'padding'          => self::LENGTH . '(?:\s+' . self::LENGTH . '){0,3}',
        'border'           => self::BORDER,
        'border-top'       => self::BORDER,
        'border-bottom'    => self::BORDER,
        'border-left'      => self::BORDER,
        'border-right'     => self::BORDER,
        'border-collapse'  => 'collapse|separate',
    ];

    /** @return list<string> */
    public function getSupportedElements(): array
    {
        return ['table', 'col', 'td', 'th', 'p', 'span'];
    }

    /** @return list<string> */
    public function getSupportedAttributes(): array
    {
        return ['style', 'colspan', 'rowspan', 'data-colwidth'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        // Merged cells and the widths the editor remembers: numbers only (the span is capped, a table has few columns).
        if ($attribute === 'colspan' || $attribute === 'rowspan') {
            return preg_match('/^\d{1,2}$/', $value) === 1 && (int) $value >= 1 ? $value : null;
        }
        if ($attribute === 'data-colwidth') {
            return preg_match('/^\d{1,4}(?:,\d{1,4})*$/', $value) === 1 ? $value : null;
        }

        $kept = [];
        foreach (explode(';', $value) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $property = strtolower(trim($parts[0]));
            $v        = trim($parts[1]);
            if (!isset(self::ALLOWED[$property]) || $v === '' || preg_match('/^(?:' . self::ALLOWED[$property] . ')$/i', $v) !== 1) {
                continue;
            }
            $kept[$property] = $property . ': ' . $v;
        }

        return $kept === [] ? null : implode('; ', $kept) . ';';
    }
}
