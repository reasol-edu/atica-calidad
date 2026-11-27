<?php

declare(strict_types=1);

namespace App\Service\Form;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A definition and its data turned into what the outputs (web, PDF, later ODT/DOCX) draw: the sections numbered, one per copy, the
 * hidden ones left out, the references replaced by their values, the values already formatted as people read them and the help
 * texts left out. One resolved document, so every output shows the same.
 *
 *   section = { key, path, number: ?string, title, level, blocks: [ block ], children: [ section ] }
 *   block   = { kind: "text", label, html, empty } | { kind: "fields", items: [ { label, value, empty } ] }
 *           | { kind: "table", label, rowHeader, fixed, columns: [ label ], rows: [ { label, cells: [ value ] } ], totals: ?[ value ] }
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
        // The attachments are numbered across the whole document («Anexo 1», «Anexo 2»…), in the order it shows them.
        $this->annex   = 0;
        $this->sources = FormDefinition::map($data['sources'] ?? null) ?? [];

        return $this->sections($this->walker->walk($definition, $data), '', true, 1, FormIndex::of($definition), $context);
    }

    /** The number of the last «Anexo» given while resolving a document. */
    private int $annex = 0;

    /** @var array<string, mixed> where the tables of deliveries of the document being resolved came from (`data.sources`) */
    private array $sources = [];

    /**
     * A title or a label with its references replaced, as plain text, for a walker instance.
     *
     * @param array<string, mixed>  $instance
     * @param array<string, string> $context
     */
    public function label(string $text, array $instance, FormIndex $index, array $context = []): string
    {
        return $this->text($text, $instance, $index, $context, false);
    }

    /**
     * @param list<array<string, mixed>> $instances
     * @param array<string, string>      $context
     *
     * @return list<array<string, mixed>>
     */
    private function sections(array $instances, string $prefix, bool $numbering, int $level, FormIndex $index, array $context): array
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
                'title'    => $this->text(FormDefinition::str($section['title'] ?? ''), $instance, $index, $context, false),
                'level'    => $level,
                'blocks'   => $this->blocks($instance, $index, $context),
                'children' => $this->sections(FormDefinition::listOf($instance['children'] ?? []), $number ?? '', $numbered, $level + 1, $index, $context),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed>  $instance
     * @param array<string, string> $context
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(array $instance, FormIndex $index, array $context): array
    {
        $scope  = FormDefinition::map($instance['scope'] ?? null) ?? [];
        $chain  = FormDefinition::listOf($instance['chain'] ?? []);
        $fields = FormDefinition::map($scope['fields'] ?? null) ?? [];
        $texts  = FormDefinition::map($scope['texts'] ?? null) ?? [];
        $tables = FormDefinition::map($scope['tables'] ?? null) ?? [];

        $out = [];
        foreach (FormDefinition::listOf($instance['blocks'] ?? []) as $entry) {
            $block = FormDefinition::map($entry['block'] ?? null);
            if ($entry['visible'] !== true || $block === null) {
                continue;
            }
            $key   = FormDefinition::str($block['key'] ?? '');
            $label = $this->text(FormDefinition::str($block['label'] ?? ''), $instance, $index, $context, false);

            switch ($block['type'] ?? '') {
                case 'text':
                    $html = ($block['mode'] ?? 'fixed') === 'fixed'
                        ? FormDefinition::str($block['html'] ?? '')
                        : FormDefinition::str(FormDefinition::map($texts[$key] ?? null)['html'] ?? '');
                    $html  = $this->text($html, $instance, $index, $context, true);
                    $out[] = ['kind' => 'text', 'key' => $key, 'label' => $label, 'html' => $html, 'empty' => trim(strip_tags($html)) === ''];
                    break;

                case 'field':
                    $value = $this->format($block, $fields[$key] ?? null, $chain, $index);
                    $item  = ['key' => $key, 'label' => $label, 'value' => $value, 'empty' => $value === ''];
                    // A «texto largo» is rich text: the document shows its HTML (the value stays the plain text).
                    if ($value !== '' && ($block['fieldType'] ?? '') === 'long' && is_string($fields[$key] ?? null)) {
                        $item['html'] = FormRichText::html($fields[$key]);
                    }
                    // A rating without an answer prints its points, to tick on paper (the blank model, an unanswered one).
                    if ($value === '' && ($block['fieldType'] ?? '') === 'scale') {
                        $item['scale'] = self::scalePoints($block);
                    }
                    // The files of a field «Adjuntos», each with its number of annex in the document.
                    if (($block['fieldType'] ?? '') === 'attachment') {
                        $item['attachments'] = [];
                        foreach (FormDefinition::listOf($fields[$key] ?? []) as $file) {
                            $mime                  = FormDefinition::str($file['mime'] ?? '');
                            $item['attachments'][] = [
                                'n'          => ++$this->annex,
                                'id'         => FormDefinition::str($file['id'] ?? ''),
                                'name'       => FormDefinition::str($file['name'] ?? ''),
                                'size'       => FormDefinition::int($file['size'] ?? 0),
                                'sizeText'   => FormAttachments::size(FormDefinition::int($file['size'] ?? 0)),
                                'mime'       => $mime,
                                'image'      => FormAttachments::isImage($mime),
                                'printPages' => ($block['printPages'] ?? false) === true && $mime === 'application/pdf',
                            ];
                        }
                    }
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
                    $out[] = ($block['mode'] ?? '') === 'matrix'
                        ? $this->matrix($block, $label, FormDefinition::listOf($tables[$key] ?? []), $chain, $index)
                        : $this->table($block, $label, FormDefinition::listOf($tables[$key] ?? []), $chain, $index);
                    break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>       $block
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $chain
     *
     * @return array<string, mixed>
     */
    private function table(array $block, string $label, array $rows, array $chain, FormIndex $index): array
    {
        $columns = FormDefinition::listOf($block['columns'] ?? []);
        $counted = ($block['mode'] ?? '') === 'counted';
        $fixed   = $counted || FormDefinition::hasGivenRows($block);

        $labels = [];
        foreach (FormDefinition::listOf($block['rows'] ?? []) as $row) {
            $labels[FormDefinition::str($row['key'] ?? '')] = FormDefinition::str($row['label'] ?? '');
        }

        $resolved = [];
        foreach ($rows as $number => $row) {
            // The rows of a counted table have no name: their number is what tells them apart.
            if ($counted) {
                $labels[FormDefinition::str($row['_key'] ?? '')] = (string) ($number + 1);
            }
            $cells = [];
            foreach ($columns as $column) {
                $cells[] = $this->format($column, $row[FormDefinition::str($column['key'] ?? '')] ?? null, $chain, $index);
            }
            $resolved[] = ['key' => FormDefinition::str($row['_key'] ?? ''), 'label' => $labels[FormDefinition::str($row['_key'] ?? '')] ?? '', 'cells' => $cells];
        }

        $table = [
            'kind'       => 'table',
            'key'        => FormDefinition::str($block['key'] ?? ''),
            'label'      => $label,
            'rowHeader'  => FormDefinition::str($block['rowHeader'] ?? ''),
            'fixed'      => $fixed,
            'columnKeys' => array_map(static fn (array $c): string => FormDefinition::str($c['key'] ?? ''), $columns),
            'columns'    => array_map(static fn (array $c): string => FormDefinition::str($c['label'] ?? ''), $columns),
            'rows'      => $resolved,
            'totals'    => $this->totals($columns, $rows, FormDefinition::map(FormDefinition::map($chain[0] ?? null)['fields'] ?? null) ?? []),
        ];

        return ($block['mode'] ?? '') === 'delivered' ? $this->delivered($block, $table, $rows) : $table;
    }

    /**
     * A table of deliveries the way a document shows it: «—» in a cell read from a delivery that has nothing (no delivery, or that field
     * empty), the column «Estado» first when the model asks for it, and where the rows came from (`deliveredFrom`: the activity and when
     * they were read), for a note under the table. A column that reads a «texto largo» gives, besides its plain text, its HTML (`html`,
     * by cell index) for the document and the PDF to draw with its formatting.
     *
     * @param array<string, mixed>       $block
     * @param array<string, mixed>       $table
     * @param list<array<string, mixed>> $raw   the rows as stored, by the same order
     *
     * @return array<string, mixed>
     */
    private function delivered(array $block, array $table, array $raw = []): array
    {
        $key     = FormDefinition::str($block['key'] ?? '');
        $source  = FormDefinition::map($this->sources[$key] ?? null) ?? [];
        $columns = FormDefinition::listOf($block['columns'] ?? []);
        $states  = [];
        foreach (FormDefinition::listOf($source['rows'] ?? []) as $row) {
            $states[FormDefinition::str($row['key'] ?? '')] = $row;
        }

        $strings = static fn (mixed $list): array => array_values(array_map(static fn (mixed $v): string => FormDefinition::str($v), is_array($list) ? $list : []));
        $status  = ($block['showStatus'] ?? false) === true;
        $rows    = [];
        foreach (FormDefinition::listOf($table['rows']) as $r => $row) {
            $cells = $strings($row['cells'] ?? []);
            $html  = [];
            foreach ($columns as $i => $column) {
                if (FormDefinition::str($column['sourceField'] ?? '') === '') {
                    continue;
                }
                if (($cells[$i] ?? '') === '') {
                    $cells[$i] = '—';
                    continue;
                }
                $value = ($raw[$r] ?? [])[FormDefinition::str($column['key'] ?? '')] ?? null;
                if (($column['fieldType'] ?? '') === 'long' && is_string($value)) {
                    $html[$i + ($status ? 1 : 0)] = FormRichText::html($value);
                }
            }
            if ($status) {
                array_unshift($cells, $this->stateText(FormDefinition::map($states[FormDefinition::str($row['key'] ?? '')] ?? null) ?? []));
            }
            $rows[] = ['cells' => $cells, 'html' => $html] + $row;
        }
        $table['rows'] = $rows;
        if ($status) {
            $table['columnKeys'] = ['_status', ...$strings($table['columnKeys'])];
            $table['columns']    = [$this->translator->trans('fill.delivered_status', [], 'forms'), ...$strings($table['columns'])];
            $table['totals']     = is_array($table['totals']) ? ['', ...$strings($table['totals'])] : null;
        }

        $at                     = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, FormDefinition::str($source['at'] ?? ''));
        $title                  = FormDefinition::str($source['title'] ?? '');
        $when                   = $at === false ? '' : $at->format('d/m/Y H:i');
        $table['deliveredFrom'] = $title === '' ? null : [
            'title' => $title,
            'at'    => $when,
            'text'  => $this->translator->trans('view.delivered_from', ['%title%' => $title, '%at%' => $when], 'forms'),
        ];

        return $table;
    }

    /**
     * «Entregada · Aprobada», «Borrador», «Sin empezar» for a row of `sources`.
     *
     * @param array<string, mixed> $row
     */
    private function stateText(array $row): string
    {
        $status   = FormDefinition::str($row['status'] ?? 'none');
        $text     = $this->translator->trans('fill.delivered_status_' . (in_array($status, ['delivered', 'draft'], true) ? $status : 'none'), [], 'forms');
        $approval = FormDefinition::str($row['approval'] ?? '');

        return $status === 'delivered' && in_array($approval, ['pending', 'approved', 'rejected'], true)
            ? $text . ' · ' . $this->translator->trans('fill.delivered_approval_' . $approval, [], 'forms')
            : $text;
    }

    /**
     * The foot of a table: for each column that asks for it, the sum, average, minimum, maximum or count of its values. Null when no
     * column does.
     *
     * @param list<array<string, mixed>> $columns
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $fields the fields of the scope: a custom total is the value of its field
     *
     * @return list<string>|null
     */
    private function totals(array $columns, array $rows, array $fields = []): ?array
    {
        if (array_filter($columns, static fn (array $c): bool => ($c['total'] ?? '') !== '') === []) {
            return null;
        }

        $out = [];
        foreach ($columns as $column) {
            $kind = FormDefinition::str($column['total'] ?? '');
            if ($kind === '') {
                $out[] = '';
                continue;
            }
            if ($kind === 'custom') {
                $value = $fields[FormDefinition::str($column['totalKey'] ?? '')] ?? null;
                $out[] = is_numeric($value) ? $this->number((float) $value, $column) : '';
                continue;
            }
            $values  = array_filter(array_map(static fn (array $r): mixed => $r[FormDefinition::str($column['key'] ?? '')] ?? null, $rows), static fn ($v): bool => $v !== null && $v !== '');
            $numbers = array_values(array_filter($values, 'is_numeric'));
            $result  = match ($kind) {
                'count' => count($values),
                'sum'   => $numbers === [] ? 0 : array_sum($numbers),
                'avg'   => $numbers === [] ? null : array_sum($numbers) / count($numbers),
                'min'   => $numbers === [] ? null : min($numbers),
                default => $numbers === [] ? null : max($numbers),
            };
            // An average has the decimals the column asks for (two by default: 82,33 and not 82,333333), always that many (82,50).
            $out[] = $result === null ? '' : ($kind === 'count' ? (string) $result : $this->number((float) $result, $column, $kind === 'avg' ? min(6, max(0, FormDefinition::int($column['totalDecimals'] ?? 2))) : null));
        }

        return $out;
    }

    /**
     * A number the way a column shows it: with $fixed decimals when it is given (an average at the foot of a table), else its own when it
     * is calculated (otherwise as many as it has, up to six) — and its unit.
     *
     * @param array<string, mixed> $column
     */
    private function number(float $value, array $column, ?int $fixed = null): string
    {
        // A column that reads a delivery may show always the same decimals (`displayDecimals`).
        $fixed ??= is_int($column['displayDecimals'] ?? null) ? min(6, max(0, $column['displayDecimals'])) : null;
        $text    = $fixed !== null
            ? number_format(round($value, $fixed), $fixed, ',', '')
            : (($column['fieldType'] ?? '') === 'calculated'
                ? number_format($value, min(6, max(0, FormDefinition::int($column['decimals'] ?? 2))), ',', '')
                : str_replace('.', ',', (string) round($value, 6)));
        $unit = FormDefinition::str($column['unit'] ?? '');

        return $unit === '' ? $text : $text . ' ' . $unit;
    }

    /**
     * A double-entry table: a row for each row of one table, a column for each row of another, one kind of cell.
     *
     * @param array<string, mixed>       $block
     * @param list<array<string, mixed>> $rows
     * @param list<array<string, mixed>> $chain
     *
     * @return array<string, mixed>
     */
    private function matrix(array $block, string $label, array $rows, array $chain, FormIndex $index): array
    {
        $cell      = FormDefinition::map($block['cell'] ?? null) ?? [];
        $colSource = FormScopes::rows($chain, FormDefinition::str($block['colTable'] ?? ''));
        $colLabel  = $index->column(FormDefinition::str($block['colTable'] ?? ''), FormDefinition::str($block['colLabel'] ?? '')) ?? [];
        $rowLabel  = $index->column(FormDefinition::str($block['rowTable'] ?? ''), FormDefinition::str($block['rowLabel'] ?? '')) ?? [];

        $resolved = [];
        foreach ($rows as $row) {
            $source = null;
            foreach (FormScopes::rows($chain, FormDefinition::str($block['rowTable'] ?? '')) as $candidate) {
                if (($candidate['_key'] ?? null) === ($row['_key'] ?? '')) {
                    $source = $candidate;
                }
            }
            $cells = [];
            foreach ($colSource as $col) {
                $cells[] = $this->format($cell, $row[FormDefinition::str($col['_key'] ?? '')] ?? null, $chain, $index);
            }
            $resolved[] = ['key' => FormDefinition::str($row['_key'] ?? ''), 'label' => $source === null ? '' : $this->format($rowLabel, $source[FormDefinition::str($block['rowLabel'] ?? '')] ?? null, $chain, $index), 'cells' => $cells];
        }

        return [
            'kind'       => 'table',
            'key'        => FormDefinition::str($block['key'] ?? ''),
            'label'      => $label,
            'rowHeader'  => '',
            'fixed'      => true,
            'columnKeys' => array_map(static fn (array $col): string => FormDefinition::str($col['_key'] ?? ''), $colSource),
            'columns'    => array_map(fn (array $col): string => $this->format($colLabel, $col[FormDefinition::str($block['colLabel'] ?? '')] ?? null, $chain, $index), $colSource),
            'rows'      => $resolved,
            'totals'    => null,
        ];
    }

    // ── References ───────────────────────────────────────────────────────────

    /**
     * $text with its `{{ … }}` replaced; $html escapes the values (the text is already HTML).
     *
     * @param array<string, mixed>  $instance
     * @param array<string, string> $context
     */
    private function text(string $text, array $instance, FormIndex $index, array $context, bool $html): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }

        return (string) preg_replace_callback('/\{\{\s*([^{}]*?)\s*\}\}/', function (array $m) use ($instance, $index, $context, $html): string {
            $parts = explode('|', (string) $m[1]);
            $value = $this->reference(trim((string) end($parts)), $instance, $index, $context);

            return $html ? htmlspecialchars($value, ENT_QUOTES) : $value;
        }, $text);
    }

    /**
     * @param array<string, mixed>  $instance
     * @param array<string, string> $context
     */
    private function reference(string $ref, array $instance, FormIndex $index, array $context): string
    {
        $copy  = FormDefinition::map($instance['copy'] ?? null);
        $chain = FormDefinition::listOf($instance['chain'] ?? []);

        if ($ref === 'copy.number') {
            return $copy === null ? '' : (string) FormDefinition::int($copy['index'] ?? 0);
        }
        if ($ref === 'copy.total') {
            return $copy === null ? '' : (string) FormDefinition::int($copy['total'] ?? 0);
        }
        if ($ref === 'copy.label') {
            // The name of the item of a list the copy is for (a section repeated by a list).
            return $copy === null ? '' : FormDefinition::str($copy['label'] ?? '');
        }
        if (str_starts_with($ref, 'ctx:')) {
            return $context[substr($ref, 4)] ?? '';
        }
        if (str_starts_with($ref, 'row:')) {
            $origin = FormDefinition::map($instance['origin'] ?? null);
            $table  = FormDefinition::map($origin['table'] ?? null);
            $row    = FormDefinition::map($origin['row'] ?? null);
            if ($table === null || $row === null) {
                return '';
            }
            $column = substr($ref, 4);
            foreach (FormDefinition::listOf($table['columns'] ?? []) as $spec) {
                if (($spec['key'] ?? null) === $column) {
                    return $this->format($spec, $row[$column] ?? null, $chain, $index);
                }
            }

            return '';
        }
        if (str_starts_with($ref, 'f:')) {
            $key  = substr($ref, 2);
            $spec = $index->fields[$key] ?? null;
            if ($spec !== null) {
                foreach ($chain as $scope) {
                    if (is_array($scope['fields'] ?? null) && array_key_exists($key, $scope['fields'])) {
                        return $this->format($spec, $scope['fields'][$key], $chain, $index);
                    }
                }
            }
        }

        return '';
    }

    /**
     * The points of a rating scale to tick on paper: «Nada ○ 1  ○ 2  ○ 3  ○ 4  ○ 5 Mucho».
     *
     * @param array<string, mixed> $spec
     */
    public static function scalePoints(array $spec): string
    {
        $points = FormDefinition::int($spec['points'] ?? FormDefinition::SCALE_DEFAULT_POINTS) ?: FormDefinition::SCALE_DEFAULT_POINTS;
        $marks  = implode('   ', array_map(static fn (int $n): string => '○ ' . $n, range(1, $points)));

        return trim(FormDefinition::str($spec['lowLabel'] ?? '') . '  ' . $marks . '  ' . FormDefinition::str($spec['highLabel'] ?? ''));
    }

    /**
     * A value as people read it; empty string when there is none. `$chain` is where a relation looks up the rows it points at.
     *
     * @param array<string, mixed>       $spec  a field block or a table column
     * @param list<array<string, mixed>> $chain
     */
    public function format(array $spec, mixed $value, array $chain, FormIndex $index): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '';
        }

        switch (FormDefinition::str($spec['fieldType'] ?? 'short')) {
            case 'number':
                $text = !is_numeric($value) ? '' : (is_int($spec['displayDecimals'] ?? null)
                    ? number_format(round((float) $value, min(6, max(0, $spec['displayDecimals']))), min(6, max(0, $spec['displayDecimals'])), ',', '')
                    : str_replace('.', ',', (string) (is_float($value) ? round($value, 6) : $value)));
                $unit = FormDefinition::str($spec['unit'] ?? '');

                return $unit === '' ? $text : $text . ' ' . $unit;

            case 'calculated':
                if (is_bool($value)) {
                    return $this->translator->trans($value ? 'view.yes' : 'view.no', [], 'forms');
                }
                if (!is_numeric($value)) {
                    return is_scalar($value) ? (string) $value : '';
                }
                $text = number_format((float) $value, min(6, max(0, FormDefinition::int($spec['decimals'] ?? 2))), ',', '');
                $unit = FormDefinition::str($spec['unit'] ?? '');

                return $unit === '' ? $text : $text . ' ' . $unit;

            case 'relation':
                $table  = FormDefinition::str($spec['relTable'] ?? '');
                $label  = FormDefinition::str($spec['relLabel'] ?? '');
                $column = $index->column($table, $label) ?? [];
                $keys   = is_array($value) ? $value : [$value];
                $texts  = [];
                foreach (FormScopes::rows($chain, $table) as $row) {
                    if (in_array($row['_key'] ?? null, $keys, true)) {
                        $texts[] = $this->format($column, $row[$label] ?? null, $chain, $index);
                    }
                }

                return implode(', ', array_filter($texts, static fn (string $t): bool => $t !== ''));

            case 'long':
                // Rich text reads as plain text here (a comparison, a Word file, a reference in a title); the document shows the HTML.
                return is_string($value) ? FormRichText::plain($value) : '';

            case 'date':
                $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

                return $date === false ? '' : $date->format('d/m/Y');

            case 'bool':
                return $this->translator->trans($value === true ? 'view.yes' : 'view.no', [], 'forms');

            case 'attachment':
                // The names of the files (a comparison, the explorer, a reference); the document lists them as annexes.
                return implode(', ', array_filter(array_map(static fn (array $f): string => FormDefinition::str($f['name'] ?? ''), FormDefinition::listOf($value))));

            case 'scale':
                if (!is_int($value)) {
                    return is_scalar($value) ? (string) $value : '';
                }
                $points = FormDefinition::int($spec['points'] ?? FormDefinition::SCALE_DEFAULT_POINTS) ?: FormDefinition::SCALE_DEFAULT_POINTS;
                // The word of an end, when the answer is that end: «1 / 5 (Nada)».
                $end = match ($value) {
                    1       => FormDefinition::str($spec['lowLabel'] ?? ''),
                    $points => FormDefinition::str($spec['highLabel'] ?? ''),
                    default => '',
                };

                return $value . ' / ' . $points . ($end !== '' ? ' (' . $end . ')' : '');

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
