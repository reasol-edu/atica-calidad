<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * A resolved document (FormResolver) as a word-processor file, DOCX or ODT, to keep working on it outside the application: the
 * same sections, texts, field lists and tables the web and the PDF show. Built by hand (a ZIP of XML) — no library, the format is
 * small enough for what a form needs: headings, paragraphs and tables.
 */
/**
 * @phpstan-type Item array{0: 'h', 1: int, 2: string}|array{0: 'p', 1: string, 2: bool}|array{0: 't', 1: list<list<string>>, 2: int}
 */
final class FormOfficeWriter
{
    public const array FORMATS = ['docx', 'odt'];

    /**
     * @param list<array<string, mixed>> $sections the output of FormResolver::resolve()
     *
     * @return string the file's content
     */
    public function build(string $format, string $title, ?string $code, string $subtitle, array $sections): string
    {
        $items = array_merge(
            [self::heading(($code !== null && $code !== '' ? $code . ' · ' : '') . $title)],
            $subtitle !== '' ? [['p', $subtitle, false]] : [],
            $this->collect($sections),
        );

        return $format === 'odt' ? $this->odt($items, $title) : $this->docx($items, $title);
    }

    /**
     * @param list<array<string, mixed>> $sections
     *
     * @return list<Item> ['h', level, text] | ['p', text, bold] | ['t', rows, header row count]
     */
    private function collect(array $sections): array
    {
        $items = [];
        foreach ($sections as $s) {
            $number = FormDefinition::str($s['number'] ?? '');
            $name   = FormDefinition::str($s['title'] ?? '');
            if ($name !== '') {
                $items[] = ['h', max(1, min(5, FormDefinition::int($s['level'] ?? 1))), ($number !== '' ? $number . '. ' : '') . $name];
            }
            foreach (FormDefinition::listOf($s['blocks'] ?? []) as $b) {
                $kind = $b['kind'] ?? '';
                if ($kind === 'text') {
                    array_push($items, ...$this->text($b));
                } elseif ($kind === 'fields') {
                    $items[] = ['t', array_map(static fn (array $i): array => [FormDefinition::str($i['label'] ?? ''), FormDefinition::str($i['value'] ?? '')], FormDefinition::listOf($b['items'] ?? [])), 0];
                } elseif ($kind === 'table') {
                    array_push($items, ...$this->table($b));
                }
            }
            array_push($items, ...$this->collect(FormDefinition::listOf($s['children'] ?? [])));
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return list<Item>
     */
    private function text(array $b): array
    {
        $items = [];
        if (FormDefinition::str($b['label'] ?? '') !== '') {
            $items[] = ['p', FormDefinition::str($b['label']), true];
        }
        foreach (self::paragraphs(FormDefinition::str($b['html'] ?? '')) as $paragraph) {
            $items[] = ['p', $paragraph, false];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return list<Item>
     */
    private function table(array $b): array
    {
        $items = [];
        if (FormDefinition::str($b['label'] ?? '') !== '') {
            $items[] = ['p', FormDefinition::str($b['label']), true];
        }
        $fixed  = (bool) ($b['fixed'] ?? false);
        $header = array_map(static fn (mixed $c): string => FormDefinition::str($c), \is_array($b['columns'] ?? null) ? array_values($b['columns']) : []);
        $rows   = [$fixed ? array_merge([FormDefinition::str($b['rowHeader'] ?? '')], $header) : $header];
        foreach (FormDefinition::listOf($b['rows'] ?? []) as $r) {
            $cells  = array_map(static fn (mixed $c): string => FormDefinition::str($c), \is_array($r['cells'] ?? null) ? array_values($r['cells']) : []);
            $rows[] = $fixed ? array_merge([FormDefinition::str($r['label'] ?? '')], $cells) : $cells;
        }
        if (\is_array($b['totals'] ?? null) && $b['totals'] !== []) {
            $totals = array_map(static fn (mixed $c): string => FormDefinition::str($c), array_values($b['totals']));
            $rows[] = $fixed ? array_merge(['Total'], $totals) : $totals;
        }
        $items[] = ['t', $rows, 1];

        return $items;
    }

    /** @return array{0: 'h', 1: int, 2: string} the document's title (level 0) */
    private static function heading(string $text): array
    {
        return ['h', 0, $text];
    }

    /** @return list<string> the paragraphs of a piece of rich text, lists as "• item" lines */
    public static function paragraphs(string $html): array
    {
        $html = preg_replace(['~<li[^>]*>~i', '~</(p|div|li|ul|ol|h[1-6]|tr)>~i', '~<br\s*/?>~i'], ["\n• ", "\n", "\n"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return array_values(array_filter(array_map(static fn (string $l): string => trim(preg_replace('/\s+/u', ' ', $l) ?? $l), explode("\n", $text)), static fn (string $l): bool => $l !== ''));
    }

    private static function x(string $text): string
    {
        // No control characters: XML 1.0 does not allow them.
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @param list<Item> $items */
    private function docx(array $items, string $title): string
    {
        $body = '';
        foreach ($items as $item) {
            if ($item[0] === 'h') {
                $style = $item[1] === 0 ? 'Title' : 'Heading' . $item[1];
                $body .= '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr><w:r><w:t xml:space="preserve">' . self::x($item[2]) . '</w:t></w:r></w:p>';
            } elseif ($item[0] === 'p') {
                $body .= '<w:p><w:r>' . ($item[2] ? '<w:rPr><w:b/></w:rPr>' : '') . '<w:t xml:space="preserve">' . self::x($item[1]) . '</w:t></w:r></w:p>';
            } else {
                $body .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>'
                    . implode('', array_map(static fn (string $e): string => '<w:' . $e . ' w:val="single" w:sz="4" w:space="0" w:color="999999"/>', ['top', 'left', 'bottom', 'right', 'insideH', 'insideV']))
                    . '</w:tblBorders></w:tblPr>';
                foreach ($item[1] as $i => $row) {
                    $body .= '<w:tr>';
                    foreach ($row as $cell) {
                        $bold  = $i < $item[2];
                        $body .= '<w:tc><w:p><w:r>' . ($bold ? '<w:rPr><w:b/></w:rPr>' : '') . '<w:t xml:space="preserve">' . self::x($cell) . '</w:t></w:r></w:p></w:tc>';
                    }
                    $body .= '</w:tr>';
                }
                $body .= '</w:tbl><w:p/>';
            }
        }
        $ns       = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $headings = '';
        foreach ([1 => 32, 2 => 28, 3 => 26, 4 => 24, 5 => 22] as $level => $size) {
            $headings .= '<w:style w:type="paragraph" w:styleId="Heading' . $level . '"><w:name w:val="heading ' . $level . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="80"/><w:outlineLvl w:val="' . ($level - 1) . '"/></w:pPr><w:rPr><w:b/><w:sz w:val="' . $size . '"/></w:rPr></w:style>';
        }
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles ' . $ns . '><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:after="80"/></w:pPr></w:pPrDefault></w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:after="200"/></w:pPr><w:rPr><w:b/><w:sz w:val="40"/></w:rPr></w:style>'
            . $headings
            . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/></w:style></w:styles>';

        return $this->zip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
            '_rels/.rels'         => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'word/document.xml'   => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $body . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="708" w:footer="708" w:gutter="0"/></w:sectPr></w:body></w:document>',
            'word/styles.xml'     => $styles,
            'docProps/core.xml'   => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . self::x($title) . '</dc:title></cp:coreProperties>',
        ]);
    }

    /** @param list<Item> $items */
    private function odt(array $items, string $title): string
    {
        $body = '';
        $n    = 0;
        foreach ($items as $item) {
            if ($item[0] === 'h') {
                $body .= $item[1] === 0
                    ? '<text:p text:style-name="Title">' . self::x($item[2]) . '</text:p>'
                    : '<text:h text:style-name="Heading_20_' . $item[1] . '" text:outline-level="' . $item[1] . '">' . self::x($item[2]) . '</text:h>';
            } elseif ($item[0] === 'p') {
                $body .= '<text:p text:style-name="' . ($item[2] ? 'Bold' : 'Standard') . '">' . self::x($item[1]) . '</text:p>';
            } else {
                $rows  = $item[1];
                $cols  = max(1, ...array_map(static fn (array $r): int => \count($r), $rows ?: [[]]));
                $body .= '<table:table table:name="T' . (++$n) . '" table:style-name="Grid"><table:table-column table:number-columns-repeated="' . $cols . '"/>';
                foreach ($rows as $i => $row) {
                    $body .= '<table:table-row>';
                    foreach ($row as $cell) {
                        $body .= '<table:table-cell table:style-name="Cell" office:value-type="string"><text:p text:style-name="' . ($i < $item[2] ? 'Bold' : 'Standard') . '">' . self::x($cell) . '</text:p></table:table-cell>';
                    }
                    $body .= '</table:table-row>';
                }
                $body .= '</table:table><text:p text:style-name="Standard"/>';
            }
        }
        $ns      = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"';
        $styles  = '<style:style style:name="Standard" style:family="paragraph"/><style:style style:name="Bold" style:family="paragraph"><style:text-properties fo:font-weight="bold"/></style:style>'
            . '<style:style style:name="Title" style:family="paragraph"><style:text-properties fo:font-size="20pt" fo:font-weight="bold"/></style:style>';
        foreach ([1 => 16, 2 => 14, 3 => 13, 4 => 12, 5 => 11] as $level => $size) {
            $styles .= '<style:style style:name="Heading_20_' . $level . '" style:display-name="Heading ' . $level . '" style:family="paragraph" style:default-outline-level="' . $level . '"><style:paragraph-properties fo:margin-top="0.4cm" fo:margin-bottom="0.15cm" fo:keep-with-next="always"/><style:text-properties fo:font-size="' . $size . 'pt" fo:font-weight="bold"/></style:style>';
        }
        $content = '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . $ns . ' office:version="1.2"><office:automatic-styles>'
            . '<style:style style:name="Grid" style:family="table"><style:table-properties style:width="17cm" table:align="margins"/></style:style>'
            . '<style:style style:name="Cell" style:family="table-cell"><style:table-cell-properties fo:border="0.5pt solid #999999" fo:padding="0.1cm"/></style:style>'
            . '</office:automatic-styles><office:body><office:text>' . $body . '</office:text></office:body></office:document-content>';

        return $this->zip([
            'mimetype'              => 'application/vnd.oasis.opendocument.text',
            'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/></manifest:manifest>',
            'content.xml'           => $content,
            'styles.xml'            => '<?xml version="1.0" encoding="UTF-8"?><office:document-styles ' . $ns . ' office:version="1.2"><office:styles>' . $styles . '</office:styles></office:document-styles>',
            'meta.xml'              => '<?xml version="1.0" encoding="UTF-8"?><office:document-meta ' . $ns . ' office:version="1.2"><office:meta><dc:title>' . self::x($title) . '</dc:title></office:meta></office:document-meta>',
        ]);
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'atica_office_');
        if ($path === false) {
            throw new \RuntimeException('No temporary file.');
        }
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
            if ($name === 'mimetype') {
                $zip->setCompressionName($name, \ZipArchive::CM_STORE); // ODF: the first entry, stored
            }
        }
        $zip->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }
}
