<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivityProfile;
use App\Entity\ActivityResponsibleProfile;
use App\Entity\ActivitySubmissionScope;
use App\Entity\AllowedFileFormat;
use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\DocumentSection;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Tag;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionProgress;
use App\Model\ActivityDeadlineSummary;
use App\Model\ActivitySubmissionSlot;
use App\Model\ActivityWindow;
use App\Model\OwnCompletionOutcome;
use App\Model\ProfileAssignmentRow;
use App\Repository\ActivityCategoryRepository;
use App\Repository\ActivityRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\FolderRepository;
use App\Repository\ListItemRepository;
use App\Repository\TagRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Security\Voter\FolderVoter;
use App\Service\ActivityCompletionChecker;
use App\Service\ActivityDeadlineChecker;
use App\Service\ActivityDeadlineSummaryBuilder;
use App\Service\ActivityLogger;
use App\Service\ActivityObligationFinder;
use App\Service\ActivitySubmissionFilenameBuilder;
use App\Service\ActivitySubmissionProgressCalculator;
use App\Service\ActivitySubmissionSlotBuilder;
use App\Service\ActivityWindowChecker;
use App\Service\DocumentFileGarbageCollector;
use App\Service\FolderProfileSynchronizer;
use App\Service\DocumentTreeAccessChecker;
use App\Service\OwnCompletionManager;
use App\Service\ProfileAssignmentRowBuilder;
use App\Service\TrashService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * "Ver" tab of Actividades: browses the category tree (any teacher) and, within a category, lists
 * its activities — the whole tree by default; turning $showAllProfiles off narrows both down to
 * whichever are relevant to the current teacher's own folder profiles/roles (a relevance filter,
 * never an access gate: a category/activity's own folder visibility is still always enforced
 * underneath, see DocumentTreeAccessChecker::isActivityRelevantToTeacher()). Activity CRUD is reserved to
 * EducationalCentreVoter::RESPONSIBILITIES, done inline here (not in the separate "Editar
 * categorías" tab) — mirrors exactly how Folder creation/editing lives in Document Tree's "Ver"
 * tab, not its "Editar árbol" tab. Everything about a submission's underlying Document (new
 * revision, download, approve, reject) reuses FolderController's own routes unchanged; only the
 * revision-management LiveActions (edit/delete a revision, pick the active one) are duplicated here
 * from SectionBrowserComponent, adapted to resolve the folder from the activity instead of a URL id.
 */
#[AsLiveComponent]
class ActivityBrowserComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;

    #[LiveProp]
    public EducationalCentre $centre;

    /** '' means the root level. */
    #[LiveProp(writable: true)]
    public string $currentCategoryId = '';

    /**
     * Relevance filter, available to any teacher — never an access gate (a category/activity's own
     * folder/section visibility is still always enforced underneath). On by default: the "Ver" tab
     * shows the whole tree to begin with, and turning this off is how a teacher narrows it down to
     * just what's relevant to their own profiles.
     */
    #[LiveProp(writable: true)]
    public bool $showAllProfiles = true;

    #[LiveProp(writable: true)]
    public string $searchQuery = '';

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

    /** @var string[] tag ids */
    #[LiveProp(writable: true)]
    public array $formTagIds = [];

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

    // ── Per-activity display toggles ─────────────────────────────────────────
    /** @var string[] activity ids whose "todas las entregas" section is expanded. */
    #[LiveProp(writable: true)]
    public array $expandedAllSubmissions = [];

    /** @var string[] activity ids whose stats panel is shown. */
    #[LiveProp(writable: true)]
    public array $statsShown = [];

    // ── Revision panel (mirrors SectionBrowserComponent's document-revision LiveProps) ──
    #[LiveProp(writable: true)]
    public string $revisionPanelDocumentId = '';

    #[LiveProp(writable: true)]
    public string $highlightedDocumentId = '';

    /**
     * The submission row a link (dashboard, bell, "Mis actividades") asked to land on: a slot key,
     * or 'next' for the first of the teacher's own that still has nothing accepted or waiting.
     */
    #[LiveProp]
    public string $highlightedSlotKey = '';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteDocumentId = '';

    #[LiveProp(writable: true)]
    public string $editingRevisionId = '';

    #[LiveProp(writable: true)]
    public string $editVersionValue = '';

    #[LiveProp(writable: true)]
    public string $editUploadedById = '';

    #[LiveProp(writable: true)]
    public string $editRevisedAtValue = '';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteRevisionId = '';

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    /** @var ActivityCategory[]|null memoised per render — see getCategoryTree() */
    private ?array $allCategoriesCache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ActivityCategoryRepository $categories,
        private readonly ActivityRepository $activities,
        private readonly FolderRepository $folders,
        private readonly DocumentSectionRepository $sections,
        private readonly ListItemRepository $listItems,
        private readonly TagRepository $tags,
        private readonly DocumentRepository $documents,
        private readonly DocumentRevisionRepository $revisions,
        private readonly TeacherRepository $teachers,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ActivityCompletionChecker $completion,
        private readonly ActivityDeadlineChecker $deadline,
        private readonly ActivityDeadlineSummaryBuilder $deadlineSummaries,
        private readonly ActivityWindowChecker $windowChecker,
        private readonly ActivityLogger $activityLogger,
        private readonly DocumentFileGarbageCollector $garbageCollector,
        private readonly ActivityObligationFinder $obligations,
        private readonly OwnCompletionManager $ownCompletions,
        private readonly ActivitySubmissionProgressCalculator $progress,
        private readonly ProfileAssignmentRowBuilder $rowBuilder,
        private readonly FolderProfileSynchronizer $folderProfiles,
        private readonly ActivitySubmissionFilenameBuilder $submissionFilename,
        private readonly ActivitySubmissionSlotBuilder $submissionSlots,
        private readonly TrashService $trash,
    ) {}

    public function mount(
        EducationalCentre $centre,
        string $initialCategoryId = '',
        string $initialActivityId = '',
        string $initialHighlightDocumentId = '',
        string $initialSlotKey = '',
    ): void {
        $this->centre = $centre;
        // Only a well-formed key (or "next"): it ends up compared, never trusted as an id.
        $this->highlightedSlotKey = $initialSlotKey === 'next' || preg_match('/^[0-9a-f-]{36}(:[0-9a-f-]{0,36}){3}$/', $initialSlotKey) === 1 ? $initialSlotKey : '';

        if ($initialActivityId !== '') {
            $activity = $this->findActivity($initialActivityId);
            if ($activity !== null) {
                $this->currentCategoryId = $activity->getCategory()->getId()->toRfc4122();
            }
        } elseif ($initialCategoryId !== '') {
            $category = $this->categories->findByIdAndCentre($initialCategoryId, $centre);
            if ($category !== null) {
                $this->currentCategoryId = $initialCategoryId;
            }
        }

        if ($initialHighlightDocumentId !== '') {
            $document = $this->findDocument($initialHighlightDocumentId);
            $activity = $document?->getFolder()->getActivity();
            if ($activity !== null) {
                $this->highlightedDocumentId = $initialHighlightDocumentId;
                // Landing here from "Revisiones pendientes" / the bell: open the activity's full
                // submission list — collapsed by default for someone with submissions of their own
                // — so the highlighted one (and the rest waiting for review) is right there.
                $this->expandedAllSubmissions[] = $activity->getId()->toRfc4122();
                if ($this->currentCategoryId === '') {
                    $this->currentCategoryId = $activity->getCategory()->getId()->toRfc4122();
                }
            }
        }
    }

    public function canEdit(): bool
    {
        return $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
    }

    /**
     * Whether the viewer manages $activity — a responsable de calidad/admin, or (for a manual
     * activity) a teacher holding one of its own responsible profiles — who can see its
     * completion stats. Narrower than canEdit() alone; the per-teacher mark/unmark-for-someone-
     * else action still requires canEdit() specifically (see toggleManualCompletionForTeacher()).
     */
    public function canManageActivity(Activity $activity): bool
    {
        return $this->canEdit() || $this->completion->isResponsibleFor($this->teacher(), $activity);
    }

    /** Whether this manual (folder-less) activity even applies to the viewer — see ActivityCompletionChecker::isApplicableToTeacher(). */
    public function isApplicableToTeacher(Activity $activity): bool
    {
        return $this->completion->isApplicableToTeacher($this->teacher(), $activity);
    }

    // ── Category navigation ──────────────────────────────────────────────────

    public function getCurrentCategory(): ?ActivityCategory
    {
        if ($this->currentCategoryId === '') {
            return null;
        }

        return $this->categories->findByIdAndCentre($this->currentCategoryId, $this->centre);
    }

    /** @return ActivityCategory[] */
    public function getVisibleCategories(): array
    {
        $parent = $this->getCurrentCategory();
        $all    = $parent === null
            ? $this->categories->findRootsByCentre($this->centre)
            : $this->categories->findChildrenByParent($parent);

        if ($this->showAllProfiles) {
            return $all;
        }

        $teacher = $this->teacher();

        return array_values(array_filter($all, fn (ActivityCategory $c): bool => $this->categoryHasRelevantActivity($c, $teacher)));
    }

    /** @return ActivityCategory[] root-first path of ancestors down to (and including) the current category */
    public function getBreadcrumb(): array
    {
        $trail = [];
        for ($item = $this->getCurrentCategory(); $item !== null; $item = $item->getParent()) {
            array_unshift($trail, $item);
        }

        return $trail;
    }

    #[LiveAction]
    public function openLevel(#[LiveArg] string $id): void
    {
        $this->currentCategoryId = $id;
        $this->resetTransientState();
        $this->dispatchCategoryLocation();
    }

    /**
     * Restores $currentCategoryId from the URL after the user hits the browser's back/forward
     * button — the counterpart to dispatchCategoryLocation()'s pushState on the JS side. Mirrors
     * SectionBrowserComponent::syncFromUrl(), scoped to just category navigation (not the rest of
     * the screen's transient state, which the URL never tracks here). Never dispatches the
     * location event itself, or every back/forward press would push a new (forward) history entry.
     */
    #[LiveAction]
    public function syncCategoryFromUrl(#[LiveArg] string $category = ''): void
    {
        $this->currentCategoryId = $category !== '' && $this->categories->findByIdAndCentre($category, $this->centre) !== null
            ? $category
            : '';
        $this->resetTransientState();
    }

    /** Tells the activity-category-url Stimulus controller to reflect the current category in the URL (pushState). */
    private function dispatchCategoryLocation(): void
    {
        $this->dispatchBrowserEvent('activity-category:location', [
            'category' => $this->currentCategoryId,
        ]);
    }

    #[LiveAction]
    public function toggleShowAllProfiles(): void
    {
        $this->showAllProfiles = !$this->showAllProfiles;
    }

    /** Whether $category, or any of its descendants, has at least one activity relevant to $teacher. */
    private function categoryHasRelevantActivity(ActivityCategory $category, Teacher $teacher): bool
    {
        foreach ($this->activities->findByCategory($category, $this->canEdit()) as $activity) {
            if ($this->access->isActivityRelevantToTeacher($teacher, $activity)) {
                return true;
            }
        }
        foreach ($this->categories->findChildrenByParent($category) as $child) {
            if ($this->categoryHasRelevantActivity($child, $teacher)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole category tree, for the sidebar shown alongside the breadcrumb/cards browsing on
     * wide screens (mirrors SectionBrowserComponent::getSectionTree()) — every category of the
     * centre (any teacher may browse the full tree, there's no per-category access gate the way
     * DocumentSection has), pruned to ones with a relevant activity (in themselves or a descendant)
     * when showAllProfiles is off, exactly like getVisibleCategories() prunes the card grid.
     *
     * @return array<int, array{category: ActivityCategory, children: array<mixed>}>
     */
    public function getCategoryTree(): array
    {
        $byParent = [];
        foreach ($this->allCategoriesForTree() as $category) {
            $key              = $category->getParent()?->getId()->toRfc4122() ?? '';
            $byParent[$key][] = $category;
        }

        return $this->buildCategoryTreeNodes('', $byParent);
    }

    /** @return ActivityCategory[] every category of the centre, loaded once per render. */
    private function allCategoriesForTree(): array
    {
        return $this->allCategoriesCache ??= $this->categories->findAllByCentre($this->centre);
    }

    /**
     * @param array<string, ActivityCategory[]> $byParent
     *
     * @return array<int, array{category: ActivityCategory, children: array<mixed>}>
     */
    private function buildCategoryTreeNodes(string $parentKey, array $byParent): array
    {
        $teacher = $this->showAllProfiles ? null : $this->teacher();
        $nodes   = [];
        foreach ($byParent[$parentKey] ?? [] as $category) {
            if ($teacher !== null && !$this->categoryHasRelevantActivity($category, $teacher)) {
                continue;
            }
            $nodes[] = [
                'category' => $category,
                'children' => $this->buildCategoryTreeNodes($category->getId()->toRfc4122(), $byParent),
            ];
        }

        return $nodes;
    }

    // ── Activities in the current category ───────────────────────────────────

    /** @return Activity[] */
    public function getVisibleActivities(): array
    {
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return [];
        }

        $all = $this->activities->findByCategory($category, $this->canEdit());
        if ($this->showAllProfiles) {
            return $all;
        }

        $teacher = $this->teacher();

        return array_values(array_filter($all, fn (Activity $a): bool => $this->access->isActivityRelevantToTeacher($teacher, $a)));
    }

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
     * the form, the list, its tags and the scope) or, for a manual activity, from whom. Null when
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
                static fn (ProfileAssignmentRow $row): bool => \in_array($row->key(), $uploadKeys, true)
                    || ($row->listItem !== null && \in_array($row->profile->getId()->toRfc4122(), $uploadKeys, true)),
            ));
            $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
            $tags     = array_values(array_filter(array_map(fn (string $id): ?Tag => $this->findTagById($id), $this->formTagIds)));
            $slots    = $this->submissionSlots->buildSlotsFor(
                $listItem,
                $tags,
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

    /** @return Tag[] */
    public function getAvailableTags(): array
    {
        return $this->tags->findByCentre($this->centre);
    }

    /**
     * Profile/subprofile rows offered to restrict a manual activity to — includes the "(todos)"
     * whole-profile wildcard for a list-associated profile, exactly like a folder's own
     * upload-profile picker (see DocumentTreeAccessChecker::allowedUploadProfileRows()).
     *
     * @return ProfileAssignmentRow[]
     */
    public function getAvailableProfileRows(): array
    {
        return $this->rowBuilder->buildActiveRowsWithWholeProfileOption($this->centre);
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

    #[LiveAction]
    public function startAddActivity(): void
    {
        $this->requireEditPermission();
        $this->formActivityId  = '';
        $this->formTitle       = '';
        $this->formDescription = '';
        $this->formSubmissionPrefix = '';
        $this->formStartDay    = '';
        $this->formStartMonth  = '';
        $this->formEndDay      = '';
        $this->formEndMonth    = '';
        $this->formFolderId    = '';
        $this->loadFolderProfileKeys(null);
        $this->formWithSubmissions  = false;
        $this->advancedOpen         = false;
        $this->folderProfilesNotice = '';
        $this->creatingFolder       = false;
        $this->newFolderName       = '';
        $this->newFolderSectionId  = '';
        $this->formListItemId  = '';
        $this->formOverrideStartDay   = [];
        $this->formOverrideStartMonth = [];
        $this->formOverrideEndDay     = [];
        $this->formOverrideEndMonth   = [];
        $this->formTagIds      = [];
        $this->formRelatedDocumentIds     = [];
        $this->relatedDocumentSearchQuery = '';
        $this->formRequired    = true;
        $this->formAutoComplete = false;
        $this->formStartDateEnforced = false;
        $this->formEndDateEnforced   = false;
        $this->formEndDateGraceDays  = '0';
        $this->formScope       = 'by_profile';
        $this->formHidden      = false;
        $this->formGeneral     = true;
        $this->formProfileKeys = [];
        $this->formResponsibleProfileKeys = [];
        $this->activityFormOpen = true;
        $this->errors           = [];
    }

    #[LiveAction]
    public function startEditActivity(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $activity = $this->findActivity($id);
        if ($activity === null) {
            return;
        }

        $this->formActivityId   = $id;
        $this->formTitle        = $activity->getTitle();
        $this->formDescription  = $activity->getDescription() ?? '';
        $this->formSubmissionPrefix = $activity->getSubmissionPrefix() ?? '';
        $this->formStartDay     = (string) $activity->getStartDay();
        $this->formStartMonth   = (string) $activity->getStartMonth();
        $this->formEndDay       = (string) $activity->getEndDay();
        $this->formEndMonth     = (string) $activity->getEndMonth();
        $this->formFolderId     = $activity->getFolder()?->getId()->toRfc4122() ?? '';
        $this->loadFolderProfileKeys($activity->getFolder());
        $this->formWithSubmissions  = $activity->getFolder() !== null;
        $this->advancedOpen         = $activity->isHidden() || $activity->isAutoComplete();
        $this->folderProfilesNotice = '';
        $this->creatingFolder      = false;
        $this->newFolderName      = '';
        $this->newFolderSectionId = '';
        $this->formListItemId   = $activity->getListItem()?->getId()->toRfc4122() ?? '';
        $this->formOverrideStartDay   = [];
        $this->formOverrideStartMonth = [];
        $this->formOverrideEndDay     = [];
        $this->formOverrideEndMonth   = [];
        foreach ($activity->getListItemDeadlines() as $override) {
            $leafId = $override->getListItem()->getId()->toRfc4122();
            $this->formOverrideStartDay[$leafId]   = (string) $override->getStartDay();
            $this->formOverrideStartMonth[$leafId] = (string) $override->getStartMonth();
            $this->formOverrideEndDay[$leafId]     = (string) $override->getEndDay();
            $this->formOverrideEndMonth[$leafId]   = (string) $override->getEndMonth();
        }
        $this->seedOverrideArraysForCurrentListItem();
        $this->formTagIds       = array_map(static fn (Tag $t): string => $t->getId()->toRfc4122(), $activity->getTags()->toArray());
        $this->formRelatedDocumentIds     = array_map(static fn (Document $d): string => $d->getId()->toRfc4122(), $activity->getRelatedDocuments()->toArray());
        $this->relatedDocumentSearchQuery = '';
        $this->formRequired     = $activity->isRequired();
        $this->formAutoComplete = $activity->isAutoComplete();
        $this->formStartDateEnforced = $activity->isStartDateEnforced();
        $this->formEndDateEnforced   = $activity->isEndDateEnforced();
        $this->formEndDateGraceDays  = (string) $activity->getEndDateGraceDays();
        $this->formScope        = $activity->getSubmissionScope()->value;
        $this->formHidden       = $activity->isHidden();
        $this->formGeneral      = $activity->isGeneral();
        $this->formProfileKeys  = array_map(
            static fn (ActivityProfile $r): string => ProfileAssignmentRow::keyFor($r->getSpecificProfile(), $r->getListItem()),
            $activity->getProfileRestrictions()->toArray(),
        );
        $this->formResponsibleProfileKeys = array_map(
            static fn (ActivityResponsibleProfile $r): string => ProfileAssignmentRow::keyFor($r->getSpecificProfile(), $r->getListItem()),
            $activity->getResponsibleProfiles()->toArray(),
        );
        $this->activityFormOpen = true;
        $this->errors           = [];
    }

    #[LiveAction]
    public function toggleAdvanced(): void
    {
        $this->advancedOpen = !$this->advancedOpen;
    }

    /** Copies the activity's general deadline onto every element of the chosen list, replacing what they had. */
    #[LiveAction]
    public function applyGeneralDeadlineToElements(): void
    {
        foreach ($this->getFormListItemLeaves() as $leaf) {
            $leafId = $leaf->getId()->toRfc4122();
            $this->formOverrideStartDay[$leafId]   = $this->formStartDay;
            $this->formOverrideStartMonth[$leafId] = $this->formStartMonth;
            $this->formOverrideEndDay[$leafId]     = $this->formEndDay;
            $this->formOverrideEndMonth[$leafId]   = $this->formEndMonth;
        }
    }

    /** Back to the general deadline for every element of the list. */
    #[LiveAction]
    public function clearElementDeadlines(): void
    {
        $this->formOverrideStartDay = $this->formOverrideStartMonth = $this->formOverrideEndDay = $this->formOverrideEndMonth = [];
        $this->seedOverrideArraysForCurrentListItem();
    }

    /**
     * A copy of the activity in the same category, hidden until it's been reviewed and with no
     * folder (a folder belongs to one activity), opened in the form so what changes can be set
     * right away — activities repeat from year to year with small variations.
     */
    #[LiveAction]
    public function duplicateActivity(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $source = $this->findActivity($id) ?? throw $this->createNotFoundException();

        $category = $source->getCategory();
        $copy     = (new Activity())
            ->setCategory($category)
            ->setPosition($this->activities->nextPosition($category))
            ->setTitle($this->translator->trans('activity.duplicate.title', ['%title%' => $source->getTitle()], 'admin'))
            ->setDescription($source->getDescription())
            ->setSubmissionPrefix($source->getSubmissionPrefix())
            ->setStart($source->getStartDay(), $source->getStartMonth())
            ->setEnd($source->getEndDay(), $source->getEndMonth())
            ->setListItem($source->getListItem())
            ->setRequired($source->isRequired())
            ->setSubmissionScope($source->getSubmissionScope())
            ->setStartDateEnforced($source->isStartDateEnforced())
            ->setEndDateEnforced($source->isEndDateEnforced())
            ->setEndDateGraceDays($source->getEndDateGraceDays())
            ->setGeneral($source->isGeneral())
            ->setHidden(true);
        foreach ($source->getListItemDeadlines() as $override) {
            $copy->setDeadlineOverride($override->getListItem(), $override->getStartDay(), $override->getStartMonth(), $override->getEndDay(), $override->getEndMonth());
        }
        foreach ($source->getTags() as $tag) {
            $copy->addTag($tag);
        }
        foreach ($source->getRelatedDocuments() as $document) {
            $copy->addRelatedDocument($document);
        }
        foreach ($source->getProfileRestrictions() as $restriction) {
            $copy->addProfileRestriction($restriction->getSpecificProfile(), $restriction->getListItem());
        }
        foreach ($source->getResponsibleProfiles() as $responsible) {
            $copy->addResponsibleProfile($responsible->getSpecificProfile(), $responsible->getListItem());
        }
        $this->em->persist($copy);
        $this->em->flush();

        $this->flashSuccess($this->translator->trans('activity.flash.duplicated', [], 'admin'));
        $this->startEditActivity($copy->getId()->toRfc4122());
    }

    #[LiveAction]
    public function cancelActivityForm(): void
    {
        $this->activityFormOpen = false;
        $this->errors           = [];
    }

    /**
     * Opens the inline "create a new folder" panel in place of the existing-folder picker — lets
     * an activity be given a brand-new folder without leaving the activity form. Mirrors
     * SectionBrowserComponent::addFolder(): just name + document section, the same minimal set a
     * folder needs to exist; everything else (upload/visibility/review profiles, allowed formats…)
     * is configured afterwards from the document tree, same as for any other folder.
     */
    #[LiveAction]
    public function startCreateFolder(): void
    {
        $this->requireEditPermission();
        $this->creatingFolder      = true;
        $this->newFolderName      = '';
        $this->newFolderSectionId = '';
    }

    #[LiveAction]
    public function cancelCreateFolder(): void
    {
        $this->creatingFolder = false;
        $this->errors         = [];
    }

    #[LiveAction]
    public function createFolderForActivity(): void
    {
        $this->requireEditPermission();
        $name    = trim($this->newFolderName);
        $section = $this->newFolderSectionId === '' ? null : $this->sections->findByIdAndCentre($this->newFolderSectionId, $this->centre);
        if ($name === '' || $section === null) {
            $this->errors = ['newFolder' => $this->t('activity.error.folder_name_and_section_required')];

            return;
        }

        $folder = (new Folder())
            ->setDocumentSection($section)
            ->setName($name)
            ->setPosition($this->folders->nextPosition($section));
        $this->em->persist($folder);
        $this->em->flush();

        // Immediately picked as the activity's own folder — getAvailableFolders() already
        // includes it (its activity is still null), so the picker just renders it selected.
        $this->formFolderId   = $folder->getId()->toRfc4122();
        $this->loadFolderProfileKeys($folder);
        $this->formWithSubmissions  = true;
        $this->folderProfilesNotice = '';
        $this->creatingFolder = false;
        $this->errors         = [];
    }

    #[LiveAction]
    public function saveActivity(): void
    {
        $this->requireEditPermission();
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return;
        }
        ++$this->saveAttempt;

        $title = trim($this->formTitle);
        if ($title === '') {
            $this->errors = ['title' => $this->t('activity.error.name_required')];

            return;
        }

        $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
        $leaves   = $listItem === null ? [] : $this->listItems->findLeafDescendants($listItem);
        $overridesByLeafId = $this->readDeadlineOverrides($leaves);
        if ($overridesByLeafId === false) {
            $this->errors = ['dates' => $this->t('activity.error.invalid_date')];

            return;
        }

        $startDay   = (int) $this->formStartDay;
        $startMonth = (int) $this->formStartMonth;
        $endDay     = (int) $this->formEndDay;
        $endMonth   = (int) $this->formEndMonth;
        $generalBlank = $this->formStartDay === '' && $this->formStartMonth === '' && $this->formEndDay === '' && $this->formEndMonth === '';
        if ($generalBlank) {
            // No general deadline is needed when every element of the list has its own: the
            // activity's own dates then just span theirs (they're what calendars etc. fall back on).
            if ($leaves === [] || count($overridesByLeafId) !== count($leaves)) {
                $this->errors = ['dates' => $this->t('activity.error.dates_required_unless_all_elements')];

                return;
            }
            $envelope = $this->deadline->envelopeOf($this->centre, array_values($overridesByLeafId));
            ['startDay' => $startDay, 'startMonth' => $startMonth, 'endDay' => $endDay, 'endMonth' => $endMonth] = $envelope;
        } elseif ($startDay < 1 || $startDay > 31 || $startMonth < 1 || $startMonth > 12 || $endDay < 1 || $endDay > 31 || $endMonth < 1 || $endMonth > 12) {
            $this->errors = ['dates' => $this->t('activity.error.invalid_date')];

            return;
        }

        $folder = $this->formFolderId === '' ? null : $this->resolveAvailableFolder($this->formFolderId);
        if ($this->formWithSubmissions && $folder === null) {
            $this->errors = ['folder' => $this->t('activity.error.folder_required')];

            return;
        }
        if ($this->formAutoComplete && $folder === null) {
            $this->errors = ['autoComplete' => $this->t('activity.error.auto_complete_requires_folder')];

            return;
        }

        // Restriction only applies to manual activities — a folder-backed one's ownership already
        // comes from the folder's own upload profiles, so $folder !== null always means general.
        $restricted = $folder === null && !$this->formGeneral;
        if ($restricted && $this->formProfileKeys === []) {
            $this->errors = ['profiles' => $this->t('activity.error.profiles_required')];

            return;
        }

        $scope = ActivitySubmissionScope::from($this->formScope === 'individual' ? 'individual' : 'by_profile');

        $activity = null;
        if ($this->formActivityId !== '') {
            // $formActivityId is client-writable: an id from another centre must never be
            // edited (nor silently turned into a brand new activity here instead).
            $activity = $this->findActivity($this->formActivityId) ?? throw $this->createNotFoundException();
        }
        if ($activity === null) {
            $activity = (new Activity())
                ->setCategory($category)
                ->setPosition($this->activities->nextPosition($category));
            $this->em->persist($activity);
        }

        $activity->setTitle($title);
        $activity->setDescription($this->formDescription !== '' ? $this->formDescription : null);
        $submissionPrefix = trim($this->formSubmissionPrefix);
        $activity->setSubmissionPrefix($submissionPrefix !== '' ? $submissionPrefix : null);
        $activity->setStart($startDay, $startMonth);
        $activity->setEnd($endDay, $endMonth);
        $activity->setListItem($listItem);
        $activity->clearListItemDeadlines();
        foreach ($overridesByLeafId as $leafId => $range) {
            $leaf = $this->listItems->findByIdAndCentre($leafId, $this->centre);
            if ($leaf !== null) {
                $activity->setDeadlineOverride($leaf, $range['startDay'], $range['startMonth'], $range['endDay'], $range['endMonth']);
            }
        }
        $activity->setRequired($this->formRequired);
        $activity->setSubmissionScope($scope);
        $activity->setHidden($this->formHidden);
        $activity->setStartDateEnforced($this->formStartDateEnforced);
        $activity->setEndDateEnforced($this->formEndDateEnforced);
        // setEndDateGraceDays() zeroes itself when the end date isn't enforced.
        $activity->setEndDateGraceDays(max(0, (int) $this->formEndDateGraceDays));
        $activity->setFolder($folder);
        // Safe unconditionally: the guard above already ensures $folder isn't null whenever
        // $this->formAutoComplete is true, and setAutoComplete(false) never throws either way.
        $activity->setAutoComplete($this->formAutoComplete);

        $rowsByKey = [];
        foreach ($this->getAvailableProfileRows() as $row) {
            $rowsByKey[$row->key()] = $row;
        }

        $activity->setGeneral(!$restricted);
        $activity->clearProfileRestrictions();
        if ($restricted) {
            foreach ($this->formProfileKeys as $key) {
                $row = $rowsByKey[$key] ?? null;
                if ($row !== null) {
                    $activity->addProfileRestriction($row->profile, $row->listItem);
                }
            }
        }

        // Responsible profiles are meaningful only for a manual activity — a folder-backed one's
        // management already comes from the folder's own FolderResponsibleProfile rows.
        $activity->clearResponsibleProfiles();
        if ($folder === null) {
            foreach ($this->formResponsibleProfileKeys as $key) {
                $row = $rowsByKey[$key] ?? null;
                if ($row !== null) {
                    $activity->addResponsibleProfile($row->profile, $row->listItem);
                }
            }
        }

        foreach (iterator_to_array($activity->getTags()) as $tag) {
            if (!in_array($tag->getId()->toRfc4122(), $this->formTagIds, true)) {
                $activity->removeTag($tag);
            }
        }
        foreach ($this->formTagIds as $tagId) {
            $tag = $this->findTagById($tagId);
            if ($tag !== null) {
                $activity->addTag($tag);
            }
        }

        foreach (iterator_to_array($activity->getRelatedDocuments()) as $document) {
            if (!in_array($document->getId()->toRfc4122(), $this->formRelatedDocumentIds, true)) {
                $activity->removeRelatedDocument($document);
            }
        }
        foreach ($this->formRelatedDocumentIds as $documentId) {
            $document = $this->findViewableDocumentById($documentId);
            if ($document !== null) {
                $activity->addRelatedDocument($document);
            }
        }

        // The folder's own profile lists, as edited in the form (rows not on offer are ignored).
        if ($folder !== null) {
            $this->folderProfiles->syncAll(
                $folder,
                $this->formFolderResponsibleKeys,
                $this->formFolderUploadKeys,
                $this->formFolderVisibilityKeys,
                $this->formFolderReviewKeys,
                $rowsByKey,
            );
        }

        $this->em->flush();

        // Documents already sitting in a newly linked folder become this occurrence's
        // submissions (the ones uploaded through the activity get stamped as they're created).
        if ($folder !== null) {
            $this->documents->assignActivityCycleYearWhereMissing($folder, $this->deadline->currentCycleKey($activity));
        }

        $this->activityFormOpen = false;
        $this->errors           = [];
        $this->flashSuccess($this->t('activity.flash.saved'));
    }

    /**
     * Reads the formOverrideStartDay/StartMonth/EndDay/EndMonth props for $leaves into one
     * validated {leafId: {startDay, startMonth, endDay, endMonth}} map — a leaf with all 4 fields blank is
     * simply left out (meaning "use the activity's own dates"); one with any field filled must have
     * all 4 valid, or the whole form is rejected (returns false) exactly like the activity's own
     * date fields.
     *
     * @param  ListItem[] $leaves
     * @return array<string, array{startDay: int, startMonth: int, endDay: int, endMonth: int}>|false
     */
    private function readDeadlineOverrides(array $leaves): array|false
    {
        $overrides = [];
        foreach ($leaves as $leaf) {
            $leafId = $leaf->getId()->toRfc4122();
            $raw    = [
                $this->formOverrideStartDay[$leafId] ?? '',
                $this->formOverrideStartMonth[$leafId] ?? '',
                $this->formOverrideEndDay[$leafId] ?? '',
                $this->formOverrideEndMonth[$leafId] ?? '',
            ];
            if ($raw === ['', '', '', '']) {
                continue;
            }
            if (in_array('', $raw, true)) {
                return false;
            }

            [$startDay, $startMonth, $endDay, $endMonth] = array_map(static fn (string $v): int => (int) $v, $raw);
            if ($startDay < 1 || $startDay > 31 || $startMonth < 1 || $startMonth > 12 || $endDay < 1 || $endDay > 31 || $endMonth < 1 || $endMonth > 12) {
                return false;
            }

            $overrides[$leafId] = ['startDay' => $startDay, 'startMonth' => $startMonth, 'endDay' => $endDay, 'endMonth' => $endMonth];
        }

        return $overrides;
    }

    private function resolveAvailableFolder(string $id): ?Folder
    {
        foreach ($this->getAvailableFolders() as $folder) {
            if ($folder->getId()->toRfc4122() === $id) {
                return $folder;
            }
        }

        return null;
    }

    private function findTagById(string $id): ?Tag
    {
        foreach ($this->getAvailableTags() as $tag) {
            if ($tag->getId()->toRfc4122() === $id) {
                return $tag;
            }
        }

        return null;
    }

    /** A related document must belong to this centre and stay within what the editing teacher can actually see — re-checked here since $formRelatedDocumentIds is client-controlled state. */
    private function findViewableDocumentById(string $id): ?Document
    {
        $document = $this->findDocument($id);
        if ($document === null) {
            return null;
        }

        return $this->access->canViewDocument($this->teacher(), $document) ? $document : null;
    }

    #[LiveAction]
    public function askDeleteActivity(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $this->confirmingDeleteActivityId = $id;
    }

    #[LiveAction]
    public function cancelDeleteActivity(): void
    {
        $this->confirmingDeleteActivityId = '';
    }

    #[LiveAction]
    public function deleteActivity(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $activity = $this->findActivity($id);
        if ($activity === null) {
            $this->confirmingDeleteActivityId = '';

            return;
        }

        $this->trash->trashActivity($activity, $this->teacher());

        $this->confirmingDeleteActivityId = '';
        $this->flashSuccess($this->t('activity.flash.deleted'));
    }

    private function requireEditPermission(): void
    {
        $this->denyAccessUnlessGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
    }

    // ── Submission slots ──────────────────────────────────────────────────────

    /** @return ActivitySubmissionSlot[] every expected submission of $activity. */
    public function getAllSlots(Activity $activity): array
    {
        return $this->completion->getAllSlots($activity);
    }

    /** @return ActivitySubmissionSlot[] the slots the current teacher is personally responsible for. */
    public function getMySlots(Activity $activity): array
    {
        return $this->completion->getMySlots($this->teacher(), $activity);
    }

    /** The key of the slot the page was asked to land on ('' for none) — "next" resolved to the first of the teacher's own still to do. */
    public function getSlotToHighlight(Activity $activity): string
    {
        if ($this->highlightedSlotKey !== 'next') {
            return $this->highlightedSlotKey;
        }

        foreach ($this->getMySlots($activity) as $slot) {
            $document = $this->resolveSlot($activity, $slot);
            if ($document === null || ($document->getActiveRevision() === null && $document->getPendingRevision() === null)) {
                return $slot->key();
            }
        }

        return '';
    }

    public function resolveSlot(Activity $activity, ActivitySubmissionSlot $slot): ?Document
    {
        return $this->completion->resolveSlot($activity, $slot);
    }

    /**
     * Whether the current teacher has at least one of their own slots still without a document —
     * i.e. "Mis entregas" actually offers a dropzone right now, as opposed to every one of their
     * slots already being filled. Used to decide whether the folder's format restriction (if any)
     * is even relevant to show them.
     */
    public function hasAnOpenSubmissionSlot(Activity $activity): bool
    {
        foreach ($this->getMySlots($activity) as $slot) {
            if ($this->resolveSlot($activity, $slot) === null) {
                return true;
            }
        }

        return false;
    }

    /** @return ActivitySubmissionSlot[] every slot NOT already covered by getMySlots() — "the rest of the profiles'" deliveries. */
    public function getOtherSlots(Activity $activity): array
    {
        $mineKeys = array_map(static fn (ActivitySubmissionSlot $s): string => $s->key(), $this->getMySlots($activity));

        return array_values(array_filter(
            $this->getAllSlots($activity),
            static fn (ActivitySubmissionSlot $s): bool => !in_array($s->key(), $mineKeys, true),
        ));
    }

    #[LiveAction]
    public function toggleAllSubmissions(#[LiveArg] string $activityId): void
    {
        if (in_array($activityId, $this->expandedAllSubmissions, true)) {
            $this->expandedAllSubmissions = array_values(array_diff($this->expandedAllSubmissions, [$activityId]));
        } else {
            $this->expandedAllSubmissions[] = $activityId;
        }
    }

    #[LiveAction]
    public function toggleStats(#[LiveArg] string $activityId): void
    {
        if (in_array($activityId, $this->statsShown, true)) {
            $this->statsShown = array_values(array_diff($this->statsShown, [$activityId]));
        } else {
            $this->statsShown[] = $activityId;
        }
    }

    /**
     * Delivered/accepted counts grouped by upload profile/subprofile (ByProfile scope) or by
     * teacher (Individual scope) — never per individual named submission, matching the request's
     * own "4/8 (50%)" example, which counts rows of a *person or profile*, not of a leaf name.
     *
     * @return array{groups: list<array{label: string, total: int, delivered: int, accepted: int}>, needsReview: bool}
     */
    public function getStats(Activity $activity): array
    {
        $needsReview = $activity->getFolder()?->requiresReview() ?? false;
        $groups      = [];

        foreach ($this->getAllSlots($activity) as $slot) {
            if ($activity->getSubmissionScope() === ActivitySubmissionScope::Individual) {
                $key   = $slot->teacher?->getId()->toRfc4122() ?? '';
                $label = $slot->teacher === null ? '' : $slot->teacher->getName()->getLastName() . ', ' . $slot->teacher->getName()->getFirstName();
            } else {
                $key   = ProfileAssignmentRow::keyFor($slot->profile, $slot->listItem);
                $label = $slot->profile->getName() . ($slot->listItem !== null ? ' ' . $slot->listItem->getName() : '');
            }

            $groups[$key] ??= ['label' => $label, 'total' => 0, 'delivered' => 0, 'accepted' => 0];
            ++$groups[$key]['total'];

            $document = $this->resolveSlot($activity, $slot);
            if ($document === null) {
                continue;
            }
            ++$groups[$key]['delivered'];
            if ($document->getActiveRevision() !== null) {
                ++$groups[$key]['accepted'];
            }
        }

        return ['groups' => array_values($groups), 'needsReview' => $needsReview];
    }

    /**
     * The activity's overall submission progress (every slot, this academic year) — only for
     * whoever manages or reviews its folder, who need the whole picture at a glance on the card
     * itself; null for everyone else, and for an activity without a folder.
     */
    public function getSubmissionProgress(Activity $activity): ?ActivitySubmissionProgress
    {
        $folder = $activity->getFolder();
        if ($folder === null || !($this->canManageFolder($folder) || $this->canReviewFolder($folder))) {
            return null;
        }

        return $this->progress->forActivity($activity);
    }

    /**
     * Per-teacher completion breakdown of a manual (folder-less) activity, this academic year —
     * the no-folder equivalent of getSubmissionProgress(), for whoever manages the activity (see
     * canManageActivity()); null for a folder-backed activity, for anyone who doesn't manage it, or
     * when the centre has no active academic year.
     *
     * @return ?list<array{teacher: Teacher, completed: bool}>
     */
    public function getManualCompletionStats(Activity $activity): ?array
    {
        if ($activity->requiresSubmissions() || !$this->canManageActivity($activity)) {
            return null;
        }

        $year = $this->centre->getActiveAcademicYear();
        if ($year === null) {
            return [];
        }

        return $this->completion->manualCompletionStats($activity, $this->teachers->findByAcademicYearOrderedByName($year));
    }

    /**
     * The activity's submissions awaiting review, for its bulk review box — only for whoever
     * reviews its folder; empty for everyone else. Split between the current occurrence's and
     * those left from earlier ones (by their document's cycle key, see
     * DocumentActivityCycleListener), so last year's are never approved or rejected along with
     * this year's by mistake; the latter carry the academic year they belong to.
     *
     * @return array{current: list<DocumentRevision>, older: list<array{revision: DocumentRevision, year: ?string}>}
     */
    public function getPendingReviews(Activity $activity): array
    {
        $folder = $activity->getFolder();
        if ($folder === null || !$this->canReviewFolder($folder)) {
            return ['current' => [], 'older' => []];
        }

        $cycle   = $this->deadline->currentCycleKey($activity);
        $current = [];
        $older   = [];
        foreach ($this->revisions->findPendingReviewByFolder($folder) as $revision) {
            $year = $revision->getDocument()->getActivityCycleYear();
            if ($year === $cycle) {
                $current[] = $revision;
            } else {
                $older[] = ['revision' => $revision, 'year' => $year === null ? null : ActivityDeadlineChecker::academicYearLabel($year)];
            }
        }

        return ['current' => $current, 'older' => $older];
    }

    /** "Recordar a pendientes": same rule as ActivityController::canRemind(). */
    public function canRemindPending(Activity $activity): bool
    {
        $folder = $activity->getFolder();

        return $this->canEdit() || ($folder !== null && ($this->canManageFolder($folder) || $this->canReviewFolder($folder)));
    }

    /**
     * Delivered/accepted/rejected counts across just the current teacher's own slots (see
     * getMySlots()) — the compact one-line summary shown next to "Mis entregas", as opposed to
     * getStats()'s full breakdown grouped across every slot in the activity. A slot counts as
     * rejected when it has a document with no pending and no active revision but at least one
     * rejected one — Document has no isRejected()/getLatestRevision() of its own, but a rejected
     * revision blocks neither a later pending upload nor a later approval, so "no pending, no
     * active, something was rejected" is enough without needing the true latest revision.
     *
     * @return array{total: int, delivered: int, accepted: int, rejected: int, needsReview: bool}
     */
    public function getMySubmissionStats(Activity $activity): array
    {
        $needsReview = $activity->getFolder()?->requiresReview() ?? false;
        $total       = $delivered = $accepted = $rejected = 0;

        foreach ($this->getMySlots($activity) as $slot) {
            ++$total;

            $document = $this->resolveSlot($activity, $slot);
            if ($document === null) {
                continue;
            }
            ++$delivered;
            if ($document->getActiveRevision() !== null) {
                ++$accepted;
            } elseif ($document->getPendingRevision() === null
                && $document->getRevisions()->exists(static fn (int $i, DocumentRevision $r): bool => $r->isRejected())
            ) {
                ++$rejected;
            }
        }

        return ['total' => $total, 'delivered' => $delivered, 'accepted' => $accepted, 'rejected' => $rejected, 'needsReview' => $needsReview];
    }

    // ── Completion ────────────────────────────────────────────────────────────

    /** Whether the current teacher's own completion is tracked as a single "me" owner (Individual scope, or no folder at all). */
    public function hasIndividualCompletionOwner(Activity $activity): bool
    {
        return $this->completion->hasIndividualCompletionOwner($activity);
    }

    /** @return array<int, array{profile: SpecificProfile, listItem: ?ListItem}> distinct upload rows the current teacher holds among this activity's slots (ByProfile scope only). */
    public function getMyCompletionOwners(Activity $activity): array
    {
        return $this->completion->getMyCompletionOwners($this->teacher(), $activity);
    }

    public function isCompletedFor(Activity $activity, ?SpecificProfile $profile, ?ListItem $listItem, ?Teacher $teacher, ?ListItem $leaf = null): bool
    {
        return $this->completion->isCompletedFor($activity, $profile, $listItem, $teacher, $leaf);
    }

    /** Submission/completion window state for the current teacher — drives the deadline notices. $leaf resolves its own override, when it has one. */
    public function getActivityWindow(Activity $activity, ?ListItem $leaf = null): ActivityWindow
    {
        return $this->windowChecker->for($activity, $this->teacher(), $leaf);
    }

    /**
     * Colour-coded status of the activity's card for the current teacher: the most urgent status
     * among their own obligations for it (an ActivityObligationStatus value, the same the
     * dashboard and "Mis actividades" show — see ActivityObligationFinder), or "neutral" when the
     * activity isn't theirs at all: someone else's activity is never painted red just because its
     * deadline passed.
     */
    public function getActivityStatus(Activity $activity): string
    {
        $status = $this->obligations->worstStatusFor($this->teacher(), $activity);

        return $status === null ? 'neutral' : $status->value;
    }

    #[LiveAction]
    public function askMarkCompleted(#[LiveArg] string $activityId, #[LiveArg] string $profileId = '', #[LiveArg] string $listItemId = '', #[LiveArg] string $leafId = ''): void
    {
        $this->confirmingCompleteKey = $activityId . ':' . $profileId . ':' . $listItemId . ':' . $leafId;
    }

    #[LiveAction]
    public function cancelMarkCompleted(): void
    {
        $this->confirmingCompleteKey = '';
    }

    #[LiveAction]
    public function markCompleted(#[LiveArg] string $activityId, #[LiveArg] string $profileId = '', #[LiveArg] string $listItemId = '', #[LiveArg] string $leafId = ''): void
    {
        $activity = $this->findActivity($activityId);
        // Auto-complete activities have nothing to mark: their status is always computed.
        if ($activity === null || $activity->isAutoComplete()) {
            $this->confirmingCompleteKey = '';

            return;
        }

        $this->confirmingCompleteKey = '';

        // Owner validation, window check, flush and the explicit log entry live in OwnCompletionManager
        // (shared with the dashboard's "Marcar hecha").
        match ($this->ownCompletions->mark($this->teacher(), $activity, $this->centre, $profileId, $listItemId, $leafId)) {
            OwnCompletionOutcome::OutOfWindow => $this->flashError($this->t('completion.error.out_of_window')),
            OwnCompletionOutcome::Marked      => $this->flashSuccess($this->t('activity.flash.completed')),
            OwnCompletionOutcome::Unchanged   => null,
        };
    }

    /** No confirmation required — undoing a completion is low-stakes and easy to redo. */
    #[LiveAction]
    public function unmarkCompleted(#[LiveArg] string $activityId, #[LiveArg] string $profileId = '', #[LiveArg] string $listItemId = '', #[LiveArg] string $leafId = ''): void
    {
        $activity = $this->findActivity($activityId);
        if ($activity === null || $activity->isAutoComplete()) {
            return;
        }

        $teacher                     = $this->teacher();
        [$profile, $listItem, $leaf] = $this->ownCompletions->resolveOwner($teacher, $activity, $this->centre, $profileId, $listItemId, $leafId);
        $targetTeacher               = $profile === null ? $teacher : null;

        if (!$this->completion->unmarkCompleted($activity, $targetTeacher, $profile, $listItem, $leaf)) {
            return;
        }

        $this->em->flush();

        $this->flashSuccess($this->t('activity.flash.completion_undone'));
    }

    /**
     * Marks or unmarks another teacher's completion of a manual activity — anyone who manages it
     * may act on someone else's behalf (canManageActivity(): a responsable de calidad/admin, or a
     * teacher holding one of the activity's own responsible profiles). Deliberately bypasses
     * ActivityWindowChecker: unlike the teacher's own self-service button, a manager correcting
     * someone else's record is not meant to be blocked by the deadline window.
     */
    #[LiveAction]
    public function toggleManualCompletionForTeacher(#[LiveArg] string $activityId, #[LiveArg] string $teacherId): void
    {
        $activity = $this->findActivity($activityId);
        if ($activity === null || $activity->requiresSubmissions() || $activity->isAutoComplete()) {
            return;
        }
        if (!$this->canManageActivity($activity)) {
            throw $this->createAccessDeniedException();
        }

        $year    = $this->centre->getActiveAcademicYear();
        $teacher = $year === null ? null : $this->teachers->findByAcademicYearAndId($year, $teacherId);
        if ($teacher === null || !$this->completion->isApplicableToTeacher($teacher, $activity)) {
            return;
        }

        $completing = !$this->completion->isCompletedFor($activity, null, null, $teacher);
        if ($completing) {
            $created = $this->completion->markCompleted($activity, $teacher, null, null, $this->teacher());
        } else {
            $created = $this->completion->unmarkCompleted($activity, $teacher, null, null);
        }
        if (!$created) {
            return;
        }

        $this->em->flush();

        $logData = ['activity' => $activity->getTitle(), 'teacher' => $teacher->getName()->getLastName() . ', ' . $teacher->getName()->getFirstName()];
        $this->activityLogger->record($completing ? 'activity.mark_complete' : 'activity.unmark_complete', $logData, $this->centre);

        $this->flashSuccess($this->t($completing ? 'activity.flash.completed' : 'activity.flash.completion_undone'));
    }

    // ── Revision panel (mirrors SectionBrowserComponent's equivalents, scoped to an activity's own folder) ──

    public function canManageFolder(Folder $folder): bool
    {
        return $this->access->canManageFolder($this->teacher(), $folder);
    }

    public function canReviewFolder(Folder $folder): bool
    {
        return $this->access->canReviewFolder($this->teacher(), $folder);
    }

    /** The author of a rejected revision may answer it with a new version — see DocumentTreeAccessChecker::canResubmitRejected(). */
    public function canResubmitRejected(Document $document): bool
    {
        return $this->access->canResubmitRejected($this->teacher(), $document);
    }

    public function canManageDocumentAsUploader(Document $document): bool
    {
        return $this->access->canManageDocumentAsUploader($this->teacher(), $document);
    }

    /**
     * Whether the current teacher may withdraw (delete) this document because it's still entirely
     * their own doing — see DocumentTreeAccessChecker::canWithdrawOwnUnreviewedUpload(). Drives the
     * delete button on a submission row for a teacher who is neither the folder's manager/reviewer
     * nor otherwise covered by canManageDocumentAsUploader(): deleting frees the slot, and the row
     * then shows a fresh dropzone in its place if — and only if — the activity's window still
     * allows submitting (the row already renders read-only otherwise, see
     * _activity_submissions.html.twig), so "replace" needs no separate action or permission.
     */
    public function canWithdrawOwnSubmission(Document $document): bool
    {
        return $this->access->canWithdrawOwnUnreviewedUpload($this->teacher(), $document);
    }

    /** Either reason a document can be deleted from here: full uploader management, or withdrawing one's own not-yet-reviewed submission. */
    private function canDeleteDocument(Document $document): bool
    {
        $teacher = $this->teacher();

        return $this->access->canManageDocumentAsUploader($teacher, $document)
            || $this->access->canWithdrawOwnUnreviewedUpload($teacher, $document);
    }

    /** Narrower than canManageFolder(): only admin/responsable de calidad may rewrite who uploaded a revision and when, or delete one outright. */
    public function canEditRevisionMetadata(): bool
    {
        return $this->access->isAdminOrQualityManager($this->teacher(), $this->centre);
    }

    /** @return Teacher[] ordered by name, for the "edit teacher" picker on a revision. */
    public function getCentreTeachers(): array
    {
        $year = $this->centre->getActiveAcademicYear();

        return $year === null ? [] : $this->teachers->findByAcademicYearOrderedByName($year);
    }

    /** @return DocumentRevision[] most recent first */
    public function getDocumentRevisions(Document $document): array
    {
        return $this->revisions->findByDocument($document);
    }

    #[LiveAction]
    public function toggleRevisionPanel(#[LiveArg] string $id): void
    {
        $this->revisionPanelDocumentId = $this->revisionPanelDocumentId === $id ? '' : $id;
    }

    #[LiveAction]
    public function askDeleteDocument(#[LiveArg] string $id): void
    {
        $document = $this->findDocument($id);
        if ($document === null || !$this->canDeleteDocument($document)) {
            throw $this->createAccessDeniedException();
        }

        $this->confirmingDeleteDocumentId = $id;
    }

    #[LiveAction]
    public function cancelDeleteDocument(): void
    {
        $this->confirmingDeleteDocumentId = '';
    }

    #[LiveAction]
    public function deleteDocument(#[LiveArg] string $id): void
    {
        $document = $this->findDocument($id);
        if ($document === null || !$this->canDeleteDocument($document)) {
            throw $this->createAccessDeniedException();
        }

        $this->confirmingDeleteDocumentId = '';

        $this->trash->trashDocument($document, $this->teacher());

        $this->flashSuccess($this->t('document.flash.deleted'));
    }

    #[LiveAction]
    public function setActiveRevision(#[LiveArg] string $id, #[LiveArg] string $revisionId = ''): void
    {
        $document = $this->requireDocument($id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $document->getFolder());

        if ($revisionId === '') {
            $document->setActiveRevision(null);
            $this->em->flush();

            return;
        }

        $revision = $this->revisions->findByIdAndDocument($revisionId, $document);
        if ($revision === null || !$revision->isApproved()) {
            $this->errors = ['activeRevision' => $this->t('document.error.revision_not_approved')];

            return;
        }

        $document->setActiveRevision($revision);
        $this->em->flush();
        $this->errors = [];
    }

    #[LiveAction]
    public function startEditRevision(#[LiveArg] string $id, #[LiveArg] string $revisionId): void
    {
        $document = $this->requireDocument($id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $document->getFolder());

        $revision = $this->revisions->findByIdAndDocument($revisionId, $document);
        if ($revision === null) {
            throw $this->createNotFoundException();
        }

        $this->editingRevisionId = $revisionId;
        $this->editVersionValue  = (string) $revision->getVersion();
        if ($this->canEditRevisionMetadata()) {
            $this->editUploadedById   = $revision->getUploadedBy()->getId()->toRfc4122();
            $this->editRevisedAtValue = $revision->getRevisedAt()->format('Y-m-d\TH:i');
        }
    }

    #[LiveAction]
    public function cancelEditRevision(): void
    {
        $this->editingRevisionId = '';
        $this->errors            = [];
    }

    #[LiveAction]
    public function saveEditRevision(#[LiveArg] string $id): void
    {
        $document = $this->requireDocument($id);
        $this->denyAccessUnlessGranted(FolderVoter::MANAGE, $document->getFolder());

        $revision = $this->revisions->findByIdAndDocument($this->editingRevisionId, $document);
        if ($revision === null) {
            throw $this->createNotFoundException();
        }

        $newVersion = (int) trim($this->editVersionValue);
        if ($newVersion < 1) {
            $this->errors = ['editRevision' => $this->t('document.error.invalid_version')];

            return;
        }
        if ($newVersion !== $revision->getVersion() && $document->hasVersion($newVersion)) {
            $this->errors = ['editRevision' => $this->t('document.error.version_in_use')];

            return;
        }

        if ($this->canEditRevisionMetadata()) {
            $year    = $this->centre->getActiveAcademicYear();
            $teacher = $year === null ? null : $this->teachers->findByAcademicYearAndId($year, $this->editUploadedById);
            if ($teacher === null) {
                $this->errors = ['editRevision' => $this->t('document.error.uploaded_by_invalid')];

                return;
            }

            $revisedAt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $this->editRevisedAtValue);
            if ($revisedAt === false) {
                $this->errors = ['editRevision' => $this->t('document.error.invalid_revised_at')];

                return;
            }

            $revision->setUploadedBy($teacher);
            $revision->setRevisedAt($revisedAt);
        }

        $revision->setVersion($newVersion);
        $this->em->flush();

        $this->editingRevisionId = '';
        $this->errors            = [];
    }

    #[LiveAction]
    public function askDeleteRevision(#[LiveArg] string $id, #[LiveArg] string $revisionId): void
    {
        $document = $this->requireDocument($id);
        if (!$this->canEditRevisionMetadata()) {
            throw $this->createAccessDeniedException();
        }
        if ($this->revisions->findByIdAndDocument($revisionId, $document) === null) {
            throw $this->createNotFoundException();
        }

        $this->confirmingDeleteRevisionId = $revisionId;
    }

    #[LiveAction]
    public function cancelDeleteRevision(): void
    {
        $this->confirmingDeleteRevisionId = '';
    }

    /** Only an admin/responsable de calidad may delete a revision outright — see canEditRevisionMetadata(). */
    #[LiveAction]
    public function deleteRevision(#[LiveArg] string $id): void
    {
        $document = $this->requireDocument($id);
        if (!$this->canEditRevisionMetadata()) {
            throw $this->createAccessDeniedException();
        }

        $revision = $this->revisions->findByIdAndDocument($this->confirmingDeleteRevisionId, $document);
        if ($revision === null) {
            throw $this->createNotFoundException();
        }

        $this->confirmingDeleteRevisionId = '';

        if ($document->getActiveRevision() === $revision) {
            $document->setActiveRevision(null);
        }

        $file = $revision->getFile();
        $document->getRevisions()->removeElement($revision);
        $this->em->remove($revision);
        $this->em->flush();

        $this->garbageCollector->deleteIfOrphaned($file);

        $this->flashSuccess($this->t('document.flash.revision_deleted'));
    }

    private function requireDocument(string $id): Document
    {
        return $this->findDocument($id) ?? throw $this->createNotFoundException();
    }

    /**
     * Every id reaching this component from the client (LiveArgs, writable LiveProps) goes through
     * these two: a bare findById() would happily return another centre's activity or document, and
     * a permission check against $this->centre (e.g. requireEditPermission()) says nothing about
     * where the entity itself lives.
     */
    private function findActivity(string $id): ?Activity
    {
        $activity = $this->activities->findById($id);
        if ($activity === null || $activity->getCategory()->getEducationalCentre() !== $this->centre) {
            return null;
        }

        // A hidden activity doesn't exist for anyone but whoever can edit activities.
        return $activity->isHidden() && !$this->canEdit() ? null : $activity;
    }

    private function findDocument(string $id): ?Document
    {
        $document = $this->documents->findById($id);

        return $document !== null && $document->getFolder()->getDocumentSection()->getEducationalCentre() === $this->centre ? $document : null;
    }

    // ── Search ───────────────────────────────────────────────────────────────

    /** @return array<int, array{category: ActivityCategory, path: string, direct: bool}> */
    public function getCategorySearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->categories->searchByCentre($this->centre, $query) as $category) {
            $results[] = ['category' => $category, 'path' => $this->categorySearchPath($category), 'direct' => $this->categoryHasRelevantActivity($category, $teacher)];
        }

        return $results;
    }

    /** @return array<int, array{activity: Activity, path: string, direct: bool}> */
    public function getActivitySearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->activities->searchByCentre($this->centre, $query, includeHidden: $this->canEdit()) as $activity) {
            $folder = $activity->getFolder();
            if ($folder !== null && !$this->access->canViewFolder($teacher, $folder)) {
                continue;
            }
            $results[] = ['activity' => $activity, 'path' => $this->categoryTrail($activity->getCategory()), 'direct' => $this->access->isActivityRelevantToTeacher($teacher, $activity)];
        }

        return $results;
    }

    /** @return array<int, array{document: Document, activity: Activity, path: string, direct: bool}> */
    public function getSubmissionSearchResults(): array
    {
        $query = trim($this->searchQuery);
        if (mb_strlen($query) < 2) {
            return [];
        }

        $teacher = $this->teacher();
        $results = [];
        foreach ($this->documents->searchActivitySubmissionsByCentre($this->centre, $query) as $document) {
            $folder   = $document->getFolder();
            $activity = $folder->getActivity();
            if ($activity === null || ($activity->isHidden() && !$this->canEdit()) || !$this->access->canViewFolder($teacher, $folder)) {
                continue;
            }
            $results[] = [
                'document' => $document,
                'activity' => $activity,
                'path'     => $this->categoryTrail($activity->getCategory()) . ' › ' . $activity->getTitle(),
                'direct'   => $this->access->isActivityRelevantToTeacher($teacher, $activity),
            ];
        }

        return $results;
    }

    #[LiveAction]
    public function clearSearch(): void
    {
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function openCategorySearchResult(#[LiveArg] string $id): void
    {
        $category = $this->categories->findByIdAndCentre($id, $this->centre);
        if ($category === null) {
            return;
        }
        if (!$this->categoryHasRelevantActivity($category, $this->teacher())) {
            $this->showAllProfiles = true;
        }
        $this->currentCategoryId = $id;
        $this->resetTransientState();
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function openActivitySearchResult(#[LiveArg] string $id): void
    {
        $activity = $this->findActivity($id);
        if ($activity === null) {
            return;
        }
        if (!$this->access->isActivityRelevantToTeacher($this->teacher(), $activity)) {
            $this->showAllProfiles = true;
        }
        $this->currentCategoryId = $activity->getCategory()->getId()->toRfc4122();
        $this->resetTransientState();
        $this->searchQuery = '';
    }

    #[LiveAction]
    public function openSubmissionSearchResult(#[LiveArg] string $documentId): void
    {
        $document = $this->findDocument($documentId);
        $activity = $document?->getFolder()->getActivity();
        if ($document === null || $activity === null) {
            return;
        }
        if (!$this->access->isActivityRelevantToTeacher($this->teacher(), $activity)) {
            $this->showAllProfiles = true;
        }
        $this->currentCategoryId      = $activity->getCategory()->getId()->toRfc4122();
        $this->highlightedDocumentId  = $documentId;
        $this->resetTransientState();
        $this->searchQuery = '';
    }

    private function categoryTrail(ActivityCategory $category): string
    {
        $trail = [];
        for ($c = $category; $c !== null; $c = $c->getParent()) {
            array_unshift($trail, $c->getName());
        }

        return implode(' › ', $trail);
    }

    private function categorySearchPath(ActivityCategory $category): string
    {
        $parent = $category->getParent();
        if ($parent === null) {
            return $this->translator->trans('breadcrumb.root', [], 'activity_content');
        }

        return $this->categoryTrail($parent);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function resetTransientState(): void
    {
        $this->activityFormOpen           = false;
        $this->confirmingDeleteActivityId = '';
        $this->revisionPanelDocumentId    = '';
        $this->confirmingDeleteDocumentId = '';
        $this->editingRevisionId          = '';
        $this->confirmingDeleteRevisionId = '';
        $this->errors                     = [];
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    /**
     * LiveAction responses only re-render this component's fragment, not the layout, so a plain
     * addFlash() never reaches the page until the next full navigation. Dispatch a browser event
     * instead so the layout's JS can render the flash immediately.
     */
    private function flashSuccess(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $message]);
    }

    private function flashError(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'error', 'message' => $message]);
    }
}
