<?php

declare(strict_types=1);

namespace App\Service\Form;

use App\Entity\EducationalCentre;
use App\Entity\FormModelRevision;
use App\Entity\FormSubmissionRevision;
use App\Entity\ListItem;
use App\Repository\ListItemRepository;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The options of a choice field (or of a table column) that are not typed in the model but come from the centre's lists of
 * Responsabilidades. A field points at an item (`list`) and its options are the leaves under it, in the list's order and named by
 * their path below the chosen item ("Ciencias › Física"), the way an activity names what it asks for; each option's key is the id
 * of the item. They are never stored in the model: a draft always reads the list as it is now, so a new item shows up and a
 * renamed one is renamed everywhere.
 *
 * Everything that reads the options of a definition (the resolver, the normalizers, the validator, the editors' JavaScript) goes
 * through the definition this service returns, with `options` filled:
 *
 *  - {@see live()} / {@see expand()}: for a draft. A selected item that is no longer on offer (deactivated, moved out of the list,
 *    deleted) stays among the options with `gone: true`, so the draft keeps it and the validator blocks the delivery until somebody
 *    picks another one — nothing is dropped without being seen.
 *  - {@see delivered()}: for a delivered document. The options are the ones it was delivered with ({@see snapshot()}), so renaming
 *    or deleting an item afterwards does not change a document that was approved.
 */
final class FormListOptions
{
    /** What an option of a list reads between the levels of its path. */
    private const string SEPARATOR = ' › ';

    /** How many selected items that are no longer offered one field keeps in sight. */
    private const int MAX_GONE = 100;

    public function __construct(
        private readonly ListItemRepository $items,
        private readonly TranslatorInterface $translator,
    ) {}

    /** Whether a string is the id of a list item (a lowercase UUID). */
    public static function isItemId(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1;
    }

    /**
     * The fields and columns of a definition that take their options from a list, with the item each one points at.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, string> item id by field or column key
     */
    public static function sources(array $definition): array
    {
        $sources = [];
        foreach (self::specs($definition) as $spec) {
            $list = FormDefinition::str($spec['list'] ?? '');
            if ($list !== '') {
                $sources[FormDefinition::str($spec['key'] ?? '')] = $list;
            }
        }

        return $sources;
    }

    /**
     * Whether anything of a definition comes from the centre's lists: the options of a field or column, the rows of a fixed table
     * (`rowsList`).
     *
     * @param array<string, mixed> $definition
     */
    public static function usesLists(array $definition): bool
    {
        if (self::sources($definition) !== []) {
            return true;
        }
        foreach (FormDefinition::blocks($definition) as $entry) {
            if (($entry['block']['type'] ?? '') === 'table' && FormDefinition::str($entry['block']['rowsList'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The definition of a model revision for filling it in: the options and the rows read from the lists as they are now.
     *
     * @param array<string, mixed>                              $data what is filled in (its selected items that are gone are kept among
     *                                                                the options, and its rows that are gone among the rows)
     * @param array{profile: string, subprofile: ?string}|null $slot who delivers it (ActivitySubmissionSlot::deliveredBy()): the rows
     *                                                                of a table that asks for it are only the items associated with them
     *
     * @return array<string, mixed>
     */
    public function live(FormModelRevision $revision, array $data = [], ?array $slot = null): array
    {
        return $this->expand($revision->getDefinition(), $revision->getModel()->getDocument()->getFolder()->getEducationalCentre(), $data, $slot);
    }

    /**
     * @param array<string, mixed>                              $definition
     * @param array<string, mixed>                              $data
     * @param array{profile: string, subprofile: ?string}|null $slot who delivers it; none (a blank model, a preview) is every item
     *
     * @return array<string, mixed>
     */
    public function expand(array $definition, EducationalCentre $centre, array $data = [], ?array $slot = null): array
    {
        if (!self::usesLists($definition)) {
            return $definition;
        }

        $cache = [];
        // The centre's tree is read at most once for the whole definition, whatever the number of fields and of selected values.
        $index = null;

        // The rows of a fixed table taken from a list: its items (those of who delivers it, when the table asks for that), plus the ones
        // that are no longer offered but still have something typed, marked `gone` (the validator blocks the delivery until they are
        // emptied).
        $definition = $this->mapTables($definition, function (array $block) use ($centre, $data, $slot, &$index): array {
            $list = FormDefinition::str($block['rowsList'] ?? '');
            if (($block['mode'] ?? '') !== 'fixed' || $list === '') {
                return $block;
            }
            $index ??= $this->items->indexByCentre($centre);
            $root    = self::isItemId($list) ? ($index[$list] ?? null) : null;
            $rows    = $root === null ? [] : self::forSlot($this->optionsOfItem($root), ($block['rowsOwn'] ?? true) === true ? $slot : null, $index);
            $known   = array_map(static fn (array $r): string => FormDefinition::str($r['key'] ?? ''), $rows);
            foreach (array_slice(self::rowsWithData($data, $block), 0, self::MAX_GONE) as $key) {
                if (self::isItemId($key) && !in_array($key, $known, true)) {
                    $rows[]  = $this->gone($key, $index[$key] ?? null);
                    $known[] = $key;
                }
            }
            $block['rows'] = $rows;

            return $block;
        });
        if (self::sources($definition) === []) {
            return $definition;
        }

        return $this->mapSpecs($definition, function (array $spec) use ($centre, $data, &$cache, &$index): array {
            $list = FormDefinition::str($spec['list'] ?? '');
            if ($list === '') {
                return $spec;
            }
            $index ??= $this->items->indexByCentre($centre);
            $root    = self::isItemId($list) ? ($index[$list] ?? null) : null;
            $options = $cache[$list] ??= ($root === null ? [] : $this->optionsOfItem($root));
            $known   = array_map(static fn (array $o): string => FormDefinition::str($o['key'] ?? ''), $options);
            // Only what looks like an item, and not without limit: the data of a draft comes from a browser.
            foreach (array_slice(self::selected($data, FormDefinition::str($spec['key'] ?? '')), 0, self::MAX_GONE) as $value) {
                if (self::isItemId($value) && !in_array($value, $known, true)) {
                    $options[] = $this->gone($value, $index[$value] ?? null);
                    $known[]   = $value;
                }
            }
            $spec['options'] = $options;

            return $spec;
        });
    }

    /**
     * The labels of the fields and columns that point at a list that offers nothing now (it does not exist in the centre, or it has
     * no active item): a model like that cannot be filled in.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    public function emptySources(array $definition, EducationalCentre $centre): array
    {
        $empty = [];
        $index = null;
        foreach (self::listSources($definition) as $spec) {
            $list = FormDefinition::str($spec['list'] ?? '');
            if ($list === '') {
                continue;
            }
            $index ??= $this->items->indexByCentre($centre);
            $root = self::isItemId($list) ? ($index[$list] ?? null) : null;
            if ($root === null || $this->optionsOfItem($root) === []) {
                $empty[] = FormDefinition::str($spec['label'] ?? '');
            }
        }

        return $empty;
    }

    /**
     * A definition ready to travel to another centre: the id of each list item (that centre's own) becomes the names that lead to it,
     * from the list's root (`listPath`).
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function toPaths(array $definition, EducationalCentre $centre): array
    {
        if (!self::usesLists($definition)) {
            return $definition;
        }

        $index = null;
        // The rows of a fixed table: `rowsList` → `rowsListPath`.
        $definition = $this->mapTables($definition, function (array $block) use ($centre, &$index): array {
            $list = FormDefinition::str($block['rowsList'] ?? '');
            if ($list === '') {
                return $block;
            }
            $index ??= $this->items->indexByCentre($centre);
            $item = self::isItemId($list) ? ($index[$list] ?? null) : null;
            unset($block['rowsList']);
            if ($item !== null) {
                $block['rowsListPath'] = self::pathOf($item);
            }

            return $block;
        });

        return $this->mapSpecs($definition, function (array $spec) use ($centre, &$index): array {
            $list = FormDefinition::str($spec['list'] ?? '');
            if ($list === '') {
                return $spec;
            }
            $index ??= $this->items->indexByCentre($centre);
            $item = self::isItemId($list) ? ($index[$list] ?? null) : null;
            unset($spec['list']);
            if ($item !== null) {
                $spec['listPath'] = self::pathOf($item);
            }

            return $spec;
        });
    }

    /** @return list<string> the names that lead to an item from the root of its list */
    private static function pathOf(ListItem $item): array
    {
        $names = [];
        for ($node = $item; $node !== null; $node = $node->getParent()) {
            array_unshift($names, $node->getName());
        }

        return $names;
    }

    /**
     * The other way round, for a definition that comes from a file: each `listPath` is looked up in this centre's lists. One that is
     * not there is left without a list (the field then has no options and the model cannot be sent to review until somebody links
     * it), and its label is reported.
     *
     * @param array<string, mixed> $definition
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the definition and the labels of the fields left unlinked
     */
    public function fromPaths(array $definition, EducationalCentre $centre): array
    {
        $unlinked = [];
        $index    = null;
        // The rows of a fixed table: `rowsListPath` → this centre's item, or no list (the table then has no rows until somebody links it).
        $definition = $this->mapTables($definition, function (array $block) use ($centre, &$unlinked, &$index): array {
            $path = array_values(array_filter(array_map('strval', array_filter(is_array($block['rowsListPath'] ?? null) ? $block['rowsListPath'] : [], 'is_scalar')), static fn (string $n): bool => $n !== ''));
            unset($block['rowsListPath'], $block['rowsList']);
            if ($path === []) {
                return $block;
            }
            $index ??= $this->items->indexByCentre($centre);
            $item  = $this->resolvePath($path, $index);
            if ($item === null) {
                $unlinked[] = FormDefinition::str($block['label'] ?? '');
            } else {
                $block['rowsList'] = $item->getId()->toRfc4122();
            }

            return $block;
        });
        $definition = $this->mapSpecs($definition, function (array $spec) use ($centre, &$unlinked, &$index): array {
            $path = array_values(array_filter(array_map('strval', array_filter(is_array($spec['listPath'] ?? null) ? $spec['listPath'] : [], 'is_scalar')), static fn (string $n): bool => $n !== ''));
            unset($spec['listPath']);
            if (!in_array($spec['fieldType'] ?? '', ['choice', 'multichoice'], true)) {
                unset($spec['list']);

                return $spec;
            }
            // An id that came in a file is of another centre's (or nobody's): only the path is trusted.
            unset($spec['list']);
            if ($path === []) {
                return $spec;
            }
            $index ??= $this->items->indexByCentre($centre);
            $item  = $this->resolvePath($path, $index);
            if ($item === null) {
                $unlinked[] = FormDefinition::str($spec['label'] ?? '');

                return $spec;
            }
            $spec['list']    = $item->getId()->toRfc4122();
            $spec['options'] = [];

            return $spec;
        });

        return [$definition, $unlinked];
    }

    /**
     * @param list<string>          $path  names from the root of a list
     * @param array<string, ListItem> $index the centre's items by id
     */
    private function resolvePath(array $path, array $index): ?ListItem
    {
        // Walked on the tree already in memory: roots are the items without parent, then each level by name.
        $current = null;
        foreach ($path as $name) {
            $match = null;
            foreach ($index as $item) {
                if ($item->getName() === $name && ($item->getParent()?->getId()->toRfc4122()) === $current?->getId()->toRfc4122()) {
                    $match = $item;
                    break;
                }
            }
            if ($match === null) {
                return null;
            }
            $current = $match;
        }

        return $current;
    }

    /**
     * The definition of a delivered document, with the options it was delivered with.
     *
     * @return array<string, mixed>
     */
    public function delivered(FormSubmissionRevision $filled): array
    {
        $definition = $filled->getModelRevision()->getDefinition();
        $snapshot   = $filled->getOptionsSnapshot() ?? [];
        if (!self::usesLists($definition)) {
            return $definition;
        }
        // The rows of a fixed table taken from a list: the ones it had when it was delivered, named as they were.
        $definition = $this->mapTables($definition, static function (array $block) use ($snapshot): array {
            if (($block['mode'] ?? '') === 'fixed' && FormDefinition::str($block['rowsList'] ?? '') !== '') {
                $block['rows'] = $snapshot[FormDefinition::str($block['key'] ?? '')] ?? [];
            }

            return $block;
        });

        return $this->mapSpecs($definition, static function (array $spec) use ($snapshot): array {
            if (FormDefinition::str($spec['list'] ?? '') !== '') {
                $spec['options'] = $snapshot[FormDefinition::str($spec['key'] ?? '')] ?? [];
            }

            return $spec;
        });
    }

    /**
     * What a delivery freezes: for each field taken from a list, the options that were selected, named as they are now.
     *
     * @param array<string, mixed> $expanded a definition from {@see expand()}
     * @param array<string, mixed> $data
     *
     * @return array<string, list<array{key: string, label: string}>>
     */
    public function snapshot(array $expanded, array $data): array
    {
        $snapshot = [];
        // A fixed table taken from a list: ALL its rows (an empty one is still a row of the document), named as they are now.
        foreach (FormDefinition::blocks($expanded) as $entry) {
            $block = $entry['block'];
            if (($block['type'] ?? '') === 'table' && ($block['mode'] ?? '') === 'fixed' && FormDefinition::str($block['rowsList'] ?? '') !== '') {
                $rows = [];
                foreach (FormDefinition::listOf($block['rows'] ?? []) as $row) {
                    if (($row['gone'] ?? false) !== true) {
                        $rows[] = ['key' => FormDefinition::str($row['key'] ?? ''), 'label' => FormDefinition::str($row['label'] ?? '')];
                    }
                }
                $snapshot[FormDefinition::str($block['key'] ?? '')] = $rows;
            }
        }
        foreach (self::specs($expanded) as $spec) {
            if (FormDefinition::str($spec['list'] ?? '') === '') {
                continue;
            }
            $key      = FormDefinition::str($spec['key'] ?? '');
            $selected = self::selected($data, $key);
            $kept     = [];
            foreach (FormDefinition::listOf($spec['options'] ?? []) as $option) {
                $optionKey = FormDefinition::str($option['key'] ?? '');
                if (in_array($optionKey, $selected, true) && ($option['gone'] ?? false) !== true) {
                    $kept[] = ['key' => $optionKey, 'label' => FormDefinition::str($option['label'] ?? '')];
                }
            }
            if ($kept !== []) {
                $snapshot[$key] = $kept;
            }
        }

        return $snapshot;
    }

    /**
     * The options a list item offers now: its active leaves (or the item itself when it has none below), in the list's order.
     *
     * @return list<array<string, mixed>> `{key, label}` each
     */
    public function optionsOf(string $itemId, EducationalCentre $centre): array
    {
        if (!self::isItemId($itemId)) {
            return [];
        }
        $root = $this->items->indexByCentre($centre)[$itemId] ?? null;

        return $root === null ? [] : $this->optionsOfItem($root);
    }

    /** @return list<array<string, mixed>> */
    private function optionsOfItem(ListItem $root): array
    {
        $options = [];
        foreach ($this->items->findLeafDescendants($root) as $leaf) {
            $label = $this->pathBelow($leaf, $root);
            if ($label !== null) {
                $options[] = ['key' => $leaf->getId()->toRfc4122(), 'label' => $label];
            }
        }

        return $options;
    }

    /**
     * The items of the centre that can feed a field (the ones that have something below them), with the options each would offer, for
     * the model editor's picker.
     *
     * @return list<array{id: string, label: string, options: list<array<string, mixed>>}>
     */
    public function pickable(EducationalCentre $centre): array
    {
        // The centre's tree is read once (the repository keeps it): the picker costs the same for one list or fifty.
        $all = $this->items->indexByCentre($centre);
        $has = [];
        foreach ($all as $item) {
            $parent = $item->getParent();
            if ($parent !== null) {
                $has[$parent->getId()->toRfc4122()] = true;
            }
        }

        $out = [];
        foreach ($all as $id => $item) {
            if (!isset($has[$id])) {
                continue;
            }
            $names = [];
            for ($node = $item; $node !== null; $node = $node->getParent()) {
                array_unshift($names, $node->getName());
            }
            $out[] = ['id' => $id, 'label' => implode(self::SEPARATOR, $names), 'options' => $this->optionsOfItem($item)];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * The name of a leaf below a chosen item (its path under it), or null when the leaf is not on offer: it is inactive or hangs
     * from an inactive item below the chosen one.
     */
    private function pathBelow(ListItem $leaf, ListItem $root): ?string
    {
        $rootId = $root->getId();
        if ($leaf->getId()->equals($rootId)) {
            return $leaf->getName();
        }
        $names = [];
        for ($node = $leaf; $node !== null && !$node->getId()->equals($rootId); $node = $node->getParent()) {
            if (!$node->isActive()) {
                return null;
            }
            array_unshift($names, $node->getName());
        }

        return implode(self::SEPARATOR, $names);
    }

    /** @return array<string, mixed> the option of a selected item that is no longer offered */
    private function gone(string $value, ?ListItem $item): array
    {
        return [
            'key'   => $value,
            'label' => $item === null
                ? $this->translator->trans('options.gone_missing', [], 'forms')
                : $this->translator->trans('options.gone_inactive', ['%name%' => $item->getName()], 'forms'),
            'gone'  => true,
        ];
    }

    /**
     * What is selected under a key, anywhere in the data (a field of a section or of a copy, a cell of a row).
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    private static function selected(array $data, string $key): array
    {
        $found = [];
        foreach ($data as $name => $value) {
            if ((string) $name === $key) {
                foreach (is_array($value) ? $value : [$value] as $one) {
                    if (is_string($one) && $one !== '') {
                        $found[] = $one;
                    }
                }
            } elseif (is_array($value)) {
                array_push($found, ...self::selected($value, $key));
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * The items of a list that belong to who delivers a document: those whose associations (their own, or the ones they inherit, as
     * everywhere in the application: ListItem::getEffectiveProfileAssociations()) name the slot's profile — through its subprofile or one
     * of the subprofile's ancestors, for a profile of a list; with no subprofile, for a plain one. When none does, all of them: a
     * profile that is not tied to that list sees the whole list.
     *
     * @param list<array<string, mixed>>                        $options `{key, label}` of the items, key = the item's id
     * @param array{profile: string, subprofile: ?string}|null $slot
     * @param array<string, ListItem>                           $index   the centre's items by id
     *
     * @return list<array<string, mixed>>
     */
    public static function forSlot(array $options, ?array $slot, array $index): array
    {
        if ($slot === null) {
            return $options;
        }
        // The subprofile and the items above it: an association to any of them covers it.
        $covering = [];
        for ($node = $slot['subprofile'] === null ? null : ($index[$slot['subprofile']] ?? null); $node !== null; $node = $node->getParent()) {
            $covering[$node->getId()->toRfc4122()] = true;
        }
        $own = array_values(array_filter($options, static function (array $option) use ($slot, $index, $covering): bool {
            $item = $index[FormDefinition::str($option['key'] ?? '')] ?? null;
            foreach ($item?->getEffectiveProfileAssociations() ?? [] as $association) {
                if ($association->getSpecificProfile()->getId()->toRfc4122() !== $slot['profile']) {
                    continue;
                }
                $sub = $association->getSubprofile()?->getId()->toRfc4122();
                if ($slot['subprofile'] === null ? $sub === null : ($sub !== null && isset($covering[$sub]))) {
                    return true;
                }
            }

            return false;
        }));

        return $own === [] ? $options : $own;
    }

    /**
     * The keys of the rows of a table that have something typed in them, wherever the table is in the data (the document or a copy of a
     * repeating section). What a calculated column holds is not typed: it is filled in on every row.
     *
     * @param array<mixed>         $data
     * @param array<string, mixed> $block
     *
     * @return list<string>
     */
    private static function rowsWithData(array $data, array $block): array
    {
        $key      = FormDefinition::str($block['key'] ?? '');
        $computed = [];
        foreach (FormDefinition::listOf($block['columns'] ?? []) as $column) {
            if (($column['fieldType'] ?? '') === 'calculated') {
                $computed[FormDefinition::str($column['key'] ?? '')] = true;
            }
        }
        $found = [];
        $visit = static function (array $node) use (&$visit, &$found, $key, $computed): void {
            foreach ($node as $name => $value) {
                if (!is_array($value)) {
                    continue;
                }
                if ((string) $name === $key && array_is_list($value)) {
                    foreach ($value as $row) {
                        if (!is_array($row) || !is_string($row['_key'] ?? null)) {
                            continue;
                        }
                        foreach ($row as $cell => $content) {
                            if (!str_starts_with((string) $cell, '_') && !isset($computed[(string) $cell]) && $content !== null && $content !== '' && $content !== []) {
                                $found[] = $row['_key'];
                                break;
                            }
                        }
                    }
                } else {
                    $visit($value);
                }
            }
        };
        $visit($data);

        return array_values(array_unique($found));
    }

    /**
     * Everything that points at a list item, for the checks of a model: the fields and columns with options from a list, and the fixed
     * tables with their rows from one (as `{label, list}`).
     *
     * @param array<string, mixed> $definition
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function listSources(array $definition): \Generator
    {
        yield from self::specs($definition);
        foreach (FormDefinition::blocks($definition) as $entry) {
            $block = $entry['block'];
            if (($block['type'] ?? '') === 'table' && ($block['mode'] ?? '') === 'fixed' && FormDefinition::str($block['rowsList'] ?? '') !== '') {
                yield ['label' => FormDefinition::str($block['label'] ?? ''), 'list' => FormDefinition::str($block['rowsList'])];
            }
        }
    }

    /**
     * @param array<string, mixed>                                  $definition
     * @param \Closure(array<string, mixed>): array<string, mixed> $change applied to every table block
     *
     * @return array<string, mixed>
     */
    private function mapTables(array $definition, \Closure $change): array
    {
        $map = static function (mixed $sections) use (&$map, $change): array {
            $out = [];
            foreach (FormDefinition::listOf($sections) as $section) {
                $blocks = [];
                foreach (FormDefinition::listOf($section['blocks'] ?? []) as $block) {
                    $blocks[] = ($block['type'] ?? '') === 'table' ? $change($block) : $block;
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
     * @param array<string, mixed> $definition
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function specs(array $definition): \Generator
    {
        foreach (FormDefinition::blocks($definition) as $entry) {
            $block = $entry['block'];
            if (($block['type'] ?? '') === 'field') {
                yield $block;
            } elseif (($block['type'] ?? '') === 'table') {
                yield from FormDefinition::listOf($block['columns'] ?? []);
            }
        }
    }

    /**
     * @param array<string, mixed>                                  $definition
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private function mapSpecs(array $definition, \Closure $change): array
    {
        $definition['sections'] = $this->mapSections($definition['sections'] ?? [], $change);

        return $definition;
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     *
     * @return list<array<string, mixed>>
     */
    private function mapSections(mixed $sections, \Closure $change): array
    {
        $out = [];
        foreach (FormDefinition::listOf($sections) as $section) {
            $blocks = [];
            foreach (FormDefinition::listOf($section['blocks'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'field') {
                    $block = $change($block);
                } elseif (($block['type'] ?? '') === 'table') {
                    $block['columns'] = array_map($change, FormDefinition::listOf($block['columns'] ?? []));
                }
                $blocks[] = $block;
            }
            $section['blocks']   = $blocks;
            $section['children'] = $this->mapSections($section['children'] ?? [], $change);
            $out[]               = $section;
        }

        return $out;
    }
}
