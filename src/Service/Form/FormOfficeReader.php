<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * The first draft of a model made from a DOCX or ODT: headings become sections (nested by level), paragraphs become fixed texts
 * and a table becomes a table of free rows whose columns are named after its first row. What a person fills in is then added in
 * the editor (fields, types, options), because a word-processor file does not say it. Reads the XML inside the ZIP, never
 * anything else from it, and refuses what is too big or is not what it says.
 */
final class FormOfficeReader
{
    private const int MAX_XML = 8_000_000;

    /** @return array<string, mixed> a definition (not normalized yet) */
    public function read(string $path, string $format): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new FormException('error.import_file');
        }
        try {
            $entry = $format === 'odt' ? 'content.xml' : 'word/document.xml';
            $stat  = $zip->statName($entry);
            if ($stat === false || $stat['size'] > self::MAX_XML) {
                throw new FormException('error.import_file');
            }
            $xml = $zip->getFromName($entry);
        } finally {
            $zip->close();
        }
        if ($xml === false) {
            throw new FormException('error.import_file');
        }

        $dom = new \DOMDocument();
        // No entities, no network: the file is not trusted.
        if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new FormException('error.import_file');
        }

        return $this->build($format === 'odt' ? $this->odtItems($dom) : $this->docxItems($dom));
    }

    /** @return list<array{0: string, 1: mixed, 2?: int}> ['h', text, level] | ['p', text] | ['t', rows] */
    private function docxItems(\DOMDocument $dom): array
    {
        $body = $dom->getElementsByTagNameNS('*', 'body')->item(0);
        $out  = [];
        foreach ($body === null ? [] : $body->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if ($node->localName === 'p') {
                $text = trim($node->textContent);
                if ($text === '') {
                    continue;
                }
                $level = 0;
                foreach ($node->getElementsByTagNameNS('*', 'pStyle') as $style) {
                    // "Heading1", Word in Spanish "Ttulo1" / "Título1", in French "Titre1"...
                    if (preg_match('/^(?:head|t.?tulo|titre|.?berschrift)\w*?\s*(\d)$/iu', $style->getAttributeNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'val'), $m) === 1) {
                        $level = (int) $m[1];
                    }
                }
                $out[] = $level > 0 ? ['h', $text, $level] : ['p', $text];
            } elseif ($node->localName === 'tbl') {
                $rows = [];
                foreach ($node->getElementsByTagNameNS('*', 'tr') as $tr) {
                    $cells = [];
                    foreach ($tr->childNodes as $tc) {
                        if ($tc instanceof \DOMElement && $tc->localName === 'tc') {
                            $cells[] = trim($tc->textContent);
                        }
                    }
                    $rows[] = $cells;
                }
                $out[] = ['t', $rows];
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: mixed, 2?: int}> */
    private function odtItems(\DOMDocument $dom): array
    {
        $text = $dom->getElementsByTagNameNS('*', 'text')->item(0);
        $out  = [];
        foreach ($text === null ? [] : $text->childNodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            if ($node->localName === 'h' || $node->localName === 'p') {
                $value = trim($node->textContent);
                if ($value === '') {
                    continue;
                }
                $out[] = $node->localName === 'h' ? ['h', $value, max(1, (int) $node->getAttributeNS('urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'outline-level'))] : ['p', $value];
            } elseif ($node->localName === 'table') {
                $rows = [];
                foreach ($node->getElementsByTagNameNS('*', 'table-row') as $tr) {
                    $cells = [];
                    foreach ($tr->childNodes as $tc) {
                        if ($tc instanceof \DOMElement && $tc->localName === 'table-cell') {
                            $cells[] = trim($tc->textContent);
                        }
                    }
                    $rows[] = $cells;
                }
                $out[] = ['t', $rows];
            }
        }

        return $out;
    }

    /**
     * @param list<array<int, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function build(array $items): array
    {
        // One entry per section, in reading order: its level, title, the section it is in (an index) and its blocks.
        /** @var list<int> $levels */
        $levels = [];
        /** @var list<string> $titles */
        $titles = [];
        /** @var list<int|null> $parents */
        $parents = [];
        /** @var list<list<array<string, mixed>>> $blocks */
        $blocks = [];
        /** @var list<int> $stack the sections open now, from the outermost */
        $stack = [];

        $open = static function (string $title, int $level) use (&$levels, &$titles, &$parents, &$blocks, &$stack): void {
            while ($stack !== [] && $levels[$stack[\count($stack) - 1]] >= $level) {
                array_pop($stack);
            }
            $parents[] = $stack === [] ? null : $stack[\count($stack) - 1];
            $levels[]  = $level;
            $titles[]  = mb_substr($title, 0, 255);
            $blocks[]  = [];
            $stack[]   = \count($levels) - 1;
        };

        foreach ($items as $item) {
            if ($item[0] === 'h') {
                $open(FormDefinition::str($item[1]), max(1, min(5, FormDefinition::int($item[2] ?? 1))));
                continue;
            }
            if ($stack === []) {
                $open('', 1); // what comes before the first heading is not lost
            }
            $block = $item[0] === 'p'
                ? ['key' => FormDefinition::newKey(), 'type' => 'text', 'mode' => 'fixed', 'label' => '', 'html' => '<p>' . htmlspecialchars(FormDefinition::str($item[1]), ENT_QUOTES) . '</p>', 'required' => false, 'help' => '']
                : $this->tableBlock(FormDefinition::listOf($item[1]));
            if ($block !== null) {
                $blocks[$stack[\count($stack) - 1]][] = $block;
            }
        }

        $section = static function (int $index) use (&$section, $titles, $parents, $blocks): array {
            $children = [];
            foreach ($parents as $i => $parent) {
                if ($parent === $index) {
                    $children[] = $section($i);
                }
            }

            return ['key' => FormDefinition::newKey(), 'title' => $titles[$index], 'numbered' => true, 'help' => '', 'blocks' => $blocks[$index], 'children' => $children];
        };
        $roots = [];
        foreach ($parents as $i => $parent) {
            if ($parent === null) {
                $roots[] = $section($i);
            }
        }

        return ['version' => FormDefinition::VERSION, 'sections' => $roots];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private function tableBlock(array $rows): ?array
    {
        $first = $rows[0] ?? [];
        $names = array_values(array_filter(array_map(static fn (mixed $c): string => mb_substr(trim(FormDefinition::str($c)), 0, 255), $first), static fn (string $c): bool => $c !== ''));
        if ($names === []) {
            return null;
        }

        return [
            'key' => FormDefinition::newKey(), 'type' => 'table', 'name' => '', 'label' => '', 'mode' => 'free', 'rowHeader' => '', 'rows' => [], 'minRows' => 0, 'maxRows' => 20, 'help' => '',
            'columns' => array_map(static fn (string $c): array => ['key' => FormDefinition::newKey(), 'name' => '', 'label' => $c, 'fieldType' => 'short', 'required' => false], $names),
        ];
    }
}
