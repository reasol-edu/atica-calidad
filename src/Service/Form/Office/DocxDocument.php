<?php

declare(strict_types=1);

namespace App\Service\Form\Office;

use App\Service\Form\Layout\Cell;
use App\Service\Form\Layout\DataTable;
use App\Service\Form\Layout\Heading;
use App\Service\Form\Layout\Image;
use App\Service\Form\Layout\Paragraph;
use App\Service\Form\Layout\Run;
use App\Service\Form\Layout\Table;

/**
 * A document as a Word file (.docx), made by hand (a ZIP of XML): the body (headings, paragraphs, lists of fields and tables) in the
 * look of the application, the running header and footer as the section's, and its orientation.
 */
final class DocxDocument
{
    private const string NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';

    private const string XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    /** @var array<string, Image> the logos of the document, by token */
    private array $images = [];

    /** @var array<string, Image> the logos the part being made uses (they become its relationships and media files) */
    private array $used = [];

    private int $drawings = 0;

    /**
     * @param list<Heading|Paragraph|DataTable> $body
     * @param list<Paragraph|Table>             $header
     * @param list<Paragraph|Table>             $firstHeader the header of the first page, or none when it is the same
     * @param list<Paragraph|Table>             $footer
     * @param array<string, Image>              $images      the logos, by token
     */
    public function render(array $body, array $header, array $firstHeader, array $footer, Page $page, float $scale, string $title, array $images = []): string
    {
        $this->images = $images;
        $content      = '';
        foreach ($body as $block) {
            $content .= match (true) {
                $block instanceof Heading   => '<w:p><w:pPr><w:pStyle w:val="' . ($block->level === 0 ? 'Title' : 'Heading' . $block->level) . '"/></w:pPr><w:r><w:t xml:space="preserve">' . self::x($block->text) . '</w:t></w:r></w:p>',
                $block instanceof DataTable => $this->dataTable($block, $page),
                default                     => $this->paragraph($block, $block->style === Paragraph::LABEL ? 'Label' : null),
            };
        }

        // The header and footer parts (and, with a header of its own for the first page, its pair): each with the pictures it uses.
        $first = $firstHeader !== [];
        $parts = ['header1' => $this->part($header, $page), 'footer1' => $this->part($footer, $page)];
        if ($first) {
            $parts['header2'] = $this->part($firstHeader, $page);
            $parts['footer2'] = $this->part($footer, $page);
        }

        $width  = Page::twips($page->widthCm());
        $height = Page::twips($page->heightCm());
        $sect   = '<w:sectPr><w:headerReference w:type="default" r:id="rId2"/>' . ($first ? '<w:headerReference w:type="first" r:id="rId4"/>' : '')
            . '<w:footerReference w:type="default" r:id="rId3"/>' . ($first ? '<w:footerReference w:type="first" r:id="rId5"/>' : '')
            . '<w:pgSz w:w="' . ($page->landscape ? $height : $width) . '" w:h="' . ($page->landscape ? $width : $height) . '"' . ($page->landscape ? ' w:orient="landscape"' : '') . '/>'
            // The body starts below the header and ends above the footer, whatever they measure (Word moves it down when they are taller).
            . '<w:pgMar w:top="' . Page::twips($page->topCm()) . '" w:right="' . Page::twips($page->rightCm()) . '" w:bottom="' . Page::twips($page->bottomCm()) . '" w:left="' . Page::twips($page->leftCm()) . '" w:header="' . Page::twips($page->topCm()) . '" w:footer="' . Page::twips($page->bottomCm()) . '" w:gutter="0"/>'
            . ($first ? '<w:titlePg/>' : '') . '</w:sectPr>';

        $ct   = 'application/vnd.openxmlformats-officedocument.wordprocessingml.';
        $rel  = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
        $over = '';
        $rels = '<Relationship Id="rId1" Type="' . $rel . 'styles" Target="styles.xml"/>';
        $files = [];
        $ids   = ['header1' => 'rId2', 'footer1' => 'rId3', 'header2' => 'rId4', 'footer2' => 'rId5'];
        foreach ($parts as $name => [$xml, $used]) {
            $kind  = str_starts_with($name, 'header') ? 'header' : 'footer';
            $root  = $kind === 'header' ? 'w:hdr' : 'w:ftr';
            $over .= '<Override PartName="/word/' . $name . '.xml" ContentType="' . $ct . $kind . '+xml"/>';
            $rels .= '<Relationship Id="' . $ids[$name] . '" Type="' . $rel . $kind . '" Target="' . $name . '.xml"/>';
            $files['word/' . $name . '.xml'] = self::XML . '<' . $root . ' ' . self::NS . '>' . $xml . '</' . $root . '>';
            if ($used !== []) {
                $partRels = '';
                foreach ($used as $token => $image) {
                    $partRels .= '<Relationship Id="rIdImg' . $token . '" Type="' . $rel . 'image" Target="media/' . $token . '.' . $image->extension() . '"/>';
                    $files['word/media/' . $token . '.' . $image->extension()] = $image->content;
                }
                $files['word/_rels/' . $name . '.xml.rels'] = self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $partRels . '</Relationships>';
            }
        }

        return $this->zip([
            '[Content_Types].xml'          => self::XML . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/>'
                . '<Override PartName="/word/document.xml" ContentType="' . $ct . 'document.main+xml"/><Override PartName="/word/styles.xml" ContentType="' . $ct . 'styles+xml"/>' . $over
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
            '_rels/.rels'                  => self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="' . $rel . 'officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>',
            'word/document.xml'            => self::XML . '<w:document ' . self::NS . '><w:body>' . $content . $sect . '</w:body></w:document>',
            'word/styles.xml'              => $this->styles($scale),
            'docProps/core.xml'            => self::XML . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . self::x($title) . '</dc:title></cp:coreProperties>',
        ] + $files);
    }

    /**
     * The content of a header or footer part and the logos it draws.
     *
     * @param list<Paragraph|Table> $blocks
     *
     * @return array{0: string, 1: array<string, Image>}
     */
    private function part(array $blocks, Page $page): array
    {
        $this->used = [];
        $xml        = $this->running($blocks, $page);

        return [$xml, $this->used];
    }

    private function styles(float $scale): string
    {
        $half = static fn (float $pt): int => (int) round(Theme::size($pt, $scale) * 2);
        $font = '<w:rFonts w:ascii="' . Theme::FONT . '" w:hAnsi="' . Theme::FONT . '" w:cs="' . Theme::FONT . '"/>';
        $rule = static fn (string $colour, int $size): string => '<w:pBdr><w:bottom w:val="single" w:sz="' . $size . '" w:space="2" w:color="' . $colour . '"/></w:pBdr>';

        $s  = self::XML . '<w:styles ' . self::NS . '><w:docDefaults><w:rPrDefault><w:rPr>' . $font . '<w:color w:val="' . Theme::INK . '"/><w:sz w:val="' . $half(Theme::BODY) . '"/><w:szCs w:val="' . $half(Theme::BODY) . '"/><w:lang w:val="es-ES"/></w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="100" w:line="283" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>';
        $s .= '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:qFormat/></w:style>';
        $s .= '<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/><w:pPr>' . $rule(Theme::GREEN, 12) . '<w:spacing w:before="0" w:after="280"/></w:pPr><w:rPr><w:b/><w:sz w:val="' . $half(Theme::HEADINGS[0]) . '"/></w:rPr></w:style>';
        foreach ([1, 2, 3, 4, 5] as $level) {
            $s .= '<w:style w:type="paragraph" w:styleId="Heading' . $level . '"><w:name w:val="heading ' . $level . '"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
                . '<w:pPr><w:keepNext/>' . ($level === 1 ? $rule(Theme::RULE, 6) : '') . '<w:spacing w:before="' . ($level === 1 ? 320 : 220) . '" w:after="120"/><w:outlineLvl w:val="' . ($level - 1) . '"/></w:pPr>'
                . '<w:rPr><w:b/><w:color w:val="' . ($level <= 2 ? Theme::GREEN : Theme::INK) . '"/><w:sz w:val="' . $half(Theme::HEADINGS[$level]) . '"/></w:rPr></w:style>';
        }
        $s .= '<w:style w:type="paragraph" w:customStyle="1" w:styleId="Label"><w:name w:val="Label"/><w:basedOn w:val="Normal"/><w:qFormat/><w:pPr><w:keepNext/><w:spacing w:before="120" w:after="50"/></w:pPr><w:rPr><w:b/><w:color w:val="' . Theme::MUTED . '"/><w:sz w:val="' . $half(Theme::BODY - 0.5) . '"/></w:rPr></w:style>';
        $s .= '<w:style w:type="paragraph" w:customStyle="1" w:styleId="CellText"><w:name w:val="Cell text"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="0" w:after="0" w:line="264" w:lineRule="auto"/></w:pPr></w:style>';
        $s .= '<w:style w:type="paragraph" w:customStyle="1" w:styleId="TableHead"><w:name w:val="Table head"/><w:basedOn w:val="CellText"/><w:rPr><w:b/><w:color w:val="FFFFFF"/><w:sz w:val="' . $half(Theme::BODY - 0.5) . '"/></w:rPr></w:style>';
        $s .= '<w:style w:type="paragraph" w:customStyle="1" w:styleId="TableLabel"><w:name w:val="Table label"/><w:basedOn w:val="CellText"/><w:rPr><w:b/><w:color w:val="' . Theme::MUTED . '"/></w:rPr></w:style>';
        $s .= '<w:style w:type="paragraph" w:customStyle="1" w:styleId="TableTotal"><w:name w:val="Table total"/><w:basedOn w:val="CellText"/><w:rPr><w:b/></w:rPr></w:style>';
        $s .= '<w:style w:type="paragraph" w:customStyle="1" w:styleId="Running"><w:name w:val="Running text"/><w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="0" w:after="0" w:line="240" w:lineRule="auto"/></w:pPr><w:rPr><w:sz w:val="' . ((int) (Theme::RUNNING * 2)) . '"/></w:rPr></w:style>';
        $s .= '<w:style w:type="paragraph" w:styleId="Header"><w:name w:val="header"/><w:basedOn w:val="Running"/></w:style>';
        $s .= '<w:style w:type="paragraph" w:styleId="Footer"><w:name w:val="footer"/><w:basedOn w:val="Running"/></w:style>';
        $s .= '<w:style w:type="table" w:default="1" w:styleId="TableNormal"><w:name w:val="Normal Table"/><w:tblPr><w:tblInd w:w="0" w:type="dxa"/><w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="108" w:type="dxa"/><w:bottom w:w="0" w:type="dxa"/><w:right w:w="108" w:type="dxa"/></w:tblCellMar></w:tblPr></w:style>';

        return $s . '</w:styles>';
    }

    // ── Body tables ────────────────────────────────────────────────────────────

    private function dataTable(DataTable $t, Page $page): string
    {
        $columns = $t->columns();
        $total   = Page::twips($page->contentCm());
        if ($t->labelColumn && $columns > 1) {
            $first = $columns === 2 ? 0.32 : min(0.3, 1 / $columns + 0.08);
            $shares = array_merge([$first], array_fill(0, $columns - 1, (1 - $first) / ($columns - 1)));
        } else {
            $shares = array_fill(0, $columns, 1 / $columns);
        }
        $widths = array_map(static fn (float $s): int => (int) round($s * $total), $shares);

        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="80" w:type="dxa"/><w:left w:w="130" w:type="dxa"/><w:bottom w:w="80" w:type="dxa"/><w:right w:w="130" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'
            . implode('', array_map(static fn (int $w): string => '<w:gridCol w:w="' . $w . '"/>', $widths)) . '</w:tblGrid>';
        $last = \count($t->rows) - 1;
        foreach ($t->rows as $r => $row) {
            $header = $r < $t->headerRows;
            $isTotal = $t->totalRow && $r === $last && !$header;
            $xml .= '<w:tr><w:trPr><w:cantSplit/>' . ($header ? '<w:tblHeader/>' : '') . '</w:trPr>';
            for ($c = 0; $c < $columns; ++$c) {
                $label = $t->labelColumn && $c === 0 && !$header && !$isTotal;
                [$fill, $style, $border] = match (true) {
                    $header  => [Theme::GREEN, 'TableHead', Theme::GREEN],
                    $isTotal => [Theme::GREEN_SOFT, 'TableTotal', Theme::RULE],
                    $label   => [Theme::FILL, 'TableLabel', Theme::RULE],
                    default  => [($r - $t->headerRows) % 2 === 1 ? Theme::ZEBRA : null, 'CellText', Theme::RULE],
                };
                $edge = static fn (string $side, string $colour, int $size): string => '<w:' . $side . ' w:val="single" w:sz="' . $size . '" w:space="0" w:color="' . $colour . '"/>';
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$c] . '" w:type="dxa"/><w:tcBorders>'
                    . ($isTotal ? $edge('top', Theme::GREEN, 12) : $edge('top', $border, 4)) . $edge('left', $border, 4) . $edge('bottom', $border, 4) . $edge('right', $border, 4) . '</w:tcBorders>'
                    . ($fill !== null ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '') . '<w:vAlign w:val="center"/></w:tcPr>'
                    . '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/></w:pPr><w:r><w:t xml:space="preserve">' . self::x($row[$c] ?? '') . '</w:t></w:r></w:p></w:tc>';
            }
            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl><w:p><w:pPr><w:spacing w:after="60"/></w:pPr></w:p>';
    }

    // ── Header and footer ──────────────────────────────────────────────────────

    /** @param list<Paragraph|Table> $blocks */
    private function running(array $blocks, Page $page): string
    {
        $xml = '';
        foreach ($blocks as $block) {
            $xml .= $block instanceof Table ? $this->layoutTable($block, $page) : $this->paragraph($block, 'Running', $page->contentCm());
        }

        // A header or footer must end with a paragraph (a table cannot be the last thing).
        return $xml === '' || str_ends_with($xml, '</w:tbl>') ? $xml . '<w:p><w:pPr><w:pStyle w:val="Running"/></w:pPr></w:p>' : $xml;
    }

    private function layoutTable(Table $table, Page $page): string
    {
        $total  = Page::twips($page->contentCm());
        $widths = array_map(static fn (float $s): int => (int) round($s * $total), $table->widths);
        $xml    = '<w:tbl><w:tblPr><w:tblW w:w="' . $total . '" w:type="dxa"/><w:tblLayout w:type="fixed"/><w:tblCellMar><w:top w:w="57" w:type="dxa"/><w:left w:w="85" w:type="dxa"/><w:bottom w:w="57" w:type="dxa"/><w:right w:w="85" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'
            . implode('', array_map(static fn (int $w): string => '<w:gridCol w:w="' . $w . '"/>', $widths)) . '</w:tblGrid>';
        $placement = $table->placement();
        for ($r = 0; $r < $table->rows; ++$r) {
            $xml .= '<w:tr>';
            for ($c = 0; $c < $table->columns();) {
                $at = $placement[$r][$c] ?? null;
                if ($at === null) {
                    $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $widths[$c] . '" w:type="dxa"/></w:tcPr><w:p><w:pPr><w:pStyle w:val="Running"/></w:pPr></w:p></w:tc>';
                    ++$c;
                    continue;
                }
                $cell = $at['cell'];
                $span = array_sum(\array_slice($widths, $cell->col, $cell->colspan));
                if ($at['origin']) {
                    $xml .= $this->layoutCell($cell, $span, $cell->rowspan > 1 ? 'restart' : null);
                } else {
                    // Under a cell merged down: a cell that continues it. (The positions to the right of a merged cell are skipped by its span.)
                    $xml .= $this->layoutCell($cell, $span, 'continue');
                }
                $c += $cell->colspan;
            }
            $xml .= '</w:tr>';
        }

        return $xml . '</w:tbl>';
    }

    private function layoutCell(Cell $cell, int $width, ?string $merge): string
    {
        $edge   = static fn (string $side): string => '<w:' . $side . ($cell->border ? ' w:val="single" w:sz="4" w:space="0" w:color="' . Theme::LINE . '"/>' : ' w:val="nil"/>');
        $valign = ['top' => 'top', 'middle' => 'center', 'bottom' => 'bottom'][$cell->valign ?? 'middle'] ?? 'center';
        $inner  = '';
        if ($merge !== 'continue') {
            foreach ($cell->paragraphs as $p) {
                // What the cell has for a logo: its width less the margins of the cells of the table (85 twips on each side).
                $inner .= $this->paragraph($p, 'Running', ($width - 170) / 567);
            }
        }

        return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>' . ($cell->colspan > 1 ? '<w:gridSpan w:val="' . $cell->colspan . '"/>' : '')
            . ($merge === 'restart' ? '<w:vMerge w:val="restart"/>' : ($merge === 'continue' ? '<w:vMerge/>' : ''))
            . '<w:tcBorders>' . $edge('top') . $edge('left') . $edge('bottom') . $edge('right') . '</w:tcBorders>'
            . ($cell->background !== null ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $cell->background . '"/>' : '')
            . '<w:vAlign w:val="' . $valign . '"/></w:tcPr>' . ($inner === '' ? '<w:p><w:pPr><w:pStyle w:val="Running"/></w:pPr></w:p>' : $inner) . '</w:tc>';
    }

    // ── Paragraphs ─────────────────────────────────────────────────────────────

    private function paragraph(Paragraph $p, ?string $style, float $roomCm = 17.0): string
    {
        $pr = ($style !== null ? '<w:pStyle w:val="' . $style . '"/>' : '') . ($p->align !== 'left' ? '<w:jc w:val="' . ($p->align === 'justify' ? 'both' : $p->align) . '"/>' : '');
        $xml = '';
        foreach ($p->runs as $run) {
            $xml .= $this->run($run, $roomCm);
        }

        return '<w:p>' . ($pr !== '' ? '<w:pPr>' . $pr . '</w:pPr>' : '') . $xml . '</w:p>';
    }

    /** A logo drawn in the line (its file and relationship are made for the part being built). */
    private function picture(Run $run, float $roomCm): string
    {
        $image = $this->images[$run->kind] ?? null;
        if ($image === null) {
            return '';
        }
        $this->used[$run->kind] = $image;
        [$w, $h] = $image->sizeCm($roomCm);
        $cx = (int) round($w * 360000);
        $cy = (int) round($h * 360000);
        $id = ++$this->drawings;

        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="Logo ' . $id . '"/>'
            . '<wp:cNvGraphicFramePr><a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="' . $id . '" name="' . $run->kind . '.' . $image->extension() . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="rIdImg' . $run->kind . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    private function run(Run $run, float $roomCm): string
    {
        if ($run->isImage()) {
            return $this->picture($run, $roomCm);
        }
        $pr = ($run->bold ? '<w:b/>' : '') . ($run->italic ? '<w:i/>' : '') . ($run->color !== null ? '<w:color w:val="' . $run->color . '"/>' : '')
            . ($run->size !== null ? '<w:sz w:val="' . (int) round($run->size * 2) . '"/>' : '') . ($run->underline ? '<w:u w:val="single"/>' : '');
        $rpr = $pr !== '' ? '<w:rPr>' . $pr . '</w:rPr>' : '';

        return match ($run->kind) {
            Run::BREAK => '<w:r><w:br/></w:r>',
            Run::PAGE  => '<w:fldSimple w:instr=" PAGE "><w:r>' . $rpr . '<w:t>1</w:t></w:r></w:fldSimple>',
            Run::PAGES => '<w:fldSimple w:instr=" NUMPAGES "><w:r>' . $rpr . '<w:t>1</w:t></w:r></w:fldSimple>',
            default    => '<w:r>' . $rpr . '<w:t xml:space="preserve">' . self::x($run->text) . '</w:t></w:r>',
        };
    }

    private static function x(string $text): string
    {
        // No control characters: XML 1.0 does not allow them.
        return htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
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
        }
        $zip->close();
        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }
}
