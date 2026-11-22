<?php

declare(strict_types=1);

namespace App\Service\Form;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * What a person typed into a filled document (browser input, never trusted) turned into data the definition can explain. Data is
 * scoped (see FormWalker): the root scope holds the blocks of the sections that do not repeat, and every copy of a repeating
 * section is a scope of its own, inside the scope that holds the section:
 *
 *   scope = { "fields": { <blockKey>: value }, "texts": { <blockKey>: { html, modified } },
 *             "tables": { <blockKey>: [ { "_key": <rowKey>, <columnKey>: value } ] },
 *             "copies": { <sectionKey>: [ { "_key": <copyKey>, "_source"?: <rowKey>, …a scope } ] } }
 *
 * Only what the definition declares survives, every value has the type its field says, texts are sanitized, and a missing answer
 * is simply absent. The copies of a section that repeats by table are never typed: there is one for each row, keyed by the row,
 * so a copy follows its row when the rows are reordered. `initial()` is the data of a new document.
 */
final class FormDataNormalizer
{
    public const int MAX_ROWS   = 1000;
    public const int MAX_COPIES = 100;

    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.app.rich_text')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {}

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function initial(array $definition): array
    {
        return $this->normalize($definition, []);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public function normalize(array $definition, array $raw): array
    {
        return $this->scope(FormDefinition::listOf($definition['sections'] ?? []), $raw, []);
    }

    /**
     * What a blank copy of the model looks like: the initial data plus one empty copy of every repeating section that has none, so
     * the preview and the blank PDF show them.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function sample(array $definition): array
    {
        return $this->sampleScope(FormDefinition::listOf($definition['sections'] ?? []), $this->initial($definition), []);
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @param array<string, mixed>       $data
     * @param list<array<string, mixed>> $outer
     *
     * @return array<string, mixed>
     */
    private function sampleScope(array $sections, array $data, array $outer): array
    {
        [, $repeating] = $this->collect($sections);
        $chain         = [$data, ...$outer];
        $copies        = is_array($data['copies'] ?? null) ? $data['copies'] : [];
        foreach ($repeating as $section) {
            $key   = FormDefinition::str($section['key'] ?? '');
            $inner = ['repeat' => null] + $section;
            $list  = FormDefinition::listOf($copies[$key] ?? []);
            if ($list === []) {
                $list = [['_key' => FormDefinition::newKey()] + $this->scope([$inner], [], $chain)];
            }
            $copies[$key] = array_map(fn (array $copy): array => $this->sampleScope([$inner], $copy, $chain), $list);
        }
        $data['copies'] = $copies;

        return $data;
    }

    /**
     * The same data without the rows of a free table in which nobody typed anything (the editor leaves blank rows to type
     * into) — and without the copies that belonged to them: what is delivered and frozen.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function withoutEmptyRows(array $definition, array $data): array
    {
        return $this->normalize($definition, $this->stripEmptyRows(FormDefinition::listOf($definition['sections'] ?? []), $data));
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $sections
     * @param array<string, mixed>       $raw
     * @param list<array<string, mixed>> $outer    the normalized scopes around this one, innermost first
     *
     * @return array<string, mixed>
     */
    private function scope(array $sections, array $raw, array $outer): array
    {
        [$blocks, $repeating] = $this->collect($sections);
        $data                 = $this->blocksData($blocks, $raw);
        $chain                = [$data, ...$outer];

        $rawCopies = is_array($raw['copies'] ?? null) ? $raw['copies'] : [];
        $copies    = [];
        foreach ($repeating as $section) {
            $key          = FormDefinition::str($section['key'] ?? '');
            $copies[$key] = $this->copies($section, $chain, FormDefinition::listOf($rawCopies[$key] ?? []), array_key_exists($key, $rawCopies));
        }

        return $data + ['copies' => $copies];
    }

    /**
     * The blocks that live in this scope and the repeating sections that open a new one (a repeating section is a boundary).
     *
     * @param list<array<string, mixed>> $sections
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function collect(array $sections): array
    {
        $blocks = $repeating = [];
        foreach ($sections as $section) {
            if (is_array($section['repeat'] ?? null)) {
                $repeating[] = $section;
                continue;
            }
            array_push($blocks, ...FormDefinition::listOf($section['blocks'] ?? []));
            [$childBlocks, $childRepeating] = $this->collect(FormDefinition::listOf($section['children'] ?? []));
            array_push($blocks, ...$childBlocks);
            array_push($repeating, ...$childRepeating);
        }

        return [$blocks, $repeating];
    }

    /**
     * @param array<string, mixed>       $section
     * @param list<array<string, mixed>> $chain    this scope first, then the ones around it
     * @param list<array<string, mixed>> $rawList
     *
     * @return list<array<string, mixed>>
     */
    private function copies(array $section, array $chain, array $rawList, bool $given): array
    {
        $repeat = is_array($section['repeat'] ?? null) ? $section['repeat'] : [];
        $inner  = ['repeat' => null] + $section;
        $byKey  = [];
        foreach ($rawList as $copy) {
            $byKey[FormDefinition::str($copy['_key'] ?? '')] = $copy;
        }

        $out = [];
        switch (FormDefinition::str($repeat['mode'] ?? '')) {
            case 'table':
                foreach ($this->rowsOf($chain, FormDefinition::str($repeat['table'] ?? '')) as $row) {
                    $key   = FormDefinition::str($row['_key'] ?? '');
                    $out[] = ['_key' => $key, '_source' => $key] + $this->scope([$inner], $byKey[$key] ?? [], $chain);
                }
                break;

            case 'field':
                $count = min(self::MAX_COPIES, max(0, FormDefinition::int($this->fieldOf($chain, FormDefinition::str($repeat['field'] ?? '')))));
                for ($i = 0; $i < $count; ++$i) {
                    $out[] = $this->numbered($inner, $rawList[$i] ?? [], $chain, $out);
                }
                break;

            default: // manual
                $max   = min(self::MAX_COPIES, FormDefinition::int($repeat['max'] ?? self::MAX_COPIES) ?: self::MAX_COPIES);
                $total = $given ? min($max, count($rawList)) : min($max, FormDefinition::int($repeat['min'] ?? 0));
                for ($i = 0; $i < $total; ++$i) {
                    $out[] = $this->numbered($inner, $rawList[$i] ?? [], $chain, $out);
                }
        }

        return $out;
    }

    /**
     * One copy that is identified by its own key (typed, not derived): its raw key when it is a valid one nobody used yet.
     *
     * @param array<string, mixed>       $inner
     * @param array<string, mixed>       $raw
     * @param list<array<string, mixed>> $chain
     * @param list<array<string, mixed>> $done
     *
     * @return array<string, mixed>
     */
    private function numbered(array $inner, array $raw, array $chain, array $done): array
    {
        $key = FormDefinition::str($raw['_key'] ?? '');
        if (preg_match('/^[0-9a-f-]{36}$/', $key) !== 1 || in_array($key, array_column($done, '_key'), true)) {
            $key = FormDefinition::newKey();
        }

        return ['_key' => $key] + $this->scope([$inner], $raw, $chain);
    }

    /**
     * @param list<array<string, mixed>> $chain
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(array $chain, string $tableKey): array
    {
        foreach ($chain as $scope) {
            if (is_array($scope['tables'] ?? null) && array_key_exists($tableKey, $scope['tables'])) {
                return FormDefinition::listOf($scope['tables'][$tableKey]);
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $chain
     */
    private function fieldOf(array $chain, string $fieldKey): mixed
    {
        foreach ($chain as $scope) {
            if (is_array($scope['fields'] ?? null) && array_key_exists($fieldKey, $scope['fields'])) {
                return $scope['fields'][$fieldKey];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @param array<string, mixed>       $raw
     *
     * @return array{fields: array<string, mixed>, texts: array<string, mixed>, tables: array<string, mixed>}
     */
    private function blocksData(array $blocks, array $raw): array
    {
        $rawFields = is_array($raw['fields'] ?? null) ? $raw['fields'] : [];
        $rawTexts  = is_array($raw['texts'] ?? null) ? $raw['texts'] : [];
        $rawTables = is_array($raw['tables'] ?? null) ? $raw['tables'] : [];

        $fields = $texts = $tables = [];
        foreach ($blocks as $block) {
            $key = FormDefinition::str($block['key'] ?? '');
            switch ($block['type'] ?? '') {
                case 'field':
                    $value = $this->coerce($block, $rawFields[$key] ?? null);
                    if ($value !== null) {
                        $fields[$key] = $value;
                    }
                    break;

                case 'text':
                    $mode = $block['mode'] ?? 'fixed';
                    if ($mode === 'fixed') {
                        break;
                    }
                    $given = is_array($rawTexts[$key] ?? null) ? $rawTexts[$key] : null;
                    $html  = $given === null && $mode === 'starter'
                        ? FormDefinition::str($block['html'] ?? '')
                        : $this->sanitizer->sanitize(FormDefinition::str($given['html'] ?? ''));
                    if ($html === '' && $mode === 'fill') {
                        break;
                    }
                    $texts[$key] = ['html' => $html, 'modified' => $mode === 'starter' && $html !== FormDefinition::str($block['html'] ?? '')];
                    break;

                case 'table':
                    $given = is_array($rawTables[$key] ?? null) ? $rawTables[$key] : null;
                    // A table never filled (a new document, a new copy) starts with its minimum rows.
                    $tables[$key] = $this->rows($block, $given);
                    break;
            }
        }

        return ['fields' => $fields, 'texts' => $texts, 'tables' => $tables];
    }

    /**
     * @param list<array<string, mixed>> $sections
     * @param array<string, mixed>       $data
     *
     * @return array<string, mixed>
     */
    private function stripEmptyRows(array $sections, array $data): array
    {
        [$blocks, $repeating] = $this->collect($sections);

        $tables = is_array($data['tables'] ?? null) ? $data['tables'] : [];
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') !== 'table' || ($block['mode'] ?? '') !== 'free') {
                continue;
            }
            $key  = FormDefinition::str($block['key'] ?? '');
            $rows = [];
            foreach (FormDefinition::listOf($tables[$key] ?? []) as $row) {
                if ($this->rowHasData($block, $row)) {
                    $rows[] = $row;
                }
            }
            $tables[$key] = $rows;
        }
        $data['tables'] = $tables;

        $copies = is_array($data['copies'] ?? null) ? $data['copies'] : [];
        foreach ($repeating as $section) {
            $key  = FormDefinition::str($section['key'] ?? '');
            $list = [];
            foreach (FormDefinition::listOf($copies[$key] ?? []) as $copy) {
                $list[] = $this->stripEmptyRows([['repeat' => null] + $section], $copy) + ['_key' => $copy['_key'] ?? null] + (isset($copy['_source']) ? ['_source' => $copy['_source']] : []);
            }
            $copies[$key] = $list;
        }
        $data['copies'] = $copies;

        return $data;
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, mixed> $row
     */
    private function rowHasData(array $block, array $row): bool
    {
        foreach (FormDefinition::listOf($block['columns'] ?? []) as $column) {
            $value = $row[FormDefinition::str($column['key'] ?? '')] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed>          $block
     * @param array<int|string, mixed>|null $given
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $block, ?array $given): array
    {
        $columns = FormDefinition::listOf($block['columns'] ?? []);

        if (($block['mode'] ?? '') === 'fixed') {
            $byKey = [];
            foreach (FormDefinition::listOf($given ?? []) as $row) {
                $byKey[FormDefinition::str($row['_key'] ?? '')] = $row;
            }
            $rows = [];
            foreach (FormDefinition::listOf($block['rows'] ?? []) as $defRow) {
                $key    = FormDefinition::str($defRow['key'] ?? '');
                $rows[] = ['_key' => $key] + $this->cells($columns, $byKey[$key] ?? []);
            }

            return $rows;
        }

        $max  = FormDefinition::int($block['maxRows'] ?? 0);
        $max  = $max > 0 ? min($max, self::MAX_ROWS) : self::MAX_ROWS;
        $seen = [];
        $rows = [];
        foreach (array_slice(FormDefinition::listOf($given ?? []), 0, $max) as $row) {
            $key = FormDefinition::str($row['_key'] ?? '');
            if (preg_match('/^[0-9a-f-]{36}$/', $key) !== 1 || isset($seen[$key])) {
                $key = FormDefinition::newKey();
            }
            $seen[$key] = true;
            $rows[]     = ['_key' => $key] + $this->cells($columns, $row);
        }
        for ($i = count($rows); $given === null && $i < FormDefinition::int($block['minRows'] ?? 0); ++$i) {
            $rows[] = ['_key' => FormDefinition::newKey()];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @param array<string, mixed>       $row
     *
     * @return array<string, mixed>
     */
    private function cells(array $columns, array $row): array
    {
        $cells = [];
        foreach ($columns as $column) {
            $key   = FormDefinition::str($column['key'] ?? '');
            $value = $this->coerce($column, $row[$key] ?? null);
            if ($value !== null) {
                $cells[$key] = $value;
            }
        }

        return $cells;
    }

    /**
     * A value as the type of its field says, or null when it is empty or does not fit.
     *
     * @param array<string, mixed> $spec a field block or a table column
     *
     * @return string|int|float|bool|list<string>|null
     */
    public function coerce(array $spec, mixed $value): string|int|float|bool|array|null
    {
        $type = FormDefinition::str($spec['fieldType'] ?? 'short');

        switch ($type) {
            case 'short':
            case 'long':
                $text = is_scalar($value) ? trim((string) $value) : '';
                $text = $type === 'short' ? trim(strip_tags(str_replace(["\r", "\n"], ' ', $text))) : strip_tags($text);

                return $text === '' ? null : mb_substr($text, 0, $type === 'short' ? 255 : 20000);

            case 'number':
                if (is_string($value)) {
                    $value = str_replace(',', '.', trim($value));
                }
                if ($value === '' || $value === null || !is_numeric($value) || !is_finite((float) $value)) {
                    return null;
                }

                return $value + 0;

            case 'date':
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

                return $date !== false && $date->format('Y-m-d') === $value ? $value : null;

            case 'time':
                $time = is_string($value) ? \DateTimeImmutable::createFromFormat('!H:i', $value) : false;

                return $time !== false && $time->format('H:i') === $value ? $value : null;

            case 'bool':
                return is_bool($value) ? $value : null;

            case 'choice':
                $key = is_string($value) ? $value : '';

                return $this->optionKeys($spec, [$key]) === [] ? null : $key;

            case 'multichoice':
                $keys = $this->optionKeys($spec, is_array($value) ? array_map(static fn ($v): string => is_string($v) ? $v : '', array_values($value)) : []);

                return $keys === [] ? null : $keys;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<string>         $candidates
     *
     * @return list<string> the candidates that are options of the field, in the order of the options and without repeats
     */
    private function optionKeys(array $spec, array $candidates): array
    {
        $valid = [];
        foreach (FormDefinition::listOf($spec['options'] ?? []) as $option) {
            $key = FormDefinition::str($option['key'] ?? '');
            if (in_array($key, $candidates, true)) {
                $valid[] = $key;
            }
        }

        return $valid;
    }
}
