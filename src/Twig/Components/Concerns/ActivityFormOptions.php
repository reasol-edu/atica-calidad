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
        if ($this->formListItemId === '') {
            return [];
        }

        $root = $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);

        return $root === null ? [] : $this->listItems->findLeafDescendants($root);
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
            $documentName = $this->submissionSlots->submissionName($listItem, $leaves[0]);
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
            );
            if ($slots === []) {
                return $this->t('activity.summary.no_submissions');
            }

            $summary = $this->translator->trans('activity.summary.submissions', ['%count%' => \count($slots)], 'admin');
            if ($listItem !== null) {
                $elements = \count(array_unique(array_map(static fn (ActivitySubmissionSlot $s): string => $s->displayName, $slots)));
                $summary .= ' ' . $this->translator->trans('activity.summary.of_elements', ['%count%' => $elements], 'admin');
            }

            return $summary;
        }

        if ($this->formGeneral) {
            return $this->t('activity.summary.everyone');
        }

        return $this->translator->trans('activity.summary.profiles', ['%count%' => \count($this->formProfileKeys)], 'admin');
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
