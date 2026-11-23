<?php

declare(strict_types=1);

namespace App\Service\Form\Layout;

/**
 * Reads the HTML of a header or footer (already sanitized, see "app.doc_layout") into paragraphs and tables laid out on a grid, which
 * the PDF, ODT and DOCX writers draw each in its own way, and writes it back as HTML the PDF library takes. Merged cells are resolved
 * here once (the grid), because every format asks for them differently.
 */
final class LayoutHtml
{
    /** The elements that make a block of their own (what is not in one is gathered into a paragraph). */
    private const array BLOCKS = ['table', 'p', 'ul', 'ol', 'h2', 'blockquote'];

    private const int MAX_COLUMNS = 12;
    private const int MAX_ROWS    = 30;

    /**
     * @return list<Paragraph|Table>
     */
    public function parse(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return [];
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);

        return $body === null ? [] : $this->blocks($body);
    }

    /**
     * @return list<Paragraph|Table>
     */
    private function blocks(\DOMNode $parent): array
    {
        $blocks = [];
        /** @var list<Run> $inline loose text between the blocks, gathered into a paragraph */
        $inline = [];

        foreach ($parent->childNodes as $node) {
            $isBlock = $node instanceof \DOMElement && \in_array($node->tagName, self::BLOCKS, true);
            if ($isBlock && $inline !== []) {
                $blocks[] = new Paragraph($inline);
                $inline   = [];
            }
            if ($node instanceof \DOMElement && $node->tagName === 'table') {
                $table = $this->table($node);
                if ($table !== null) {
                    $blocks[] = $table;
                }
            } elseif ($node instanceof \DOMElement && $node->tagName === 'p') {
                $blocks[] = new Paragraph($this->runs($node, []), $this->align($node));
            } elseif ($node instanceof \DOMElement && ($node->tagName === 'ul' || $node->tagName === 'ol')) {
                $n = 0;
                foreach ($node->childNodes as $li) {
                    if ($li instanceof \DOMElement && $li->tagName === 'li') {
                        $marker   = $node->tagName === 'ol' ? (++$n) . '. ' : '• ';
                        $blocks[] = new Paragraph([new Run(Run::TEXT, $marker), ...$this->runs($li, [])]);
                    }
                }
            } elseif ($node instanceof \DOMElement && ($node->tagName === 'h2' || $node->tagName === 'blockquote')) {
                $blocks[] = new Paragraph($this->runs($node, $node->tagName === 'h2' ? ['bold' => true] : ['italic' => true]), $this->align($node));
            } else {
                array_push($inline, ...$this->runs($node, [], true));
            }
        }
        if ($inline !== []) {
            $blocks[] = new Paragraph($inline);
        }

        return $blocks;
    }

    private function table(\DOMElement $table): ?Table
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $rows[] = $tr;
        }
        $rows = \array_slice($rows, 0, self::MAX_ROWS);
        if ($rows === []) {
            return null;
        }

        /** @var array<int, array<int, true>> $taken the positions already covered by a cell */
        $taken   = [];
        $cells   = [];
        $columns = 0;
        foreach ($rows as $r => $tr) {
            $c = 0;
            foreach ($tr->childNodes as $td) {
                if (!$td instanceof \DOMElement || ($td->tagName !== 'td' && $td->tagName !== 'th')) {
                    continue;
                }
                while (isset($taken[$r][$c])) {
                    ++$c;
                }
                $colspan = max(1, min(self::MAX_COLUMNS, (int) $td->getAttribute('colspan')));
                $rowspan = max(1, min(self::MAX_ROWS, (int) $td->getAttribute('rowspan')));
                if ($c + $colspan > self::MAX_COLUMNS) {
                    continue;
                }
                for ($i = 0; $i < $rowspan; ++$i) {
                    for ($j = 0; $j < $colspan; ++$j) {
                        $taken[$r + $i][$c + $j] = true;
                    }
                }
                $style = $this->style($td->getAttribute('style'));
                $paragraphs = array_values(array_filter($this->blocks($td), static fn ($b): bool => $b instanceof Paragraph));
                // A cell aligned as a whole (text-align on the cell) aligns the paragraphs that say nothing of their own.
                $cellAlign = $style['text-align'] ?? 'left';
                if (\in_array($cellAlign, ['center', 'right', 'justify'], true)) {
                    $paragraphs = array_map(static fn (Paragraph $p): Paragraph => $p->align === 'left' ? new Paragraph($p->runs, $cellAlign, $p->style) : $p, $paragraphs);
                }
                $cells[]    = new Cell(
                    $r,
                    $c,
                    $colspan,
                    $rowspan,
                    $paragraphs === [] ? [new Paragraph([])] : $paragraphs,
                    $this->colour($style['background-color'] ?? null),
                    $this->hasBorder($style),
                    isset($style['vertical-align']) && \in_array($style['vertical-align'], ['top', 'middle', 'bottom'], true) ? $style['vertical-align'] : null,
                );
                $c       += $colspan;
                $columns  = max($columns, $c);
            }
        }
        if ($cells === []) {
            return null;
        }

        // A cell merged down past the last row would leave empty rows: the grid has as many rows as there are rows in the HTML.
        $rowCount = \count($rows);
        $cells    = array_map(static fn (Cell $c): Cell => $c->row + $c->rowspan > $rowCount
            ? new Cell($c->row, $c->col, $c->colspan, $rowCount - $c->row, $c->paragraphs, $c->background, $c->border, $c->valign)
            : $c, $cells);

        return new Table($this->widths($table, $columns), $rowCount, $cells);
    }

    /**
     * The share of the width of each column: the widths the editor remembers (`<col style="width: Npx">`, or `data-colwidth` of the
     * cells), else all equal.
     *
     * @return list<float>
     */
    private function widths(\DOMElement $table, int $columns): array
    {
        $px = [];
        foreach ($table->getElementsByTagName('col') as $col) {
            $style = $this->style($col->getAttribute('style'));
            $px[]  = isset($style['width']) ? (float) $style['width'] : 0.0;
        }
        if (\count($px) !== $columns || \in_array(0.0, $px, true)) {
            // The remembered widths of the first row (a merged cell lists one per column it covers).
            $px = [];
            $tr = $table->getElementsByTagName('tr')->item(0);
            if ($tr !== null) {
                foreach ($tr->childNodes as $td) {
                    if ($td instanceof \DOMElement && $td->hasAttribute('data-colwidth')) {
                        foreach (explode(',', $td->getAttribute('data-colwidth')) as $w) {
                            $px[] = (float) $w;
                        }
                    }
                }
            }
        }
        if (\count($px) !== $columns || \in_array(0.0, $px, true)) {
            return array_fill(0, $columns, 1 / $columns);
        }
        $sum = array_sum($px);

        return array_map(static fn (float $w): float => $w / $sum, $px);
    }

    /**
     * @param array{bold?: bool, italic?: bool, underline?: bool, color?: ?string, size?: ?float} $state
     *
     * @return list<Run>
     */
    private function runs(\DOMNode $node, array $state, bool $topLevel = false): array
    {
        if ($node instanceof \DOMText) {
            return $this->textRuns(preg_replace('/\s+/u', ' ', $node->textContent) ?? '', $state, $topLevel);
        }
        if (!$node instanceof \DOMElement) {
            return [];
        }
        if ($node->tagName === 'br') {
            return [new Run(Run::BREAK)];
        }
        if ($node->tagName === 'table') {
            // A table inside a paragraph (the HTML nests them oddly): only its text is taken.
            return $this->textRuns(trim($node->textContent), $state, $topLevel);
        }

        $style = $this->style($node->getAttribute('style'));
        $state['bold']      = ($state['bold'] ?? false) || \in_array($node->tagName, ['strong', 'b'], true) || ($style['font-weight'] ?? '') === 'bold';
        $state['italic']    = ($state['italic'] ?? false) || \in_array($node->tagName, ['em', 'i'], true) || ($style['font-style'] ?? '') === 'italic';
        $state['underline'] = ($state['underline'] ?? false) || $node->tagName === 'u';
        if (isset($style['color']) && $this->colour($style['color']) !== null) {
            $state['color'] = $this->colour($style['color']);
        }
        if (isset($style['font-size'])) {
            $state['size'] = $this->points($style['font-size']) ?? ($state['size'] ?? null);
        }

        $runs = [];
        foreach ($node->childNodes as $child) {
            array_push($runs, ...$this->runs($child, $state));
        }

        return $runs;
    }

    /**
     * @param array{bold?: bool, italic?: bool, underline?: bool, color?: ?string, size?: ?float} $state
     *
     * @return list<Run>
     */
    private function textRuns(string $text, array $state, bool $trim): array
    {
        if ($trim && trim($text) === '') {
            return [];
        }
        $runs = [];
        $fields = ['{pagina}' => Run::PAGE, '{paginas}' => Run::PAGES, '{logo}' => Run::LOGO, '{logo2}' => Run::LOGO2];
        foreach (preg_split('/(\{pagina\}|\{paginas\}|\{logo\}|\{logo2\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if (isset($fields[$piece])) {
                $runs[] = new Run($fields[$piece], '', $state['bold'] ?? false, $state['italic'] ?? false, $state['underline'] ?? false, $state['color'] ?? null, $state['size'] ?? null);
            } else {
                $runs[] = new Run(Run::TEXT, $piece, $state['bold'] ?? false, $state['italic'] ?? false, $state['underline'] ?? false, $state['color'] ?? null, $state['size'] ?? null);
            }
        }

        return $runs;
    }

    /** @return 'left'|'center'|'right'|'justify' */
    private function align(\DOMElement $p): string
    {
        $align = $this->style($p->getAttribute('style'))['text-align'] ?? 'left';

        return match ($align) {
            'center'  => 'center',
            'right'   => 'right',
            'justify' => 'justify',
            default   => 'left',
        };
    }

    /** @return array<string, string> */
    private function style(string $style): array
    {
        $map = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (\count($parts) === 2) {
                $map[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $map;
    }

    /** A CSS colour as six hex digits (no '#'): #rgb, #rrggbb and rgb(r, g, b); null when it is anything else. */
    private function colour(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{6})$/', $value, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m) === 1) {
            return $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})/', $value, $m) === 1) {
            return sprintf('%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }

        return null;
    }

    /** A font size in points (it takes pt, px, em and %: the last two against a 10 pt base). */
    private function points(string $value): ?float
    {
        if (preg_match('/^(\d+(?:\.\d+)?)(pt|px|em|rem|%)$/', trim($value), $m) !== 1) {
            return null;
        }
        $n = (float) $m[1];

        return match ($m[2]) {
            'pt'      => $n,
            'px'      => $n * 0.75,
            'em', 'rem' => $n * 10,
            default   => $n / 10,
        };
    }

    /** @param array<string, string> $style a cell has lines unless it says `border: none` */
    private function hasBorder(array $style): bool
    {
        $border = $style['border'] ?? ($style['border-top'] ?? '');

        return $border !== '' && !str_starts_with($border, 'none') && !str_starts_with($border, '0');
    }

    // ── PDF ────────────────────────────────────────────────────────────────────

    /**
     * The blocks as HTML for the PDF library (mPDF): tables of percentage widths, inline styles (a header is drawn outside the
     * document's stylesheet), and the page fields as its `{PAGENO}` and `{nbpg}`.
     *
     * @param list<Paragraph|Table> $blocks
     * @param array<string, Image>  $images the logos by token, drawn where `{logo}` and `{logo2}` are
     */
    public function toPdfHtml(array $blocks, float $baseSize, array $images = [], float $contentCm = 17.0): string
    {
        $out = '<div style="font-family: sourcesanspro, sans-serif; font-size: ' . $this->n($baseSize) . 'pt; color: #2e2822;">';
        foreach ($blocks as $block) {
            $out .= $block instanceof Table ? $this->pdfTable($block, $images, $contentCm) : $this->pdfParagraph($block, $images, $contentCm);
        }

        return $out . '</div>';
    }

    /** @param array<string, Image> $images */
    private function pdfTable(Table $table, array $images, float $contentCm): string
    {
        $rows = array_fill(0, $table->rows, '');
        foreach ($table->cells as $cell) {
            $style = ['padding: 3px 5px'];
            $style[] = $cell->border ? 'border: 0.5pt solid #555555' : 'border: none';
            if ($cell->background !== null) {
                $style[] = 'background-color: #' . $cell->background;
            }
            $style[] = 'vertical-align: ' . ($cell->valign ?? 'middle');
            // The width of the columns it covers: mPDF sizes a table by what is in it unless every cell says how wide it is (a `<col>`
            // alone is not enough once there are merged cells), so the logos' columns keep their width whether they hold a logo or not.
            $style[] = 'width: ' . $this->n(array_sum(\array_slice($table->widths, $cell->col, $cell->colspan)) * 100) . '%';
            // mPDF aligns the text of a cell by the cell, not by the paragraphs in it: the first paragraph's alignment is the cell's.
            $style[] = 'text-align: ' . ($cell->paragraphs[0]->align ?? 'left');
            $content = '';
            foreach ($cell->paragraphs as $p) {
                // What the cell has for a logo: its share of the width, less its padding (5 px on each side).
                $room     = array_sum(\array_slice($table->widths, $cell->col, $cell->colspan)) * $contentCm - 0.4;
                $content .= $this->pdfParagraph($p, $images, $room);
            }
            $rows[$cell->row] .= '<td' . ($cell->colspan > 1 ? ' colspan="' . $cell->colspan . '"' : '') . ($cell->rowspan > 1 ? ' rowspan="' . $cell->rowspan . '"' : '')
                . ' style="' . implode('; ', $style) . '">' . $content . '</td>';
        }

        $cols = '';
        foreach ($table->widths as $w) {
            // The attribute: mPDF ignores a width given in the style of a <col>.
            $cols .= '<col width="' . $this->n($w * 100) . '%">';
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= '<tr>' . $row . '</tr>';
        }

        return '<table style="width: 100%; border-collapse: collapse; table-layout: fixed;">' . $cols . $body . '</table>';
    }

    /** @param array<string, Image> $images */
    private function pdfParagraph(Paragraph $p, array $images, float $roomCm): string
    {
        $html = '';
        foreach ($p->runs as $run) {
            $html .= $this->pdfRun($run, $images, $roomCm);
        }

        return '<p style="margin: 0; text-align: ' . $p->align . ';">' . ($html === '' ? '&nbsp;' : $html) . '</p>';
    }

    /** @param array<string, Image> $images */
    private function pdfRun(Run $run, array $images, float $roomCm): string
    {
        if ($run->kind === Run::BREAK) {
            return '<br>';
        }
        if ($run->isImage()) {
            $image = $images[$run->kind] ?? null;
            if ($image === null) {
                return '';
            }
            [$w, $h] = $image->sizeCm($roomCm);

            return '<img src="data:' . $image->mime . ';base64,' . base64_encode($image->content) . '" style="width: ' . $this->n($w * 10) . 'mm; height: ' . $this->n($h * 10) . 'mm;">';
        }
        $text = match ($run->kind) {
            Run::PAGE  => '{PAGENO}',
            Run::PAGES => '{nbpg}',
            default    => htmlspecialchars($run->text, ENT_QUOTES),
        };
        $style = [];
        if ($run->bold) {
            $style[] = 'font-weight: bold';
        }
        if ($run->italic) {
            $style[] = 'font-style: italic';
        }
        if ($run->underline) {
            $style[] = 'text-decoration: underline';
        }
        if ($run->color !== null) {
            $style[] = 'color: #' . $run->color;
        }
        if ($run->size !== null) {
            $style[] = 'font-size: ' . $this->n($run->size) . 'pt';
        }

        return $style === [] ? $text : '<span style="' . implode('; ', $style) . '">' . $text . '</span>';
    }

    private function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
