<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Document;
use App\Entity\DocumentRevision;
use App\Entity\EducationalCentre;
use App\Entity\Folder;
use App\Entity\Teacher;
use App\Model\ProfileAssignmentRow;
use App\Repository\DocumentRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\FolderRepository;
use App\Repository\TeacherRepository;
use App\Security\Voter\EducationalCentreVoter;
use App\Security\Voter\FolderVoter;
use App\Service\ActivityFolderCycleFilter;
use App\Service\AttachmentDownloadResponder;
use App\Service\DocumentFileGarbageCollector;
use App\Service\DocumentTreeAccessChecker;
use App\Service\DocumentVersioning;
use App\Service\FolderProfileSynchronizer;
use App\Service\ProfileAssignmentRowBuilder;
use App\Service\ReadAcknowledgementService;
use App\Service\TrashService;
use App\Twig\Components\Concerns\SectionDocuments;
use App\Twig\Components\Concerns\SectionFolders;
use App\Twig\Components\Concerns\SectionNavigation;
use App\Twig\Components\Concerns\SectionReadAcknowledgement;
use App\Twig\Components\Concerns\SectionRevisions;
use App\Twig\Components\Concerns\SectionSearch;
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
 * The "Ver" tab of Document Tree: browse the section tree (any teacher, respecting each
 * section's own profile restrictions), and within a section manage its folders and browse/act on
 * their documents. Folder structural configuration (create/rename/reorder/delete, the three
 * switches, the four profile lists) is reserved to quality manager/management
 * team/administration (EducationalCentreVoter::RESPONSIBILITIES) — once a folder exists, its
 * own responsible/upload/review profiles govern permissions over its *content* (documents and
 * revisions) via FolderVoter/DocumentTreeAccessChecker, available to any teacher who qualifies.
 */
#[AsLiveComponent]
class SectionBrowserComponent extends AbstractController
{
    use DefaultActionTrait;
    use ComponentToolsTrait;
    use SectionNavigation;
    use SectionFolders;
    use SectionReadAcknowledgement;
    use SectionDocuments;
    use SectionRevisions;
    use SectionSearch;


    #[LiveProp]
    public EducationalCentre $centre;

    /** @var array<string, string> */
    #[LiveProp]
    public array $errors = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly DocumentSectionRepository $sections,
        private readonly FolderRepository $folders,
        private readonly DocumentRepository $documents,
        private readonly DocumentRevisionRepository $revisions,
        private readonly DocumentTreeAccessChecker $access,
        private readonly ProfileAssignmentRowBuilder $rowBuilder,
        private readonly FolderProfileSynchronizer $folderProfiles,
        private readonly DocumentFileGarbageCollector $garbageCollector,
        private readonly TrashService $trash,
        private readonly TeacherRepository $teachers,
        private readonly ActivityFolderCycleFilter $cycleFilter,
        private readonly ReadAcknowledgementService $readAcknowledgements,
        private readonly AttachmentDownloadResponder $downloadResponder,
        private readonly DocumentVersioning $versioning,
    ) {}

    /** The lowest revision number the centre allows (0 or 1): the starting version offered on an upload and the floor of every number typed. */
    public function getMinRevision(): int
    {
        return $this->versioning->minimum($this->centre);
    }

    /** @var array<string, bool> memoised per render: revision id → can the browser show it? */
    private array $previewable = [];

    /** Whether the revision's file is one the browser can show in place (PDF, raster image): offers the "Ver" link. */
    public function canPreview(DocumentRevision $revision): bool
    {
        $id = $revision->getId()->toRfc4122();

        return $this->previewable[$id] ??= $this->downloadResponder->isPreviewable($this->revisions->findMimeType($revision) ?? '');
    }

    public function mount(
        EducationalCentre $centre,
        string $initialSectionId = '',
        string $initialFolderId = '',
        string $initialDocumentId = '',
        string $initialSettingsFolderId = '',
        string $initialHighlightDocumentId = '',
    ): void {
        $this->centre = $centre;
        $this->applyLocation($initialSectionId, $initialFolderId, $initialDocumentId, $initialSettingsFolderId, $initialHighlightDocumentId);
    }

    /**
     * Resolves a (section, folder, document, settings-folder, highlighted-document) tuple — from
     * the initial page load's query string or from a browser back/forward navigation — into the
     * browsing state, silently falling back to whatever part is still valid (e.g. a folder that
     * got deleted since the URL was recorded, or a settings folder the current teacher can no
     * longer edit). $documentId opens that document's revision panel (the clock icon); the
     * separate $highlightDocumentId instead just flashes it (search results land here without
     * forcing the revision panel open — see openSearchResult()).
     */
    private function applyLocation(string $sectionId, string $folderId, string $documentId, string $settingsFolderId = '', string $highlightDocumentId = ''): void
    {
        $this->currentSectionId = $sectionId !== '' && $this->sections->findByIdAndCentre($sectionId, $this->centre) !== null
            ? $sectionId
            : '';

        $section = $this->getCurrentSection();
        if ($section === null) {
            return;
        }

        if ($folderId !== '') {
            $folder = $this->folders->findByIdAndSection($folderId, $section);
            if ($folder !== null && $this->access->canViewFolder($this->teacher(), $folder)) {
                $this->expandedFolderIds[] = $folderId;
                // A link to a retired folder (a search result) must not land on a list that hides it: whoever may see those gets them shown.
                if ($folder->isObsolete() && $this->access->isAdminOrQualityManager($this->teacher(), $this->centre)) {
                    $this->showObsoleteFolders = true;
                }
                if ($documentId !== '' && $this->documents->findByIdAndFolder($documentId, $folder) !== null) {
                    $this->revisionPanelDocumentId = $documentId;
                }
                $highlighted = $highlightDocumentId !== '' ? $this->documents->findByIdAndFolder($highlightDocumentId, $folder) : null;
                if ($highlighted !== null) {
                    $this->highlightedDocumentId = $highlightDocumentId;
                    $this->showCycleOf($highlighted);
                }
            }
        }

        if ($settingsFolderId !== '' && $this->canEditStructure()) {
            $settingsFolder = $this->folders->findByIdAndSection($settingsFolderId, $section);
            if ($settingsFolder !== null) {
                $this->openFolderSettings($settingsFolder);
            }
        }
    }

    /**
     * Restores browsing state from the URL after the user hits the browser's back/forward button —
     * the counterpart to dispatchLocation()'s pushState on the JS side. Never dispatches a location
     * event itself, or every back/forward press would push a new (forward) history entry.
     */
    #[LiveAction]
    public function syncFromUrl(#[LiveArg] string $section = '', #[LiveArg] string $folder = '', #[LiveArg] string $document = '', #[LiveArg] string $settings = '', #[LiveArg] string $highlight = ''): void
    {
        $this->resetTransientState();
        $this->applyLocation($section, $folder, $document, $settings, $highlight);
    }

    /** Tells the document-tree-url Stimulus controller to reflect the current location in the URL (pushState). */
    private function dispatchLocation(): void
    {
        $this->dispatchBrowserEvent('document-tree:location', [
            'section'  => $this->currentSectionId,
            'folder'   => $this->primaryExpandedFolderId(),
            'document' => $this->revisionPanelDocumentId,
            'settings' => $this->folderSettingsPanelId,
            // Mirrored too, so the URL never keeps the highlight of an earlier landing (a reload
            // would try to flash a document that isn't in this folder, i.e. nothing at all).
            'highlight' => $this->highlightedDocumentId,
        ]);
    }

    /**
     * The most recently expanded folder, for the URL/back-button: several can be open at once
     * (see toggleFolder()), but a reloaded or shared link only ever needs to restore one of them.
     */
    private function primaryExpandedFolderId(): string
    {
        return $this->expandedFolderIds === [] ? '' : $this->expandedFolderIds[array_key_last($this->expandedFolderIds)];
    }

    /** How many documents each quick-access list on the root shows. */
    public const int QUICK_ACCESS_ITEMS = 5;

    /** @var list<Document>|null */
    private ?array $pendingReadCache = null;

    /** @var list<Document>|null */
    private ?array $recentCache = null;

    /**
     * The documents this teacher still has to acknowledge as read — the same list as the home
     * dashboard's widget — for the quick access on the tree's root.
     *
     * @return list<Document>
     */
    public function getPendingReadDocuments(): array
    {
        return $this->pendingReadCache ??= $this->readAcknowledgements->pendingFor($this->teacher(), $this->centre);
    }

    /**
     * The documents most recently given a new approved version that this teacher can open, newest
     * first, for the quick access on the tree's root. Looks at the latest thirty and keeps the
     * ones the teacher can see, so a restricted folder never leaks through here.
     *
     * @return list<Document>
     */
    public function getRecentDocuments(): array
    {
        if ($this->recentCache !== null) {
            return $this->recentCache;
        }

        $teacher = $this->teacher();
        $recent  = [];
        foreach ($this->documents->findRecentlyRevisedByCentre($this->centre) as $document) {
            if ($this->access->canViewDocument($teacher, $document)) {
                $recent[] = $document;
            }
            if (\count($recent) === self::QUICK_ACCESS_ITEMS) {
                break;
            }
        }

        return $this->recentCache = $recent;
    }

    private function teacher(): Teacher
    {
        $user = $this->getUser();
        if (!$user instanceof Teacher) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    public function canEditStructure(): bool
    {
        return $this->isGranted(EducationalCentreVoter::RESPONSIBILITIES, $this->centre);
    }

    public function canManageFolder(Folder $folder): bool
    {
        return $this->access->canManageFolder($this->teacher(), $folder);
    }

    public function canUploadToFolder(Folder $folder): bool
    {
        return $this->access->canUploadToFolder($this->teacher(), $folder);
    }

    /** Folder responsible, or (the sole exception) the teacher who uploaded this document's current active revision. */
    public function canManageDocumentAsUploader(Document $document): bool
    {
        return $this->access->canManageDocumentAsUploader($this->teacher(), $document);
    }

    public function canReviewFolder(Folder $folder): bool
    {
        return $this->access->canReviewFolder($this->teacher(), $folder);
    }

    /** @return ProfileAssignmentRow[] for the inline upload-profile picker next to each staged file. */
    public function getAllowedUploadProfileRows(Folder $folder): array
    {
        return $this->access->allowedUploadProfileRows($this->teacher(), $folder);
    }

    /** Narrower than canManageFolder(): only admin/quality manager may rewrite who uploaded a revision and when — not every folder-level responsible. */
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


    private function requireDocument(Folder $folder, string $id): Document
    {
        $document = $this->documents->findByIdAndFolder($id, $folder);
        if ($document === null) {
            throw $this->createNotFoundException();
        }

        return $document;
    }

    private function requireFolder(string $id): Folder
    {
        $section = $this->getCurrentSection();
        $folder  = $section === null ? null : $this->folders->findByIdAndSection($id, $section);
        if ($folder === null) {
            throw $this->createNotFoundException();
        }

        return $folder;
    }

    private function t(string $key): string
    {
        return $this->translator->trans($key, [], 'admin');
    }

    private function flashSuccess(string $message): void
    {
        $this->dispatchBrowserEvent('flash:show', ['type' => 'success', 'message' => $message]);
    }
}
