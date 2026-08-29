<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Activity;
use App\Entity\ActivityCategory;
use App\Entity\ActivitySubmissionScope;
use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\ListItem;
use App\Entity\SpecificProfile;
use App\Entity\Tag;
use App\Entity\Teacher;
use App\Model\ActivitySubmissionSlot;
use App\Model\ProfileAssignmentRow;
use App\Repository\ActivityCategoryRepository;
use App\Repository\ActivityRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\FolderRepository;
use App\Repository\ListItemRepository;
use App\Repository\SpecificProfileRepository;
use App\Repository\TagRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Security\Voter\FolderVoter;
use App\Service\ActivityCompletionChecker;
use App\Service\DocumentFileGarbageCollector;
use App\Service\DocumentTreeAccessChecker;
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
 * its activities — filtered to whichever are relevant to the current teacher's own folder
 * profiles/roles, unless $showAllProfiles widens it (a relevance filter, never an access gate: a
 * category/activity's own folder visibility is still always enforced underneath, see
 * DocumentTreeAccessChecker::isActivityRelevantToTeacher()). Activity CRUD is reserved to
 * EducationalCentreVoter::RESPONSIBILITIES, done inline here (not in the separate "Editar
 * categorías" tab) — mirrors exactly how Folder creation/editing lives in Árbol documental's "Ver"
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

    /** Relevance-widening toggle, available to any teacher — never bypasses folder/section visibility. */
    #[LiveProp(writable: true)]
    public bool $showAllProfiles = false;

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

    #[LiveProp(writable: true)]
    public string $formStartDay = '';

    #[LiveProp(writable: true)]
    public string $formStartMonth = '';

    #[LiveProp(writable: true)]
    public string $formEndDay = '';

    #[LiveProp(writable: true)]
    public string $formEndMonth = '';

    #[LiveProp(writable: true)]
    public string $formFolderId = '';

    #[LiveProp(writable: true)]
    public string $formListItemId = '';

    /** @var string[] tag ids */
    #[LiveProp(writable: true)]
    public array $formTagIds = [];

    #[LiveProp(writable: true)]
    public bool $formRequired = true;

    #[LiveProp(writable: true)]
    public bool $formAutoComplete = false;

    #[LiveProp(writable: true)]
    public string $formScope = 'by_profile';

    #[LiveProp(writable: true)]
    public string $confirmingDeleteActivityId = '';

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

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ActivityCategoryRepository $categories,
        private readonly ActivityRepository $activities,
        private readonly FolderRepository $folders,
        private readonly ListItemRepository $listItems,
        private readonly SpecificProfileRepository $profiles,
        private readonly TagRepository $tags,
        private readonly DocumentRepository $documents,
        private readonly DocumentRevisionRepository $revisions,
        private readonly TeacherRepository $teachers,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ActivityCompletionChecker $completion,
        private readonly DocumentFileGarbageCollector $garbageCollector,
    ) {}

    public function mount(
        EducationalCentre $centre,
        string $initialCategoryId = '',
        string $initialActivityId = '',
        string $initialHighlightDocumentId = '',
    ): void {
        $this->centre = $centre;

        if ($initialActivityId !== '') {
            $activity = $this->activities->findById($initialActivityId);
            if ($activity !== null && $activity->getCategory()->getEducationalCentre() === $centre) {
                $this->currentCategoryId = $activity->getCategory()->getId()->toRfc4122();
            }
        } elseif ($initialCategoryId !== '') {
            $category = $this->categories->findByIdAndCentre($initialCategoryId, $centre);
            if ($category !== null) {
                $this->currentCategoryId = $initialCategoryId;
            }
        }

        if ($initialHighlightDocumentId !== '') {
            $document = $this->documents->findById($initialHighlightDocumentId);
            if ($document !== null && $document->getFolder()->getActivity() !== null) {
                $this->highlightedDocumentId = $initialHighlightDocumentId;
            }
        }
    }

    public function canEdit(): bool
    {
        return $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
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
    }

    #[LiveAction]
    public function toggleShowAllProfiles(): void
    {
        $this->showAllProfiles = !$this->showAllProfiles;
    }

    /** Whether $category, or any of its descendants, has at least one activity relevant to $teacher. */
    private function categoryHasRelevantActivity(ActivityCategory $category, Teacher $teacher): bool
    {
        foreach ($this->activities->findByCategory($category) as $activity) {
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

    // ── Activities in the current category ───────────────────────────────────

    /** @return Activity[] */
    public function getVisibleActivities(): array
    {
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return [];
        }

        $all = $this->activities->findByCategory($category);
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
        $editing = $this->formActivityId === '' ? null : $this->activities->findById($this->formActivityId);

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

    /** @return ListItem[] */
    public function getAvailableListItems(): array
    {
        return $this->listItems->findAllByCentre($this->centre);
    }

    public function getListItemLabel(ListItem $item): string
    {
        $trail = [];
        for ($node = $item; $node !== null; $node = $node->getParent()) {
            array_unshift($trail, $node->getName());
        }

        return implode(' › ', $trail);
    }

    /** @return Tag[] */
    public function getAvailableTags(): array
    {
        return $this->tags->findByCentre($this->centre);
    }

    #[LiveAction]
    public function startAddActivity(): void
    {
        $this->requireEditPermission();
        $this->formActivityId  = '';
        $this->formTitle       = '';
        $this->formDescription = '';
        $this->formStartDay    = '';
        $this->formStartMonth  = '';
        $this->formEndDay      = '';
        $this->formEndMonth    = '';
        $this->formFolderId    = '';
        $this->formListItemId  = '';
        $this->formTagIds      = [];
        $this->formRequired    = true;
        $this->formAutoComplete = false;
        $this->formScope       = 'by_profile';
        $this->activityFormOpen = true;
        $this->errors           = [];
    }

    #[LiveAction]
    public function startEditActivity(#[LiveArg] string $id): void
    {
        $this->requireEditPermission();
        $activity = $this->activities->findById($id);
        if ($activity === null) {
            return;
        }

        $this->formActivityId   = $id;
        $this->formTitle        = $activity->getTitle();
        $this->formDescription  = $activity->getDescription() ?? '';
        $this->formStartDay     = (string) $activity->getStartDay();
        $this->formStartMonth   = (string) $activity->getStartMonth();
        $this->formEndDay       = (string) $activity->getEndDay();
        $this->formEndMonth     = (string) $activity->getEndMonth();
        $this->formFolderId     = $activity->getFolder()?->getId()->toRfc4122() ?? '';
        $this->formListItemId   = $activity->getListItem()?->getId()->toRfc4122() ?? '';
        $this->formTagIds       = array_map(static fn (Tag $t): string => $t->getId()->toRfc4122(), $activity->getTags()->toArray());
        $this->formRequired     = $activity->isRequired();
        $this->formAutoComplete = $activity->isAutoComplete();
        $this->formScope        = $activity->getSubmissionScope()->value;
        $this->activityFormOpen = true;
        $this->errors           = [];
    }

    #[LiveAction]
    public function cancelActivityForm(): void
    {
        $this->activityFormOpen = false;
        $this->errors           = [];
    }

    #[LiveAction]
    public function saveActivity(): void
    {
        $this->requireEditPermission();
        $category = $this->getCurrentCategory();
        if ($category === null) {
            return;
        }

        $title = trim($this->formTitle);
        if ($title === '') {
            $this->errors = ['title' => $this->t('activity.error.name_required')];

            return;
        }

        $startDay   = (int) $this->formStartDay;
        $startMonth = (int) $this->formStartMonth;
        $endDay     = (int) $this->formEndDay;
        $endMonth   = (int) $this->formEndMonth;
        if ($startDay < 1 || $startDay > 31 || $startMonth < 1 || $startMonth > 12 || $endDay < 1 || $endDay > 31 || $endMonth < 1 || $endMonth > 12) {
            $this->errors = ['dates' => $this->t('activity.error.invalid_date')];

            return;
        }

        $folder = $this->formFolderId === '' ? null : $this->resolveAvailableFolder($this->formFolderId);
        if ($this->formAutoComplete && $folder === null) {
            $this->errors = ['autoComplete' => $this->t('activity.error.auto_complete_requires_folder')];

            return;
        }

        $listItem = $this->formListItemId === '' ? null : $this->listItems->findByIdAndCentre($this->formListItemId, $this->centre);
        $scope    = ActivitySubmissionScope::from($this->formScope === 'individual' ? 'individual' : 'by_profile');

        $activity = $this->formActivityId === '' ? null : $this->activities->findById($this->formActivityId);
        if ($activity === null) {
            $activity = (new Activity())
                ->setCategory($category)
                ->setPosition($this->activities->nextPosition($category));
            $this->em->persist($activity);
        }

        $activity->setTitle($title);
        $activity->setDescription($this->formDescription !== '' ? $this->formDescription : null);
        $activity->setStart($startDay, $startMonth);
        $activity->setEnd($endDay, $endMonth);
        $activity->setListItem($listItem);
        $activity->setRequired($this->formRequired);
        $activity->setSubmissionScope($scope);
        $activity->setFolder($folder);
        // Safe unconditionally: the guard above already ensures $folder isn't null whenever
        // $this->formAutoComplete is true, and setAutoComplete(false) never throws either way.
        $activity->setAutoComplete($this->formAutoComplete);

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

        $this->em->flush();

        $this->activityFormOpen = false;
        $this->errors           = [];
        $this->flashSuccess($this->t('activity.flash.saved'));
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
        $activity = $this->activities->findById($id);
        if ($activity === null) {
            $this->confirmingDeleteActivityId = '';

            return;
        }

        $this->em->remove($activity);
        $this->em->flush();

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

    public function resolveSlot(Activity $activity, ActivitySubmissionSlot $slot): ?Document
    {
        return $this->completion->resolveSlot($activity, $slot);
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
     * Delivered/accepted counts grouped by upload profile/subperfil (ByProfile scope) or by
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

    public function isCompletedFor(Activity $activity, ?SpecificProfile $profile, ?ListItem $listItem, ?Teacher $teacher): bool
    {
        return $this->completion->isCompletedFor($activity, $profile, $listItem, $teacher);
    }

    #[LiveAction]
    public function markCompleted(#[LiveArg] string $activityId, #[LiveArg] string $profileId = '', #[LiveArg] string $listItemId = ''): void
    {
        $activity = $this->activities->findById($activityId);
        if ($activity === null) {
            return;
        }

        $teacher       = $this->teacher();
        $profile       = $profileId === '' ? null : $this->profiles->findByIdAndCentre($profileId, $this->centre);
        $listItem      = $listItemId === '' ? null : $this->listItems->findByIdAndCentre($listItemId, $this->centre);
        $targetTeacher = $profile === null ? $teacher : null;

        if (!$this->completion->markCompleted($activity, $targetTeacher, $profile, $listItem, $teacher)) {
            return;
        }

        $this->em->flush();

        $this->flashSuccess($this->t('activity.flash.completed'));
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

    public function canManageDocumentAsUploader(Document $document): bool
    {
        return $this->access->canManageDocumentAsUploader($this->teacher(), $document);
    }

    /** Narrower than canManageFolder(): only admin/responsable de calidad may rewrite who uploaded a revision and when, or delete one outright. */
    public function canEditRevisionMetadata(): bool
    {
        return $this->access->isAdminOrQualityManager($this->teacher(), $this->centre);
    }

    /** @return Teacher[] ordered by name, for the "editar docente" picker on a revision. */
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
        $document = $this->documents->findById($id);
        if ($document === null || !$this->access->canManageDocumentAsUploader($this->teacher(), $document)) {
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
        $document = $this->documents->findById($id);
        if ($document === null || !$this->access->canManageDocumentAsUploader($this->teacher(), $document)) {
            throw $this->createAccessDeniedException();
        }

        $this->confirmingDeleteDocumentId = '';

        $files = [];
        foreach ($document->getRevisions() as $revision) {
            $files[] = $revision->getFile();
        }

        $this->em->remove($document);
        $this->em->flush();

        foreach ($files as $file) {
            $this->garbageCollector->deleteIfOrphaned($file);
        }

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
        $document = $this->documents->findById($id);
        if ($document === null) {
            throw $this->createNotFoundException();
        }

        return $document;
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
        foreach ($this->activities->searchByCentre($this->centre, $query) as $activity) {
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
            if ($activity === null || !$this->access->canViewFolder($teacher, $folder)) {
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
        $activity = $this->activities->findById($id);
        if ($activity === null || $activity->getCategory()->getEducationalCentre() !== $this->centre) {
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
        $document = $this->documents->findById($documentId);
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
}
