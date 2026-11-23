<?php

declare(strict_types=1);

namespace App\Service\Form;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * The page layout of a model's documents: the running header and footer (rich text with tables, where `{tokens}` stand for the centre's
 * name, the page number, the code…), an optional header for the first page, the orientation, the font scale and the margins. It is a
 * part of the model's definition (`layout`), so it is versioned with each revision; a header, footer or margins left `null` are the
 * ones the centre (or the platform) has in its settings, and a first-page header left `null` is the normal header.
 *
 * @phpstan-type Margins array{top: int, right: int, bottom: int, left: int}
 * @phpstan-type Layout array{header: ?string, footer: ?string, firstHeader: ?string, orientation: string, fontScale: int, margins: ?Margins}
 */
final class FormLayout
{
    public const string PORTRAIT  = 'portrait';
    public const string LANDSCAPE = 'landscape';

    public const array ORIENTATIONS = [self::PORTRAIT, self::LANDSCAPE];

    public const int SCALE_MIN     = 50;
    public const int SCALE_MAX     = 150;
    public const int SCALE_DEFAULT = 100;

    /** The margins, in millimetres. */
    public const int MARGIN_MIN = 5;
    public const int MARGIN_MAX = 40;

    /**
     * The fields the header and footer can show, as they are written in the text. The page numbers and the logos are drawn by whoever
     * makes the file (the PDF library, the word processor); the rest are known when the file is made.
     */
    public const array TOKENS = ['centro', 'localidad', 'logo', 'logo2', 'pagina', 'paginas', 'codigo', 'titulo', 'revision', 'fecha_revision', 'fecha_aprobacion'];

    public const array PAGE_TOKENS = ['pagina', 'paginas'];

    public const array IMAGE_TOKENS = ['logo', 'logo2'];

    /** The border of a cell: what the editor gives a new one and what the default tables use. */
    private const string CELL = 'border: 1px solid #555555; padding: 4px';

    /** @return Layout */
    public static function defaults(): array
    {
        return ['header' => null, 'footer' => null, 'firstHeader' => null, 'orientation' => self::PORTRAIT, 'fontScale' => self::SCALE_DEFAULT, 'margins' => null];
    }

    /**
     * What a header or footer says when neither the model nor the settings say anything: a table like the ones of the centre's usual
     * documents — name and town, title, then code, revision and page.
     */
    public static function defaultHeader(): string
    {
        $cell = static fn (string $content): string => '<td style="' . self::CELL . '; text-align: center"><p style="text-align: center">' . $content . '</p></td>';
        $logo = static fn (string $token, string $width): string => '<td rowspan="3" data-colwidth="' . $width . '" style="' . self::CELL . '; text-align: center; vertical-align: middle"><p style="text-align: center">' . $token . '</p></td>';

        // Five columns like the centre's ODT documents: the logo of the centre, the text (three columns wide, in three rows) and the logo of
        // the education authority, each logo as tall as the three rows.
        return '<table style="width: 100%; border-collapse: collapse"><tbody>'
            . '<tr>' . $logo('{logo}', '373')
            . '<td colspan="3" data-colwidth="480,232,272" style="' . self::CELL . '; text-align: center; vertical-align: middle; background-color: #eef2ea"><p style="text-align: center"><strong>{centro} - {localidad}</strong></p></td>'
            . $logo('{logo2}', '348') . '</tr>'
            . '<tr><td colspan="3" style="' . self::CELL . '; text-align: center"><p style="text-align: center"><strong>{titulo}</strong></p></td></tr>'
            . '<tr>' . $cell('{codigo}') . $cell('REV: {revision}') . $cell('Página {pagina} de {paginas}') . '</tr>'
            . '</tbody></table>';
    }

    public static function defaultFooter(): string
    {
        return '<table style="width: 100%; border-collapse: collapse"><tbody><tr>'
            . '<td style="border: none; padding: 2px"><p>{codigo}</p></td>'
            . '<td style="border: none; padding: 2px; text-align: right"><p style="text-align: right">Fecha: {fecha_revision}</p></td>'
            . '</tr></tbody></table>';
    }

    /**
     * @param ?HtmlSanitizerInterface $sanitizer the "app.doc_layout" one; without it a header or footer cannot be trusted and is dropped
     *
     * @return Layout
     */
    public static function clean(mixed $raw, ?HtmlSanitizerInterface $sanitizer): array
    {
        $raw = \is_array($raw) ? $raw : [];

        $orientation = \is_string($raw['orientation'] ?? null) && \in_array($raw['orientation'], self::ORIENTATIONS, true) ? $raw['orientation'] : self::PORTRAIT;
        $scale       = self::number($raw['fontScale'] ?? null) ?? self::SCALE_DEFAULT;

        return [
            'header'      => self::html($raw['header'] ?? null, $sanitizer),
            'footer'      => self::html($raw['footer'] ?? null, $sanitizer),
            'firstHeader' => self::html($raw['firstHeader'] ?? null, $sanitizer),
            'orientation' => $orientation,
            'fontScale'   => max(self::SCALE_MIN, min(self::SCALE_MAX, $scale)),
            'margins'     => self::margins($raw['margins'] ?? null),
        ];
    }

    /**
     * The margins of the model, or null (the centre's): the four of them, each between the limits; one that is missing or not a number
     * makes the model not have margins of its own at all.
     *
     * @return ?Margins
     */
    public static function margins(mixed $raw): ?array
    {
        if (!\is_array($raw)) {
            return null;
        }
        $margins = [];
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $value = self::number($raw[$side] ?? null);
            if ($value === null) {
                return null;
            }
            $margins[$side] = max(self::MARGIN_MIN, min(self::MARGIN_MAX, $value));
        }

        return $margins;
    }

    private static function number(mixed $value): ?int
    {
        return \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)) ? (int) round((float) $value) : null;
    }

    /** The sanitized HTML of a header or footer, or null when it is not set (or has nothing but empty paragraphs). */
    public static function html(mixed $value, ?HtmlSanitizerInterface $sanitizer): ?string
    {
        if (!\is_string($value) || $sanitizer === null || self::isEmpty($value)) {
            return null;
        }
        $clean = trim($sanitizer->sanitize(mb_substr($value, 0, 100000)));

        return self::isEmpty($clean) ? null : $clean;
    }

    /** Nothing visible: no text and no table (the editor gives `<p></p>` for an empty document). */
    public static function isEmpty(string $html): bool
    {
        return trim(strip_tags($html)) === '' && stripos($html, '<table') === false;
    }

    /**
     * Fills the tokens of a header or footer in. The values are plain text (escaped here); a token without a value, the two page
     * numbers when `$pages` is null, and the logos (which are images) are left as they are for the caller to turn into the fields
     * of its format.
     *
     * @param array<string, string>  $values token (without braces) => text
     * @param ?array{0: string, 1: string} $pages what `{pagina}` and `{paginas}` become (already valid in the output format), or null to keep them
     */
    public static function fill(string $html, array $values, ?array $pages = null): string
    {
        $pageValues = $pages !== null ? ['pagina' => $pages[0], 'paginas' => $pages[1]] : [];

        return (string) preg_replace_callback('/\{([a-z0-9_]+)\}/', static function (array $m) use ($values, $pageValues): string {
            $name = $m[1];
            if (isset($pageValues[$name])) {
                return $pageValues[$name];
            }
            if (\in_array($name, self::PAGE_TOKENS, true) || \in_array($name, self::IMAGE_TOKENS, true) || !isset($values[$name])) {
                return $m[0];
            }

            return htmlspecialchars($values[$name], ENT_QUOTES);
        }, $html);
    }
}
