<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\Activity;
use App\Entity\FormSubmissionRevision;
use App\Model\ActivitySubmissionSlot;
use App\Repository\FormSubmissionRepository;
use App\Repository\FormSubmissionRevisionRepository;
use App\Repository\ListItemRepository;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\SlotResponsibility;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The tables of deliveries («Una por entrega de otra actividad», FormDefinition mode `delivered`): a row per delivery of another
 * activity — the one the activity chose for the table (Activity::getDeliverySource()) — with the values of the named fields its
 * columns read and the state of each delivery. The server reads them (read()) when a draft is opened, when «Actualizar» is pressed
 * and when it is delivered, and writes them into the draft's data (inject()): the cells into `tables[<table key>]`, like any other
 * cell, and where they came from into `sources[<table key>]`:
 *
 *   sources[<table key>] = { activity: ?<activity id>, title, at: <ISO date>, assumedAll: bool, teachersAsked: bool,
 *                            rows: [ { key, slot, label, status: delivered|draft|none, approval: pending|approved|rejected|null,
 *                                      revision: ?<DocumentRevision id>, version: ?int, document: ?<Document id>, teachers: [names] } ] }
 *
 * The browser never reads a delivery: it draws what is in the data. Saving never reads either (an autosave every few seconds cannot
 * query deliveries): it keeps what the draft already had (keepSaved()). The rows of the table are in `sources`, and the definition only
 * gets them from there (rowsFromData()), for a draft and for a delivered revision alike.
 *
 * Not called «fuente» on purpose: that word is the lists' (FormSourceChoices, «fuente automática»).
 */
final class FormDeliveredRows
{
    /** The namespace of the row keys: Uuid::v5(NAMESPACE, slot key), the same row for the same slot whatever the order. */
    public const string NAMESPACE = '4b1f6f0e-7a51-4c1e-9d0b-2f6a3c8e5d17';

    public const array STATUSES  = ['delivered', 'draft', 'none'];
    public const array APPROVALS = ['pending', 'approved', 'rejected'];

    public function __construct(
        private readonly ActivitySubmissionSlotBuilder $slots,
        private readonly FormSubmissionRepository $submissions,
        private readonly FormSubmissionRevisionRepository $revisions,
        private readonly ListItemRepository $items,
        private readonly SlotResponsibility $responsibility,
        private readonly ClockInterface $clock,
    ) {}

    // ── Reading ──────────────────────────────────────────────────────────────

    /**
     * What every table of deliveries of the definition shows now for the document of $slotKey of $activity in $cycleYear: by table
     * key, `sources` (what goes into data.sources) and `values` (the cells read, by row key and column key; only the ones with a value)
     * and `columns` (the keys of the columns that read a delivery, the cells inject() replaces).
     *
     * The deliveries of the cycle of the activity (activities recur every academic year: the same year here and there). Building the
     * slots of the activity and two queries per activity read, whatever the number of rows (never a query per row).
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, array{sources: array<string, mixed>, values: array<string, array<string, mixed>>, columns: list<string>}>
     */
    public function read(array $definition, Activity $activity, int $cycleYear, string $slotKey): array
    {
        $readings = [];
        /** @var array<string, array{slots: list<ActivitySubmissionSlot>, delivered: array<string, FormSubmissionRevision>, drafts: array<string, bool>}> $cache */
        $cache = [];
        $now   = $this->clock->now()->format(\DateTimeInterface::ATOM);

        foreach (self::tables($definition) as $block) {
            $key     = FormDefinition::str($block['key'] ?? '');
            $columns = self::sourceColumns($block);
            $source  = $activity->getDeliverySource($key);
            if ($source === null || $source->getId()->equals($activity->getId())) {
                $readings[$key] = ['sources' => ['activity' => null, 'title' => '', 'at' => $now, 'assumedAll' => false, 'rows' => []], 'values' => [], 'columns' => array_keys($columns)];
                continue;
            }

            $id      = $source->getId()->toRfc4122();
            $cache[$id] ??= $this->load($source, $cycleYear);
            ['slots' => $slots, 'assumedAll' => $assumedAll] = $this->inScope($cache[$id]['slots'], $source, FormDefinition::str($block['sourceScope'] ?? 'own'), $slotKey);

            $rows   = [];
            $values = [];
            foreach ($slots as $slot) {
                $slotKeyOf = $slot->key();
                $rowKey    = self::rowKey($slotKeyOf);
                $filled    = $cache[$id]['delivered'][$slotKeyOf] ?? null;
                $revision  = $filled?->getRevision();
                $rows[]    = [
                    'key'      => $rowKey,
                    'slot'     => $slotKeyOf,
                    'label'    => $slot->displayName,
                    'status'   => $filled !== null ? 'delivered' : (($cache[$id]['drafts'][$slotKeyOf] ?? false) ? 'draft' : 'none'),
                    'approval' => $revision === null ? null : ($revision->isRejected() ? 'rejected' : ($revision->isPendingReview() ? 'pending' : 'approved')),
                    'revision' => $revision?->getId()->toRfc4122(),
                    'version'  => $revision?->getVersion(),
                    'document' => $revision?->getDocument()->getId()->toRfc4122(),
                    'teachers' => array_map(static fn ($t): string => $t->getName()->full(), $this->responsibility->teachersOf($source, $slot)),
                ];
                if ($filled !== null) {
                    $cells = $this->cells($filled, $columns);
                    if ($cells !== []) {
                        $values[$rowKey] = $cells;
                    }
                }
            }

            $readings[$key] = [
                'sources' => ['activity' => $id, 'title' => $source->getTitle(), 'at' => $now, 'assumedAll' => $assumedAll, 'teachersAsked' => $source->isTeachersDeliver(), 'rows' => $rows],
                'values'  => $values,
                'columns' => array_keys($columns),
            ];
        }

        return $readings;
    }

    /** @return array{slots: list<ActivitySubmissionSlot>, delivered: array<string, FormSubmissionRevision>, drafts: array<string, bool>} */
    private function load(Activity $source, int $cycleYear): array
    {
        $drafts = [];
        foreach ($this->submissions->findByActivityCycle($source, $cycleYear) as $submission) {
            if ($submission->hasDraft()) {
                $drafts[$submission->getSlotKey()] = true;
            }
        }

        return [
            'slots'     => array_values($this->slots->buildSlots($source)),
            'delivered' => $this->revisions->findLatestDeliveredByActivityCycle($source, $cycleYear),
            'drafts'    => $drafts,
        ];
    }

    /**
     * The slots a table shows: all of them, or (scope `own`) those whose element belongs to who delivers the document — the same rule as
     * the rows of a table of a list (FormListOptions::belongsToSlot()). Slots with no element always stay. When no slot with an element
     * is left, all of them, and the table says so (`assumedAll`).
     *
     * @param list<ActivitySubmissionSlot> $slots
     *
     * @return array{slots: list<ActivitySubmissionSlot>, assumedAll: bool}
     */
    private function inScope(array $slots, Activity $source, string $scope, string $slotKey): array
    {
        $who = ActivitySubmissionSlot::deliveredBy($slotKey);
        if ($scope !== 'own' || $who === null) {
            return ['slots' => $slots, 'assumedAll' => false];
        }

        $index = $this->items->indexByCentre($source->getCategory()->getEducationalCentre());
        $kept  = [];
        $any   = false;
        foreach ($slots as $slot) {
            $item = $slot->nameListItem ?? $slot->listItem;
            if ($item === null) {
                $kept[] = $slot;
            } elseif ($this->isOfWhoDelivers($slot, $item, $who, $index)) {
                $kept[] = $slot;
                $any    = true;
            }
        }

        return $any ? ['slots' => $kept, 'assumedAll' => false] : ['slots' => $slots, 'assumedAll' => true];
    }

    /**
     * Whether a slot of the activity read belongs to who delivers this document. When both are delivered by the same profile of a list,
     * the slot's own row decides: a subject shared by two departments asks each one for its own delivery, and each head sees theirs,
     * not the other's. Otherwise, the element's associations (FormListOptions::belongsToSlot(), as the tables of a list).
     *
     * @param array{profile: string, subprofile: ?string} $who
     * @param array<string, \App\Entity\ListItem>          $index
     */
    private function isOfWhoDelivers(ActivitySubmissionSlot $slot, \App\Entity\ListItem $item, array $who, array $index): bool
    {
        if ($slot->profile->getId()->toRfc4122() === $who['profile'] && $slot->listItem !== null && $who['subprofile'] !== null) {
            for ($node = $slot->listItem; $node !== null; $node = $node->getParent()) {
                if ($node->getId()->toRfc4122() === $who['subprofile']) {
                    return true;
                }
            }

            return false;
        }

        return FormListOptions::belongsToSlot($item, $who, $index);
    }

    /**
     * The cells of a delivery for the columns that read it: the value of the named field in the data it was delivered with, read with
     * the definition of the model revision it was filled with. A field that is not there (an older revision), or whose type cannot
     * go into the column, is no value — never an error.
     *
     * @param array<string, string> $columns column key → field name
     *
     * @return array<string, mixed>
     */
    private function cells(FormSubmissionRevision $filled, array $columns): array
    {
        $fields   = $this->namedFieldsOf($filled);
        $data     = $filled->getData();
        $values   = FormDefinition::map($data['fields'] ?? null) ?? [];
        $snapshot = $filled->getOptionsSnapshot() ?? [];

        $cells = [];
        foreach ($columns as $columnKey => $name) {
            $field = $fields[$name] ?? null;
            if ($field === null) {
                continue;
            }
            $value = self::value($field, $values[FormDefinition::str($field['key'] ?? '')] ?? null, $snapshot);
            if ($value !== null) {
                $cells[$columnKey] = $value;
            }
        }

        return $cells;
    }

    /** @var array<string, array<string, array<string, mixed>>> named fields by model revision id (a definition is walked once) */
    private array $namedFields = [];

    /** @return array<string, array<string, mixed>> */
    private function namedFieldsOf(FormSubmissionRevision $filled): array
    {
        $modelRevision = $filled->getModelRevision();

        return $this->namedFields[$modelRevision->getId()->toRfc4122()] ??= self::namedFields($modelRevision->getDefinition());
    }

    // ── What can be read ─────────────────────────────────────────────────────

    /**
     * The fields of a definition a table of deliveries can read, by their internal name: those of the document itself (not inside a
     * table or a repeating section) that have a name — named foot totals included —, except files.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, array<string, mixed>>
     */
    public static function namedFields(array $definition): array
    {
        $out   = [];
        $visit = static function (mixed $sections) use (&$visit, &$out): void {
            foreach (FormDefinition::listOf($sections) as $section) {
                if (FormDefinition::map($section['repeat'] ?? null) !== null) {
                    continue; // one value per copy: not one value per delivery
                }
                foreach (FormDefinition::listOf($section['blocks'] ?? []) as $block) {
                    $name = FormDefinition::str($block['name'] ?? '');
                    if (($block['type'] ?? '') === 'field' && $name !== '' && self::columnTypeFor(FormDefinition::str($block['fieldType'] ?? '')) !== null) {
                        $out[$name] ??= $block;
                    }
                }
                $visit($section['children'] ?? []);
            }
        };
        $visit($definition['sections'] ?? []);

        return $out;
    }

    /**
     * The type of the column that shows a field of this type, or null when it cannot be shown: numbers (typed, calculated or a rating)
     * as numbers, choices as the text of what was chosen, and texts, dates, times and yes/no as they are.
     */
    public static function columnTypeFor(string $fieldType): ?string
    {
        return match ($fieldType) {
            'number', 'calculated', 'scale' => 'number',
            'choice', 'multichoice'         => 'short',
            'short', 'long', 'date', 'time', 'bool' => $fieldType,
            default                         => null,
        };
    }

    /**
     * A value of a delivery as the column shows it; null when there is none or it does not fit.
     *
     * @param array<string, mixed>                                    $field
     * @param array<string, list<array{key: string, label: string}>> $snapshot the options a delivery froze (lists of the centre)
     */
    public static function value(array $field, mixed $value, array $snapshot = []): string|int|float|bool|null
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        $type = FormDefinition::str($field['fieldType'] ?? '');

        switch ($type) {
            case 'number':
            case 'calculated':
            case 'scale':
                return is_int($value) || is_float($value) ? $value : (is_string($value) && is_numeric($value) ? $value + 0 : null);

            case 'short':
            case 'long':
                $text = is_string($value) ? ($type === 'long' ? FormRichText::plain($value) : trim($value)) : '';

                return $text === '' ? null : $text;

            case 'date':
            case 'time':
                return is_string($value) ? $value : null;

            case 'bool':
                return is_bool($value) ? $value : null;

            case 'choice':
            case 'multichoice':
                $labels = [];
                foreach ([...FormDefinition::listOf($field['options'] ?? []), ...($snapshot[FormDefinition::str($field['key'] ?? '')] ?? [])] as $option) {
                    $labels[FormDefinition::str($option['key'] ?? '')] = FormDefinition::str($option['label'] ?? '');
                }
                $chosen = array_filter(array_map(static fn (mixed $k): string => $labels[FormDefinition::str($k)] ?? '', is_array($value) ? $value : [$value]), static fn (string $l): bool => $l !== '');

                return $chosen === [] ? null : implode(', ', $chosen);
        }

        return null;
    }

    // ── The data ─────────────────────────────────────────────────────────────

    /**
     * The tables of deliveries of a definition that a reading fills: those of the document (inside a repeating section a table would be
     * one per copy — not supported; the model's checks say so).
     *
     * @param array<string, mixed> $definition
     *
     * @return list<array<string, mixed>>
     */
    public static function tables(array $definition): array
    {
        $out   = [];
        $visit = static function (mixed $sections) use (&$visit, &$out): void {
            foreach (FormDefinition::listOf($sections) as $section) {
                if (FormDefinition::map($section['repeat'] ?? null) !== null) {
                    continue;
                }
                foreach (FormDefinition::listOf($section['blocks'] ?? []) as $block) {
                    if (($block['type'] ?? '') === 'table' && ($block['mode'] ?? '') === 'delivered') {
                        $out[] = $block;
                    }
                }
                $visit($section['children'] ?? []);
            }
        };
        $visit($definition['sections'] ?? []);

        return $out;
    }

    /**
     * The columns of a table that read a delivery: column key → field name.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, string>
     */
    public static function sourceColumns(array $block): array
    {
        $out = [];
        foreach (FormDefinition::listOf($block['columns'] ?? []) as $column) {
            $field = FormDefinition::str($column['sourceField'] ?? '');
            if ($field !== '') {
                $out[FormDefinition::str($column['key'] ?? '')] = $field;
            }
        }

        return $out;
    }

    public static function rowKey(string $slotKey): string
    {
        return Uuid::v5(Uuid::fromString(self::NAMESPACE), $slotKey)->toRfc4122();
    }

    /**
     * The data with what was read: each table rebuilt in the order of its rows, every row keeping what was typed in its own columns
     * (found by `_key`) with the cells read on top (an empty one, removed); the rows that are not there any more go, with what was typed
     * in them, and the new ones come in their place. `sources` written. Pure.
     *
     * @param array<string, mixed>                                                                                                   $data
     * @param array<string, array{sources: array<string, mixed>, values: array<string, array<string, mixed>>, columns: list<string>}> $readings
     *
     * @return array<string, mixed>
     */
    public static function inject(array $data, array $readings): array
    {
        $tables  = FormDefinition::map($data['tables'] ?? null) ?? [];
        $sources = FormDefinition::map($data['sources'] ?? null) ?? [];

        foreach ($readings as $key => $reading) {
            $before = [];
            foreach (FormDefinition::listOf($tables[$key] ?? []) as $row) {
                $before[FormDefinition::str($row['_key'] ?? '')] = $row;
            }
            $rows = [];
            foreach (FormDefinition::listOf($reading['sources']['rows'] ?? []) as $sourceRow) {
                $rowKey = FormDefinition::str($sourceRow['key'] ?? '');
                $row    = $before[$rowKey] ?? [];
                foreach ($reading['columns'] as $column) {
                    unset($row[$column]);
                }
                $rows[] = ['_key' => $rowKey] + $row + ($reading['values'][$rowKey] ?? []);
            }
            $tables[$key]  = $rows;
            $sources[$key] = $reading['sources'];
        }
        $data['tables']  = $tables;
        $data['sources'] = $sources;

        return $data;
    }

    /**
     * How many rows of the tables of deliveries differ between two versions of the data — in a cell read, in the state or in the
     * approval of the delivery, or because they come in or go — and which cells changed (to show them). Who teaches an element is only
     * shown: a change there is no change. What was typed in the own columns is the same on both sides (inject() keeps it).
     *
     * @param array<string, mixed>                                                                                                   $before
     * @param array<string, mixed>                                                                                                   $after
     * @param array<string, array{sources: array<string, mixed>, values: array<string, array<string, mixed>>, columns: list<string>}> $readings
     *
     * @return array{count: int, cells: list<array{table: string, row: string, column: string}>}
     */
    public static function changes(array $before, array $after, array $readings): array
    {
        $count = 0;
        $cells = [];
        foreach ($readings as $key => $reading) {
            $oldRows = self::rowsByKey($before, $key);
            $newRows = self::rowsByKey($after, $key);
            $oldMeta = self::metaByKey($before, $key);
            $newMeta = self::metaByKey($after, $key);
            $count  += count(array_diff_key($oldRows, $newRows));

            foreach ($newRows as $rowKey => $row) {
                if (!isset($oldRows[$rowKey])) {
                    ++$count;
                    continue;
                }
                $changed = ($oldMeta[$rowKey] ?? null) !== ($newMeta[$rowKey] ?? null);
                foreach ($reading['columns'] as $column) {
                    if (($oldRows[$rowKey][$column] ?? null) !== ($row[$column] ?? null)) {
                        $changed = true;
                        $cells[] = ['table' => (string) $key, 'row' => $rowKey, 'column' => $column];
                    }
                }
                $count += $changed ? 1 : 0;
            }
        }

        return ['count' => $count, 'cells' => $cells];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, array<string, mixed>>
     */
    private static function rowsByKey(array $data, string $table): array
    {
        $out = [];
        foreach (FormDefinition::listOf((FormDefinition::map($data['tables'] ?? null) ?? [])[$table] ?? []) as $row) {
            $out[FormDefinition::str($row['_key'] ?? '')] = $row;
        }

        return $out;
    }

    /**
     * The state and approval of each row as data.sources says (a row read before the state existed has none).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function metaByKey(array $data, string $table): array
    {
        $out    = [];
        $source = FormDefinition::map((FormDefinition::map($data['sources'] ?? null) ?? [])[$table] ?? null) ?? [];
        foreach (FormDefinition::listOf($source['rows'] ?? []) as $row) {
            $out[FormDefinition::str($row['key'] ?? '')] = FormDefinition::str($row['status'] ?? '') . '|' . FormDefinition::str($row['approval'] ?? '');
        }

        return $out;
    }

    /**
     * The definition with the rows of each table of deliveries taken from the data (`sources`): the only way it gets them, so that a
     * draft, a delivered revision, its PDF, the comparison and the explorer all see the rows that were read. Each row also carries
     * what the fill page shows about its delivery (state, approval, teachers, document). A table with nothing read has no rows.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function rowsFromData(array $definition, array $data): array
    {
        $sources = FormDefinition::map($data['sources'] ?? null) ?? [];
        $map     = static function (mixed $sections) use (&$map, $sources): array {
            $out = [];
            foreach (FormDefinition::listOf($sections) as $section) {
                $blocks = [];
                foreach (FormDefinition::listOf($section['blocks'] ?? []) as $block) {
                    if (($block['type'] ?? '') === 'table' && ($block['mode'] ?? '') === 'delivered') {
                        $source = FormDefinition::map($sources[FormDefinition::str($block['key'] ?? '')] ?? null) ?? [];
                        $block['rows'] = array_map(static fn (array $r): array => [
                            'key'      => FormDefinition::str($r['key'] ?? ''),
                            'label'    => FormDefinition::str($r['label'] ?? ''),
                            'status'   => FormDefinition::str($r['status'] ?? 'none'),
                            'approval' => is_string($r['approval'] ?? null) ? $r['approval'] : null,
                            'revision' => is_string($r['revision'] ?? null) ? $r['revision'] : null,
                            'document' => is_string($r['document'] ?? null) ? $r['document'] : null,
                            'teachers' => array_values(array_filter(is_array($r['teachers'] ?? null) ? $r['teachers'] : [], 'is_string')),
                        ], FormDefinition::listOf($source['rows'] ?? []));
                    }
                    $blocks[] = $block;
                }
                $section['blocks']   = $blocks;
                $section['children'] = $map($section['children'] ?? []);
                $out[]               = $section;
            }

            return $out;
        };
        $definition['sections'] = $map($definition['sections'] ?? []);

        return $definition;
    }

    /**
     * `sources` as data (what the normalizer keeps): only the shape described above, with known values; anything else is dropped.
     * Whether they are true is not this method's business: what the browser sends is replaced by what the draft had (keepSaved()).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function clean(mixed $sources): array
    {
        $out = [];
        foreach (FormDefinition::map($sources) ?? [] as $key => $source) {
            $source = FormDefinition::map($source);
            if ($source === null || !FormListOptions::isItemId($key)) {
                continue;
            }
            $activity = FormDefinition::str($source['activity'] ?? '');
            $rows     = [];
            foreach (array_slice(FormDefinition::listOf($source['rows'] ?? []), 0, FormDataNormalizer::MAX_ROWS) as $row) {
                $rowKey = FormDefinition::str($row['key'] ?? '');
                if (!FormListOptions::isItemId($rowKey)) {
                    continue;
                }
                $status   = FormDefinition::str($row['status'] ?? '');
                $approval = FormDefinition::str($row['approval'] ?? '');
                $revision = FormDefinition::str($row['revision'] ?? '');
                $document = FormDefinition::str($row['document'] ?? '');
                $rows[]   = [
                    'key'      => $rowKey,
                    'slot'     => mb_substr(FormDefinition::str($row['slot'] ?? ''), 0, 255),
                    'label'    => mb_substr(FormDefinition::str($row['label'] ?? ''), 0, 500),
                    'status'   => in_array($status, self::STATUSES, true) ? $status : 'none',
                    'approval' => in_array($approval, self::APPROVALS, true) ? $approval : null,
                    'revision' => FormListOptions::isItemId($revision) ? $revision : null,
                    'version'  => is_int($row['version'] ?? null) ? $row['version'] : null,
                    'document' => FormListOptions::isItemId($document) ? $document : null,
                    'teachers' => array_slice(array_map(static fn (string $t): string => mb_substr($t, 0, 255), array_values(array_filter(is_array($row['teachers'] ?? null) ? $row['teachers'] : [], 'is_string'))), 0, 20),
                ];
            }
            $out[$key] = [
                'activity'   => FormListOptions::isItemId($activity) ? $activity : null,
                'title'      => mb_substr(FormDefinition::str($source['title'] ?? ''), 0, 255),
                'at'         => mb_substr(FormDefinition::str($source['at'] ?? ''), 0, 40),
                'assumedAll' => ($source['assumedAll'] ?? false) === true,
                'teachersAsked' => ($source['teachersAsked'] ?? false) === true,
                'rows'       => $rows,
            ];
        }

        return $out;
    }

    /**
     * What the browser sent, with what it cannot change put back as the draft has it: the `sources` and every cell a column of a table
     * of deliveries read. Before normalizing, so formulas and totals are worked out on the values read, not on edited ones.
     *
     * @param array<string, mixed> $definition the draft's definition (with the saved rows)
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $saved      the draft as it is stored
     *
     * @return array<string, mixed>
     */
    public static function keepSaved(array $definition, array $raw, array $saved): array
    {
        $raw['sources'] = FormDefinition::map($saved['sources'] ?? null) ?? [];
        $tables         = FormDefinition::map($raw['tables'] ?? null) ?? [];
        foreach (self::tables($definition) as $block) {
            $key     = FormDefinition::str($block['key'] ?? '');
            $columns = array_keys(self::sourceColumns($block));
            $stored  = self::rowsByKey($saved, $key);
            $rows    = [];
            foreach (FormDefinition::listOf($tables[$key] ?? []) as $row) {
                $rowKey = FormDefinition::str($row['_key'] ?? '');
                foreach ($columns as $column) {
                    unset($row[$column]);
                    if (array_key_exists($column, $stored[$rowKey] ?? [])) {
                        $row[$column] = $stored[$rowKey][$column];
                    }
                }
                $rows[] = $row;
            }
            $tables[$key] = $rows;
        }
        $raw['tables'] = $tables;

        return $raw;
    }
}
