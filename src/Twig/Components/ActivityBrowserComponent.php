<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Activity;
use App\Entity\Document;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Repository\ActivityCategoryRepository;
use App\Repository\ActivityChangeRepository;
use App\Repository\ActivityRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\FolderRepository;
use App\Repository\ListItemRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Service\ActivityChangeRecorder;
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
use App\Service\DocumentTreeAccessChecker;
use App\Service\DocumentVersioning;
use App\Service\FolderProfileSynchronizer;
use App\Service\OwnCompletionManager;
use App\Service\ProfileAssignmentRowBuilder;
use App\Service\TrashService;
use App\Twig\Components\Concerns\ActivityCategoryNavigation;
use App\Twig\Components\Concerns\ActivityCompletion;
use App\Twig\Components\Concerns\ActivityFormActions;
use App\Twig\Components\Concerns\ActivityFormOptions;
use App\Twig\Components\Concerns\ActivityRevisions;
use App\Twig\Components\Concerns\ActivitySearch;
use App\Twig\Components\Concerns\ActivitySubmissions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
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
    use ActivityCategoryNavigation;
    use ActivityFormOptions;
    use ActivityFormActions;
    use ActivitySubmissions;
    use ActivityCompletion;
    use ActivityRevisions;
    use ActivitySearch;


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

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];


    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly ActivityCategoryRepository $categories,
        private readonly ActivityRepository $activities,
        private readonly FolderRepository $folders,
        private readonly DocumentSectionRepository $sections,
        private readonly ListItemRepository $listItems,
        private readonly ActivityChangeRepository $activityChanges,
        private readonly ActivityChangeRecorder $changeRecorder,
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
        private readonly DocumentVersioning $versioning,
    ) {}

    /** The lowest revision number the centre allows (0 or 1): the floor of the version typed when editing a submission's revision. */
    public function getMinRevision(): int
    {
        return $this->versioning->minimum($this->centre);
    }

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
