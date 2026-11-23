<?php

declare(strict_types=1);

namespace App\Service\Form\Office;

use App\Service\Form\Layout\Cell;
use App\Service\Form\Layout\DataTable;
use App\Service\Form\Layout\Figure;
use App\Service\Form\Layout\Heading;
use App\Service\Form\Layout\Image;
use App\Service\Form\Layout\Paragraph;
use App\Service\Form\Layout\Run;
use App\Service\Form\Layout\Table;

/**
 * A document as an OpenDocument text file (.odt), made by hand (a ZIP of XML): the body (headings, paragraphs, lists of fields and
 * tables) in the look of the application, the running header and footer as the page's, and its orientation. One instance makes one file.
 */
final class OdtDocument
{
    private const string NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:xlink="http://www.w3.org/1999/xlink" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0"';

    /** @var array<string, Image> the logos used, by the file name they get in the package (Pictures/…) */
    private array $pictures = [];

    /** @var array<string, Image> the logos of the document, by token */
    private array $images = [];

    /** @var array<string, string> the automatic styles of the body (content.xml) by name */
    private array $contentStyles = [];

    /** @var array<string, string> the automatic styles of the page (styles.xml): the header's and the footer's */
    private array $pageStyles = [];

    private int $counter = 0;

    /**
     * @param list<Heading|Paragraph|DataTable|Figure> $body
     * @param list<Paragraph|Table>             $header
     * @param list<Paragraph|Table>             $firstHeader the header of the first page, or none when it is the same
     * @param list<Paragraph|Table>             $footer
     * @param array<string, Image>              $images      the logos, by token
     */
    public function render(array $body, array $header, array $firstHeader, array $footer, Page $page, float $scale, string $title, array $images = []): string
    {
        $this->images = $images;
        $content      = $firstHeader !== [] ? '<text:p text:style-name="FirstPageStart"/>' : '';
        foreach ($body as $block) {
            $content .= match (true) {
                $block instanceof Heading   => $this->heading($block),
                $block instanceof DataTable => $this->dataTable($block, $page),
                $block instanceof Figure    => $this->figure($block, $page),
                default                     => $this->paragraph($block, false, $block->style === Paragraph::LABEL ? 'Label' : 'Standard'),
            };
        }
        $headerXml = $this->running($header, $page);
        $footerXml = $this->running($footer, $page);
        // The first page's header is another master page that the body starts on and that is followed by the normal one.
        $masters = '<style:master-page style:name="Standard" style:page-layout-name="pm1"><style:header>' . $headerXml . '</style:header><style:footer>' . $footerXml . '</style:footer></style:master-page>';
        if ($firstHeader !== []) {
            $masters .= '<style:master-page style:name="First_20_Page" style:display-name="First Page" style:page-layout-name="pm1" style:next-style-name="Standard"><style:header>'
                . $this->running($firstHeader, $page) . '</style:header><style:footer>' . $this->running($footer, $page) . '</style:footer></style:master-page>';
        }

        $automatic = '<office:automatic-styles>' . implode('', $this->contentStyles)
            . '<style:style style:name="TableCellHead" style:family="table-cell"><style:table-cell-properties fo:padding="0.14cm" fo:border="0.5pt solid #' . Theme::GREEN . '" fo:background-color="#' . Theme::GREEN . '" style:vertical-align="middle"/></style:style>'
            . $this->cellStyle('TableCellLabel', Theme::FILL, Theme::RULE)
            . $this->cellStyle('TableCellBody', null, Theme::RULE)
            . $this->cellStyle('TableCellZebra', Theme::ZEBRA, Theme::RULE)
            . '<style:style style:name="TableCellTotal" style:family="table-cell"><style:table-cell-properties fo:padding="0.14cm" fo:border-left="0.5pt solid #' . Theme::RULE . '" fo:border-right="0.5pt solid #' . Theme::RULE . '" fo:border-bottom="0.5pt solid #' . Theme::RULE . '" fo:border-top="1.5pt solid #' . Theme::GREEN . '" fo:background-color="#' . Theme::GREEN_SOFT . '" style:vertical-align="middle"/></style:style>'
            . '</office:automatic-styles>';

        $manifest = '';
        $files    = [];
        foreach ($this->pictures as $name => $image) {
            $manifest      .= '<manifest:file-entry manifest:full-path="' . $name . '" manifest:media-type="' . $image->mime . '"/>';
            $files[$name]   = $image->content;
        }

        // The `mimetype` goes first (ODF requires it), the pictures after the rest.
        return $this->zip([
            'mimetype'              => 'application/vnd.oasis.opendocument.text',
            'META-INF/manifest.xml' => '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2"><manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/><manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="styles.xml" manifest:media-type="text/xml"/><manifest:file-entry manifest:full-path="meta.xml" manifest:media-type="text/xml"/>' . $manifest . '</manifest:manifest>',
            'content.xml'           => '<?xml version="1.0" encoding="UTF-8"?><office:document-content ' . self::NS . ' office:version="1.2">' . $this->fonts() . $automatic . '<office:body><office:text>' . $content . '</office:text></office:body></office:document-content>',
            'styles.xml'            => '<?xml version="1.0" encoding="UTF-8"?><office:document-styles ' . self::NS . ' office:version="1.2">' . $this->fonts()
                . '<office:styles>' . $this->namedStyles($scale, $firstHeader !== []) . '</office:styles>'
                . '<office:automatic-styles>' . $this->pageLayout($page) . implode('', $this->pageStyles) . '</office:automatic-styles>'
                . '<office:master-styles>' . $masters . '</office:master-styles></office:document-styles>',
            'meta.xml'              => '<?xml version="1.0" encoding="UTF-8"?><office:document-meta ' . self::NS . ' office:version="1.2"><office:meta><dc:title>' . self::x($title) . '</dc:title></office:meta></office:document-meta>',
        ] + $files);
    }

    private function fonts(): string
    {
        return '<office:font-face-decls><style:font-face style:name="' . Theme::FONT . '" svg:font-family="' . Theme::FONT . '" style:font-family-generic="swiss" style:font-pitch="variable"/></office:font-face-decls>';
    }

    private function pageLayout(Page $page): string
    {
        return '<style:page-layout style:name="pm1"><style:page-layout-properties fo:page-width="' . $page->widthCm() . 'cm" fo:page-height="' . $page->heightCm() . 'cm" style:print-orientation="' . ($page->landscape ? 'landscape' : 'portrait') . '"'
            . ' fo:margin-top="' . $page->topCm() . 'cm" fo:margin-bottom="' . $page->bottomCm() . 'cm" fo:margin-left="' . $page->leftCm() . 'cm" fo:margin-right="' . $page->rightCm() . 'cm"/>'
            . '<style:header-style><style:header-footer-properties fo:min-height="0cm" fo:margin-bottom="0.5cm"/></style:header-style>'
            . '<style:footer-style><style:header-footer-properties fo:min-height="0cm" fo:margin-top="0.4cm"/></style:footer-style></style:page-layout>';
    }

    /** The styles the paragraphs refer to by name: the text of the body, the headings and the paragraphs of the tables. */
    private function namedStyles(float $scale, bool $withFirstPage): string
    {
        $size = static fn (float $pt): string => Theme::size($pt, $scale) . 'pt';
        $out  = '<style:default-style style:family="paragraph"><style:paragraph-properties fo:orphans="2" fo:widows="2"/>'
            . '<style:text-properties style:font-name="' . Theme::FONT . '" fo:font-size="' . $size(Theme::BODY) . '" fo:color="#' . Theme::INK . '" fo:language="es" fo:country="ES"/></style:default-style>';
        $out .= '<style:style style:name="Standard" style:family="paragraph" style:class="text"><style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0.18cm" fo:line-height="118%"/></style:style>';
        $out .= '<style:style style:name="Label" style:family="paragraph" style:parent-style-name="Standard" style:class="text"><style:paragraph-properties fo:margin-top="0.2cm" fo:margin-bottom="0.08cm" fo:keep-with-next="always"/><style:text-properties fo:font-weight="bold" fo:color="#' . Theme::MUTED . '" fo:font-size="' . $size(Theme::BODY - 0.5) . '"/></style:style>';
        $out .= '<style:style style:name="Title" style:family="paragraph" style:parent-style-name="Standard" style:class="chapter"><style:paragraph-properties fo:margin-top="0cm" fo:margin-bottom="0.5cm" fo:padding-bottom="0.18cm" fo:border-bottom="1.5pt solid #' . Theme::GREEN . '"/><style:text-properties fo:font-weight="bold" fo:font-size="' . $size(Theme::HEADINGS[0]) . '"/></style:style>';
        foreach ([1, 2, 3, 4, 5] as $level) {
            $rule = $level === 1 ? ' fo:padding-bottom="0.08cm" fo:border-bottom="0.75pt solid #' . Theme::RULE . '"' : '';
            $out .= '<style:style style:name="Heading_20_' . $level . '" style:display-name="Heading ' . $level . '" style:family="paragraph" style:parent-style-name="Standard" style:default-outline-level="' . $level . '" style:class="text">'
                . '<style:paragraph-properties fo:margin-top="' . ($level === 1 ? '0.55cm' : '0.4cm') . '" fo:margin-bottom="0.2cm" fo:keep-with-next="always"' . $rule . '/>'
                . '<style:text-properties fo:font-weight="bold" fo:color="#' . ($level <= 2 ? Theme::GREEN : Theme::INK) . '" fo:font-size="' . $size(Theme::HEADINGS[$level]) . '"/></style:style>';
        }
        $out .= '<style:style style:name="CellText" style:family="paragraph"><style:paragraph-properties fo:margin="0cm" fo:line-height="112%"/></style:style>';
        $out .= '<style:style style:name="TableHead" style:family="paragraph" style:parent-style-name="CellText"><style:text-properties fo:font-weight="bold" fo:color="#ffffff" fo:font-size="' . $size(Theme::BODY - 0.5) . '"/></style:style>';
        $out .= '<style:style style:name="TableLabel" style:family="paragraph" style:parent-style-name="CellText"><style:text-properties fo:font-weight="bold" fo:color="#' . Theme::MUTED . '"/></style:style>';
        $out .= '<style:style style:name="TableTotal" style:family="paragraph" style:parent-style-name="CellText"><style:text-properties fo:font-weight="bold"/></style:style>';
        $out .= '<style:style style:name="Running" style:family="paragraph"><style:paragraph-properties fo:margin="0cm"/><style:text-properties fo:font-size="' . Theme::RUNNING . 'pt" fo:color="#' . Theme::INK . '"/></style:style>';
        if ($withFirstPage) {
            // A paragraph as small as can be that opens the document on the master page of the first page.
            $out .= '<style:style style:name="FirstPageStart" style:family="paragraph" style:master-page-name="First_20_Page"><style:paragraph-properties fo:margin="0cm" fo:line-height="1pt"/><style:text-properties fo:font-size="1pt"/></style:style>';
        }
        $out .= '<style:style style:name="Header" style:family="paragraph" style:parent-style-name="Running" style:class="extra"/>';
        $out .= '<style:style style:name="Footer" style:family="paragraph" style:parent-style-name="Running" style:class="extra"/>';

        return $out;
    }

    private function cellStyle(string $name, ?string $background, string $border): string
    {
        return '<style:style style:name="' . $name . '" style:family="table-cell"><style:table-cell-properties fo:padding="0.14cm" fo:border="0.5pt solid #' . $border . '"'
            . ($background !== null ? ' fo:background-color="#' . $background . '"' : '') . ' style:vertical-align="middle"/></style:style>';
    }

    // ── Body ───────────────────────────────────────────────────────────────────

    private function heading(Heading $h): string
    {
        return $h->level === 0
            ? '<text:p text:style-name="Title">' . self::x($h->text) . '</text:p>'
            : '<text:h text:style-name="Heading_20_' . $h->level . '" text:outline-level="' . $h->level . '">' . self::x($h->text) . '</text:h>';
    }

    private function dataTable(DataTable $t, Page $page): string
    {
        $name    = 'BT' . (++$this->counter);
        $columns = $t->columns();
        $this->contentStyles[$name] = '<style:style style:name="' . $name . '" style:family="table"><style:table-properties style:width="' . $page->contentCm() . 'cm" table:align="margins" fo:margin-top="0.1cm" fo:margin-bottom="0.35cm"/></style:style>';

        $widths = [];
        if ($t->labelColumn && $columns > 1) {
            $first = $columns === 2 ? 0.32 : min(0.3, 1 / $columns + 0.08);
            $rest  = (1 - $first) / ($columns - 1);
            $widths = array_merge([$first], array_fill(0, $columns - 1, $rest));
        } else {
            $widths = array_fill(0, $columns, 1 / $columns);
        }
        $xml = '<table:table table:name="' . $name . '" table:style-name="' . $name . '">';
        foreach ($widths as $i => $w) {
            $col = $name . 'C' . $i;
            $this->contentStyles[$col] = '<style:style style:name="' . $col . '" style:family="table-column"><style:table-column-properties style:column-width="' . round($w * $page->contentCm(), 3) . 'cm"/></style:style>';
            $xml .= '<table:table-column table:style-name="' . $col . '"/>';
        }

        $last = \count($t->rows) - 1;
        foreach ($t->rows as $r => $row) {
            $header = $r < $t->headerRows;
            $total  = $t->totalRow && $r === $last && !$header;
            $xml   .= $header && $r === 0 ? '<table:table-header-rows>' : '';
            $xml   .= '<table:table-row>';
            for ($c = 0; $c < $columns; ++$c) {
                $label = $t->labelColumn && $c === 0 && !$header && !$total;
                [$cell, $para] = match (true) {
                    $header => ['TableCellHead', 'TableHead'],
                    $total  => ['TableCellTotal', 'TableTotal'],
                    $label  => ['TableCellLabel', 'TableLabel'],
                    default => [($r - $t->headerRows) % 2 === 1 ? 'TableCellZebra' : 'TableCellBody', 'CellText'],
                };
                $xml .= '<table:table-cell table:style-name="' . $cell . '" office:value-type="string"><text:p text:style-name="' . $para . '">' . self::xt($row[$c] ?? '') . '</text:p></table:table-cell>';
            }
            $xml .= '</table:table-row>';
            $xml .= $header && $r === $t->headerRows - 1 ? '</table:table-header-rows>' : '';
        }

        return $xml . '</table:table><text:p text:style-name="Standard"/>';
    }

    // ── Paragraphs and tables (the body's and the page's) ──────────────────────

    /** @param list<Paragraph|Table> $blocks */
    private function running(array $blocks, Page $page): string
    {
        $xml = '';
        foreach ($blocks as $block) {
            $xml .= $block instanceof Table ? $this->layoutTable($block, $page) : $this->paragraph($block, true, 'Running', $page->contentCm());
        }

        return $xml === '' ? '<text:p text:style-name="Running"/>' : $xml;
    }

    private function layoutTable(Table $table, Page $page): string
    {
        $name = 'HT' . (++$this->counter);
        $this->pageStyles[$name] = '<style:style style:name="' . $name . '" style:family="table"><style:table-properties style:width="' . $page->contentCm() . 'cm" table:align="margins"/></style:style>';
        $xml = '<table:table table:name="' . $name . '" table:style-name="' . $name . '">';
        foreach ($table->widths as $i => $w) {
            $col = $name . 'C' . $i;
            $this->pageStyles[$col] = '<style:style style:name="' . $col . '" style:family="table-column"><style:table-column-properties style:column-width="' . round($w * $page->contentCm(), 3) . 'cm"/></style:style>';
            $xml .= '<table:table-column table:style-name="' . $col . '"/>';
        }
        $placement = $table->placement();
        for ($r = 0; $r < $table->rows; ++$r) {
            $xml .= '<table:table-row>';
            for ($c = 0; $c < $table->columns(); ++$c) {
                $at = $placement[$r][$c] ?? null;
                if ($at === null) {
                    $xml .= '<table:table-cell office:value-type="string"><text:p text:style-name="Running"/></table:table-cell>';
                } elseif (!$at['origin']) {
                    $xml .= '<table:covered-table-cell/>';
                } else {
                    // What the cell has for a logo: its share of the width, less its padding (0.1 cm on each side).
                    $xml .= $this->layoutCell($at['cell'], array_sum(\array_slice($table->widths, $at['cell']->col, $at['cell']->colspan)) * $page->contentCm() - 0.2);
                }
            }
            $xml .= '</table:table-row>';
        }

        return $xml . '</table:table>';
    }

    private function layoutCell(Cell $cell, float $roomCm): string
    {
        $key  = 'HC' . md5(serialize([$cell->background, $cell->border, $cell->valign]));
        $this->pageStyles[$key] ??= '<style:style style:name="' . $key . '" style:family="table-cell"><style:table-cell-properties fo:padding="0.1cm" fo:border="' . ($cell->border ? '0.5pt solid #' . Theme::LINE : 'none') . '"'
            . ($cell->background !== null ? ' fo:background-color="#' . $cell->background . '"' : '') . ' style:vertical-align="' . ($cell->valign ?? 'middle') . '"/></style:style>';

        $inner = '';
        foreach ($cell->paragraphs as $p) {
            $inner .= $this->paragraph($p, true, 'Running', $roomCm);
        }

        return '<table:table-cell table:style-name="' . $key . '"'
            . ($cell->colspan > 1 ? ' table:number-columns-spanned="' . $cell->colspan . '"' : '') . ($cell->rowspan > 1 ? ' table:number-rows-spanned="' . $cell->rowspan . '"' : '')
            . ' office:value-type="string">' . $inner . '</table:table-cell>';
    }

    private function paragraph(Paragraph $p, bool $page, string $base, float $roomCm = 17.0): string
    {
        $style = $base;
        if ($p->align !== 'left') {
            $style = 'PA' . md5($base . $p->align);
            $css   = '<style:style style:name="' . $style . '" style:family="paragraph" style:parent-style-name="' . $base . '"><style:paragraph-properties fo:text-align="' . ($p->align === 'right' ? 'end' : $p->align) . '"/></style:style>';
            if ($page) {
                $this->pageStyles[$style] = $css;
            } else {
                $this->contentStyles[$style] = $css;
            }
        }
        $xml = '';
        foreach ($p->runs as $run) {
            $xml .= $this->run($run, $page, $roomCm);
        }

        return '<text:p text:style-name="' . $style . '">' . $xml . '</text:p>';
    }

    /** An attached picture in the body: a paragraph of its own with the picture, and its caption under it. */
    private function figure(Figure $f, Page $page): string
    {
        $name = 'Pictures/' . $f->name . '.' . $f->image->extension();
        $this->pictures[$name] = $f->image;
        [$w, $h] = $f->sizeCm($page->contentCm());
        $this->contentStyles['FigureFrame'] ??= '<style:style style:name="FigureFrame" style:family="graphic"><style:graphic-properties style:wrap="none" style:vertical-pos="top" style:vertical-rel="baseline" fo:margin-left="0cm" fo:margin-right="0cm" fo:margin-top="0cm" fo:margin-bottom="0cm" fo:padding="0cm" fo:border="0.5pt solid #' . Theme::RULE . '"/></style:style>';
        $this->contentStyles['FigureCaption'] ??= '<style:style style:name="FigureCaption" style:family="paragraph" style:parent-style-name="Standard"><style:paragraph-properties fo:margin-top="0.1cm" fo:margin-bottom="0.3cm"/><style:text-properties fo:color="#' . Theme::MUTED . '" fo:font-size="8pt"/></style:style>';

        return '<text:p text:style-name="Standard"><draw:frame draw:style-name="FigureFrame" draw:name="' . self::x($f->name) . '" text:anchor-type="as-char" svg:width="' . $w . 'cm" svg:height="' . $h . 'cm">'
            . '<draw:image xlink:href="' . $name . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/></draw:frame></text:p>'
            . '<text:p text:style-name="FigureCaption">' . self::xt($f->caption) . '</text:p>';
    }

    /** A logo as a picture drawn in the line (the file goes in the package's Pictures/ folder, once however many times it is used). */
    private function picture(Run $run, float $roomCm): string
    {
        $image = $this->images[$run->kind] ?? null;
        if ($image === null) {
            return '';
        }
        $name = 'Pictures/' . $run->kind . '.' . $image->extension();
        $this->pictures[$name] = $image;
        [$w, $h] = $image->sizeCm($roomCm);
        $this->pageStyles['LogoFrame'] ??= '<style:style style:name="LogoFrame" style:family="graphic"><style:graphic-properties style:wrap="none" style:vertical-pos="middle" style:vertical-rel="text" style:horizontal-pos="from-left" style:horizontal-rel="paragraph" fo:margin-left="0cm" fo:margin-right="0.1cm" fo:margin-top="0cm" fo:margin-bottom="0cm" fo:padding="0cm" fo:border="none"/></style:style>';

        return '<draw:frame draw:style-name="LogoFrame" draw:name="Logo' . (++$this->counter) . '" text:anchor-type="as-char" svg:width="' . $w . 'cm" svg:height="' . $h . 'cm">'
            . '<draw:image xlink:href="' . $name . '" xlink:type="simple" xlink:show="embed" xlink:actuate="onLoad"/></draw:frame>';
    }

    private function run(Run $run, bool $page, float $roomCm): string
    {
        if ($run->isImage()) {
            return $this->picture($run, $roomCm);
        }
        $inner = match ($run->kind) {
            Run::BREAK => '<text:line-break/>',
            Run::PAGE  => '<text:page-number text:select-page="current">1</text:page-number>',
            Run::PAGES => '<text:page-count>1</text:page-count>',
            default    => self::xt($run->text),
        };
        if (!$run->bold && !$run->italic && !$run->underline && $run->color === null && $run->size === null) {
            return $inner;
        }
        $name  = 'TX' . md5(serialize([$run->bold, $run->italic, $run->underline, $run->color, $run->size]));
        $props = ($run->bold ? ' fo:font-weight="bold"' : '') . ($run->italic ? ' fo:font-style="italic"' : '')
            . ($run->underline ? ' style:text-underline-style="solid" style:text-underline-width="auto" style:text-underline-color="font-color"' : '')
            . ($run->color !== null ? ' fo:color="#' . $run->color . '"' : '') . ($run->size !== null ? ' fo:font-size="' . $run->size . 'pt"' : '');
        $css = '<style:style style:name="' . $name . '" style:family="text"><style:text-properties' . $props . '/></style:style>';
        if ($page) {
            $this->pageStyles[$name] = $css;
        } else {
            $this->contentStyles[$name] = $css;
        }

        return '<text:span text:style-name="' . $name . '">' . $inner . '</text:span>';
    }

    // ── XML ────────────────────────────────────────────────────────────────────

    private static function x(string $text): string
    {
        // No control characters: XML 1.0 does not allow them.
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Text of a paragraph: runs of spaces are kept (ODT collapses them otherwise). */
    private static function xt(string $text): string
    {
        return (string) preg_replace_callback('/ {2,}/', static fn (array $m): string => ' <text:s text:c="' . (\strlen($m[0]) - 1) . '"/>', self::x($text));
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
