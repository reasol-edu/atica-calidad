<?php

declare(strict_types=1);

namespace App\Service\Form;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A definition and its data turned into what the outputs (web, PDF, later ODT/DOCX) draw: the sections numbered, one per copy, the
 * hidden ones left out, the references replaced by their values, the values already formatted as people read them and the help
 * texts left out. One resolved document, so every output shows the same.
 *
 *   section = { key, number: ?string, title, level, blocks: [ block ], children: [ section ] }
 *   block   = { kind: "text", label, html, empty } | { kind: "fields", items: [ { label, value, empty } ] }
 *           | { kind: "table", label, rowHeader, fixed, columns: [ label ], rows: [ { label, cells: [ value ] } ] }
 *
 * A section that is not numbered has no number below it either (its subtree is not numbered), and a repeating section whose copies
 * are not numbered does not number its copies' contents either.
 *
 * References are `{{ … }}` in titles, labels and texts; what counts is what follows the last `|` (the text before it is only a
 * readable label the editor writes): `copy.number`, `copy.total`, `row:<column key>` (a column of the row a copy comes from),
 * `f:<field key>` (a field of the document, looked up from the copy outwards) and `ctx:<centre|year|activity|slot|date>`. A
 * reference that cannot be resolved is empty.
 */
final class FormResolver
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly FormWalker $walker,
    ) {}

    /**
     * @param array<string, mixed>  $definition
     * @param array<string, mixed>  $data
     * @param array<string, string> $context    what `ctx:` reads: centre, year, activity, slot, date
     *
     * @return list<array<string, mixed>>
     */
    public function resolve(array $definition, array $data, array $context = []): array
    {
        return $this->sections($this->walker->walk($definition, $data), '', true, 1, $this->fieldSpecs($definition), $context);
    }

    /**
     * Every field block of the definition by key (what `f:` references are formatted with).
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldSpecs(array $definition): array
    {
        $specs = [];
        foreach (FormDefinition::blocks($definition) as $entry) {
            if (($entry['block']['type'] ?? '') === 'field') {
                $specs[FormDefinition::str($entry['block']['key'] ?? '')] = $entry['block'];
            }
        }

        return $specs;
    }

    /**
     * A title or a label with its references replaced, as plain text, for a walker instance.
     *
     * @param array<string, mixed>                $instance
     * @param array<string, array<string, mixed>> $specs
     * @param array<string, string>               $context
     */
    public function label(string $text, array $instance, array $specs, array $context = []): string
    {
        return $this->text($text, $instance, $specs, $context, false);
    }

    /**
     * @param list<array<string, mixed>>          $instances
     * @param array<string, array<string, mixed>> $specs
     * @param array<string, string>               $context
     *
     * @return list<array<string, mixed>>
     */
    private function sections(array $instances, string $prefix, bool $numbering, int $level, array $specs, array $context): array
    {
        $out     = [];
        $counter = 0;
        foreach ($instances as $instance) {
            if ($instance['visible'] !== true || !is_array($instance['section'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $section */
            $section  = $instance['section'];
            $numbered = $numbering && ($section['numbered'] ?? true) !== false && (($instance['own'] ?? false) !== true || ($section['numberCopies'] ?? true) !== false);
            $number   = null;
            if ($numbered) {
                ++$counter;
                $number = $prefix === '' ? (string) $counter : $prefix . '.' . $counter;
            }

            $out[] = [
                'key'      => FormDefinition::str($section['key'] ?? ''),
                'path'     => FormDefinition::str($instance['path'] ?? ''),
                'number'   => $number,
                'title'    => $this->text(FormDefinition::str($section['title'] ?? ''), $instance, $specs, $context, false),
                'level'    => $level,
                'blocks'   => $this->blocks($instance, $specs, $context),
                'children' => $this->sections(FormDefinition::listOf($instance['children'] ?? []), $number ?? '', $numbered, $level + 1, $specs, $context),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>                $instance
     * @param array<string, array<string, mixed>> $specs
     * @param array<string, string>               $context
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(array $instance, array $specs, array $context): array
    {
        /** @var array<string, mixed> $scope */
        $scope  = $instance['scope'];
        $fields = is_array($scope['fields'] ?? null) ? $scope['fields'] : [];
        $texts  = is_array($scope['texts'] ?? null) ? $scope['texts'] : [];
        $tables = is_array($scope['tables'] ?? null) ? $scope['tables'] : [];

        $out = [];
        foreach (FormDefinition::listOf($instance['blocks'] ?? []) as $entry) {
            if ($entry['visible'] !== true || !is_array($entry['block'] ?? null)) {
                continue;
            }
            /** @var array<string, mixed> $block */
            $block = $entry['block'];
            $key   = FormDefinition::str($block['key'] ?? '');
            $label = $this->text(FormDefinition::str($block['label'] ?? ''), $instance, $specs, $context, false);

            switch ($block['type'] ?? '') {
                case 'text':
                    $html = ($block['mode'] ?? 'fixed') === 'fixed'
                        ? FormDefinition::str($block['html'] ?? '')
                        : FormDefinition::str(is_array($texts[$key] ?? null) ? ($texts[$key]['html'] ?? '') : '');
                    $html  = $this->text($html, $instance, $specs, $context, true);
                    $out[] = ['kind' => 'text', 'label' => $label, 'html' => $html, 'empty' => trim(strip_tags($html)) === ''];
                    break;

                case 'field':
                    $value = $this->format($block, $fields[$key] ?? null);
                    $item  = ['label' => $label, 'value' => $value, 'empty' => $value === ''];
                    $last  = count($out) - 1;
                    if ($last >= 0 && $out[$last]['kind'] === 'fields') {
                        /** @var list<array<string, mixed>> $items */
                        $items               = $out[$last]['items'];
                        $items[]             = $item;
                        $out[$last]['items'] = $items;
                    } else {
                        $out[] = ['kind' => 'fields', 'items' => [$item]];
                    }
                    break;

                case 'table':
                    $out[] = $this->table($block, $label, FormDefinition::listOf($tables[$key] ?? []));
                    break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>       $block
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private function table(array $block, string $label, array $rows): array
    {
        $columns = FormDefinition::listOf($block['columns'] ?? []);
        $fixed   = ($block['mode'] ?? '') === 'fixed';

        $labels = [];
        foreach (FormDefinition::listOf($block['rows'] ?? []) as $row) {
            $labels[FormDefinition::str($row['key'] ?? '')] = FormDefinition::str($row['label'] ?? '');
        }

        $resolved = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $this->format($column, $row[FormDefinition::str($column['key'] ?? '')] ?? null);
            }
            $resolved[] = ['label' => $labels[FormDefinition::str($row['_key'] ?? '')] ?? '', 'cells' => $cells];
        }

        return [
            'kind'      => 'table',
            'label'     => $label,
            'rowHeader' => FormDefinition::str($block['rowHeader'] ?? ''),
            'fixed'     => $fixed,
            'columns'   => array_map(static fn (array $c): string => FormDefinition::str($c['label'] ?? ''), $columns),
            'rows'      => $resolved,
        ];
    }

    // ── References ───────────────────────────────────────────────────────────

    /**
     * $text with its `{{ … }}` replaced; $html escapes the values (the text is already HTML).
     *
     * @param array<string, mixed>                $instance
     * @param array<string, array<string, mixed>> $specs
     * @param array<string, string>               $context
     */
    private function text(string $text, array $instance, array $specs, array $context, bool $html): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }

        return (string) preg_replace_callback('/\{\{\s*([^{}]*?)\s*\}\}/', function (array $m) use ($instance, $specs, $context, $html): string {
            $parts = explode('|', (string) $m[1]);
            $value = $this->reference(trim((string) end($parts)), $instance, $specs, $context);

            return $html ? htmlspecialchars($value, ENT_QUOTES) : $value;
        }, $text);
    }

    /**
     * @param array<string, mixed>                $instance
     * @param array<string, array<string, mixed>> $specs
     * @param array<string, string>               $context
     */
    private function reference(string $ref, array $instance, array $specs, array $context): string
    {
        $copy = is_array($instance['copy'] ?? null) ? $instance['copy'] : null;

        if ($ref === 'copy.number') {
            return $copy === null ? '' : (string) FormDefinition::int($copy['index'] ?? 0);
        }
        if ($ref === 'copy.total') {
            return $copy === null ? '' : (string) FormDefinition::int($copy['total'] ?? 0);
        }
        if (str_starts_with($ref, 'ctx:')) {
            return $context[substr($ref, 4)] ?? '';
        }
        if (str_starts_with($ref, 'row:')) {
            $origin = is_array($instance['origin'] ?? null) ? $instance['origin'] : null;
            if ($origin === null || !is_array($origin['table'] ?? null) || !is_array($origin['row'] ?? null)) {
                return '';
            }
            $column = substr($ref, 4);
            foreach (FormDefinition::listOf($origin['table']['columns'] ?? []) as $spec) {
                if (($spec['key'] ?? null) === $column) {
                    return $this->format($spec, $origin['row'][$column] ?? null);
                }
            }

            return '';
        }
        if (str_starts_with($ref, 'f:')) {
            $key  = substr($ref, 2);
            $spec = $specs[$key] ?? null;
            if ($spec === null) {
                return '';
            }
            foreach (FormDefinition::listOf($instance['chain'] ?? []) as $scope) {
                if (is_array($scope['fields'] ?? null) && array_key_exists($key, $scope['fields'])) {
                    return $this->format($spec, $scope['fields'][$key]);
                }
            }
        }

        return '';
    }

    /**
     * A value as people read it; empty string when there is none.
     *
     * @param array<string, mixed> $spec a field block or a table column
     */
    public function format(array $spec, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        switch (FormDefinition::str($spec['fieldType'] ?? 'short')) {
            case 'number':
                $text = is_numeric($value) ? str_replace('.', ',', (string) (is_float($value) ? round($value, 6) : $value)) : '';
                $unit = FormDefinition::str($spec['unit'] ?? '');

                return $unit === '' ? $text : $text . ' ' . $unit;

            case 'date':
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

                return $date === false ? '' : $date->format('d/m/Y');

            case 'bool':
                return $this->translator->trans($value === true ? 'view.yes' : 'view.no', [], 'forms');

            case 'choice':
            case 'multichoice':
                $keys   = is_array($value) ? $value : [$value];
                $labels = [];
                foreach (FormDefinition::listOf($spec['options'] ?? []) as $option) {
                    if (in_array($option['key'] ?? null, $keys, true)) {
                        $labels[] = FormDefinition::str($option['label'] ?? '');
                    }
                }

                return implode(', ', $labels);
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
