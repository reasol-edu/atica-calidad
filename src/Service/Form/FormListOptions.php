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
     * The definition of a model revision for filling it in: the options read from the lists as they are now.
     *
     * @param array<string, mixed> $data what is filled in (its selected items that are gone are kept among the options)
     *
     * @return array<string, mixed>
     */
    public function live(FormModelRevision $revision, array $data = []): array
    {
        return $this->expand($revision->getDefinition(), $revision->getModel()->getDocument()->getFolder()->getEducationalCentre(), $data);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function expand(array $definition, EducationalCentre $centre, array $data = []): array
    {
        if (self::sources($definition) === []) {
            return $definition;
        }

        $cache = [];

        return $this->mapSpecs($definition, function (array $spec) use ($centre, $data, &$cache): array {
            $list = FormDefinition::str($spec['list'] ?? '');
            if ($list === '') {
                return $spec;
            }
            $options = $cache[$list] ??= $this->optionsOf($list, $centre);
            $known   = array_map(static fn (array $o): string => FormDefinition::str($o['key'] ?? ''), $options);
            // Only what looks like an item, and not without limit: the data of a draft comes from a browser.
            foreach (array_slice(self::selected($data, FormDefinition::str($spec['key'] ?? '')), 0, self::MAX_GONE) as $value) {
                if (self::isItemId($value) && !in_array($value, $known, true)) {
                    $options[] = $this->gone($value, $centre);
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
        foreach (self::specs($definition) as $spec) {
            $list = FormDefinition::str($spec['list'] ?? '');
            if ($list !== '' && $this->optionsOf($list, $centre) === []) {
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
        if (self::sources($definition) === []) {
            return $definition;
        }

        return $this->mapSpecs($definition, function (array $spec) use ($centre): array {
            $list = FormDefinition::str($spec['list'] ?? '');
            if ($list === '') {
                return $spec;
            }
            $item = self::isItemId($list) ? $this->items->findByIdAndCentre($list, $centre) : null;
            unset($spec['list']);
            if ($item !== null) {
                $names = [];
                for ($node = $item; $node !== null; $node = $node->getParent()) {
                    array_unshift($names, $node->getName());
                }
                $spec['listPath'] = $names;
            }

            return $spec;
        });
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
        $definition = $this->mapSpecs($definition, function (array $spec) use ($centre, &$unlinked): array {
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
            $item = $this->resolvePath($path, $centre);
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

    /** @param list<string> $path names from the root of a list */
    private function resolvePath(array $path, EducationalCentre $centre): ?ListItem
    {
        $siblings = $this->items->findRootsByCentre($centre);
        $current  = null;
        foreach ($path as $name) {
            $match = null;
            foreach ($siblings as $sibling) {
                if ($sibling->getName() === $name) {
                    $match = $sibling;
                    break;
                }
            }
            if ($match === null) {
                return null;
            }
            $current  = $match;
            $siblings = $this->items->findChildrenByParent($match);
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
        if (self::sources($definition) === []) {
            return $definition;
        }

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
        $root = $this->items->findByIdAndCentre($itemId, $centre);
        if ($root === null) {
            return [];
        }

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
        $all = $this->items->findAllByCentre($centre);
        $has = [];
        foreach ($all as $item) {
            $parent = $item->getParent();
            if ($parent !== null) {
                $has[$parent->getId()->toRfc4122()] = true;
            }
        }

        $out = [];
        foreach ($all as $item) {
            $id = $item->getId()->toRfc4122();
            if (!isset($has[$id])) {
                continue;
            }
            $names = [];
            for ($node = $item; $node !== null; $node = $node->getParent()) {
                array_unshift($names, $node->getName());
            }
            $out[] = ['id' => $id, 'label' => implode(self::SEPARATOR, $names), 'options' => $this->optionsOf($id, $centre)];
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
    private function gone(string $value, EducationalCentre $centre): array
    {
        $item = self::isItemId($value) ? $this->items->findByIdAndCentre($value, $centre) : null;

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
