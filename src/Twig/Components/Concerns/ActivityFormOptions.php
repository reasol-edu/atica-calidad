<?php

declare(strict_types=1);

namespace App\Twig\Components\Concerns;

use App\Entity\Activity;
use App\Entity\ActivitySubmissionScope;
use App\Entity\AllowedFileFormat;
use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Model\ActivityDeadlineSummary;
use App\Model\ActivitySubmissionSlot;
use App\Model\ProfileAssignmentRow;
use App\Service\ActivitySubmissionFilenameBuilder;
use App\Service\Form\ActivityDeliverySources;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;

/**
 * Activity add/edit form state: the form's LiveProps, the option lists it offers and its live previews.
 */
trait ActivityFormOptions
{
    // ── Activity add/edit form (RESPONSIBILITIES-gated) ─────────────────────
    #[LiveProp(writable: true)]
    public bool $activityFormOpen = false;

    /** '' while adding a brand-new activity; otherwise the id of the one being edited. */
    #[LiveProp(writable: true)]
    public string $formActivityId = '';

    #[LiveProp(writable: true)]
    public string $formTitle = '';

    #[LiveProp(writable: true)]
    public string $formDescription = '';

    /** Overrides the activity's own title as the prefix a submission's downloaded filename leads with — see FolderController::downloadNameParts(). Empty means "use the title", the default. */
    #[LiveProp(writable: true)]
    public string $formSubmissionPrefix = '';

    #[LiveProp(writable: true)]
    public string $formStartDay = '';

    #[LiveProp(writable: true)]
    public string $formStartMonth = '';

    #[LiveProp(writable: true)]
    public string $formEndDay = '';

    #[LiveProp(writable: true)]
    public string $formEndMonth = '';

    #[LiveProp(writable: true, onUpdated: 'onFormFolderIdChanged')]
    public string $formFolderId = '';

    /**
     * The picked folder's own profile lists, editable from the form (saved onto the folder with the
     * activity): who manages it, who is asked to upload, who sees it, who reviews. ProfileAssignmentRow
     * keys; loaded from the folder whenever it is picked — see loadFolderProfileKeys().
     *
     * @var string[]
     */
    #[LiveProp(writable: true)]
    public array $formFolderResponsibleKeys = [];

    /** @var string[] */
    #[LiveProp(writable: true)]
    public array $formFolderUploadKeys = [];

    /** @var string[] */
    #[LiveProp(writable: true)]
    public array $formFolderVisibilityKeys = [];

    /** @var string[] */
    #[LiveProp(writable: true)]
    public array $formFolderReviewKeys = [];

    /**
     * Whether the activity collects documents (and so has a folder) rather than being a plain
     * manual reminder — decides which fields the form shows. Switching it off clears the folder.
     */
    #[LiveProp(writable: true, onUpdated: 'onFormWithSubmissionsChanged')]
    public bool $formWithSubmissions = false;

    /** Whether the form's "advanced" section (auto-complete, hidden) is expanded. */
    #[LiveProp]
    public bool $advancedOpen = false;

    /** Bumped on every save attempt: lets the form scroll to its first error each time Save fails. */
    #[LiveProp]
    public int $saveAttempt = 0;

    /** Shown under the folder picker when picking another folder discarded unsaved folder-profile edits. */
    #[LiveProp]
    public string $folderProfilesNotice = '';

    /** The folder whose profiles the four formFolder*Keys lists were loaded from ('' for none). */
    #[LiveProp]
    public string $formLoadedFolderId = '';

    /** Whether the inline "create a new folder" panel is open, in place of picking an existing one. */
    #[LiveProp(writable: true)]
    public bool $creatingFolder = false;

    #[LiveProp(writable: true)]
    public string $newFolderName = '';

    #[LiveProp(writable: true)]
    public string $newFolderSectionId = '';

    #[LiveProp(writable: true, onUpdated: 'onFormListItemIdChanged')]
    public string $formListItemId = '';

    /**
     * Per-leaf deadline overrides of $formListItemId's leaf descendants, keyed by leaf id — see
     * Activity::getDeadlineOverride(). A leaf absent here (or with any of its 4 fields blank) uses
     * the activity's own $formStartDay/etc instead; only a leaf with all 4 fields filled gets a
     * row in ActivityListItemDeadline on save.
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $formOverrideStartDay = [];

    /** @var array<string, string> */
    #[LiveProp(writable: true)]
    public array $formOverrideStartMonth = [];

    /** @var array<string, string> */
    #[LiveProp(writable: true)]
    public array $formOverrideEndDay = [];

    /** @var array<string, string> */
    #[LiveProp(writable: true)]
    public array $formOverrideEndMonth = [];

    /** @var string[] related document ids, in the order they were added */
    #[LiveProp(writable: true)]
    public array $formRelatedDocumentIds = [];

    #[LiveProp(writable: true)]
    public string $relatedDocumentSearchQuery = '';

    #[LiveProp(writable: true)]
    public bool $formRequired = true;

    #[LiveProp(writable: true)]
    public bool $formAutoComplete = false;

    #[LiveProp(writable: true)]
    public bool $formStartDateEnforced = false;

    /** Not `norender`: toggling it must reveal/hide the grace-days field. */
    #[LiveProp(writable: true)]
    public bool $formEndDateEnforced = false;

    #[LiveProp(writable: true)]
    public string $formEndDateGraceDays = '0';

    #[LiveProp(writable: true)]
    public string $formScope = 'by_profile';

    /** The document model the activity is delivered by filling in ('' = uploading a file, the usual way). */
    #[LiveProp(writable: true)]
    public string $formModelId = '';

    /** Everybody's draft is created when the period opens (only with a document model). */
    #[LiveProp(writable: true)]
    public bool $formDraftsForAll = false;

    /**
     * With a catalogue element: the documents are named with its whole path under it («Ciencias › Física», true, what it always did)
     * or only with the leaf's own name («Física», false) — see Activity::isSubmissionNameFullPath().
     */
    #[LiveProp(writable: true)]
    public bool $formNameFullPath = true;

    /**
     * With a catalogue element and a shared (not individual) delivery: the teachers associated with each element also fill in and
     * deliver it — see Activity::isTeachersDeliver().
     */
    #[LiveProp(writable: true)]
    public bool $formTeachersDeliver = false;

    /**
     * «Una entrega por»: the lists the submissions are multiplied by (ids of catalogue nodes, in order — see ActivityDimension).
     *
     * @var list<string>
     */
    #[LiveProp(writable: true)]
    public array $formDimensionIds = [];

    /** The one of $formDimensionIds whose values each have their own dates ('' = none). */
    #[LiveProp(writable: true, onUpdated: 'onFormDimensionsChanged')]
    public string $formDatedDimensionId = '';

    /** With a list element: one submission per element (true) or one per owner gathering its elements. */
    #[LiveProp(writable: true)]
    public bool $formSplitByElement = true;

    /** With «Una entrega por» and a model: each new draft starts with what was delivered in the previous value (the 2ª from the 1ª). */
    #[LiveProp(writable: true)]
    public bool $formPrefillPrevious = true;

    /** The «Añadir lista» picker: a node picked there is added to $formDimensionIds and the picker empties. */
    #[LiveProp(writable: true, onUpdated: 'onFormDimensionToAdd')]
    public string $formDimensionToAdd = '';

    /** The other form activity whose delivered documents pre-fill this one ('' = only last year's own). */
    #[LiveProp(writable: true)]
    public string $formPrefillSourceId = '';

    /**
     * For each table of deliveries of the model: the activity its rows come from (by the table's key; '' = not chosen yet).
     *
     * @var array<string, string>
     */
    #[LiveProp(writable: true)]
    public array $formDeliverySources = [];

    /** Hidden from everyone but whoever can edit activities, and counted nowhere — see Activity::isHidden(). */
    #[LiveProp(writable: true)]
    public bool $formHidden = false;

    /** Meaningful only when formFolderId === '' (a manual activity): applies to every teacher when true. */
    #[LiveProp(writable: true)]
    public bool $formGeneral = true;

    /** @var string[] ProfileAssignmentRow keys, meaningful only when formFolderId === '' and !formGeneral. */
    #[LiveProp(writable: true)]
    public array $formProfileKeys = [];

    /** @var string[] ProfileAssignmentRow keys of the activity's own responsible profiles, meaningful only when formFolderId === ''. */
    #[LiveProp(writable: true)]
    public array $formResponsibleProfileKeys = [];

    #[LiveProp(writable: true)]
    public string $confirmingDeleteActivityId = '';

    /** '' when not confirming; otherwise "{activityId}:{profileId}:{listItemId}" of the completion pending confirmation. */
    #[LiveProp(writable: true)]
    public string $confirmingCompleteKey = '';

    // ── Activity add/edit form ───────────────────────────────────────────────

    /** @return Folder[] folders not yet linked to another activity, plus the one $formActivityId is currently linked to (if editing). */
    public function getAvailableFolders(): array
    {
        $editing = $this->formActivityId === '' ? null : $this->findActivity($this->formActivityId);

        return array_values(array_filter(
            $this->folders->findAllByCentre($this->centre),
            static fn (Folder $f): bool => $f->getActivity() === null || $f->getActivity() === $editing,
        ));
    }

    public function getFolderLabel(Folder $folder): string
    {
        $trail = [];
        for ($section = $folder->getDocumentSection(); $section !== null; $section = $section->getParent()) {
            array_unshift($trail, $section->getName());
        }
        $trail[] = $folder->getName();

        return implode(' › ', $trail);
    }

    /** @return DocumentSection[] every section of the centre's document tree — where a new folder created from this form can be placed. */
    public function getAvailableDocumentSections(): array
    {
        return $this->sections->findAllByCentre($this->centre);
    }

    public function getSectionLabel(DocumentSection $section): string
    {
        $trail = [];
        for ($node = $section; $node !== null; $node = $node->getParent()) {
            array_unshift($trail, $node->getName());
        }

        return implode(' › ', $trail);
    }

    public function getDeadlineSummary(Activity $activity): ActivityDeadlineSummary
    {
        return $this->deadlineSummaries->for($activity);
    }

    /** @return ListItem[] */
    public function getAvailableListItems(): array
    {
        // findAllByCentre() only orders by position among siblings, which interleaves different
        // lists and levels: walk the tree instead (each list, then its children by position, depth
        // first), so the picker reads like the lists themselves — and ties in a search keep that order.
        $byParent = [];
        foreach ($this->listItems->findAllByCentre($this->centre) as $item) {
            $byParent[$item->getParent()?->getId()->toRfc4122() ?? ''][] = $item;
        }

        $ordered = [];
        $walk    = static function (string $parentId) use (&$walk, &$ordered, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $item) {
                $ordered[] = $item;
                $walk($item->getId()->toRfc4122());
            }
        };
        $walk('');

        return $ordered;
    }

    public function getListItemLabel(ListItem $item): string
    {
        $trail = [];
        for ($node = $item; $node !== null; $node = $node->getParent()) {
            array_unshift($trail, $node->getName());
        }

        return implode(' › ', $trail);
    }

    /**
     * Leaf descendants of the activity form's currently selected list item — one deadline-override
     * row is offered per leaf (see Activity::getDeadlineOverride()). Empty while the form has no
     * list item selected, same as an activity without one never splitting its submissions by leaf.
     *
     * @return ListItem[]
     */
    public function getFormListItemLeaves(): array
    {
        // With a list that marks the dates («Evaluación»), the dates are its values' — one row each — and not the elements'.
        $dated = $this->getFormDatedDimension();
        if ($dated !== null) {
            return $this->listItems->findLeafDescendants($dated);
        }
        if ($this->formListItemId === '') {
            return [];
        }

        $root = $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);

        return $root === null ? [] : $this->listItems->findLeafDescendants($root);
    }

    /** @return list<ListItem> the lists of «Una entrega por», in order — those that still exist in the centre */
    public function getFormDimensionLists(): array
    {
        $lists = [];
        foreach (array_unique(array_filter($this->formDimensionIds, 'is_string')) as $id) {
            $list = $this->listItems->findByIdAndCentre($id, $this->centre);
            if ($list !== null) {
                $lists[] = $list;
            }
        }

        return $lists;
    }

    /** The list of «Una entrega por» that marks the dates, if one is chosen (and still one of them). */
    public function getFormDatedDimension(): ?ListItem
    {
        if ($this->formDatedDimensionId === '' || !in_array($this->formDatedDimensionId, $this->formDimensionIds, true) || $this->formFolderId === '') {
            return null;
        }

        return $this->listItems->findByIdAndCentre($this->formDatedDimensionId, $this->centre);
    }

    /**
     * Each list of «Una entrega por» as the form shows it: its path, whether it is associated with the activity's list (each element
     * takes its own values) or free (every element, all of them), whether it marks the dates, and how many values it has.
     *
     * @return list<array{id: string, label: string, associated: bool, dated: bool, values: int}>
     */
    public function getFormDimensions(): array
    {
        $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
        $rows     = [];
        foreach ($this->getFormDimensionLists() as $list) {
            $id     = $list->getId()->toRfc4122();
            $rows[] = [
                'id'         => $id,
                'label'      => $this->getListItemLabel($list),
                'associated' => $listItem !== null && $listItem->hasLinkedList($list),
                'dated'      => $id === $this->formDatedDimensionId,
                'values'     => \count($this->listItems->findLeafDescendants($list)),
            ];
        }

        return $rows;
    }

    /** @return list<ListItem> what «Añadir lista» offers: the catalogue's nodes with leaves, not the activity's list nor already chosen */
    public function getFormDimensionChoices(): array
    {
        $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
        $chosen   = array_flip($this->formDimensionIds);

        return array_values(array_filter(
            $this->getAvailableListItems(),
            static fn (ListItem $item): bool => !$item->isLeaf() && !isset($chosen[$item->getId()->toRfc4122()])
                && ($listItem === null || (!$item->isUnder($listItem) && !$listItem->isUnder($item))),
        ));
    }

    /**
     * For each associated list of «Una entrega por», how many elements of the activity's list have none of its values: they ask for
     * nothing («37 materias sin Evaluación no piden entrega»).
     *
     * @return list<array{list: string, count: int}>
     */
    public function getFormElementsWithoutValues(): array
    {
        $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
        if ($listItem === null || $this->formFolderId === '') {
            return [];
        }
        $out    = [];
        $leaves = $this->listItems->findLeafDescendants($listItem);
        foreach ($this->getFormDimensionLists() as $list) {
            if (!$listItem->hasLinkedList($list)) {
                continue;
            }
            $count = 0;
            foreach ($leaves as $leaf) {
                if ($this->submissionSlots->valuesOf($leaf, $listItem, [$list])[0] === []) {
                    ++$count;
                }
            }
            if ($count > 0) {
                $out[] = ['list' => $list->getRoot()->getName(), 'count' => $count];
            }
        }

        return $out;
    }

    /** LiveProp(onUpdated:) of «Añadir lista»: the picked node joins «Una entrega por» (at most ActivityDimension::MAX), the picker empties. */
    public function onFormDimensionToAdd(): void
    {
        $id                       = $this->formDimensionToAdd;
        $this->formDimensionToAdd = '';
        foreach ($this->getFormDimensionChoices() as $choice) {
            if ($choice->getId()->toRfc4122() === $id && \count($this->formDimensionIds) < \App\Entity\ActivityDimension::MAX) {
                $this->formDimensionIds[] = $id;
            }
        }
        $this->onFormDimensionsChanged();
    }

    /** The rows of the dates follow the list that marks them: they are seeded again. */
    public function onFormDimensionsChanged(): void
    {
        $this->seedOverrideArraysForCurrentListItem();
    }

    #[\Symfony\UX\LiveComponent\Attribute\LiveAction]
    public function removeFormDimension(#[\Symfony\UX\LiveComponent\Attribute\LiveArg] string $id): void
    {
        $this->formDimensionIds = array_values(array_filter($this->formDimensionIds, static fn (string $d): bool => $d !== $id));
        if ($this->formDatedDimensionId === $id) {
            $this->formDatedDimensionId = '';
        }
        $this->onFormDimensionsChanged();
    }

    #[\Symfony\UX\LiveComponent\Attribute\LiveAction]
    public function moveFormDimension(#[\Symfony\UX\LiveComponent\Attribute\LiveArg] string $id, #[\Symfony\UX\LiveComponent\Attribute\LiveArg] int $direction): void
    {
        $ids   = $this->formDimensionIds;
        $index = array_search($id, $ids, true);
        $to    = $index === false ? null : $index + ($direction < 0 ? -1 : 1);
        if ($index === false || $to === null || !isset($ids[$to])) {
            return;
        }
        [$ids[$index], $ids[$to]] = [$ids[$to], $ids[$index]];
        $this->formDimensionIds   = array_values($ids);
    }

    /**
     * Whether the activity being edited already has something delivered or drafted this academic year: then how its submissions are
     * split («Una entrega por», its element, whether elements are gathered) can no longer change — every key would.
     */
    public function isFormSplitLocked(): bool
    {
        $activity = $this->formActivityId === '' ? null : $this->findActivity($this->formActivityId);
        if ($activity === null) {
            return false;
        }
        $folder = $activity->getFolder();
        $cycle  = $this->deadline->currentCycleKey($activity);

        return ($folder !== null && $this->documents->findByFolderAndCycleYearWithRevisions($folder, $cycle) !== [])
            || $this->formSubmissions->findByActivityCycle($activity, $cycle) !== [];
    }

    /**
     * Re-keys the four override LiveProps to exactly the current $formListItemId's leaves — every
     * leaf gets an entry (blank unless it already had one), and a leaf that's no longer under the
     * selected list item is dropped. Needed because LiveComponent's array-prop bracket binding
     * (data-model="...[leafId]") can only ever target a key the prop's *initial, server-rendered*
     * JSON for that leaf already has: an empty array serialises as JSON `[]`, not `{}`, so a brand
     * new leaf with no prior entry is otherwise unreachable from the browser. Called by
     * onFormListItemIdChanged() (see LiveProp(onUpdated:) above) whenever the list item changes
     * interactively, and once more from startEditActivity() for the form's very first render.
     */
    private function seedOverrideArraysForCurrentListItem(): void
    {
        $leafIds = array_map(static fn (ListItem $l): string => $l->getId()->toRfc4122(), $this->getFormListItemLeaves());

        $this->formOverrideStartDay   = $this->reseedOverrideArray($this->formOverrideStartDay, $leafIds);
        $this->formOverrideStartMonth = $this->reseedOverrideArray($this->formOverrideStartMonth, $leafIds);
        $this->formOverrideEndDay     = $this->reseedOverrideArray($this->formOverrideEndDay, $leafIds);
        $this->formOverrideEndMonth   = $this->reseedOverrideArray($this->formOverrideEndMonth, $leafIds);
    }

    /**
     * @param  array<string, string> $current
     * @param  string[]              $leafIds
     * @return array<string, string>
     */
    private function reseedOverrideArray(array $current, array $leafIds): array
    {
        $seeded = [];
        foreach ($leafIds as $leafId) {
            $seeded[$leafId] = $current[$leafId] ?? '';
        }

        return $seeded;
    }

    /**
     * The two ways of naming a document, with a real example from the chosen element: its deepest leaf (where they differ the most),
     * as «Ciencias › Física» and as «Física». Null without an element, or when its leaves are all direct children (both read the same).
     *
     * @return array{full: string, leaf: string}|null
     */
    public function getNameModeExamples(): ?array
    {
        $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
        if ($listItem === null) {
            return null;
        }
        $best = null;
        foreach ($this->listItems->findLeafDescendants($listItem) as $leaf) {
            $full = $this->submissionSlots->submissionName($listItem, $leaf);
            if ($best === null || mb_strlen($full) > mb_strlen($best['full'])) {
                $best = ['full' => $full, 'leaf' => $leaf->getName()];
            }
        }

        return $best;
    }

    /**
     * What a submission of the activity being edited would be downloaded as, from the form as it
     * stands (title, prefix, scope, folder upload profiles, list element, deadline): the same
     * naming rule as a real download (ActivitySubmissionFilenameBuilder::compose()), on an example
     * submission — the first element of the chosen list, else the first upload profile. Null for a
     * manual activity, which has no submissions.
     */
    public function getSubmissionNamePreview(): ?string
    {
        $category = $this->getCurrentCategory();
        if ($this->formFolderId === '' || $category === null) {
            return null;
        }

        $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
        $leaves   = $listItem === null ? [] : $this->listItems->findLeafDescendants($listItem);
        if ($listItem !== null && $leaves !== []) {
            // The deepest leaf: the one where the two ways of naming differ the most.
            $sampleLeaf = $leaves[0];
            foreach ($leaves as $leaf) {
                if (mb_strlen($this->submissionSlots->submissionName($listItem, $leaf)) > mb_strlen($this->submissionSlots->submissionName($listItem, $sampleLeaf))) {
                    $sampleLeaf = $leaf;
                }
            }
            $documentName = $this->submissionSlots->submissionName($listItem, $sampleLeaf, $this->formNameFullPath);
        } else {
            $documentName = $this->t('activity.field.submission_preview_example_name');
            foreach ($this->getAvailableProfileRows() as $row) {
                if ($this->formFolderUploadKeys !== [] && $row->key() === $this->formFolderUploadKeys[0]) {
                    $documentName = $row->displayName;
                    break;
                }
            }
        }

        // A throwaway Activity (never persisted) just to ask which academic year "now" falls in.
        $startDay = (int) $this->formStartDay;
        $endDay   = (int) $this->formEndDay;
        $valid    = $startDay >= 1 && $startDay <= 31 && $endDay >= 1 && $endDay <= 31
            && (int) $this->formStartMonth >= 1 && (int) $this->formStartMonth <= 12
            && (int) $this->formEndMonth >= 1 && (int) $this->formEndMonth <= 12;
        $example = (new Activity())->setCategory($category)->setStart($valid ? $startDay : 1, $valid ? (int) $this->formStartMonth : 9)
            ->setEnd($valid ? $endDay : 30, $valid ? (int) $this->formEndMonth : 6);

        $teacher      = $this->teacher()->getName();
        $teacherLabel = $this->formScope === 'individual' ? $teacher->getLastName() . ', ' . $teacher->getFirstName() : null;
        $title        = trim($this->formTitle);
        $prefix       = trim($this->formSubmissionPrefix);

        return implode(' - ', $this->submissionFilename->compose(
            $this->deadline->currentCycleKey($example),
            $title === '' ? $this->t('activity.field.submission_preview_example_title') : $title,
            $prefix === '' ? null : $prefix,
            $documentName,
            $teacherLabel,
        )) . '.pdf';
    }

    /**
     * One line saying what the settings in the form would ask for right now — how many submissions
     * (counted the way the activity itself will, from the folder's upload profiles as they stand in
     * the form, the list and the scope) or, for a manual activity, from whom. Null when
     * there is nothing to say yet (a submission activity with no folder picked).
     */
    public function getFormSummary(): ?string
    {
        if ($this->formWithSubmissions || $this->formFolderId !== '') {
            if ($this->formFolderId === '') {
                return null;
            }
            $uploadKeys = $this->formFolderUploadKeys;
            $rows       = array_values(array_filter(
                $this->rowBuilder->buildActiveRows($this->centre),
                fn (ProfileAssignmentRow $row): bool => \in_array($row->key(), $uploadKeys, true)
                    || ($row->listItem !== null && $this->isCoveredByKeys($row, $uploadKeys)),
            ));
            $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
            $slots    = $this->submissionSlots->buildSlotsFor(
                $listItem,
                ActivitySubmissionScope::from($this->formScope === 'individual' ? 'individual' : 'by_profile'),
                $rows,
                true,
                $this->getFormDimensionLists(),
                $listItem === null || $this->formSplitByElement,
                $this->formTeachersDeliver ? $this->centre : null,
            );
            if ($slots === []) {
                return $this->t('activity.summary.no_submissions');
            }

            $summary = $this->translator->trans('activity.summary.submissions', ['%count%' => \count($slots)], 'admin');
            if ($listItem !== null && ($this->formSplitByElement || $this->formDimensionIds === [])) {
                // Elements, not combinations: «Programación · 1ª» and «Programación · 2ª» are one element.
                $elements = \count(array_unique(array_map(
                    static fn (ActivitySubmissionSlot $s): string => $s->nameListItem?->getId()->toRfc4122() ?? $s->displayName,
                    $slots,
                )));
                $summary .= ' ' . $this->translator->trans('activity.summary.of_elements', ['%count%' => $elements], 'admin');

                if ($this->formTeachersDeliver && $this->formScope !== 'individual') {
                    $summary = rtrim($summary, '.') . ' ' . $this->translator->trans('activity.summary.without_teacher', ['%count%' => $this->countElementsWithoutTeacher($slots)], 'admin');
                }
            }

            return $summary;
        }

        if ($this->formGeneral) {
            return $this->t('activity.summary.everyone');
        }

        return $this->translator->trans('activity.summary.profiles', ['%count%' => \count($this->formProfileKeys)], 'admin');
    }

    /**
     * How many of the elements the slots are named after have no teacher in the course — what «· 3 sin docente asociado» counts.
     *
     * @param ActivitySubmissionSlot[] $slots
     */
    private function countElementsWithoutTeacher(array $slots): int
    {
        $without = [];
        foreach ($slots as $slot) {
            $leaf = $slot->nameListItem;
            if ($leaf !== null && $this->leafTeachers->ofLeaf($leaf, $this->centre) === []) {
                $without[$leaf->getId()->toRfc4122()] = true;
            }
        }

        return \count($without);
    }

    /** LiveProp(onUpdated:) hook for $formFolderId: the form now shows the newly picked folder's profiles. */
    public function onFormFolderIdChanged(): void
    {
        $this->folderProfilesNotice = $this->hasUnsavedFolderProfileEdits() ? $this->t('activity.field.folder_profiles_discarded') : '';
        if ($this->formFolderId !== '') {
            $this->formWithSubmissions = true;
        }
        $this->loadFolderProfileKeys($this->formFolderId === '' ? null : $this->resolveAvailableFolder($this->formFolderId));
    }

    /** @return list<array{id: string, name: string}> the centre's document models an activity can be delivered with, by name */
    public function getFormModelChoices(): array
    {
        $choices = [];
        foreach ($this->formModels->findByCentre($this->centre) as $model) {
            $choices[] = ['id' => $model->getId()->toRfc4122(), 'name' => $model->getDocument()->getName()];
        }

        return $choices;
    }

    /** @return list<array{id: string, name: string}> the other activities of the centre filled in online, to pre-fill from */
    public function getFormPrefillChoices(): array
    {
        $choices = [];
        foreach ($this->activities->findAllByCentre($this->centre, true) as $other) {
            if ($other->isForm() && $other->getId()->toRfc4122() !== $this->formActivityId) {
                $choices[] = ['id' => $other->getId()->toRfc4122(), 'name' => $other->getTitle()];
            }
        }

        return $choices;
    }

    /**
     * «Datos de otras entregas» of the form being edited: a row per table of deliveries of the chosen model, with the activities it can
     * read (each with its deadline) and, for the one chosen, whether it ends after this activity.
     *
     * When both activities split their submissions by the same list with dates («Evaluación»), the deadlines are compared value by
     * value, and `lateValues` names the ones that end later there («2ª»).
     *
     * @return list<array{key: string, label: string, section: string, choices: list<array{id: string, name: string, due: string}>, chosen: string, late: bool, lateValues: list<string>}>
     */
    public function getFormDeliveredTables(): array
    {
        $model = $this->formModelId === '' ? null : $this->formModels->findByIdAndCentre($this->formModelId, $this->centre);
        $out   = [];
        foreach ($this->deliverySources->tablesOf($model) as $table) {
            $chosen     = $this->formDeliverySources[$table['key']] ?? '';
            $choices    = [];
            $late       = false;
            $lateValues = [];
            foreach ($this->deliverySources->choicesFor($this->centre, $table['sourceModel'], $this->formActivityId) as $activity) {
                $id        = $activity->getId()->toRfc4122();
                $choices[] = ['id' => $id, 'name' => $activity->getTitle(), 'due' => sprintf('%02d/%02d', $activity->getEndDay(), $activity->getEndMonth())];
                if ($id !== $chosen) {
                    continue;
                }
                $lateValues = $this->lateValuesOf($activity);
                if ($lateValues !== null) {
                    $late = $lateValues !== [];
                } elseif ($this->formEndDay !== '' && $this->formEndMonth !== '') {
                    $late       = ActivityDeliverySources::endsAfter($activity->getEndDay(), $activity->getEndMonth(), (int) $this->formEndDay, (int) $this->formEndMonth);
                    $lateValues = [];
                }
            }
            $out[] = $table + ['choices' => $choices, 'chosen' => $chosen, 'late' => $late, 'lateValues' => $lateValues ?? []];
        }

        return $out;
    }

    /**
     * The values of the list with dates both activities share whose range ends later in $source than in this form (its own row of
     * dates, else its general end). Null when they do not share one: then the general deadlines are compared.
     *
     * @return list<string>|null
     */
    private function lateValuesOf(Activity $source): ?array
    {
        $mine   = $this->getFormDatedDimension();
        $theirs = $source->getDatedDimension()?->getList();
        if ($mine === null || $theirs === null || $mine->getRoot()->identityKey() !== $theirs->getRoot()->identityKey()) {
            return null;
        }
        $late = [];
        foreach ($this->listItems->findLeafDescendants($mine) as $value) {
            $id       = $value->getId()->toRfc4122();
            $override = $source->getDeadlineOverride($value);
            $endDay   = ($this->formOverrideEndDay[$id] ?? '') !== '' ? (int) $this->formOverrideEndDay[$id] : (int) $this->formEndDay;
            $endMonth = ($this->formOverrideEndMonth[$id] ?? '') !== '' ? (int) $this->formOverrideEndMonth[$id] : (int) $this->formEndMonth;
            if ($endDay === 0 || $endMonth === 0) {
                continue;
            }
            if (ActivityDeliverySources::endsAfter($override?->getEndDay() ?? $source->getEndDay(), $override?->getEndMonth() ?? $source->getEndMonth(), $endDay, $endMonth)) {
                $late[] = $value->getName();
            }
        }

        return $late;
    }

    /**
     * The tables of deliveries of an activity's model and the activity each one reads (null: none chosen, or in the trash), for the
     * line «Datos de otras entregas» of its view and the warning when one is missing.
     *
     * @return list<array{label: string, source: ?Activity}>
     */
    public function getDeliverySourceSummary(Activity $activity): array
    {
        if (!$activity->isForm()) {
            return [];
        }

        return array_map(
            static fn (array $table): array => ['label' => $table['label'], 'source' => $activity->getDeliverySource($table['key'])],
            $this->deliverySources->tablesOf($activity->getFormModel()),
        );
    }

    /** LiveProp(onUpdated:) hook for $formWithSubmissions: a manual activity has no folder. */
    public function onFormWithSubmissionsChanged(): void
    {
        if (!$this->formWithSubmissions) {
            $this->folderProfilesNotice = $this->hasUnsavedFolderProfileEdits() ? $this->t('activity.field.folder_profiles_discarded') : '';
            $this->formFolderId         = '';
            $this->creatingFolder       = false;
            $this->formAutoComplete     = false;
            $this->loadFolderProfileKeys(null);
        }
    }

    /** Whether the four folder-profile lists differ from what the folder they were loaded from holds now. */
    private function hasUnsavedFolderProfileEdits(): bool
    {
        $loaded = $this->formLoadedFolderId === '' ? null : $this->resolveAvailableFolder($this->formLoadedFolderId);
        if ($loaded === null) {
            return false;
        }
        $same = static function (array $a, array $b): bool {
            sort($a);
            sort($b);

            return $a === $b;
        };

        return !$same($this->formFolderResponsibleKeys, $this->folderProfiles->keysFor($loaded->getResponsibleProfiles()))
            || !$same($this->formFolderUploadKeys, $this->folderProfiles->keysFor($loaded->getUploadProfiles()))
            || !$same($this->formFolderVisibilityKeys, $this->folderProfiles->keysFor($loaded->getVisibilityProfiles()))
            || !$same($this->formFolderReviewKeys, $this->folderProfiles->keysFor($loaded->getReviewProfiles()));
    }

    private function loadFolderProfileKeys(?Folder $folder): void
    {
        $this->formLoadedFolderId        = $folder?->getId()->toRfc4122() ?? '';
        $this->formFolderResponsibleKeys = $folder === null ? [] : $this->folderProfiles->keysFor($folder->getResponsibleProfiles());
        $this->formFolderUploadKeys      = $folder === null ? [] : $this->folderProfiles->keysFor($folder->getUploadProfiles());
        $this->formFolderVisibilityKeys  = $folder === null ? [] : $this->folderProfiles->keysFor($folder->getVisibilityProfiles());
        $this->formFolderReviewKeys      = $folder === null ? [] : $this->folderProfiles->keysFor($folder->getReviewProfiles());
    }

    /** LiveProp(onUpdated:) hook for $formListItemId — see seedOverrideArraysForCurrentListItem(). */
    public function onFormListItemIdChanged(): void
    {
        $this->seedOverrideArraysForCurrentListItem();
    }

    /**
     * Profile/subprofile rows offered to restrict a manual activity to — includes the "(todos)"
     * whole-profile wildcard for a list-associated profile and the intermediate nodes of its list
     * (every subprofile under one counts), exactly like a folder's own profile pickers.
     *
     * @return ProfileAssignmentRow[]
     */
    public function getAvailableProfileRows(): array
    {
        return $this->rowBuilder->buildActiveRowsForPicker($this->centre);
    }

    /**
     * Whether a subprofile row falls under one of the picked keys: the profile's "(todos)" key, or the
     * key of an intermediate node of its list that the subprofile hangs from.
     *
     * @param string[] $keys
     */
    private function isCoveredByKeys(ProfileAssignmentRow $row, array $keys): bool
    {
        if ($row->listItem === null) {
            return false;
        }
        if (\in_array($row->profile->getId()->toRfc4122(), $keys, true)) {
            return true;
        }
        foreach ($this->listItems->findSelfAndAncestorIds($row->listItem) as $coveringId) {
            if (\in_array(ProfileAssignmentRow::keyFor($row->profile, null) . ':' . $coveringId, $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return Document[] currently staged related documents, in the order they were added. */
    public function getFormRelatedDocuments(): array
    {
        $documents = [];
        foreach ($this->formRelatedDocumentIds as $id) {
            $document = $this->findDocument($id);
            if ($document !== null) {
                $documents[] = $document;
            }
        }

        return $documents;
    }

    /** @return array<int, array{document: Document, path: string}> search matches not already staged, for the related-document autocomplete. */
    public function getRelatedDocumentSearchResults(): array
    {
        $query = trim($this->relatedDocumentSearchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->documents->searchByCentreOrFolderName($this->centre, $query) as $document) {
            if (in_array($document->getId()->toRfc4122(), $this->formRelatedDocumentIds, true)) {
                continue;
            }
            if (!$this->access->canViewDocument($teacher, $document)) {
                continue;
            }
            $results[] = ['document' => $document, 'path' => $this->getFolderLabel($document->getFolder())];
        }

        return $results;
    }

    #[LiveAction]
    public function addRelatedDocument(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        if (!in_array($id, $this->formRelatedDocumentIds, true)) {
            $this->formRelatedDocumentIds[] = $id;
        }
        $this->relatedDocumentSearchQuery = '';
    }

    #[LiveAction]
    public function removeRelatedDocument(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $this->formRelatedDocumentIds = array_values(array_filter(
            $this->formRelatedDocumentIds,
            static fn (string $existing): bool => $existing !== $id,
        ));
    }

    /** Whether the current teacher can see $document — a related document can point anywhere in the tree, so the viewer (not just the editor who added it) needs re-checking before its link is shown. */
    public function canViewRelatedDocument(Document $document): bool
    {
        return $this->access->canViewDocument($this->teacher(), $document);
    }

    /** Deep link to $document's own location in the document tree (never the "Actividades" redirect getFolderUploadRows()/documentSearchUrl() use for a submission's folder) — a related document is an arbitrary tree document, not this activity's own submission. */
    public function getDocumentTreeUrl(Document $document): string
    {
        $folder = $document->getFolder();

        return $this->generateUrl('app_document_tree', [
            'section'   => $folder->getDocumentSection()->getId()->toRfc4122(),
            'folder'    => $folder->getId()->toRfc4122(),
            'highlight' => $document->getId()->toRfc4122(),
        ]);
    }

    /**
     * Whether the current teacher could actually browse to $folder — same reachability rule as a
     * related document's own link (canViewRelatedDocument()): the folder's own visibility
     * restriction AND every ancestor section's. An activity's own submissions folder can be
     * unreachable even to a teacher who holds the activity's profile (a section further up the
     * tree can restrict to a different, unrelated set of profiles), so the "go to folder" link
     * needs this re-check rather than assuming relevance implies visibility.
     */
    public function canReachFolder(Folder $folder): bool
    {
        return $this->access->canReachFolder($this->teacher(), $folder);
    }

    /** Deep link to $folder itself in the document tree — no document highlighted. */
    public function getFolderTreeUrl(Folder $folder): string
    {
        return $this->generateUrl('app_document_tree', [
            'section' => $folder->getDocumentSection()->getId()->toRfc4122(),
            'folder'  => $folder->getId()->toRfc4122(),
        ]);
    }

    /**
     * Comma-joined, translated labels of $folder's allowed formats — mirrors
     * SectionBrowserComponent::getAllowedFormatsLabel() for the same notice, shown above "Mis
     * entregas" here instead of above the document tree's own listing.
     */
    public function getAllowedFormatsLabel(Folder $folder): string
    {
        return implode(', ', array_map(
            fn (AllowedFileFormat $format): string => $this->translator->trans($format->labelKey(), [], 'admin'),
            $folder->getAllowedFormats(),
        ));
    }
}
