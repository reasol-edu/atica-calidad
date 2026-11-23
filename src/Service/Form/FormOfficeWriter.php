<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Service\Form\Layout\DataTable;
use App\Service\Form\Layout\Heading;
use App\Service\Form\Layout\LayoutHtml;
use App\Service\Form\Layout\Paragraph;
use App\Service\Form\Layout\Run;
use App\Service\Form\Office\DocxDocument;
use App\Service\Form\Office\OdtDocument;
use App\Service\Form\Office\Page;

/**
 * A resolved document (FormResolver) as a word-processor file, DOCX or ODT, to keep working on it outside the application: the same
 * sections, texts, field lists and tables the web and the PDF show, in the look of the application, with the running header and
 * footer, the orientation and the font scale of the model's layout. Built by hand (a ZIP of XML): DocxDocument and OdtDocument.
 */
final class FormOfficeWriter
{
    public const array FORMATS = ['docx', 'odt'];

    public function __construct(private readonly LayoutHtml $html) {}

    /**
     * @param list<array<string, mixed>> $sections the output of FormResolver::resolve()
     * @param ?string                    $heading  a title for the top of the body, when the document has a name of its own (the header
     *                                             already says the model's)
     *
     * @return string the file's content
     */
    public function build(string $format, string $title, array $sections, DocumentLayout $layout, ?string $heading = null): string
    {
        $body = $heading !== null && $heading !== '' ? [new Heading(0, $heading)] : [];
        array_push($body, ...$this->collect($sections));

        $document = $format === 'odt' ? new OdtDocument() : new DocxDocument();

        return $document->render(
            $body,
            $this->html->parseForDocument($layout->headerHtml, $layout->images),
            $this->html->parseForDocument($layout->firstHeaderHtml, $layout->images),
            $this->html->parseForDocument($layout->footerHtml, $layout->images),
            new Page($layout->isLandscape(), $layout->margins),
            $layout->factor(),
            $title,
            $layout->images,
        );
    }

    /**
     * @param list<array<string, mixed>> $sections
     *
     * @return list<Heading|Paragraph|DataTable>
     */
    private function collect(array $sections): array
    {
        $items = [];
        foreach ($sections as $s) {
            $number = FormDefinition::str($s['number'] ?? '');
            $name   = FormDefinition::str($s['title'] ?? '');
            if ($name !== '') {
                $items[] = new Heading(max(1, min(5, FormDefinition::int($s['level'] ?? 1))), ($number !== '' ? $number . '. ' : '') . $name);
            }
            foreach (FormDefinition::listOf($s['blocks'] ?? []) as $b) {
                $kind = $b['kind'] ?? '';
                if ($kind === 'text') {
                    array_push($items, ...$this->text($b));
                } elseif ($kind === 'fields') {
                    $items[] = new DataTable(
                        array_map(static fn (array $i): array => [FormDefinition::str($i['label'] ?? ''), FormDefinition::str($i['value'] ?? '')], FormDefinition::listOf($b['items'] ?? [])),
                        0,
                        true,
                    );
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
     * @return list<Paragraph>
     */
    private function text(array $b): array
    {
        $items = $this->label($b);
        $html  = FormDefinition::str($b['html'] ?? '');
        // Rich text keeps its bold, italics, underline and lists; a plain text (a value typed before the field took formatting) is its lines.
        $blocks = FormRichText::isHtml($html) ? $this->html->parse($html) : array_map(static fn (string $line): Paragraph => new Paragraph([new Run(Run::TEXT, $line)]), self::paragraphs($html));
        foreach ($blocks as $block) {
            if ($block instanceof Paragraph) {
                $items[] = $block;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return list<Paragraph|DataTable>
     */
    private function table(array $b): array
    {
        $items  = $this->label($b);
        $fixed  = (bool) ($b['fixed'] ?? false);
        $header = array_map(static fn (mixed $c): string => FormDefinition::str($c), \is_array($b['columns'] ?? null) ? array_values($b['columns']) : []);
        $rows   = [$fixed ? array_merge([FormDefinition::str($b['rowHeader'] ?? '')], $header) : $header];
        foreach (FormDefinition::listOf($b['rows'] ?? []) as $r) {
            $cells  = array_map(static fn (mixed $c): string => FormDefinition::str($c), \is_array($r['cells'] ?? null) ? array_values($r['cells']) : []);
            $rows[] = $fixed ? array_merge([FormDefinition::str($r['label'] ?? '')], $cells) : $cells;
        }
        $totals = \is_array($b['totals'] ?? null) && $b['totals'] !== [];
        if ($totals) {
            $values = array_map(static fn (mixed $c): string => FormDefinition::str($c), array_values((array) $b['totals']));
            $rows[] = $fixed ? array_merge(['Total'], $values) : $values;
        }
        $items[] = new DataTable($rows, 1, $fixed, $totals);

        return $items;
    }

    /**
     * @param array<string, mixed> $b
     *
     * @return list<Paragraph>
     */
    private function label(array $b): array
    {
        $label = FormDefinition::str($b['label'] ?? '');

        return $label === '' ? [] : [new Paragraph([new Run(Run::TEXT, $label)], 'left', Paragraph::LABEL)];
    }

    /** @return list<string> the paragraphs of a piece of rich text, lists as "• item" lines */
    public static function paragraphs(string $html): array
    {
        $html = preg_replace(['~<li[^>]*>~i', '~</(p|div|li|ul|ol|h[1-6]|tr)>~i', '~<br\s*/?>~i'], ["\n• ", "\n", "\n"], $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return array_values(array_filter(array_map(static fn (string $l): string => trim(preg_replace('/\s+/u', ' ', $l) ?? $l), explode("\n", $text)), static fn (string $l): bool => $l !== ''));
    }
}
