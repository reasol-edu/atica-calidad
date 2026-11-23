<?php

declare(strict_types=1);

namespace App\Service\Form\Office;

use App\Service\Form\Layout\Cell;
use App\Service\Form\Layout\DataTable;
use App\Service\Form\Layout\Heading;
use App\Service\Form\Layout\Paragraph;
use App\Service\Form\Layout\Run;
use App\Service\Form\Layout\Table;

/**
 * A document as a Word file (.docx), made by hand (a ZIP of XML): the body (headings, paragraphs, lists of fields and tables) in the
 * look of the application, the running header and footer as the section's, and its orientation.
 */
final class DocxDocument
{
    private const string NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

    private const string XML = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    /**
     * @param list<Heading|Paragraph|DataTable> $body
     * @param list<Paragraph|Table>             $header
     * @param list<Paragraph|Table>             $footer
     */
    public function render(array $body, array $header, array $footer, Page $page, float $scale, string $title): string
    {
        $content = '';
        foreach ($body as $block) {
            $content .= match (true) {
                $block instanceof Heading   => '<w:p><w:pPr><w:pStyle w:val="' . ($block->level === 0 ? 'Title' : 'Heading' . $block->level) . '"/></w:pPr><w:r><w:t xml:space="preserve">' . self::x($block->text) . '</w:t></w:r></w:p>',
                $block instanceof DataTable => $this->dataTable($block, $page),
                default                     => $this->paragraph($block, $block->style === Paragraph::LABEL ? 'Label' : null),
            };
        }

        $width  = Page::twips($page->widthCm());
        $height = Page::twips($page->heightCm());
        $sect   = '<w:sectPr><w:headerReference w:type="default" r:id="rId2"/><w:footerReference w:type="default" r:id="rId3"/>'
            . '<w:pgSz w:w="' . ($page->landscape ? $height : $width) . '" w:h="' . ($page->landscape ? $width : $height) . '"' . ($page->landscape ? ' w:orient="landscape"' : '') . '/>'
            . '<w:pgMar w:top="' . Page::twips(Page::MARGIN_TOP) . '" w:right="' . Page::twips(Page::MARGIN_X) . '" w:bottom="' . Page::twips(Page::MARGIN_BOTTOM) . '" w:left="' . Page::twips(Page::MARGIN_X) . '" w:header="454" w:footer="454" w:gutter="0"/></w:sectPr>';

        $ct = 'application/vnd.openxmlformats-officedocument.wordprocessingml.';

        return $this->zip([
            '[Content_Types].xml'          => self::XML . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/word/document.xml" ContentType="' . $ct . 'document.main+xml"/><Override PartName="/word/styles.xml" ContentType="' . $ct . 'styles+xml"/>'
                . '<Override PartName="/word/header1.xml" ContentType="' . $ct . 'header+xml"/><Override PartName="/word/footer1.xml" ContentType="' . $ct . 'footer+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
            '_rels/.rels'                  => self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
            'word/_rels/document.xml.rels' => self::XML . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/></Relationships>',
            'word/document.xml'            => self::XML . '<w:document ' . self::NS . '><w:body>' . $content . $sect . '</w:body></w:document>',
            'word/header1.xml'             => self::XML . '<w:hdr ' . self::NS . '>' . $this->running($header, $page) . '</w:hdr>',
            'word/footer1.xml'             => self::XML . '<w:ftr ' . self::NS . '>' . $this->running($footer, $page) . '</w:ftr>',
            'word/styles.xml'              => $this->styles($scale),
            'docProps/core.xml'            => self::XML . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>' . self::x($title) . '</dc:title></cp:coreProperties>',
        ]);
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
            $xml .= $block instanceof Table ? $this->layoutTable($block, $page) : $this->paragraph($block, 'Running');
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
                $inner .= $this->paragraph($p, 'Running');
            }
        }

        return '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>' . ($cell->colspan > 1 ? '<w:gridSpan w:val="' . $cell->colspan . '"/>' : '')
            . ($merge === 'restart' ? '<w:vMerge w:val="restart"/>' : ($merge === 'continue' ? '<w:vMerge/>' : ''))
            . '<w:tcBorders>' . $edge('top') . $edge('left') . $edge('bottom') . $edge('right') . '</w:tcBorders>'
            . ($cell->background !== null ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $cell->background . '"/>' : '')
            . '<w:vAlign w:val="' . $valign . '"/></w:tcPr>' . ($inner === '' ? '<w:p><w:pPr><w:pStyle w:val="Running"/></w:pPr></w:p>' : $inner) . '</w:tc>';
    }

    // ── Paragraphs ─────────────────────────────────────────────────────────────

    private function paragraph(Paragraph $p, ?string $style): string
    {
        $pr = ($style !== null ? '<w:pStyle w:val="' . $style . '"/>' : '') . ($p->align !== 'left' ? '<w:jc w:val="' . ($p->align === 'justify' ? 'both' : $p->align) . '"/>' : '');
        $xml = '';
        foreach ($p->runs as $run) {
            $xml .= $this->run($run);
        }

        return '<w:p>' . ($pr !== '' ? '<w:pPr>' . $pr . '</w:pPr>' : '') . $xml . '</w:p>';
    }

    private function run(Run $run): string
    {
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
