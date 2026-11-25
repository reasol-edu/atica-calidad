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
    public const int MAX_RULES      = 100;
    public const int MAX_APPROVAL_STEPS = 10;

    private int $sections = 0;
    private int $blocks   = 0;

    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(
        #[Autowire(service: 'html_sanitizer.sanitizer.app.rich_text')]
        private readonly HtmlSanitizerInterface $sanitizer,
        #[Autowire(service: 'html_sanitizer.sanitizer.app.doc_layout')]
        private readonly ?HtmlSanitizerInterface $layoutSanitizer = null,
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

        $rules = [];
        foreach (array_slice(FormDefinition::listOf($raw['rules'] ?? []), 0, self::MAX_RULES) as $rule) {
            $severity = FormDefinition::str($rule['severity'] ?? '') === 'block' ? 'block' : 'warning';
            $rules[]  = [
                'key'      => $this->key($rule['key'] ?? null),
                'expr'     => mb_substr(trim(FormDefinition::str($rule['expr'] ?? '')), 0, 1000),
                'message'  => $this->text($rule['message'] ?? '', 500),
                'severity' => $severity,
            ];
        }

        // The approval chain: ordered steps, each decided by whoever holds a profile of the centre.
        $approval = [];
        foreach (array_slice(FormDefinition::listOf($raw['approval'] ?? []), 0, self::MAX_APPROVAL_STEPS) as $step) {
            $approval[] = [
                'key'     => $this->key($step['key'] ?? null),
                'name'    => $this->text($step['name'] ?? '', 255),
                'profile' => $this->reference($step['profile'] ?? null) ?? '',
                // 'element': decided only by whoever holds the profile with a subprofile associated with the document's element.
                'scope'   => FormDefinition::str($step['scope'] ?? '') === 'element' ? 'element' : 'any',
            ];
        }

        return [
            'version'  => FormDefinition::VERSION,
            'sections' => $sections,
            'rules'    => $rules,
            'approval' => $approval,
            // Header, footer, orientation and font scale of the documents (an old revision without it gets the defaults).
            'layout'   => FormLayout::clean($raw['layout'] ?? null, $this->layoutSanitizer),
        ];
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
                    $this->checkTable($block, $label, $fieldTypes, $problems);
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
        $this->checkCalculations($definition, $problems);
        foreach (FormDefinition::listOf($definition['approval'] ?? []) as $step) {
            if (FormDefinition::str($step['name'] ?? '') === '') {
                $problems[] = ['code' => 'approval_step_without_name', 'params' => []];
            }
            // A chain imported from another centre arrives without profiles: it cannot be approved until each step has one.
            if (FormDefinition::str($step['profile'] ?? '') === '') {
                $problems[] = ['code' => 'approval_step_without_profile', 'params' => ['%name%' => FormDefinition::str($step['name'] ?? '')]];
            }
        }

        return $problems;
    }

    /**
     * Formulas, relations, double-entry tables and rules: what they point at has to exist, formulas have to be readable and
     * calculated values cannot depend on each other in a circle.
     *
     * @param array<string, mixed> $definition
     * @param list<array{code: string, params: array<string, string>}> $problems
     */
    private function checkCalculations(array $definition, array &$problems): void
    {
        $index = FormIndex::of($definition);
        $sections = [];
        foreach (FormDefinition::sectionsOf($definition['sections'] ?? []) as $section) {
            $sections[FormDefinition::str($section['key'] ?? '')] = true;
        }

        /** @var array<string, list<string>> $graph calculated value → the calculated values its formula reads */
        $graph  = [];
        $labels = [];

        // `$part` says which part of the model a problem belongs to when it is not the content (`rules`): the editor shows it in that tab.
        $checkFormula = function (string $formula, string $label, string $node, ?string $table, string $part = '') use ($index, $sections, &$problems, &$graph): void {
            $where = $part === '' ? [] : ['%part%' => $part];
            if (trim($formula) === '') {
                $problems[] = ['code' => 'formula_missing', 'params' => ['%label%' => $label] + $where];

                return;
            }
            $parsed = FormExpression::parse($formula);
            if ($parsed['ast'] === null) {
                $problems[] = ['code' => 'formula_invalid', 'params' => ['%label%' => $label] + $where];

                return;
            }
            foreach (FormExpression::references($parsed['ast']) as $ref) {
                $target = $this->referenceTarget($ref, $index, $sections, $table);
                if ($target === false) {
                    $problems[] = ['code' => 'formula_ref_missing', 'params' => ['%label%' => $label, '%ref%' => $ref] + $where];
                } elseif ($target !== null && $node !== '') {
                    $graph[$node][] = $target;
                }
            }
        };

        foreach ($index->fields as $key => $field) {
            if (($field['fieldType'] ?? '') === 'calculated') {
                $labels[$key] = FormDefinition::str($field['label'] ?? '');
                $graph[$key]  = [];
                $checkFormula(FormDefinition::str($field['formula'] ?? ''), $labels[$key], $key, null);
            }
        }
        foreach ($index->tables as $tableKey => $table) {
            foreach (FormDefinition::listOf($table['columns'] ?? []) as $column) {
                $columnKey = FormDefinition::str($column['key'] ?? '');
                $label     = FormDefinition::str($table['label'] ?? '') . ' · ' . FormDefinition::str($column['label'] ?? '');
                if (($column['fieldType'] ?? '') === 'calculated') {
                    $labels[$tableKey . '.' . $columnKey] = $label;
                    $graph[$tableKey . '.' . $columnKey]  = [];
                    $checkFormula(FormDefinition::str($column['formula'] ?? ''), $label, $tableKey . '.' . $columnKey, $tableKey);
                }
                if (($column['fieldType'] ?? '') === 'relation') {
                    $source = $index->tables[FormDefinition::str($column['relTable'] ?? '')] ?? null;
                    if ($source === null || ($source['mode'] ?? '') === 'matrix' || $index->column(FormDefinition::str($column['relTable'] ?? ''), FormDefinition::str($column['relLabel'] ?? '')) === null) {
                        $problems[] = ['code' => 'relation_invalid', 'params' => ['%label%' => $label]];
                    }
                }
            }
            if (($table['mode'] ?? '') === 'matrix') {
                foreach (['row', 'col'] as $side) {
                    $source = FormDefinition::str($table[$side . 'Table'] ?? '');
                    if (!isset($index->tables[$source]) || $source === $tableKey || $index->column($source, FormDefinition::str($table[$side . 'Label'] ?? '')) === null) {
                        $problems[] = ['code' => 'matrix_invalid', 'params' => ['%label%' => FormDefinition::str($table['label'] ?? '')]];
                        break;
                    }
                }
            }
        }

        foreach (FormDefinition::listOf($definition['rules'] ?? []) as $rule) {
            $message = FormDefinition::str($rule['message'] ?? '');
            if ($message === '') {
                $problems[] = ['code' => 'rule_without_message', 'params' => ['%part%' => 'rules']];
            }
            $checkFormula(FormDefinition::str($rule['expr'] ?? ''), $message === '' ? '…' : $message, '', null, 'rules');
        }

        // A calculated value that depends on itself, however far.
        foreach (array_keys($graph) as $start) {
            if ($this->reaches($graph, $start, $start, [])) {
                $problems[] = ['code' => 'formula_cycle', 'params' => ['%label%' => $labels[$start] ?? '']];
            }
        }
    }

    /**
     * What a reference of a formula points at: false when it does not exist, a calculated-value node (to look for cycles) when it
     * reads one, null otherwise.
     *
     * @param array<string, true> $sections
     */
    private function referenceTarget(string $ref, FormIndex $index, array $sections, ?string $table): string|false|null
    {
        [$kind, $rest] = array_pad(explode(':', $ref, 2), 2, '');
        $node          = static fn (string $t, string $c): string => $t . '.' . $c;

        switch ($kind) {
            case 'f':
                return isset($index->fields[$rest]) ? $rest : false;

            case 't':
                [$t, $c] = array_pad(explode('.', $rest, 2), 2, '');

                return $index->column($t, $c) === null ? false : $node($t, $c);

            case 'r':
                return $table !== null && $index->column($table, $rest) !== null ? $node($table, $rest) : false;

            case 'o':
                return null;

            case 'c':
                [$s, $tail] = array_pad(explode('.', $rest, 2), 2, '');
                if (!isset($sections[$s])) {
                    return false;
                }
                if (str_starts_with($tail, 'f:')) {
                    return isset($index->fields[substr($tail, 2)]) ? substr($tail, 2) : false;
                }
                if (str_starts_with($tail, 't:')) {
                    [$t, $c] = array_pad(explode('.', substr($tail, 2), 2), 2, '');

                    return $index->column($t, $c) === null ? false : $node($t, $c);
                }

                return false;

            case 'm':
                [$m] = array_pad(explode('.', $rest, 2), 2, '');

                return ($index->tables[$m]['mode'] ?? '') === 'matrix' ? null : false;
        }

        return false;
    }

    /**
     * @param array<string, list<string>> $graph
     * @param list<string>                $seen
     */
    private function reaches(array $graph, string $from, string $target, array $seen): bool
    {
        foreach ($graph[$from] ?? [] as $next) {
            if ($next === $target) {
                return true;
            }
            if (!isset($graph[$next]) || in_array($next, $seen, true)) {
                continue;
            }
            if ($this->reaches($graph, $next, $target, [...$seen, $next])) {
                return true;
            }
        }

        return false;
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
        if ($mode === 'list' && FormDefinition::str($repeat['list'] ?? '') === '') {
            $problems[] = ['code' => 'repeat_list_invalid', 'params' => ['%section%' => $title]];
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
        if (in_array($type, ['choice', 'multichoice'], true) && FormDefinition::listOf($block['options'] ?? []) === [] && FormDefinition::str($block['list'] ?? '') === '') {
            $problems[] = ['code' => 'choice_without_options', 'params' => ['%label%' => $label]];
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param array<string, string> $fieldTypes field key → type
     * @param list<array{code: string, params: array<string, string>}> $problems
     */
    private function checkTable(array $block, string $label, array $fieldTypes, array &$problems): void
    {
        $columns = FormDefinition::listOf($block['columns'] ?? []);
        // A double-entry table has no columns of its own (its sources are checked with the calculations).
        if ($columns === [] && ($block['mode'] ?? '') !== 'matrix') {
            $problems[] = ['code' => 'table_without_columns', 'params' => ['%label%' => $label]];
        }
        foreach ($columns as $column) {
            if (trim(FormDefinition::str($column['label'] ?? '')) === '') {
                $problems[] = ['code' => 'column_without_label', 'params' => ['%label%' => $label]];
            }
            $this->checkOptions($column, FormDefinition::str($column['label'] ?? ''), $problems);
        }
        if (($block['mode'] ?? '') === 'fixed' && FormDefinition::listOf($block['rows'] ?? []) === [] && FormDefinition::str($block['rowsList'] ?? '') === '') {
            $problems[] = ['code' => 'fixed_table_without_rows', 'params' => ['%label%' => $label]];
        }
        // The number that says how many rows a counted table has: a number field the person types (a calculated one is worked out
        // only once the rows are there).
        if (($block['mode'] ?? '') === 'counted' && ($fieldTypes[FormDefinition::str($block['rowsField'] ?? '')] ?? null) !== 'number') {
            $problems[] = ['code' => 'counted_table_invalid', 'params' => ['%label%' => $label]];
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
            // The field of a named foot total is made again from its column every time, so a stored one is left out.
            if (($block['foot'] ?? false) === true) {
                continue;
            }
            $clean = $this->block($block);
            if ($clean !== null) {
                $blocks[] = $clean;
                array_push($blocks, ...$this->footFields($clean));
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

            case 'list':
                // One copy per item of a list of the centre (read when filling it in, never stored: FormListOptions puts them in
                // `items`), only those of who delivers it when `own` says so.
                $item = is_string($raw['list'] ?? null) ? strtolower(trim($raw['list'])) : '';

                return ['mode' => 'list', 'list' => FormListOptions::isItemId($item) ? $item : '', 'own' => ($raw['own'] ?? true) !== false];
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
     * The fields of the foot totals that have a name: a calculated field (`sum([t:TABLE.COLUMN])`) marked `foot`, which is not shown as
     * a block (the table shows the value at the foot of its column) but is a field of the document like any other: formulas,
     * indicators, rules and the data explorer can use it.
     *
     * @param array<string, mixed> $block
     *
     * @return list<array<string, mixed>>
     */
    private function footFields(array $block): array
    {
        if (($block['type'] ?? '') !== 'table') {
            return [];
        }
        $fields = [];
        foreach (FormDefinition::listOf($block['columns'] ?? []) as $column) {
            $name    = FormDefinition::str($column['totalName'] ?? '');
            $fn      = FormDefinition::str($column['total'] ?? '');
            $custom  = $fn === 'custom';
            $formula = $custom
                ? FormDefinition::str($column['totalFormula'] ?? '')
                : sprintf('%s([t:%s.%s])', $fn, FormDefinition::str($block['key'] ?? ''), FormDefinition::str($column['key'] ?? ''));
            if ($fn === '' || $formula === '' || ($name === '' && !$custom)) {
                continue;
            }
            $calculated = ($column['fieldType'] ?? '') === 'calculated';
            $fields[]   = ['key' => FormDefinition::str($column['totalKey'] ?? ''), 'type' => 'field', 'name' => '']
                + $this->fieldProperties([
                    'label'     => $name !== '' ? $name : FormDefinition::str($column['label'] ?? ''),
                    'fieldType' => 'calculated',
                    'formula'   => $formula,
                    'decimals'  => $fn === 'count' ? 0 : ($fn === 'avg' ? FormDefinition::int($column['totalDecimals'] ?? 2) : ($calculated ? FormDefinition::int($column['decimals'] ?? 2) : 2)),
                    'unit'      => $fn === 'count' ? '' : FormDefinition::str($column['unit'] ?? ''),
                ])
                + ['help' => '', 'visibleIf' => null, 'foot' => true];
        }

        return $fields;
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
            $props    = $this->fieldProperties($column, column: true);
            $key      = $this->key($column['key'] ?? null);
            // A foot total with a name is also a field of the document (see footFields()); its key is kept so that what reads it still finds it.
            $footName = $props['total'] === '' ? '' : $this->text($column['totalName'] ?? '', 255);
            // A custom total is a formula of its own (over the whole table, not a row); it is always a field, named or not.
            $formula  = $props['total'] === 'custom' ? mb_substr(trim(FormDefinition::str($column['totalFormula'] ?? '')), 0, 1000) : '';
            $isField  = $footName !== '' || $formula !== '';
            $columns[] = ['key' => $key, 'name' => $this->name($column['name'] ?? '')] + $props
                + ['totalName' => $footName, 'totalFormula' => $formula, 'totalKey' => $isField ? $this->key($column['totalKey'] ?? null) : ''];
        }
        $rows = [];
        // The rows of a fixed table are typed in the model, or they are the items of a list of the centre (`rowsList`): then they are
        // never stored, but read from the list every time (FormListOptions), only those of who delivers it when `rowsOwn` says so.
        $rowsList = '';
        if ($mode === 'fixed') {
            $item     = is_string($raw['rowsList'] ?? null) ? strtolower(trim($raw['rowsList'])) : '';
            $rowsList = FormListOptions::isItemId($item) ? $item : '';
        }
        if ($mode === 'fixed' && $rowsList === '') {
            foreach (array_slice(FormDefinition::listOf($raw['rows'] ?? []), 0, self::MAX_FIXED_ROWS) as $row) {
                $rows[] = ['key' => $this->key($row['key'] ?? null), 'label' => $this->text($row['label'] ?? '', 500)];
            }
        }
        $listRows = $mode === 'fixed' ? ['rowsList' => $rowsList, 'rowsOwn' => ($raw['rowsOwn'] ?? true) !== false] : [];
        $min = max(0, FormDefinition::int($raw['minRows'] ?? 0));
        $max = max(0, FormDefinition::int($raw['maxRows'] ?? 0));

        // A counted table has as many rows as a number field says, up to a cap (no minimum: the field decides).
        $counted = [];
        if ($mode === 'counted') {
            $counted = ['rowsField' => $this->reference($raw['rowsField'] ?? null) ?? ''];
            $max     = min($max > 0 ? $max : FormDefinition::MAX_COUNTED_ROWS, FormDefinition::MAX_COUNTED_ROWS);
        }

        // A double-entry table has no columns of its own: its rows are the rows of one table and its columns the rows of another,
        // and every cell is of the same kind.
        $matrix = [];
        if ($mode === 'matrix') {
            $cell   = FormDefinition::map($raw['cell'] ?? null) ?? [];
            $matrix = [
                'rowTable' => $this->reference($raw['rowTable'] ?? null) ?? '',
                'rowLabel' => $this->reference($raw['rowLabel'] ?? null) ?? '',
                'colTable' => $this->reference($raw['colTable'] ?? null) ?? '',
                'colLabel' => $this->reference($raw['colLabel'] ?? null) ?? '',
                'cell'     => $this->fieldProperties(['label' => ''] + $cell, true),
            ];
            $columns = [];
        }

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
            'maxRows'   => in_array($mode, ['free', 'counted'], true) ? $max : 0,
            'help'      => $this->text($raw['help'] ?? '', 1000),
            'visibleIf' => $this->condition($raw['visibleIf'] ?? null),
        ] + $matrix + $counted + $listRows;
    }

    /**
     * What a field and a table column share: label, type, constraints and options (and, by type, the formula of a calculated one,
     * what a relation points at, and the total a column shows at its foot).
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function fieldProperties(array $raw, bool $cell = false, bool $column = false): array
    {
        $type = FormDefinition::str($raw['fieldType'] ?? '');
        $type = in_array($type, FormDefinition::FIELD_TYPES, true) ? $type : 'short';
        // Files are attached to a field of their own, never to a cell of a table.
        if (($cell || $column) && $type === 'attachment') {
            $type = 'short';
        }
        // The cells of a double-entry table are typed in: no formulas or relations there (nor ratings: a grid of numbers).
        if ($cell && in_array($type, ['calculated', 'relation', 'choice', 'multichoice', 'scale'], true)) {
            $type = 'number';
        }

        // The options are the ones typed in, or the leaves of an item of the centre's lists (then they are not stored: they are read
        // from the list every time, see FormListOptions).
        $options = [];
        $list    = '';
        if (in_array($type, ['choice', 'multichoice'], true)) {
            $item = is_string($raw['list'] ?? null) ? strtolower(trim($raw['list'])) : '';
            $list = FormListOptions::isItemId($item) ? $item : '';
            if ($list === '') {
                foreach (array_slice(FormDefinition::listOf($raw['options'] ?? []), 0, self::MAX_OPTIONS) as $option) {
                    $options[] = ['key' => $this->key($option['key'] ?? null), 'label' => $this->text($option['label'] ?? '', 255)];
                }
            }
        }

        $calculated = $type === 'calculated';
        $scale      = $type === 'scale';
        $total      = FormDefinition::str($raw['total'] ?? '');
        $points     = FormDefinition::int($raw['points'] ?? FormDefinition::SCALE_DEFAULT_POINTS) ?: FormDefinition::SCALE_DEFAULT_POINTS;

        return [
            'label'     => $this->text($raw['label'] ?? '', 255),
            'fieldType' => $type,
            'required'  => !$calculated && ($raw['required'] ?? false) === true,
            'options'   => $options,
            'list'      => $list,
            'min'       => $type === 'number' ? $this->number($raw['min'] ?? null) : null,
            'max'       => $type === 'number' ? $this->number($raw['max'] ?? null) : null,
            // How many decimals a number takes: null, any (what it always took); 0, a whole number; up to 6.
            'maxDecimals' => $type === 'number' && is_numeric($raw['maxDecimals'] ?? null) ? min(6, max(0, (int) $raw['maxDecimals'])) : null,
            'unit'      => in_array($type, ['number', 'calculated'], true) ? $this->text($raw['unit'] ?? '', 20) : '',
            'formula'   => $calculated ? mb_substr(trim(FormDefinition::str($raw['formula'] ?? '')), 0, 1000) : '',
            'decimals'  => $calculated ? min(6, max(0, FormDefinition::int($raw['decimals'] ?? 2))) : 0,
            'relTable'  => $type === 'relation' ? ($this->reference($raw['relTable'] ?? null) ?? '') : '',
            'relLabel'  => $type === 'relation' ? ($this->reference($raw['relLabel'] ?? null) ?? '') : '',
            'multiple'  => $type === 'relation' && ($raw['multiple'] ?? false) === true,
            'total'     => in_array($total, FormDefinition::TOTALS, true) && in_array($type, FormDefinition::NUMERIC_TYPES, true) ? $total : '',
            // The decimals of an average at the foot of the column (0–6): two, or, in a calculated column, the ones of the column itself.
            'totalDecimals' => min(6, max(0, FormDefinition::int($raw['totalDecimals'] ?? ($calculated ? ($raw['decimals'] ?? 2) : 2)))),
            'points'    => $scale ? min(FormDefinition::SCALE_MAX_POINTS, max(FormDefinition::SCALE_MIN_POINTS, $points)) : 0,
            'lowLabel'  => $scale ? $this->text($raw['lowLabel'] ?? '', 60) : '',
            'highLabel' => $scale ? $this->text($raw['highLabel'] ?? '', 60) : '',
            'maxFiles'  => $type === 'attachment' ? min(FormDefinition::ATTACHMENT_MAX_FILES, max(1, FormDefinition::int($raw['maxFiles'] ?? FormDefinition::ATTACHMENT_DEFAULT_FILES) ?: FormDefinition::ATTACHMENT_DEFAULT_FILES)) : 0,
            'printPages' => $type === 'attachment' && ($raw['printPages'] ?? false) === true,
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
