<?php

declare(strict_types=1);

namespace App\Service\Form;

/**
 * What still prevents a filled document from being delivered: a required answer missing, a number out of its limits, too few or
 * too many rows. Only what is shown counts: a section or block hidden by its condition is not validated (FormWalker). A draft can
 * always be saved with problems (nobody should lose work for it); delivering is what they stop. `code` is a key of the translation
 * domain "forms" under `fill.problem.`; `path` identifies the copy of a repeating section the problem is in ('' outside copies).
 */
final class FormDataValidator
{
    public function __construct(
        private readonly FormWalker $walker,
        private readonly FormResolver $resolver,
        private readonly FormEvaluator $evaluator,
    ) {}

    /**
     * What is wrong, blocking ones and warnings together (`severity`): what is missing or out of limits blocks; a consistency rule of
     * the model blocks or only warns, as the model says (`code` `rule`, its text in `%message%`).
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data       normalized by FormDataNormalizer
     *
     * @return list<array{block: string, path: string, row: ?string, column: ?string, code: string, params: array<string, string>, severity: string}>
     */
    public function problems(array $definition, array $data): array
    {
        $found = [];
        $this->check($this->walker->walk($definition, $data), FormIndex::of($definition), $found);

        $problems = array_map(static fn (array $p): array => $p + ['severity' => 'block'], $found);

        $index = FormIndex::of($definition);
        foreach (FormDefinition::listOf($definition['rules'] ?? []) as $rule) {
            // A rule that cannot be decided (a missing term) is not a rule that is broken.
            if ($this->evaluator->run(FormDefinition::str($rule['expr'] ?? ''), $index, [$data]) === false) {
                $problems[] = [
                    'block'    => '',
                    'path'     => '',
                    'row'      => null,
                    'column'   => null,
                    'code'     => 'rule',
                    'params'   => ['%message%' => FormDefinition::str($rule['message'] ?? '')],
                    'severity' => ($rule['severity'] ?? '') === 'block' ? 'block' : 'warning',
                ];
            }
        }

        return $problems;
    }

    /**
     * Only the problems that stop a delivery.
     *
     * @param list<array{block: string, path: string, row: ?string, column: ?string, code: string, params: array<string, string>, severity: string}> $problems
     *
     * @return list<array{block: string, path: string, row: ?string, column: ?string, code: string, params: array<string, string>, severity: string}>
     */
    public function blocking(array $problems): array
    {
        return array_values(array_filter($problems, static fn (array $p): bool => $p['severity'] === 'block'));
    }

    /**
     * @param list<array<string, mixed>>          $instances
     * @param list<array{block: string, path: string, row: ?string, column: ?string, code: string, params: array<string, string>}> $problems
     */
    private function check(array $instances, FormIndex $index, array &$problems): void
    {
        foreach ($instances as $instance) {
            if ($instance['visible'] !== true) {
                continue;
            }
            /** @var array<string, mixed> $scope */
            $scope  = $instance['scope'];
            $path   = FormDefinition::str($instance['path'] ?? '');
            $copy   = is_array($instance['copy'] ?? null) ? $instance['copy'] : null;
            // A problem inside a copy says which one: the title of the section as it reads there ("UD 1. Redes (40 h)").
            $prefix = $copy === null ? '' : $this->resolver->label(FormDefinition::str($copy['title'] ?? ''), $instance, $index) . ' · ';

            $fields = is_array($scope['fields'] ?? null) ? $scope['fields'] : [];
            $texts  = is_array($scope['texts'] ?? null) ? $scope['texts'] : [];
            $tables = is_array($scope['tables'] ?? null) ? $scope['tables'] : [];

            // The copy of an item of a list that no longer belongs here, kept only because something is typed in it: it has to be
            // emptied before delivering (what is in it is not checked: it is not part of the document any more).
            if ($copy !== null && ($instance['own'] ?? false) === true) {
                $section = FormDefinition::map($instance['section'] ?? null) ?? [];
                $repeat  = FormDefinition::map($section['repeat'] ?? null) ?? [];
                foreach (($repeat['mode'] ?? '') === 'list' ? FormDefinition::listOf($repeat['items'] ?? []) : [] as $item) {
                    if (($item['gone'] ?? false) === true && FormDefinition::str($item['key'] ?? '') === FormDefinition::str($copy['key'] ?? '')) {
                        $problems[] = ['block' => FormDefinition::str($section['key'] ?? ''), 'path' => $path, 'row' => null, 'column' => null, 'code' => 'copy_gone', 'params' => ['%section%' => FormDefinition::str($section['title'] ?? ''), '%item%' => FormDefinition::str($item['label'] ?? '')]];
                        continue 2;
                    }
                }
            }

            foreach (FormDefinition::listOf($instance['blocks'] ?? []) as $entry) {
                if ($entry['visible'] !== true || !is_array($entry['block'] ?? null)) {
                    continue;
                }
                /** @var array<string, mixed> $block */
                $block = $entry['block'];
                $key   = FormDefinition::str($block['key'] ?? '');
                $label = $prefix . $this->resolver->label(FormDefinition::str($block['label'] ?? ''), $instance, $index);

                switch ($block['type'] ?? '') {
                    case 'field':
                        $this->checkValue($block, $fields[$key] ?? null, $key, $path, null, null, $label, $problems);
                        break;

                    case 'text':
                        $html = is_array($texts[$key] ?? null) ? FormDefinition::str($texts[$key]['html'] ?? '') : '';
                        if (($block['required'] ?? false) === true && trim(strip_tags($html)) === '') {
                            $problems[] = ['block' => $key, 'path' => $path, 'row' => null, 'column' => null, 'code' => 'required', 'params' => ['%label%' => $label]];
                        }
                        break;

                    case 'table':
                        $this->checkTable($block, FormDefinition::listOf($tables[$key] ?? []), $key, $path, $label, $problems);
                        break;
                }
            }

            $this->check(FormDefinition::listOf($instance['children'] ?? []), $index, $problems);
        }
    }

    /**
     * @param array<string, mixed>       $block
     * @param list<array<string, mixed>> $rows
     * @param list<array{block: string, path: string, row: ?string, column: ?string, code: string, params: array<string, string>}> $problems
     */
    private function checkTable(array $block, array $rows, string $key, string $path, string $label, array &$problems): void
    {
        $min = FormDefinition::int($block['minRows'] ?? 0);
        $max = FormDefinition::int($block['maxRows'] ?? 0);
        if (count($rows) < $min) {
            $problems[] = ['block' => $key, 'path' => $path, 'row' => null, 'column' => null, 'code' => 'rows_min', 'params' => ['%label%' => $label, '%min%' => (string) $min]];
        }
        if ($max > 0 && count($rows) > $max) {
            $problems[] = ['block' => $key, 'path' => $path, 'row' => null, 'column' => null, 'code' => 'rows_max', 'params' => ['%label%' => $label, '%max%' => (string) $max]];
        }
        // A row taken from a list whose item is no longer offered (deactivated, moved, deleted, or no longer of who delivers it) is only
        // kept while something is typed in it: it has to be emptied before delivering.
        $gone = [];
        // In a table of deliveries, a required column of its own is only asked of the rows whose delivery is there: nobody can say
        // anything yet about one that has not been delivered.
        $notDelivered = [];
        foreach (FormDefinition::listOf($block['rows'] ?? []) as $defRow) {
            if (($defRow['gone'] ?? false) === true) {
                $gone[FormDefinition::str($defRow['key'] ?? '')] = FormDefinition::str($defRow['label'] ?? '');
            }
            if (($block['mode'] ?? '') === 'delivered' && ($defRow['status'] ?? '') !== 'delivered') {
                $notDelivered[FormDefinition::str($defRow['key'] ?? '')] = true;
            }
        }
        foreach ($rows as $index => $row) {
            $rowKey = FormDefinition::str($row['_key'] ?? '');
            if (isset($gone[$rowKey])) {
                $problems[] = ['block' => $key, 'path' => $path, 'row' => $rowKey, 'column' => null, 'code' => 'row_gone', 'params' => ['%label%' => $label, '%row%' => $gone[$rowKey]]];
                continue;
            }
            foreach (FormDefinition::listOf($block['columns'] ?? []) as $column) {
                $columnKey = FormDefinition::str($column['key'] ?? '');
                $this->checkValue(
                    isset($notDelivered[$rowKey]) ? ['required' => false] + $column : $column,
                    $row[$columnKey] ?? null,
                    $key,
                    $path,
                    FormDefinition::str($row['_key'] ?? ''),
                    $columnKey,
                    $label . ' · ' . ($index + 1) . ' · ' . FormDefinition::str($column['label'] ?? ''),
                    $problems,
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $spec
     * @param list<array{block: string, path: string, row: ?string, column: ?string, code: string, params: array<string, string>}> $problems
     */
    private function checkValue(array $spec, mixed $value, string $block, string $path, ?string $row, ?string $column, string $label, array &$problems): void
    {
        if ($value === null || $value === '' || $value === []) {
            if (($spec['required'] ?? false) === true) {
                $problems[] = ['block' => $block, 'path' => $path, 'row' => $row, 'column' => $column, 'code' => 'required', 'params' => ['%label%' => $label]];
            }

            return;
        }
        // An item of a list that is not offered any more (deactivated, deleted): a draft keeps it, a delivery does not.
        if (in_array($spec['fieldType'] ?? '', ['choice', 'multichoice'], true)) {
            $chosen = is_array($value) ? $value : [$value];
            foreach (FormDefinition::listOf($spec['options'] ?? []) as $option) {
                if (($option['gone'] ?? false) === true && in_array($option['key'] ?? null, $chosen, true)) {
                    $problems[] = ['block' => $block, 'path' => $path, 'row' => $row, 'column' => $column, 'code' => 'option_gone', 'params' => ['%label%' => $label, '%option%' => FormDefinition::str($option['label'] ?? '')]];
                }
            }

            return;
        }
        if (($spec['fieldType'] ?? '') !== 'number' || !is_numeric($value)) {
            return;
        }
        $min = $spec['min'] ?? null;
        $max = $spec['max'] ?? null;
        if (is_numeric($min) && $value < $min) {
            $problems[] = ['block' => $block, 'path' => $path, 'row' => $row, 'column' => $column, 'code' => 'number_min', 'params' => ['%label%' => $label, '%min%' => (string) $min]];
        }
        if (is_numeric($max) && $value > $max) {
            $problems[] = ['block' => $block, 'path' => $path, 'row' => $row, 'column' => $column, 'code' => 'number_max', 'params' => ['%label%' => $label, '%max%' => (string) $max]];
        }
        $places = $spec['maxDecimals'] ?? null;
        if (is_int($places) && self::decimalsOf((float) $value) > $places) {
            $problems[] = $places === 0
                ? ['block' => $block, 'path' => $path, 'row' => $row, 'column' => $column, 'code' => 'number_integer', 'params' => ['%label%' => $label]]
                : ['block' => $block, 'path' => $path, 'row' => $row, 'column' => $column, 'code' => 'number_decimals', 'params' => ['%label%' => $label, '%decimals%' => (string) $places]];
        }
    }

    /** How many decimals a number has as written (0.1 has one, 2.50 one, 3 none), up to ten. */
    public static function decimalsOf(float $value): int
    {
        $text = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        $dot  = strpos($text, '.');

        return $dot === false ? 0 : strlen($text) - $dot - 1;
    }
}
