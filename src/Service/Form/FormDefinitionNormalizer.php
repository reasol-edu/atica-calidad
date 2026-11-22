<?php

declare(strict_types=1);

namespace App\Service\Form;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;

/**
 * Cleans what the model editor sends (it is browser input, never trusted) into a definition the rest of the application can
 * rely on: only known properties, known types, texts sanitized, every element with a key and no key repeated. The editor
 * generates the keys; the ones that arrive missing or repeated are given a new one here.
 *
 * `problems()` is what stops a model from being sent to review: it names what cannot work (a choice without options, a table
 * without columns…) — a draft with problems can still be saved, because nobody should lose work for it.
 */
final class FormDefinitionNormalizer
{
    public const int MAX_DEPTH      = 6;
    public const int MAX_SECTIONS   = 300;
    public const int MAX_BLOCKS     = 2000;
    public const int MAX_COLUMNS    = 40;
    public const int MAX_OPTIONS    = 100;
    public const int MAX_FIXED_ROWS = 200;

    private int $sections = 0;
    private int $blocks   = 0;

    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.app.rich_text')]
        private readonly HtmlSanitizerInterface $sanitizer,
    ) {}

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public function normalize(array $raw): array
    {
        $this->sections = $this->blocks = 0;
        $this->seen     = [];

        $sections = [];
        foreach (FormDefinition::listOf($raw['sections'] ?? []) as $section) {
            $clean = $this->section($section, 1);
            if ($clean !== null) {
                $sections[] = $clean;
            }
        }

        return ['version' => FormDefinition::VERSION, 'sections' => $sections];
    }

    /**
     * @param array<string, mixed> $definition a normalized definition
     *
     * @return list<array{code: string, params: array<string, string>}> translation keys of the domain "forms" under `model.problem.`
     */
    public function problems(array $definition): array
    {
        $problems = [];
        $names    = [];
        $count    = 0;

        $fieldTypes = [];
        $tableKeys  = [];
        foreach (FormDefinition::blocks($definition) as $entry) {
            $key = FormDefinition::str($entry['block']['key'] ?? '');
            if (($entry['block']['type'] ?? '') === 'field') {
                $fieldTypes[$key] = FormDefinition::str($entry['block']['fieldType'] ?? '');
            } elseif (($entry['block']['type'] ?? '') === 'table') {
                $tableKeys[$key] = true;
            }
        }

        foreach (FormDefinition::sectionsOf($definition['sections'] ?? []) as $section) {
            ++$count;
            $title = FormDefinition::str($section['title'] ?? '');
            if (trim($title) === '') {
                $problems[] = ['code' => 'section_without_title', 'params' => []];
            }
            $this->checkRepeat($section, $title, $fieldTypes, $tableKeys, $problems);
            $this->checkCondition($section['visibleIf'] ?? null, $title, $fieldTypes, $problems);
            foreach (FormDefinition::listOf($section['blocks'] ?? []) as $block) {
                $this->checkCondition($block['visibleIf'] ?? null, FormDefinition::str($block['label'] ?? '') ?: $title, $fieldTypes, $problems);
                $label = FormDefinition::str($block['label'] ?? '');
                $type  = FormDefinition::str($block['type'] ?? '');
                if ($type === 'field') {
                    $this->checkField($block, $label, $problems);
                } elseif ($type === 'table') {
                    $this->checkTable($block, $label, $problems);
                } elseif ($type === 'text' && ($block['mode'] ?? '') === 'fill' && $label === '') {
                    $problems[] = ['code' => 'text_without_label', 'params' => ['%section%' => FormDefinition::str($section['title'] ?? '')]];
                }
                $name = FormDefinition::str($block['name'] ?? '');
                if ($name !== '') {
                    if (isset($names[$name])) {
                        $problems[] = ['code' => 'name_repeated', 'params' => ['%name%' => $name]];
                    }
                    $names[$name] = true;
                }
            }
        }
        if ($count === 0) {
            $problems[] = ['code' => 'empty', 'params' => []];
        }

        return $problems;
    }

    /**
     * @param array<string, mixed>     $section
     * @param array<string, string>    $fieldTypes field key → type
     * @param array<string, true>      $tableKeys
     * @param list<array{code: string, params: array<string, string>}> $problems
     */
    private function checkRepeat(array $section, string $title, array $fieldTypes, array $tableKeys, array &$problems): void
    {
        $repeat = $section['repeat'] ?? null;
        if (!is_array($repeat)) {
            return;
        }
        $mode = FormDefinition::str($repeat['mode'] ?? '');
        $ref  = $mode === 'field' ? FormDefinition::str($repeat['field'] ?? '') : ($mode === 'table' ? FormDefinition::str($repeat['table'] ?? '') : '');
        if ($mode === 'field' && ($fieldTypes[$ref] ?? null) !== 'number') {
            $problems[] = ['code' => 'repeat_field_invalid', 'params' => ['%section%' => $title]];
        }
        if ($mode === 'table' && !isset($tableKeys[$ref])) {
            $problems[] = ['code' => 'repeat_table_invalid', 'params' => ['%section%' => $title]];
        }
        if ($ref !== '') {
            // What decides the copies cannot be inside the copies.
            foreach (FormDefinition::blocks(['sections' => [$section]]) as $entry) {
                if (($entry['block']['key'] ?? null) === $ref) {
                    $problems[] = ['code' => 'repeat_inside_itself', 'params' => ['%section%' => $title]];
                    break;
                }
            }
        }
    }

    /**
     * @param array<string, string> $fieldTypes
     * @param list<array{code: string, params: array<string, string>}> $problems
     */
    private function checkCondition(mixed $condition, string $where, array $fieldTypes, array &$problems): void
    {
        if (is_array($condition) && !isset($fieldTypes[FormDefinition::str($condition['field'] ?? '')])) {
            $problems[] = ['code' => 'condition_field_missing', 'params' => ['%where%' => $where]];
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array{code: string, params: array<string, string>}> $problems
     */
    private function checkField(array $block, string $label, array &$problems): void
    {
        if ($label === '') {
            $problems[] = ['code' => 'field_without_label', 'params' => []];
        }
        $this->checkOptions($block, $label, $problems);
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array{code: string, params: array<string, string>}> $problems
     */
    private function checkOptions(array $block, string $label, array &$problems): void
    {
        $type = FormDefinition::str($block['fieldType'] ?? '');
        if (in_array($type, ['choice', 'multichoice'], true) && FormDefinition::listOf($block['options'] ?? []) === []) {
            $problems[] = ['code' => 'choice_without_options', 'params' => ['%label%' => $label]];
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array{code: string, params: array<string, string>}> $problems
     */
    private function checkTable(array $block, string $label, array &$problems): void
    {
        $columns = FormDefinition::listOf($block['columns'] ?? []);
        if ($columns === []) {
            $problems[] = ['code' => 'table_without_columns', 'params' => ['%label%' => $label]];
        }
        foreach ($columns as $column) {
            if (trim(FormDefinition::str($column['label'] ?? '')) === '') {
                $problems[] = ['code' => 'column_without_label', 'params' => ['%label%' => $label]];
            }
            $this->checkOptions($column, FormDefinition::str($column['label'] ?? ''), $problems);
        }
        if (($block['mode'] ?? '') === 'fixed' && FormDefinition::listOf($block['rows'] ?? []) === []) {
            $problems[] = ['code' => 'fixed_table_without_rows', 'params' => ['%label%' => $label]];
        }
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function section(array $raw, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH || $this->sections >= self::MAX_SECTIONS) {
            return null;
        }
        ++$this->sections;

        $blocks = [];
        foreach (FormDefinition::listOf($raw['blocks'] ?? []) as $block) {
            $clean = $this->block($block);
            if ($clean !== null) {
                $blocks[] = $clean;
            }
        }
        $children = [];
        foreach (FormDefinition::listOf($raw['children'] ?? []) as $child) {
            $clean = $this->section($child, $depth + 1);
            if ($clean !== null) {
                $children[] = $clean;
            }
        }

        return [
            'key'          => $this->key($raw['key'] ?? null),
            'title'        => $this->text($raw['title'] ?? '', 255),
            'numbered'     => ($raw['numbered'] ?? true) !== false,
            'help'         => $this->text($raw['help'] ?? '', 1000),
            'repeat'       => $this->repeat($raw['repeat'] ?? null),
            'numberCopies' => ($raw['numberCopies'] ?? true) !== false,
            'visibleIf'    => $this->condition($raw['visibleIf'] ?? null),
            'blocks'       => $blocks,
            'children'     => $children,
        ];
    }

    /**
     * How a section repeats: { mode: manual, min, max } (whoever fills adds the copies), { mode: field, field } (as many as a
     * number field says) or { mode: table, table } (one per row of a table). Null when it does not repeat.
     *
     * @return array<string, mixed>|null
     */
    private function repeat(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        /** @var array<string, mixed> $raw */
        switch (FormDefinition::str($raw['mode'] ?? '')) {
            case 'manual':
                $min = min(100, max(0, FormDefinition::int($raw['min'] ?? 0)));
                $max = min(100, max(0, FormDefinition::int($raw['max'] ?? 0)));

                return ['mode' => 'manual', 'min' => $min, 'max' => $max === 0 ? 100 : max($min, $max)];

            case 'field':
                $field = $this->reference($raw['field'] ?? null);

                return $field === null ? null : ['mode' => 'field', 'field' => $field];

            case 'table':
                $table = $this->reference($raw['table'] ?? null);

                return $table === null ? null : ['mode' => 'table', 'table' => $table];
        }

        return null;
    }

    /**
     * @return array{field: string, op: string, value: string}|null
     */
    private function condition(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $field = $this->reference($raw['field'] ?? null);
        $op    = FormDefinition::str($raw['op'] ?? '');
        if ($field === null || !in_array($op, FormConditions::OPERATORS, true)) {
            return null;
        }

        return ['field' => $field, 'op' => $op, 'value' => mb_substr(FormDefinition::str($raw['value'] ?? ''), 0, 255)];
    }

    /** The key of another element: kept as it is when it looks like one (whether the element exists is `problems()`' business). */
    private function reference(mixed $value): ?string
    {
        $key = is_string($value) ? strtolower(trim($value)) : '';

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key) === 1 ? $key : null;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    private function block(array $raw): ?array
    {
        if ($this->blocks >= self::MAX_BLOCKS) {
            return null;
        }
        $type = FormDefinition::str($raw['type'] ?? '');

        $block = match ($type) {
            'text'  => $this->textBlock($raw),
            'field' => $this->fieldBlock($raw),
            'table' => $this->tableBlock($raw),
            default => null,
        };
        if ($block !== null) {
            ++$this->blocks;
        }

        return $block;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function textBlock(array $raw): array
    {
        $mode = FormDefinition::str($raw['mode'] ?? '');
        $mode = in_array($mode, FormDefinition::TEXT_MODES, true) ? $mode : 'fixed';

        return [
            'key'      => $this->key($raw['key'] ?? null),
            'type'     => 'text',
            'mode'     => $mode,
            'label'    => $this->text($raw['label'] ?? '', 255),
            // A text that is filled in carries no starting content.
            'html'     => $mode === 'fill' ? '' : $this->html($raw['html'] ?? ''),
            'required' => $mode !== 'fixed' && ($raw['required'] ?? false) === true,
            'help'     => $this->text($raw['help'] ?? '', 1000),
            'visibleIf' => $this->condition($raw['visibleIf'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function fieldBlock(array $raw): array
    {
        return ['key' => $this->key($raw['key'] ?? null), 'type' => 'field', 'name' => $this->name($raw['name'] ?? '')]
            + $this->fieldProperties($raw)
            + ['help' => $this->text($raw['help'] ?? '', 1000), 'visibleIf' => $this->condition($raw['visibleIf'] ?? null)];
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function tableBlock(array $raw): array
    {
        $mode = FormDefinition::str($raw['mode'] ?? '');
        $mode = in_array($mode, FormDefinition::TABLE_MODES, true) ? $mode : 'free';

        $columns = [];
        foreach (array_slice(FormDefinition::listOf($raw['columns'] ?? []), 0, self::MAX_COLUMNS) as $column) {
            $columns[] = ['key' => $this->key($column['key'] ?? null), 'name' => $this->name($column['name'] ?? '')] + $this->fieldProperties($column);
        }
        $rows = [];
        if ($mode === 'fixed') {
            foreach (array_slice(FormDefinition::listOf($raw['rows'] ?? []), 0, self::MAX_FIXED_ROWS) as $row) {
                $rows[] = ['key' => $this->key($row['key'] ?? null), 'label' => $this->text($row['label'] ?? '', 500)];
            }
        }
        $min = max(0, (int) FormDefinition::str($raw['minRows'] ?? 0));
        $max = max(0, (int) FormDefinition::str($raw['maxRows'] ?? 0));

        return [
            'key'       => $this->key($raw['key'] ?? null),
            'type'      => 'table',
            'name'      => $this->name($raw['name'] ?? ''),
            'label'     => $this->text($raw['label'] ?? '', 255),
            'mode'      => $mode,
            'rowHeader' => $this->text($raw['rowHeader'] ?? '', 255),
            'columns'   => $columns,
            'rows'      => $rows,
            'minRows'   => $mode === 'free' ? $min : 0,
            'maxRows'   => $mode === 'free' ? $max : 0,
            'help'      => $this->text($raw['help'] ?? '', 1000),
            'visibleIf' => $this->condition($raw['visibleIf'] ?? null),
        ];
    }

    /**
     * What a field and a table column share: label, type, constraints and options.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function fieldProperties(array $raw): array
    {
        $type = FormDefinition::str($raw['fieldType'] ?? '');
        $type = in_array($type, FormDefinition::FIELD_TYPES, true) ? $type : 'short';

        $options = [];
        if (in_array($type, ['choice', 'multichoice'], true)) {
            foreach (array_slice(FormDefinition::listOf($raw['options'] ?? []), 0, self::MAX_OPTIONS) as $option) {
                $options[] = ['key' => $this->key($option['key'] ?? null), 'label' => $this->text($option['label'] ?? '', 255)];
            }
        }

        return [
            'label'     => $this->text($raw['label'] ?? '', 255),
            'fieldType' => $type,
            'required'  => ($raw['required'] ?? false) === true,
            'options'   => $options,
            'min'       => $type === 'number' ? $this->number($raw['min'] ?? null) : null,
            'max'       => $type === 'number' ? $this->number($raw['max'] ?? null) : null,
            'unit'      => $type === 'number' ? $this->text($raw['unit'] ?? '', 20) : '',
        ];
    }

    /** A UUID that has not been used yet; any other value (or a repeated one) is replaced by a new one. */
    private function key(mixed $value): string
    {
        $key = is_string($value) ? strtolower(trim($value)) : '';
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $key) !== 1 || isset($this->seen[$key])) {
            $key = FormDefinition::newKey();
        }
        $this->seen[$key] = true;

        return $key;
    }

    private function text(mixed $value, int $max): string
    {
        $text = trim(strip_tags(FormDefinition::str($value)));

        return mb_substr($text, 0, $max);
    }

    /** The internal name used in references: lower case letters, digits and dots/underscores. */
    private function name(mixed $value): string
    {
        $name = strtolower(trim(FormDefinition::str($value)));
        $name = (string) preg_replace('/[^a-z0-9_.]+/', '_', $name);

        return mb_substr(trim($name, '_.'), 0, 60);
    }

    private function html(mixed $value): string
    {
        return $this->sanitizer->sanitize(FormDefinition::str($value));
    }

    private function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return $value + 0;
    }
}
