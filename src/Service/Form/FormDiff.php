<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * What changed between two revisions of a filled document, value by value: the reviewer of a correction reads only what is new.
 * It compares what people read (the resolved documents), identifying every value by the keys of its section copy, its block and, in
 * a table, its row and column — so it still lines up when the two revisions were filled with different revisions of the model, and
 * when rows were reordered.
 */
final class FormDiff
{
    private const int MAX_TEXT = 400;

    public function __construct(
        private readonly FormResolver $resolver,
    ) {}

    /**
     * @param array<string, mixed>  $beforeDefinition
     * @param array<string, mixed>  $beforeData
     * @param array<string, mixed>  $afterDefinition
     * @param array<string, mixed>  $afterData
     * @param array<string, string> $context
     *
     * @return list<array{label: string, before: ?string, after: ?string, kind: string}> kind: added | removed | changed
     */
    public function compare(array $beforeDefinition, array $beforeData, array $afterDefinition, array $afterData, array $context = []): array
    {
        $before = $this->flatten($this->resolver->resolve($beforeDefinition, $beforeData, $context));
        $after  = $this->flatten($this->resolver->resolve($afterDefinition, $afterData, $context));

        $changes = [];
        foreach ($before as $id => $entry) {
            if (!array_key_exists($id, $after)) {
                if ($entry['value'] !== '') {
                    $changes[] = ['label' => $entry['label'], 'before' => $entry['value'], 'after' => null, 'kind' => 'removed'];
                }
            } elseif ($after[$id]['value'] !== $entry['value']) {
                $changes[] = ['label' => $after[$id]['label'], 'before' => $entry['value'] ?: null, 'after' => $after[$id]['value'] ?: null, 'kind' => 'changed'];
            }
        }
        foreach ($after as $id => $entry) {
            if (!array_key_exists($id, $before) && $entry['value'] !== '') {
                $changes[] = ['label' => $entry['label'], 'before' => null, 'after' => $entry['value'], 'kind' => 'added'];
            }
        }

        return $changes;
    }

    /**
     * Every value of a resolved document by its identity.
     *
     * @param list<array<string, mixed>> $sections
     *
     * @return array<string, array{label: string, value: string}>
     */
    private function flatten(array $sections, string $trail = ''): array
    {
        $out = [];
        foreach ($sections as $section) {
            $path  = FormDefinition::str($section['path'] ?? '');
            $title = trim(FormDefinition::str($section['number'] ?? '') . ' ' . FormDefinition::str($section['title'] ?? ''));
            $where = $trail === '' ? $title : $trail . ' › ' . $title;

            foreach (FormDefinition::listOf($section['blocks'] ?? []) as $block) {
                $key = FormDefinition::str($block['key'] ?? '');
                switch ($block['kind'] ?? '') {
                    case 'text':
                        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(FormDefinition::str($block['html'] ?? '')))));
                        $out[$path . '|' . $key] = ['label' => $where . ' › ' . FormDefinition::str($block['label'] ?? ''), 'value' => mb_substr($text, 0, self::MAX_TEXT)];
                        break;

                    case 'fields':
                        foreach (FormDefinition::listOf($block['items'] ?? []) as $item) {
                            $out[$path . '|' . FormDefinition::str($item['key'] ?? '')] = ['label' => $where . ' › ' . FormDefinition::str($item['label'] ?? ''), 'value' => FormDefinition::str($item['value'] ?? '')];
                        }
                        break;

                    case 'table':
                        $columnKeys = array_values(array_map(static fn ($k): string => FormDefinition::str($k), is_array($block['columnKeys'] ?? null) ? $block['columnKeys'] : []));
                        $columns    = array_values(array_map(static fn ($c): string => FormDefinition::str($c), is_array($block['columns'] ?? null) ? $block['columns'] : []));
                        foreach (FormDefinition::listOf($block['rows'] ?? []) as $i => $row) {
                            $rowName = FormDefinition::str($row['label'] ?? '') ?: (string) ($i + 1);
                            foreach (array_values(array_map(static fn ($c): string => FormDefinition::str($c), is_array($row['cells'] ?? null) ? $row['cells'] : [])) as $c => $cell) {
                                $out[$path . '|' . FormDefinition::str($block['key'] ?? '') . '|' . FormDefinition::str($row['key'] ?? '') . '|' . ($columnKeys[$c] ?? $c)] = [
                                    'label' => $where . ' › ' . FormDefinition::str($block['label'] ?? '') . ' › ' . $rowName . ' › ' . ($columns[$c] ?? ''),
                                    'value' => $cell,
                                ];
                            }
                        }
                        break;
                }
            }
            $out += $this->flatten(FormDefinition::listOf($section['children'] ?? []), $where);
        }

        return $out;
    }
}
